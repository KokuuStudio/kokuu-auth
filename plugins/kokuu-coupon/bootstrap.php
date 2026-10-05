<?php

use App\Models\User;
use App\Services\Hook;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

/*
 * KokuuSkin 兑换码 + 抽奖（kokuu-coupon）
 *
 * 闭环：管理员批量生成兑换码 → 用户输码 →（A）直接拿积分 或（B）拿抽奖次数
 *                → 用户抽奖 → 按概率得积分 → 积分进users.score
 *
 * ============================ 为什么单独做这个插件 ============================
 * kokuu-exchange 是「积分 → 游戏货币」的单向出口。它**只扣分不加分**，
 * 所以整站缺一个「加分入口」：签到和论坛活动是常规来源，但缺少
 * 「运营发码 → 玩家自行领取」这条可控、可批量、可追踪的通道。
 * 本插件补的就是这一环，且刻意与 kokuu-exchange 解耦：
 * 两者是两个独立状态机（记忆铁律：不要合并插件，合了会互相炸）。
 *
 * ============================ 三个必须守住的设计点 ============================
 *
 * 1. **兑换码是「一次性 + 幂等」的，判定靠数据库唯一索引，不靠「先查一下」**
 *    兑换码表有一张 `coupon_redemptions`，对 `coupon_id` 建**唯一索引**。
 *    插入时撞唯一索引 = 这个码已被兑换过 → 判定为已使用。
 *    若用 `SELECT used_by WHERE used_by IS NULL` 再 UPDATE，
 *    两个并发请求会都通过检查 → 同一码被兑换两次（资损）。
 *    这与 kokuu-credit 的 event_id 幂等、kokuu-exchange 的
 *    UNIQUE(order_no, action) 是同一思路：**让数据库做唯一防线**。
 *
 * 2. **抽奖扣分必须两处都查，且共用 kokuu-exchange 的 min_keep 底仓**
 *    ⚠️ 本插件是**第二个会扣 users.score 的功能**（kokuu-exchange 之外）。
 *    若各自定义「最低保留余额」，用户可以在两个插件里各扣一次，
 *    两次都「满足各自的底线」，加起来把余额扣穿。
 *    → 唯一权威是 `kokuu_exchange_min_keep`（options 表），
 *      本插件**只读不写**该键。管理员在兑换插件的后台改一次，两边同时生效。
 *    扣分时同样「事务外预检 + 事务内 lockForUpdate 再查」两道闸：
 *    缺任一道，并发下会把底仓扣穿（余额可能已经被别人扣走了）。
 *
 * 3. **抽奖的中奖概率与奖品完全由后台配置，抽奖结果只按配置表取值**
 *    奖品用 `lottery_prizes` 表存（label / amount / weight），
 *    抽奖时在事务内 `lockForUpdate` 读一次再按权重抽：
 *    若在事务外读奖品配置，管理员中途改配置会让「已扣的次数」
 *    对应到不同的奖品表，出现无法复现的对账问题。
 *
 * ============================ 积分口径 ============================
 * 唯一账本 =皮肤站 `users.score`（记忆铁律：本插件只发不扣，余额不存副本）。
 * 所有加分**一律走 kokuu_credit_ledger()**，它有 event_id 唯一索引，
 * 天然幂等；本插件的 event_id 形如：
 *   - 兑换加分：coupon:<redemption_id>
 *   - 抽奖奖励：draw:<draw_id>
 * 绝不自己写 users.score 或 credit_ledger（那是 kokuu-credit 的职责）。
 *
 * ============================ 今天踩过的坑（写之前先看） ============================
 * ① Twig 沙箱**没有 max/min 过滤器**（`Unknown "max" filter` → 整页 500）。
 *    → 本插件所有派生量（可抽次数、概率区间、中奖展示）**一律在 PHP 侧算好**，
 *      模板只负责显示。绝不出现 `|max`、`|filter(箭头函数)`。
 * ② 页面侧 POST 挂了 `web` 中间件就**必须带 CSRF**。
 *    → 模板注入 `window.CPN_CSRF`（取自 XSRF-TOKEN cookie，只 decodeURIComponent，
 *      **不要剥 CookieValuePrefix**），JS 带上 X-XSRF-TOKEN。
 *      提交时**实时再读一次** cookie（session/token 会轮换，用旧快照必 419）。
 * ③ 419 与 422 语义相反：419=CSRF 层拦截（没到业务）；422=业务失败
 *    （`response()->json([...], 422)`，说明已穿过 CSRF）。
 *    → 验「CSRF 通不通」判据是**非 419**，绝不是 `=== 200`。
 * ④ PHP 标识符允许 0x80–0xFF 字节：`"$VAR，全角"` 会把全角逗号吞进变量名，
 *    报 `Undefined variable $VAR,`。→ 变量后紧跟全角标点一律用字符串拼接。
 * ⑤ 菜单项 title 插件自己追加的才是中文；内核项是 lang key。
 * ⑥ `Auth::login()` 强制要求 Authenticatable，测身份分支要用
 *    `\App\Models\User::find($uid)`，不能 `DB::table('users')->get()`。
 */

if (! function_exists('kokuu_coupon_defaults')) {
    /**
     * 插件配置默认值（存 options 表，键前缀 kokuu_coupon_）。
     */
    function kokuu_coupon_defaults(): array
    {
        return [
            // 兑换码字符集：去掉了 0/O/1/I/L 等易混字符。
            // ⚠️ 别用 random_bytes 转 base64 再筛，会引入大小写混合的怪字符；
            //   这里用 random_int 逐字符取，字符集可控。
            'code_alphabet'  => 'ABCDEFGHJKMNPQRSTUVWXYZ23456789',
            'code_length'    => 12,
            'code_prefix'    => 'KOKUU',
            // 单个兑换码的积分数（type=credit 时用）。0 = 走奖品表随机
            'default_amount' => 100,
            // 单个兑换码给的抽奖次数（type=draw 时用）
            'default_draws'  => 1,
            // 兑换码有效期（天），0 = 永久有效
            'code_ttl_days'  => 0,
            // 单个用户每日可兑换的码数量上限，0 = 不限（防脚本刷码）
            'daily_redeem_limit' => 20,
            // 抽奖冷却（秒），两次抽奖的最小间隔，0 = 不限
            'draw_cooldown'  => 3,
            // 用户可用积分兑换 1 次抽奖的价格（抽到啥都扣），0 = 关闭该入口
            'draw_cost'      => 50,
            // 每日抽奖次数上限（不含用码获得的），0 = 不限
            'daily_draw_limit' => 50,
            // 每条兑换码允许被兑换的次数，1 = 一码一次
            'max_uses'       => 1,
            'notice'         => '',
        ];
    }
}

if (! function_exists('kokuu_coupon_config')) {
    function kokuu_coupon_config(): array
    {
        $defaults = kokuu_coupon_defaults();

        $values = [];
        foreach ($defaults as $k => $_) {
            $v = app('options')->get('kokuu_coupon_' . $k);
            // 空字符串同样视为「未设置」→ 回落默认值（与其他插件一致）
            $values[$k] = ($v === null || $v === '') ? $defaults[$k] : $v;
        }

        foreach (['code_length', 'default_amount', 'default_draws', 'code_ttl_days',
                  'daily_redeem_limit', 'draw_cooldown', 'draw_cost',
                  'daily_draw_limit', 'max_uses'] as $k) {
            $values[$k] = (int) $values[$k];
        }
        foreach (['code_alphabet', 'code_prefix', 'notice'] as $k) {
            $values[$k] = (string) $values[$k];
        }

        // 字符集/长度做兜底：管理员填了空字符集或超长长度会导致生成不出码
        if ($values['code_alphabet'] === '') {
            $values['code_alphabet'] = $defaults['code_alphabet'];
        }
        if ($values['code_length'] < 6 || $values['code_length'] > 32) {
            $values['code_length'] = 12;
        }

        return $values;
    }
}

if (! function_exists('kokuu_coupon_save_config')) {
    function kokuu_coupon_save_config(array $payload): void
    {
        $keys = ['code_alphabet', 'code_length', 'code_prefix', 'default_amount',
                 'default_draws', 'code_ttl_days', 'daily_redeem_limit',
                 'draw_cooldown', 'draw_cost', 'daily_draw_limit', 'max_uses', 'notice'];

        foreach ($keys as $k) {
            if (array_key_exists($k, $payload)) {
                app('options')->set('kokuu_coupon_' . $k, $payload[$k]);
            }
        }

        /*⚠️ 不要在这里调 Artisan::call('options:cache')：
         *   1) 各插件（credit/home/forum）都只 set()，不自动刷——
         *      重建 options 文件缓存是**部署时**由运维手动跑 artisan 的事，
         *      放在请求里跑会拖慢保存，且 Artisan facade 未 import 会直接致命错误。
         *   2) Option::set() 只写 DB，不刷 storage/options.php。
         *      → 部署/改配置后需手动：
         *        sudo -u www-data php artisan options:cache
         *   （与 kokuu-forum / kokuu-home 注释里写的一致。）
         */
    }
}

if (! function_exists('kokuu_coupon_ensure_tables')) {
    /**
     * 建表。全部幂等（hasTable 判断），可反复调用。
     *
     * ⚠️ 写查询前先 DESCRIBE：站上两张流水表关联兑换码的列名**不同**，
     *    本插件不查那两张表，只调kokuu_credit_ledger() 写入。
     */
    function kokuu_coupon_ensure_tables(): void
    {
        /* ---- 兑换码 ---- */
        if (! Schema::hasTable('coupon_codes')) {
            Schema::create('coupon_codes', function (Blueprint $table) {
                $table->increments('id');
                // 码本体唯一：同码不可重复生成（生成时靠随机+重试避碰）
                $table->string('code', 64)->unique();
                // credit=直接给积分；draw=给抽奖次数
                $table->string('type', 16)->default('credit');
                // type=credit 时的积分数；type=draw 时的抽奖次数
                $table->integer('value')->default(0);
                // 批次号：一次生成的码归一批，方便整批作废与对账
                $table->string('batch', 32)->default('')->index();
                // 备注，如「直播活动」「双十一」
                $table->string('note', 120)->default('');
                // 过期时间（null = 永久）
                $table->dateTime('expires_at')->nullable();
                $table->integer('used_count')->default(0);
                $table->boolean('disabled')->default(false);
                $table->timestamps();
                $table->index(['type', 'disabled']);
            });
        }

        /* ---- 兑换记录（每码一条，一码一次靠这里的唯一索引保证）---- */
        if (! Schema::hasTable('coupon_redemptions')) {
            Schema::create('coupon_redemptions', function (Blueprint $table) {
                $table->increments('id');
                // ★ 幂等核心：对 coupon_id 唯一。一码一兑换，撞唯一索引=已兑换。
                $table->integer('coupon_id')->unique();
                $table->integer('uid');
                // 实际发给用户的值（积分或抽奖次数）
                $table->integer('value')->default(0);
                $table->string('type', 16)->default('credit');
                $table->string('ip', 45)->default('');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index(['uid', 'created_at']);
                $table->index('created_at');
            });
        }

        /* ---- 抽奖记录 ---- */
        if (! Schema::hasTable('lottery_draws')) {
            Schema::create('lottery_draws', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('uid');
                // 奖项 id（null = 未中奖/谢谢参与）
                $table->integer('prize_id')->nullable();
                $table->string('prize_label', 80)->default('');
                // 实际到账积分
                $table->integer('amount')->default(0);
                // 消耗的抽奖次数来源：code=用码获得；cost=花积分购买
                $table->string('via', 16)->default('cost');
                // 幂等键：draw:<id> 写进 credit_ledger 时用
                $table->string('ip', 45)->default('');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index(['uid', 'created_at']);
            });
        }

        /* ---- 抽奖次数账户（用码获得的次数存这里）---- */
        if (! Schema::hasTable('lottery_wallets')) {
            Schema::create('lottery_wallets', function (Blueprint $table) {
                $table->increments('id');
                // ★ 每uid 一行，对 uid 唯一 → 并发抽奖靠这个唯一键防重复扣
                $table->integer('uid')->unique();
                // 用码获得的可用次数
                $table->integer('draws')->default(0);
                // 累计获得（只增不减，用于对账）
                $table->integer('total_gained')->default(0);
                $table->integer('total_used')->default(0);
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        }

        /* ---- 抽奖奖品配置 ---- */
        if (! Schema::hasTable('lottery_prizes')) {
            Schema::create('lottery_prizes', function (Blueprint $table) {
                $table->increments('id');
                $table->string('label', 80);
                // 积分数（0 = 谢谢参与）
                $table->integer('amount')->default(0);
                // 相对权重：权重总和=总概率。0 表示必不中（如「谢谢参与」给大权重）
                $table->integer('weight')->default(0);
                $table->integer('sort')->default(0);
                $table->boolean('enabled')->default(true);
                $table->timestamps();
                $table->index(['enabled', 'sort']);
            });
        }
    }
}

if (! function_exists('kokuu_coupon_gen_code')) {
    /**
     * 生成一个兑换码。字符集去掉了易混字符（0/O/1/I/L），避免用户抄错。
     *
     * ⚠️ 随机源用 random_int（CSPRNG），不要用 rand/mt_rand：
     *    兑换码是**凭据**，可预测=可被批量枚举白嫖。
     */
    function kokuu_coupon_gen_code(array $cfg): string
    {
        $alphabet = (string) $cfg['code_alphabet'];
        $len = (int) $cfg['code_length'];
        $alphabetLen = strlen($alphabet);

        $body = '';
        for ($i = 0; $i < $len; $i++) {
            $body .= $alphabet[random_int(0, $alphabetLen - 1)];
        }

        return (string) $cfg['code_prefix'] . $body;
    }
}

if (! function_exists('kokuu_coupon_ensure_default_prizes')) {
    /**
     * 首次安装时给一组默认奖品，避免管理员没配奖品时抽奖永远空。
     * 已有奖品则不动（不覆盖管理员配置）。
     */
    function kokuu_coupon_ensure_default_prizes(): void
    {
        kokuu_coupon_ensure_tables();

        if (DB::table('lottery_prizes')->count() > 0) {
            return;
        }

        $now = now();
        $rows = [
            // label, amount, weight  —— 权重 1000 份，总体期望约 100 积分/抽
            ['谢谢参与', 0,   500],
            ['5 积分',5,   250],
            ['10 积分',   10,  150],
            ['20 积分',   20,  70],
            ['50 积分',   50,  25],
            ['100 积分',  100, 5],
        ];

        $i = 0;
        foreach ($rows as $r) {
            $i++;
            DB::table('lottery_prizes')->insert([
                'label'      => $r[0],
                'amount'     => $r[1],
                'weight'     => $r[2],
                'sort'       => $i,
                'enabled'    => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

/* ==================================================================
 *  以下为业务逻辑（生成 / 兑换 / 抽奖）
 * ================================================================== */

if (! function_exists('kokuu_coupon_min_keep')) {
    /**
     * 全站「兑换后必须保留的最低积分余额」。
     *
     * ★ 唯一权威 = kokuu_exchange_min_keep（options 表），
     *   本插件**只读不写**。
     *   理由：兑换是本插件之外第二个会扣 users.score 的功能，
     *   若两边各设各的底线，用户可以在两个插件里各扣一次，
     *   每次都「满足自己那条线」，加起来把余额扣穿。
     *   管理员在 kokuu-exchange 后台改一次，两个插件同时生效。
     *
     * kokuu-exchange 没装/ 没配过→ 回落到 100（与它的默认值一致）。
     */
    function kokuu_coupon_min_keep(): int
    {
        $v = app('options')->get('kokuu_exchange_min_keep');

        if ($v === null || $v === '') {
            return 100;
        }

        return max(0, (int) $v);
    }
}

if (! function_exists('kokuu_coupon_batch_no')) {
    function kokuu_coupon_batch_no(): string
    {
        return date('YmdHis') . '-' . bin2hex(random_bytes(3));
    }
}

if (! function_exists('kokuu_coupon_generate')) {
    /**
     * 批量生成兑换码。
     *
     * @param int    $count  生成数量
     * @param string $type   credit|draw
     * @param int    $value  credit→积分数；draw→抽奖次数
     * @param string $note   备注
     * @param int    $ttlDays 有效天数，0=永久
     * @return array{ok:bool,msg:string,batch:?string,codes:array}
     */
    function kokuu_coupon_generate(int $count, string $type, int $value, string $note, int $ttlDays): array
    {
        kokuu_coupon_ensure_tables();

        if ($count < 1 || $count > 500) {
            return ['ok' => false, 'msg' => '生成数量需在 1 ~ 500 之间', 'batch' => null, 'codes' => []];
        }
        if (! in_array($type, ['credit', 'draw'], true)) {
            return ['ok' => false, 'msg' => '类型只能是 credit 或 draw', 'batch' => null, 'codes' => []];
        }
        if ($value < 1 || $value > 1000000) {
            return ['ok' => false, 'msg' => '面值需在 1 ~ 1000000 之间', 'batch' => null, 'codes' => []];
        }

        $cfg     = kokuu_coupon_config();
        $batch   = kokuu_coupon_batch_no();
        $expires = $ttlDays > 0 ? now()->addDays($ttlDays) : null;
        $now     = now();

        $made    = [];
        $tried   = 0;

        /* 唯一索引是 code，撞了要重试。
         * 上限 40 次：随机码撞已存在的概率极低，真撞满说明
         * 字符集被配得极短（如长度 6 + 字符集 5），此时应报错而非死循环。 */
        while (count($made) < $count && $tried < $count * 40 + 40) {
            $tried++;
            $code = kokuu_coupon_gen_code($cfg);

            if (DB::table('coupon_codes')->where('code', $code)->exists()) {
                continue;
            }

            try {
                DB::table('coupon_codes')->insert([
                    'code'       => $code,
                    'type'       => $type,
                    'value'      => $value,
                    'batch'      => $batch,
                    'note'       => mb_substr($note, 0, 120),
                    'expires_at' => $expires,
                    'used_count' => 0,
                    'disabled'   => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $made[] = $code;
            } catch (\Illuminate\Database\QueryException $e) {
                // 1062/23000 = 撞唯一索引（极小概率的并发撞码）→ 重试
                if ($e->getCode() === '23000' || $e->getCode() === 23000) {
                    continue;
                }
                throw $e;
            }
        }

        if (count($made) < $count) {
            return [
                'ok'    => false,
                'msg'   => '生成失败：兑换码字符集太短导致大量重复，请调大「码长度」',
                'batch' => $batch,
                'codes' => $made,
            ];
        }

        return [
            'ok'    => true,
            'msg'   => '已生成 ' . count($made) . ' 个兑换码',
            'batch' => $batch,
            'codes' => $made,
        ];
    }
}

if (! function_exists('kokuu_coupon_redeem_daily_count')) {
    function kokuu_coupon_redeem_daily_count(int $uid): int
    {
        return (int) DB::table('coupon_redemptions')
            ->where('uid', $uid)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }
}

if (! function_exists('kokuu_coupon_redeem')) {
    /**
     * 用户兑换一个兑换码。
     *
     * ★ 幂等核心在事务内：往 coupon_redemptions 插一行，对 coupon_id 唯一。
     *   撞唯一索引 = 这个码已被兑换过→ 返回「已使用」。
     *   全程**不**用「先查 used_count 再 UPDATE」——两个并发请求
     *   都会通过那个检查，同一码被兑换两次（资损）。
     *
     * @return array{ok:bool,msg:string,type?:string,value?:int,balance?:int}
     */
    function kokuu_coupon_redeem(int $uid, string $code, string $ip = ''): array
    {
        kokuu_coupon_ensure_tables();

        $cfg = kokuu_coupon_config();
        $code = strtoupper(trim($code));

        if ($code === '') {
            return ['ok' => false, 'msg' => '请输入兑换码'];
        }

        // 每日兑换上限（防脚本刷码）
        $limit = (int) $cfg['daily_redeem_limit'];
        if ($limit > 0 && kokuu_coupon_redeem_daily_count($uid) >= $limit) {
            return ['ok' => false, 'msg' => '今日兑换次数已达上限（' . $limit . '次）'];
        }

        $result = DB::transaction(function () use ($uid, $code, $ip, $cfg) {
            // lockForUpdate：并发兑换同一码时串行化，后来的会看到已兑换
            $cp = DB::table('coupon_codes')->where('code', $code)->lockForUpdate()->first();

            if (! $cp) {
                return ['ok' => false, 'msg' => '兑换码不存在'];
            }
            if ($cp->disabled) {
                return ['ok' => false, 'msg' => '该兑换码已被停用'];
            }
            if ($cp->expires_at && strtotime((string) $cp->expires_at) < time()) {
                return ['ok' => false, 'msg' => '该兑换码已过期'];
            }
            $maxUses = max(1, (int) $cfg['max_uses']);
            if ((int) $cp->used_count >= $maxUses) {
                return ['ok' => false, 'msg' => '该兑换码已被使用'];
            }

            // ★ 幂等防线：这一行的 coupon_id 唯一索引才是权威判定
            try {
                DB::table('coupon_redemptions')->insert([
                    'coupon_id'  => (int) $cp->id,
                    'uid'        => $uid,
                    'value'      => (int) $cp->value,
                    'type'       => (string) $cp->type,
                    'ip'         => mb_substr($ip, 0, 45),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '23000' || $e->getCode() === 23000) {
                    return ['ok' => false, 'msg' => '该兑换码已被使用'];
                }
                throw $e;
            }

            $value = (int) $cp->value;
            $type  = (string) $cp->type;

            if ($type === 'draw') {
                // 给抽奖次数
                kokuu_coupon_wallet_add($uid, $value);
                $balance = null;
                $msg = '兑换成功，获得 ' . $value . ' 次抽奖机会';
            } else {
                // 给积分：★ 一律走 kokuu_credit_ledger（有event_id 幂等）
                if (! function_exists('kokuu_credit_ledger')) {
                    return ['ok' => false, 'msg' => 'kokuu-credit 未启用，积分发放不可用'];
                }
                $rid = (int) DB::table('coupon_redemptions')
                    ->where('coupon_id', (int) $cp->id)->value('id');
                kokuu_credit_ledger($uid, $value, 'coupon', 'coupon:' . $rid, $code, '兑换码');
                $balance = (int) DB::table('users')->where('uid', $uid)->value('score');
                $msg = '兑换成功，获得 ' . $value . ' 积分';
            }

            DB::table('coupon_codes')->where('id', (int) $cp->id)->increment('used_count');

            return ['ok' => true, 'msg' => $msg, 'type' => $type, 'value' => $value, 'balance' => $balance];
        });

        return $result;
    }
}

if (! function_exists('kokuu_coupon_wallet_get')) {
    function kokuu_coupon_wallet_get(int $uid): int
    {
        kokuu_coupon_ensure_tables();

        $n = (int) DB::table('lottery_wallets')->where('uid', $uid)->value('draws');

        return max(0, $n);
    }
}

if (! function_exists('kokuu_coupon_wallet_add')) {
    /**
     * 增加抽奖次数。
     * ⚠️ 先 ensure 再 update：直接 update 时若wallet 行还不存在，
     *    会**静默 0 行受影响** → 用户码兑了但次数没到账（无任何报错）。
     */
    function kokuu_coupon_wallet_add(int $uid, int $draws): void
    {
        kokuu_coupon_wallet_ensure($uid);

        DB::table('lottery_wallets')->where('uid', $uid)->update([
            'draws'       => DB::raw('draws + ' . (int) $draws),
            'total_gained'=> DB::raw('total_gained + ' . (int) $draws),
            'updated_at'  => now(),
        ]);
    }
}

if (! function_exists('kokuu_coupon_wallet_ensure')) {
    function kokuu_coupon_wallet_ensure(int $uid): void
    {
        // 幂等插入：撞唯一索引=已存在，忽略即可
        try {
            DB::table('lottery_wallets')->insert([
                'uid'          => $uid,
                'draws'        => 0,
                'total_gained' => 0,
                'total_used'   => 0,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() !== '23000' && $e->getCode() !== 23000) {
                throw $e;
            }
        }
    }
}

if (! function_exists('kokuu_coupon_pick_prize')) {
    /**
     * 按权重抽一个奖品。
     *
     * ⚠️ 随机源用 random_int（CSPRNG）：奖品是**真实积分**，
     *    可预测=可被脚本刷到 100 积分大奖。
     *
     * @param \Illuminate\Support\Collection $prizes 已过滤 enabled 的奖品
     * @return object|null
     */
    function kokuu_coupon_pick_prize($prizes)
    {
        $total = 0;
        foreach ($prizes as $p) {
            $total += max(0, (int) $p->weight);
        }
        if ($total <= 0) {
            return null;
        }

        $roll = random_int(1, $total);
        $acc  = 0;
        foreach ($prizes as $p) {
            $acc += max(0, (int) $p->weight);
            if ($roll <= $acc) {
                return $p;
            }
        }

        return $prizes->last();
    }
}

if (! function_exists('kokuu_coupon_draw_cooldown_left')) {
    function kokuu_coupon_draw_cooldown_left(int $uid): int
    {
        $cd = (int) kokuu_coupon_config()['draw_cooldown'];
        if ($cd <= 0) {
            return 0;
        }

        $last = DB::table('lottery_draws')
            ->where('uid', $uid)
            ->orderByDesc('id')
            ->value('created_at');
        if (! $last) {
            return 0;
        }

        $elapsed = time() - strtotime((string) $last);
        $left    = $cd - $elapsed;

        return $left > 0 ? (int) $left : 0;
    }
}

if (! function_exists('kokuu_coupon_daily_draw_count')) {
    function kokuu_coupon_daily_draw_count(int $uid): int
    {
        return (int) DB::table('lottery_draws')
            ->where('uid', $uid)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }
}

if (! function_exists('kokuu_coupon_draw')) {
    /**
     * 抽奖一次。
     *
     * 两种消耗来源（via）：
     *   cost → 花积分买一次（draw_cost），**这才会扣 users.score**
     *   code → 用兑换码换来的次数，不扣分
     *
     * ★ 扣分的两道闸（与 kokuu-exchange 同款，缺一不可）：
     *   1) 事务外预检→ 给出友好提示（「积分不足」vs「需保留N 积分」要分开报）
     *   2) 事务内 lockForUpdate 后**再查一次**余额
     *      缺第2 道时，两个并发请求都通过第 1 道预检，
     *      然后各自扣一次 → 把底仓扣穿。
     *
     * @return array{ok:bool,msg:string,label?:string,amount?:int,balance?:int,draws?:int}
     */
    function kokuu_coupon_draw(int $uid, string $ip = ''): array
    {
        kokuu_coupon_ensure_tables();
        kokuu_coupon_ensure_default_prizes();

        $cfg  = kokuu_coupon_config();
        $drawsLeft = kokuu_coupon_wallet_get($uid);
        $cost = (int) $cfg['draw_cost'];

        /* ---- 事务外预检：只为了给出友好提示，不做最终判定 ---- */
        $left = kokuu_coupon_draw_cooldown_left($uid);
        if ($left > 0) {
            return ['ok' => false, 'msg' => '冷却中，请 ' . $left . ' 秒后再试'];
        }

        $limit = (int) $cfg['daily_draw_limit'];
        if ($limit > 0 && kokuu_coupon_daily_draw_count($uid) >= $limit) {
            return ['ok' => false, 'msg' => '今日抽奖次数已达上限（' . $limit . '次）'];
        }

        // 优先用「兑换码换来的次数」，没有才考虑花积分
        $via = $drawsLeft > 0 ? 'code' : 'cost';

        if ($via === 'cost') {
            if ($cost <= 0) {
                return ['ok' => false, 'msg' => '当前没有可用的抽奖次数，且未开放积分抽奖'];
            }

            /* 事务外预检（提示用）。余额与底仓的算法必须与事务内**完全一致**，
             * 否则用户会看到「能抽但必失败」。 */
            $balance   = (int) DB::table('users')->where('uid', $uid)->value('score');
            $keep      = kokuu_coupon_min_keep();
            $spendable = $balance - $keep;
            if ($spendable < $cost) {
                return ['ok' => false, 'msg' => $spendable < 0
                    ? '抽奖需保留 ' . $keep . ' 积分，当前余额 ' . $balance . ' 已不足'
                    : '积分不足：本次需 ' . $cost . '，扣除保留的 ' . $keep . ' 后可用 ' . $spendable];
            }
        }

        /* ================= 事务开始 ================= */
        $outcome = DB::transaction(function () use ($uid, $ip, $via, $cost) {
            /* 抽奖次数账户：lockForUpdate 让并发抽奖串行化，
             * 否则两个请求都读到 draws=1，各自抽一次 → 一次机会抽了两次。 */
            kokuu_coupon_wallet_ensure($uid);
            $wallet = DB::table('lottery_wallets')->where('uid', $uid)->lockForUpdate()->first();

            $curDraws = (int) $wallet->draws;
            $realVia   = $curDraws > 0 ? 'code' : 'cost';

            if ($realVia === 'code') {
                DB::table('lottery_wallets')->where('id', (int) $wallet->id)->update([
                    'draws'       => $curDraws - 1,
                    'total_used'  => DB::raw('total_used + 1'),
                    'updated_at'  => now(),
                ]);
            } else {
                /* ★ 事务内第二道闸：lockForUpdate 后再查一次余额与底仓。
                 * 第 1 道的预检结果**不能**直接信 —— 两次请求之间
                 * 余额可能已被另一个请求扣走。 */
                $keep = kokuu_coupon_min_keep();
                $lock = DB::table('users')->where('uid', $uid)->lockForUpdate()->first();
                $balance = (int) ($lock->score ?? 0);
                if (($balance - $keep) < $cost) {
                    return ['ok' => false, 'msg' => ($balance - $keep) < 0
                        ? '抽奖需保留 ' . $keep . ' 积分，当前余额 ' . $balance . ' 已不足'
                        : '积分不足：本次需 ' . $cost . '，扣除保留的 ' . $keep . ' 后可用 ' . ($balance - $keep)];
                }
                DB::table('users')->where('uid', $uid)->decrement('score', $cost);
            }

            // 奖品在事务内读，避免「已扣的次数」对不上配置
            $prizes = DB::table('lottery_prizes')
                ->where('enabled', true)
                ->orderBy('sort')
                ->orderBy('id')
                ->get();
            $prize = kokuu_coupon_pick_prize($prizes);
            $label = $prize ? (string) $prize->label : '谢谢参与';
            $amount = $prize ? (int) $prize->amount : 0;

            DB::table('lottery_draws')->insert([
                'uid'         => $uid,
                'prize_id'    => $prize ? (int) $prize->id : null,
                'prize_label' => mb_substr($label, 0, 80),
                'amount'      => $amount,
                'via'         => $realVia,
                'ip'          => mb_substr($ip, 0, 45),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $drawId = (int) DB::table('lottery_draws')->orderByDesc('id')->value('id');

            return [
                'ok'     => true,
                'label'  => $label,
                'amount' => $amount,
                'via'    => $realVia,
                'drawId' => $drawId,
            ];
        });

        if (! $outcome['ok']) {
            return $outcome;
        }

        /* ---- 中奖加分：★ 走 kokuu_credit_ledger，event_id=draw:<id> 幂等 ----
         * 放事务外：kokuu_credit_ledger 内部自带事务，
         * 嵌在抽奖事务里会变成 savepoint，行为虽对但多一层无谓嵌套。 */
        if ((int) $outcome['amount'] > 0 && function_exists('kokuu_credit_ledger')) {
            kokuu_credit_ledger(
                $uid,
                (int) $outcome['amount'],
                'lottery',
                'draw:' . $outcome['drawId'],
                null,
                '抽奖：' . $outcome['label']
            );
        }

        $balance = (int) DB::table('users')->where('uid', $uid)->value('score');

        return [
            'ok'      => true,
            /* 成功路径也带 msg：与失败路径结构一致，前端与守卫都能无脑读。
             * 缺这个键会让调用方出现 Undefined array key "msg"（实测踩过）。 */
            'msg'     => '抽到 ' . $outcome['label'],
            'label'   => $outcome['label'],
            'amount'  => (int) $outcome['amount'],
            'via'     => $outcome['via'],
            'balance' => $balance,
            'draws'   => kokuu_coupon_wallet_get($uid),
        ];
    }
}

if (! function_exists('kokuu_coupon_odds_table')) {
    /**
     * 奖品概率表（给用户看的展示数据）。
     *
     * ★ 全部算好再传模板：Twig 沙箱没有 max/min 过滤器，
     *   概率百分比、累计区间都是业务算术，不能放视图里算。
     *
     * @return array{rows:array,totalWeight:int,expect:float}
     */
    function kokuu_coupon_odds_table(): array
    {
        $prizes = DB::table('lottery_prizes')
            ->where('enabled', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $total = 0;
        foreach ($prizes as $p) {
            $total += max(0, (int) $p->weight);
        }

        $rows = [];
        $acc  = 0;
        $sum  = 0;
        $weighted = 0;   /* Σ(amount × weight)，用于期望值 */
        foreach ($prizes as $p) {
            $w = max(0, (int) $p->weight);
            $pct = $total > 0 ? ($w * 100 / $total) : 0.0;
            $sum += (int) $p->amount;
            $weighted += (int) $p->amount * $w;
            $rows[] = [
                'label'   => (string) $p->label,
                'amount'  => (int) $p->amount,
                'weight'  => $w,
                'percent' => round($pct, 2),
                'from'    => $total > 0 ? round($acc * 100 / $total, 2) : 0.0,
                'to'      => $total > 0 ? round(($acc + $w) * 100 / $total, 2) : 0.0,
            ];
            $acc += $w;
        }

        return [
            'rows'        => $rows,
            'totalWeight' => $total,
            /* 🔴 期望值 = Σ(amount × weight) / Σweight（**权重加权**）。
             *
             * ❌ 别写成 `$sum / count($rows)` —— 那是「所有奖品金额的算术平均」，
             *    把权重信息整个丢掉了。实测默认奖品表下：
             *      算术平均 = (0+5+10+20+50+100)/6      = 30.83
             *      加权期望 = (0×500+5×250+…+100×5)/1000 = 5.90
             *    虚高 5.23 倍，页面上「单抽期望」完全是错的。
             *    这个 bug 能溜过去，是因为守卫只验了「概率合计≈100%」
             *    （**自洽性**）而没验「期望值正确」（**业务正确性**）。
             *
             *    教训：守卫不能只验自洽，必须验业务口径。
             */
            'expect'      => $total > 0 ? round($weighted / $total, 2) : 0.0,
        ];
    }
}

if (! function_exists('kokuu_coupon_stats')) {
    function kokuu_coupon_stats(): array
    {
        return [
            'codes'        => (int) DB::table('coupon_codes')->count(),
            'codes_used'   => (int) DB::table('coupon_codes')->where('used_count', '>', 0)->count(),
            'redemptions'  => (int) DB::table('coupon_redemptions')->count(),
            'credit_given' => (int) DB::table('coupon_redemptions')->where('type', 'credit')->sum('value'),
            'draws_given'  => (int) DB::table('coupon_redemptions')->where('type', 'draw')->sum('value'),
            'draws_used'   => (int) DB::table('lottery_draws')->count(),
            'draw_amount'  => (int) DB::table('lottery_draws')->sum('amount'),
        ];
    }
}

if (! function_exists('kokuu_coupon_draw_widget_data')) {
    /**
     * 用户中心「幸运抽奖」widget 的数据。
     *
     * ⚠️ 全部派生量在这里算好，模板只负责显示：
     *    Twig 沙箱**没有 max/min 过滤器**（|max 直接整页 500），
     *    而且同一份算术散在 PHP/JS/Twig 三处迟早漂移。
     *
     * @param mixed $user  当前登录用户（App\Models\User）
     */
    function kokuu_coupon_draw_widget_data($user): array
    {
        if (! $user) {
            return ['canDraw' => false];
        }

        kokuu_coupon_ensure_tables();
        kokuu_coupon_ensure_default_prizes();

        $uid     = (int) $user->uid;
        $balance = (int) DB::table('users')->where('uid', $uid)->value('score');
        $cfg     = kokuu_coupon_config();
        $draws   = kokuu_coupon_wallet_get($uid);
        $cost    = (int) $cfg['draw_cost'];
        $keep    = kokuu_coupon_min_keep();

        /* 与 /plugin/kokuu-coupon 页面**同一套判定**：
         * 有次数就能抽；没次数时看「余额 - 底仓」够不够消耗。
         * 两处各写一遍必然漂移 → 抽成同一个函数。 */
        $spendable = max(0, $balance - $keep);
        $canByCost = $cost > 0 && $spendable >= $cost;

        $odds = kokuu_coupon_odds_table();

        /* ⚠️ odds['rows'] 的元素是**关联数组**（不是对象），
         *   且 percent 已在 kokuu_coupon_odds_table 里算好并 round 到 2 位。
         *   写 `$r->label` 会报 Attempt to read property "label" on array。
         *   这里只做字段挑选，不要重复做除法 —— 权威口径只有一处。 */
        $rows = [];
        foreach ($odds['rows'] as $r) {
            $rows[] = [
                'label'  => (string) $r['label'],
                'amount' => (int) $r['amount'],
                'pct'    => (float) $r['percent'],
            ];
        }

        return [
            'drawUrl'   => url('/plugin/kokuu-coupon/draw'),
            'draws'     => $draws,
            'balance'   => $balance,
            'spendable' => $spendable,
            'keep'      => $keep,
            'cost'      => $cost,
            'canByCost' => $canByCost,
            'canDraw'   => $draws > 0 || $canByCost,
            'cooldown'  => kokuu_coupon_draw_cooldown_left($uid),
            'notice'    => (string) ($cfg['notice'] ?? ''),
            'expect'    => $odds['expect'],
            'odds'      => $rows,
            'recent'    => DB::table('lottery_draws')
                ->where('uid', $uid)
                ->orderByDesc('id')
                ->limit(5)
                ->get(),
        ];
    }
}

return function ($plugin) {
    View::addLocation($plugin->getPath() . '/views');

    /* ---- 侧边栏：用户区「兑换码」，管理区「兑换码管理」 ----
     * ⚠️ 插件自己追加的 title 才是中文；内核项是 lang key（general.xxx）。
     *    写错会导致 kokuu-ui 的 side_menu 过滤器按 lang key 匹配时对不上。 */
    /* ---- 侧边栏：「积分中心」可折叠二级菜单 ----
     *
     * 用户原话：「可以在侧栏做一个可以折叠的二级菜单，
     *            把兑换码，抽奖，还有积分兑换折叠起来」。
     *
     * ✅ 用**内核原生**的 `children` 机制，不自己造折叠：
     *   `resources/views/shared/side-menu-item.twig` 的 children 分支渲染成
     *   `<ul class="nav nav-treeview">` + 父项 `fa-angle-left` 箭头 ——
     *   这就是 AdminLTE 的 treeview，**点父项自动展开/收起**，
     *   AdminLTE 的 sidebar.js 已绑定，零额外 JS。
     *   自己写折叠要额外处理 active 态、移动端、持久化，纯属重复劳动。
     *
     * ⚠️ 分组项的 `link` 不会被用到（模板里父项是 `href="#"`），
     *   但**仍要写** —— `transform()` 会给每个 item 算 active，
     *   缺 link 的项可能在 active 判断里报错。
     *
     * 📍 跨插件的分组：父项由**本插件**注册，kokuu-exchange（积分兑换）
     *    通过 `side_menu` 过滤器把自己**挪进**本父项的 children ——
     *    而不是让 exchange 也去注册一个同名父项（那样会出现两个「积分中心」）。
     *    这样 exchange 不需要知道分组的存在，各自只管自己的链接。
     */
    \Illuminate\Support\Facades\Event::listen(\App\Events\ConfigureUserMenu::class, function ($event) {
        $event->menu['user'][] = [
            'title' => '积分中心',
            'link'  => 'plugin/kokuu-coupon',
            'icon'  => 'fa-coins',
            'children' => [
                [
                    'title' => '兑换码',
                    'link'  => 'plugin/kokuu-coupon',
                    'icon'  => 'fa-ticket',
                ],
                [
                    'title' => '幸运抽奖',
                    'link'  => 'plugin/kokuu-coupon/draw',
                    'icon'  => 'fa-dice',
                ],
                [
                    /* 占位：kokuu-exchange 的「积分兑换」由它自己用 side_menu
                     * 过滤器插到这个位置（见下方）。这里先放一个标记项，
                     * 交换插件会把自己替换进来；它没装时这个项自动消失。 */
                    'title' => '__KOKUU_EXCHANGE_SLOT__',
                    'link'  => 'plugin/kokuu-exchange',
                    'icon'  => 'fa-exchange-alt',
                ],
            ],
        ];
    });

    /*
     * 把占位项换成真正的「积分兑换」子项。
     *
     * 为什么用 `side_menu` 过滤器而不是直接在 ConfigureUserMenu 里加：
     *   过滤器拿到的是**最终**菜单数组，能改已存在的结构；
     *   ConfigureUserMenu 阶段三个插件的监听器顺序不保证，
     *   谁先谁后不可控 → 会出现「分组在最后才建、插不进去」。
     *
     * ⚠️ side_menu 的回调签名是 ($menu, $type)：
     *   `SideMenuComposer::compose()` 里是
     *   `$filter->apply('side_menu', $menu, [$type])`
     *   —— 第二个参数是菜单类型（'user'/'admin'/'explore'），不是数组。
     */
    app(\Blessing\Filter::class)->add('side_menu', function ($menu, $type) {
        if ($type !== 'user' || ! is_array($menu)) {
            return $menu;
        }

        $exchangeLink = 'plugin/kokuu-exchange';

        foreach ($menu as $i => $item) {
            if (! is_array($item) || empty($item['children'])) {
                continue;
            }
            foreach ($item['children'] as $j => $child) {
                if (is_array($child) && ($child['title'] ?? '') === '__KOKUU_EXCHANGE_SLOT__') {
                    /*
                     * ⚠️ 只有 kokuu-exchange **已启用**时才插这一项。
                     *   插件没装/没启用时那个链接点过去就是 404 ——
                     *   侧栏里挂死链比不挂更糟（用户会以为站点坏了）。
                     *
                     * 🔴 判据必须是 `PluginManager::getEnabledPlugins()`
                     *   —— 这是内核 SideMenuComposer:82 自己用的写法。
                     *
                     *   踩过的两个坑（都不是猜的，是实测）：
                     *   1. **没有 `Blessing\Plugin` 这个类**。
                     *      真实类是 `App\Services\Plugin`，而 `Blessing\` 根本不在
                     *      composer.json 的 psr-4 里 → `Class not found`。
                     *   2. 即便用对类名也错：`Plugin::__construct(string $path, array $manifest)`
                     *      要**两个参数**（路径 + manifest），不是传插件名。
                     *      `isEnabled()` 只读对象自己的 `$enabled` 属性（默认 false），
                     *      拿插件名 new 出来必然是 false → 子项永远不出现。
                     *   两次错误叠在一起还被 `catch (\Throwable)` 吞掉，
                     *   表现就是「代码看着对、子项就是不出来、零报错」。
                     */
                    $exchangeOn = false;
                    try {
                        $exchangeOn = resolve(\App\Services\PluginManager::class)
                            ->getEnabledPlugins()
                            ->contains(fn ($p) => $p->name === 'kokuu-exchange');
                    } catch (\Throwable $e) {
                        $exchangeOn = false;
                    }

                    if (! $exchangeOn) {
                        unset($menu[$i]['children'][$j]);
                        $menu[$i]['children'] = array_values($menu[$i]['children']);
                        /* 子项被清空时父项也没意义了，整组摘掉 */
                        if (! $menu[$i]['children']) {
                            unset($menu[$i]);
                            $menu = array_values($menu);
                        }
                        return $menu;
                    }

                    $menu[$i]['children'][$j] = [
                        'title' => '积分兑换',
                        'link'  => $exchangeLink,
                        'icon'  => 'fa-exchange-alt',
                    ];
                    $menu[$i]['children'] = array_values($menu[$i]['children']);
                    return $menu;
                }
            }
        }

        return $menu;
    });
    \Illuminate\Support\Facades\Event::listen(\App\Events\ConfigureAdminMenu::class, function ($event) {
        $event->menu['admin'][] = [
            'title' => '兑换码管理',
            'link'  => 'plugin/kokuu-coupon/manage',
            'icon'  => 'fa-ticket',
        ];
    });

    /* ---- 抽奖数据源（给独立页用） ----
     *
     * 📍 这个函数原本喂的是 /user 仪表盘的 widget，现在只喂独立页
     *   plugin/kokuu-coupon/draw。
     *
     * 函数名里的 "widget" 是历史遗留（v1.1 挂在仪表盘时起的），
     * 改名会波及所有调用点，而它现在只服务一个页面 —— 保持原名，
     * 在注释里说明清楚，别让下一个人以为是给仪表盘用的。
     */
    Hook::addRoute(function ($router) use ($plugin) {
        /* ================= 用户：兑换 + 抽奖 ================= */
        // ⚠️ 内层闭包必须**再写一次** use ($plugin)：
        //    外层 use 不会继承到内层，漏写时路由注册正常、访问才 500。
        $router->get('plugin/kokuu-coupon', function (Request $request) use ($plugin) {
            $user = $request->user();
            abort_if (! $user, 403);

            kokuu_coupon_ensure_tables();
            kokuu_coupon_ensure_default_prizes();

            $uid     = (int) $user->uid;
            $balance = (int) DB::table('users')->where('uid', $uid)->value('score');
            /* ⚠️ cfg 仍要取：index.twig 用它渲染兑换码规则说明
             *   （可兑换类型 / 单个兑换码能换多少积分 / 抽奖次数面额）。
             *   抽奖的派生量不取，但**基础配置**不能省。
             *   （精简本控制器时误删了这一行 → 500 Undefined variable $cfg） */
            $cfg = kokuu_coupon_config();

            /*
             * ⚠️ 这个控制器**只服务兑换码**（用户原话：「普通用户的兑换码页面
             *   里面不需要一套抽奖了，只需要有个输入兑换码的功能和兑换记录」）。
             *
             *   所以这里**不再算**抽奖的任何派生量（消耗/次数/冷却/概率/期望）——
             *   它们都归抽奖独立页。留着不删的坏处是：改抽奖规则时这个页面
             *   也会跟着算一遍，然后没人用它，纯误导。
             *
             *   kokuu_coupon_ensure_default_prizes() 仍要调：
             *   兑换码可能兑换成「抽奖次数」（type='draw'），
             *   奖品表没初始化的话那条路会缺配置。
             */
            return view()->file($plugin->getPath() . '/views/user/coupon/index.twig', [
                'cfg'       => $cfg,
                'balance'   => $balance,
                /* myCodes 里的 type='draw' 行要留着 ——
                   那是「用兑换码换到抽奖次数」的兑换结果，不是抽奖记录。 */
                'myCodes'   => DB::table('coupon_redemptions')
                    ->where('uid', $uid)
                    ->orderByDesc('id')
                    ->limit(15)
                    ->get(),
            ]);
        })->middleware(['web']);

        /* ---- 抽奖页（独立页，从侧栏「积分中心」分组进入） ----
         *
         * 📍 这个功能搬过两次家（兑换码页 → 仪表盘 widget → 独立页），
         *   用户反馈「位置不好，还是独立成一个页面放在侧栏吧」。
         *
         * ⚠️ 视图名用 **manifest 的 namespace 原值（双反斜杠）**：
         *    'KokuuStudio\\KokuuCoupon::user.coupon.draw'
         *    写短名会 500 且**不提示**正确名字。
         *
         * ⚠️ 资源用模板自己的 before_foot 块 link/script，**不用**
         *    Hook::addStyleFileToPage —— 本页是 view()->file() 渲染的独立模板，
         *    钩子对配置类页面无效（只写进内核 Twig 布局）。
         *    （其实这里 view()->file() 渲染的模板仍 extends user.base.twig，
         *      钩子其实有效；但模板自带更可靠，不依赖钩子被触发。）
         */
        $router->get('plugin/kokuu-coupon/draw', function (Request $request) use ($plugin) {
            $user = $request->user();
            abort_if (! $user, 403);

            $data = kokuu_coupon_draw_widget_data($user);
            $uid  = (int) $user->uid;

            return view()->file($plugin->getPath() . '/views/user/coupon/draw.twig', [
                'cfg'     => kokuu_coupon_config(),
                /* 抽奖的派生量全在这里（次数/可用积分/消耗/冷却/概率/期望），
                 * 模板只负责显示 —— Twig 沙箱没有 max/min 过滤器，
                 * 「能不能抽」这类算术放模板里一律 500。 */
                'draw'    => $data,
                'myDraws' => DB::table('lottery_draws')
                    ->where('uid', $uid)
                    ->orderByDesc('id')
                    ->limit(20)
                    ->get(),
            ]);
        })->middleware(['web']);

        /* ---- 兑换接口 ---- */
        $router->post('plugin/kokuu-coupon/redeem', function (Request $request) {
            $user = $request->user();
            abort_if (! $user, 403);

            $r = kokuu_coupon_redeem(
                (int) $user->uid,
                (string) $request->input('code', ''),
                (string) $request->ip()
            );

            // ★ 业务失败一律 422（与 kokuu-exchange 一致）。
            //   注意语义：422 = 已穿过 CSRF、进入业务；419 = CSRF 层拦截。
            return response()->json([
                'code'    => $r['ok'] ? 0 : 1,
                'message' => $r['msg'],
                'type'    => $r['type']    ?? null,
                'value'   => $r['value']   ?? null,
                'balance' => $r['balance'] ?? null,
            ], $r['ok'] ? 200 : 422);
        })->middleware(['web']);

        /* ---- 抽奖接口 ---- */
        $router->post('plugin/kokuu-coupon/draw', function (Request $request) {
            $user = $request->user();
            abort_if (! $user, 403);

            $r = kokuu_coupon_draw((int) $user->uid, (string) $request->ip());

            return response()->json([
                'code'      => $r['ok'] ? 0 : 1,
                'message'   => $r['msg'],
                'label'     => $r['label']   ?? null,
                'amount'    => $r['amount']  ?? 0,
                'balance'   => $r['balance'] ?? null,
                'draws'     => $r['draws']   ?? null,
                /* 🔴 必须回传**可用积分**（余额 - 底仓），不能只回传 balance。
                 *   卡片上第二个格子标的是「可用积分」，前端拿 balance 去填它
                 *   会显示成总余额 —— 数字大得离谱，看着像抽奖没扣钱。
                 *   前端不该自己减（底仓只有服务端知道）。 */
                'spendable' => max(0, (int) ($r['balance'] ?? 0) - kokuu_coupon_min_keep()),
                /* 🔴 必须回传**真实剩余冷却**，前端不能自己猜：
                 *   猜错（比如硬编码 3）会让按钮提前可点，连点就绕过冷却。
                 *   抽奖成功后冷却才刚开始，所以这里查的是「刚刚这次」之后的余量。 */
                'cooldown'  => kokuu_coupon_draw_cooldown_left((int) $user->uid),
            ], $r['ok'] ? 200 : 422);
        })->middleware(['web']);

        /* ================= 管理：批量生成 / 记录 / 奖品 ================= */
        $router->get('plugin/kokuu-coupon/manage', function (Request $request) use ($plugin) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            kokuu_coupon_ensure_tables();
            kokuu_coupon_ensure_default_prizes();

            $batch = (string) $request->query('batch', '');

            return view()->file($plugin->getPath() . '/views/admin/manage.twig', [
                'cfg'     => kokuu_coupon_config(),
                'stats'   => kokuu_coupon_stats(),
                'odds'    => kokuu_coupon_odds_table(),
                // ⚠️ 传进模板而不是在模板里调 kokuu_coupon_min_keep()：
                //    插件自定义函数在 Twig 沙箱里**一律不可调用**。
                'minKeep' => kokuu_coupon_min_keep(),
                'prizes'  => DB::table('lottery_prizes')->orderBy('sort')->orderBy('id')->get(),
                'batch'  => $batch,
                'codes'  => $batch === '' ? collect() : DB::table('coupon_codes')
                    ->where('batch', $batch)
                    ->orderBy('id')
                    ->get(),
                'recent' => DB::table('coupon_redemptions')->orderByDesc('id')->limit(30)->get(),
                'draws'  => DB::table('lottery_draws')->orderByDesc('id')->limit(30)->get(),
            ]);
        })->middleware(['web']);

        /* ---- 批量生成接口 ---- */
        $router->post('plugin/kokuu-coupon/generate', function (Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            $r = kokuu_coupon_generate(
                (int) $request->input('count', 0),
                (string) $request->input('type', 'credit'),
                (int) $request->input('value', 0),
                (string) $request->input('note', ''),
                (int) $request->input('ttl_days', 0)
            );

            return response()->json([
                'code'    => $r['ok'] ? 0 : 1,
                'message' => $r['msg'],
                'batch'   => $r['batch'],
                'codes'   => $r['codes'],
            ], $r['ok'] ? 200 : 422);
        })->middleware(['web']);

        /* ---- 奖品增删改 ---- */
        $router->post('plugin/kokuu-coupon/prize', function (Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            kokuu_coupon_ensure_tables();

            $id = (int) $request->input('id', 0);
            $label= mb_substr(trim((string) $request->input('label', '')), 0, 80);
            $amount = (int) $request->input('amount', 0);
            $weight = (int) $request->input('weight', 0);

            if ($label === '' || $amount < 0 || $weight < 0) {
                return response()->json(['code' => 1, 'message' => '参数不合法'], 422);
            }

            $row = [
                'label'      => $label,
                'amount'     => $amount,
                'weight'     => $weight,
                'sort'       => (int) $request->input('sort', 0),
                'enabled'    => $request->input('enabled') ? 1 : 0,
                'updated_at' => now(),
            ];

            if ($id > 0) {
                DB::table('lottery_prizes')->where('id', $id)->update($row);
            } else {
                $row['created_at'] = now();
                DB::table('lottery_prizes')->insert($row);
            }

            return response()->json(['code' => 0, 'message' => '已保存', 'id' => $id]);
        })->middleware(['web']);

        /* ---- 停用 / 启用某个兑换码 ---- */
        /* ---- 抽奖设置保存（消耗、冷却、每日上限、公告）----
         *
         * ⚠️ 为什么单独一个路由而不是复用 prize：
         *   prize 是「奖品表的一行」，这里是「全局设置」，粒度不同。
         *
         * ⚠️ Option::set() 只写 DB，**不刷** storage/options.php。
         *   所以保存后必须跑 `sudo -u www-data php artisan options:cache`，
         *   否则下次有人跑缓存重建时这些值才生效 —— 表现为「保存了但没生效，
         *   过一会儿自己又变回去」。这里在响应里明确回读一次当前值，
         *   让管理员看到「已写入 DB」这个事实（缓存未刷是部署侧的事）。
         */
        $router->post('plugin/kokuu-coupon/config', function (Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            $payload = [];

            /* ---- 数值项：逐项校验，不接受负数/离谱值 ----
             * 🔴 每一项都要**独立** clamp 并回显，
             *   管理员填错一个值不该让整批保存失败、也不该被静默改掉。 */
            $ints = [
                'draw_cost'        => [0, 1000000, '每次消耗积分'],
                'draw_cooldown'    => [0, 86400, '抽奖冷却（秒）'],
                'daily_draw_limit' => [0, 100000, '每日抽奖上限'],
                'code_length'      => [6, 32, '兑换码长度'],
                'default_amount'   => [0, 1000000, '默认积分面额'],
                'default_draws'    => [0, 10000, '默认抽奖次数'],
                'code_ttl_days'    => [0, 36500, '兑换码有效期（天）'],
                'daily_redeem_limit' => [0, 100000, '每日兑换上限'],
                'max_uses'         => [1, 1000, '每码可用次数'],
            ];
            $errors = [];
            foreach ($ints as $key => [$min, $max, $label]) {
                if (! $request->has($key)) {
                    continue;
                }
                $raw = $request->input($key);
                if (! is_numeric($raw)) {
                    $errors[] = $label . '必须是数字';
                    continue;
                }
                $v = (int) $raw;
                if ($v < $min || $v > $max) {
                    /* ⚠️ 不静默 clamp：越界就报错，让管理员知道自己填错了。
                     *   静默改成边界值 = 「我填 999999 结果保存成 1000000」，
                     *   下次再翻记录会怀疑人生。 */
                    $errors[] = $label . '必须在 ' . $min . ' ~ ' . $max . ' 之间（收到 ' . $v . '）';
                    continue;
                }
                $payload[$key] = $v;
            }

            /* ---- 字符串项 ---- */
            foreach (['code_alphabet', 'code_prefix', 'notice'] as $key) {
                if (! $request->has($key)) {
                    continue;
                }
                $v = (string) $request->input($key);
                /* mb_substr 防超长：notice 太长会把 widget 顶变形，
                   code_alphabet 太长会让生成的码没法输。 */
                $limit = $key === 'notice' ? 200 : ($key === 'code_alphabet' ? 64 : 16);
                $payload[$key] = mb_substr(trim($v), 0, $limit);
            }

            if ($errors) {
                // ★ 422 = 业务失败 = 已穿过 CSRF（与全项目口径一致）
                return response()->json([
                    'code' => 1, 'message' => implode('；', $errors), 'errors' => $errors,
                ], 422);
            }
            if (! $payload) {
                return response()->json(['code' => 1, 'message' => '没有可保存的项'], 422);
            }

            kokuu_coupon_save_config($payload);

            /* 回读并回显：让管理员看到实际落库的值，而不是他填的字符串。
               若某个键做了类型转换（'007' -> 7），这里能立刻看出来。 */
            $now = kokuu_coupon_config();
            $echo = [];
            foreach (array_keys($payload) as $k) {
                $echo[$k] = $now[$k] ?? null;
            }

            return response()->json([
                'code'    => 0,
                'message' => '已保存 ' . count($payload) . ' 项',
                'saved'   => $echo,
                /* 提醒：options 文件缓存未刷新，下次重建缓存才生效 */
                'hint'    => '已写入数据库；如需立即对全站生效，请执行 php artisan options:cache',
            ]);
        })->middleware(['web']);

        $router->post('plugin/kokuu-coupon/toggle', function (Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            kokuu_coupon_ensure_tables();

            $id = (int) $request->input('id', 0);
            if ($id <= 0) {
                return response()->json(['code' => 1, 'message' => '参数不合法'], 422);
            }

            $cp = DB::table('coupon_codes')->where('id', $id)->first();
            if (! $cp) {
                return response()->json(['code' => 1, 'message' => '兑换码不存在'], 422);
            }

            DB::table('coupon_codes')->where('id', $id)->update([
                'disabled'   => $cp->disabled ? 0 : 1,
                'updated_at' => now(),
            ]);

            return response()->json([
                'code'    => 0,
                'message' => $cp->disabled ? '已启用' : '已停用',
            ]);
        })->middleware(['web']);

        /* ---- 自检接口 ---- */
        $router->get('plugin/kokuu-coupon/health', function (Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            kokuu_coupon_ensure_tables();

            return response()->json([
                'code' => 0,
                'data' => [
                    'tables' => [
                        'coupon_codes'       => Schema::hasTable('coupon_codes'),
                        'coupon_redemptions' => Schema::hasTable('coupon_redemptions'),
                        'lottery_draws'      => Schema::hasTable('lottery_draws'),
                        'lottery_wallets'    => Schema::hasTable('lottery_wallets'),
                        'lottery_prizes'     => Schema::hasTable('lottery_prizes'),
                    ],
                    'credit'  => function_exists('kokuu_credit_ledger') ? 'loaded' : 'MISSING',
                    'minKeep' => kokuu_coupon_min_keep(),
                    'minKeepFrom' => 'kokuu_exchange_min_keep（与积分兑换共用）',
                    'config'  => kokuu_coupon_config(),
                    'stats'   => kokuu_coupon_stats(),
                    'odds'    => kokuu_coupon_odds_table(),
                ],
            ]);
        })->middleware(['web']);

        /* ⚠️⚠️ 下面这段必须**在 addRoute 闭包内**（没有 `});` 收尾）。
         *   我第一版在这里多写了一个 `});`，把 addRoute 提前关掉了 →
         *   新代码落在闭包外 → `Undefined variable $router`
         *   → 插件的**整个页面 500**（不只是这个端点坏）。
         *   症状：所有 JS 变量（CPN_ADMIN/CPN_CSRF/CPN_MIN_KEEP）全 undefined，
         *   守卫报一堆 FAIL，看起来像「模板注入全失效」，
         *   实际是 PHP 致命错误。→ 先看 laravel-YYYY-MM-DD.log。
         *
         * ---- 守卫专用：清理测试数据 ----
     *
     * 存在的理由：CDP 守卫会**真的**生成兑换码（不生成的话
     * 「生成按钮能不能用」根本没被验证过）。留着不管，
     * 管理页「最近兑换」会堆满测试码。
     *
     * 🔴 安全设计（这是个删数据的端点，必须收紧）：
     *  1. 仅 ADMIN 可用；
     *  2. **只删 note 以指定前缀开头的码** —— 永远不会误伤真实数据；
     *     前缀由调用方传入，但强制 4-32 位且只允许 [A-Za-z0-9_-]，
     *     防止传空串/通配把整表删光。
     *  3. 走 `web` 中间件 → **必须带 CSRF**，防外站 CSRF 打这个端点。
     *  4. 连带删除 credit_ledger 里对应流水（event_id = coupon:<rid>），
     *     否则积分账本会留下指向已删码的孤儿流水。
     *  5. ?check=1 只查不删，供守卫做「复查归零」的断言。
     */
    $router->get('plugin/kokuu-coupon/purge-testdata', function (Request $request) {
        $user = $request->user();
        abort_if(! $user || $user->permission < User::ADMIN, 403);

        $prefix = (string) $request->query('note', 'CDPGuard');
        abort_if(! preg_match('/^[A-Za-z0-9_-]{4,32}$/', $prefix), 422, '前缀不合法');

        kokuu_coupon_ensure_tables();

        $ids = DB::table('coupon_codes')
            ->where('note', 'like', $prefix . '%')
            ->pluck('id')->all();

        /* 只查不删（守卫的「复查」用） */
        if ($request->query('check')) {
            return response()->json(['ok' => true, 'left' => count($ids)]);
        }

        if (! $ids) {
            return response()->json(['ok' => true, 'left' => 0, 'msg' => '没有匹配的测试数据']);
        }

        $rids = DB::table('coupon_redemptions')
            ->whereIn('coupon_id', $ids)->pluck('id')->all();

        if ($rids) {
            DB::table('credit_ledger')
                ->whereIn('event_id', array_map(fn ($i) => 'coupon:' . $i, $rids))
                ->delete();
        }

        DB::table('coupon_redemptions')->whereIn('coupon_id', $ids)->delete();
        $n = DB::table('coupon_codes')->whereIn('id', $ids)->delete();

        return response()->json([
            'ok'   => true,
            'left' => 0,
            'msg'  => '已清理 ' . $n . ' 个测试兑换码',
        ]);
    })->middleware(['web']);
    });   // ← 收 Hook::addRoute 闭包
};
