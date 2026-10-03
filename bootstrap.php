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
        foreach ($prizes as $p) {
            $w = max(0, (int) $p->weight);
            $pct = $total > 0 ? ($w * 100 / $total) : 0.0;
            $sum += (int) $p->amount;
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
            // 期望值：单次抽奖的平均到手积分（权重加权）
            'expect'      => $total > 0 ? round($sum / max(1, count($rows)), 2) : 0.0,
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

return function ($plugin) {
    View::addLocation($plugin->getPath() . '/views');

    /* ---- 侧边栏：用户区「兑换码」，管理区「兑换码管理」 ----
     * ⚠️ 插件自己追加的 title 才是中文；内核项是 lang key（general.xxx）。
     *    写错会导致 kokuu-ui 的 side_menu 过滤器按 lang key 匹配时对不上。 */
    \Illuminate\Support\Facades\Event::listen(\App\Events\ConfigureUserMenu::class, function ($event) {
        $event->menu['user'][] = [
            'title' => '兑换码',
            'link'  => 'plugin/kokuu-coupon',
            'icon'  => 'fa-ticket',
        ];
    });
    \Illuminate\Support\Facades\Event::listen(\App\Events\ConfigureAdminMenu::class, function ($event) {
        $event->menu['admin'][] = [
            'title' => '兑换码管理',
            'link'  => 'plugin/kokuu-coupon/manage',
            'icon'  => 'fa-ticket',
        ];
    });

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
            $cfg     = kokuu_coupon_config();
            $draws   = kokuu_coupon_wallet_get($uid);
            $cost    = (int) $cfg['draw_cost'];
            $keep    = kokuu_coupon_min_keep();

            /* ★ 派生量全在 PHP 侧算好：Twig 沙箱没有 max/min 过滤器。
             *   「能不能抽」这个判断前端也要用，三处各算一次迟早漂移。 */
            $spendable = max(0, $balance - $keep);
            $canByCost = $cost > 0 && $spendable >= $cost;
            $canDraw   = $draws > 0 || $canByCost;

            return view()->file($plugin->getPath() . '/views/user/coupon/index.twig', [
                'cfg'       => $cfg,
                'balance'   => $balance,
                'keep'      => $keep,
                'spendable' => $spendable,
                'draws'     => $draws,
                'drawCost'  => $cost,
                'canByCost' => $canByCost,
                'canDraw'   => $canDraw,
                'cooldown'  => kokuu_coupon_draw_cooldown_left($uid),
                'odds'      => kokuu_coupon_odds_table(),
                'myCodes'   => DB::table('coupon_redemptions')
                    ->where('uid', $uid)
                    ->orderByDesc('id')
                    ->limit(15)
                    ->get(),
                'myDraws'   => DB::table('lottery_draws')
                    ->where('uid', $uid)
                    ->orderByDesc('id')
                    ->limit(15)
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
                'code'    => $r['ok'] ? 0 : 1,
                'message' => $r['msg'],
                'label'   => $r['label']   ?? null,
                'amount'  => $r['amount']  ?? 0,
                'balance' => $r['balance'] ?? null,
                'draws'   => $r['draws']   ?? null,
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
    });
};
