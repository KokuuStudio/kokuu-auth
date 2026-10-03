<?php

use App\Models\User;
use App\Services\Hook;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

/*
 * KokuuSkin 积分兑换（kokuu-exchange）
 *
 * 把论坛积分（users.score）兑换成游戏内 CMI 货币。
 *
 * ============================ 为什么叫「直连发放」 ============================
 * CMI（Zrips）**没有 HTTP/REST 接口**，发放动作只能由 MC 服务端里的 Java 代码
 * 执行。所以「直连」的真实含义不是浏览器直接连到游戏，而是：
 *
 *     玩家在皮肤站点一下 → 生成订单（已扣积分）→ LPUSH 进 Redis 队列
 *                        → MC 端 KokuuBridge 插件 RPOP 消费
 *                        → 就地调 CMI.deposit() 发放
 *                        → LPUSH 结果到 result 队列
 *                        → 皮肤站消费结果，标记 done / failed
 *
 * 玩家体验是「点了就到账，不用进游戏输任何码」。
 *
 * ==== 为什么用 Redis 队列，而不是让 MC 端轮询 HTTP 接口 ====
 *   1. 不开任何公网端口。Redis 只监听 127.0.0.1 + requirepass，
 *      MC 端走 SSH 隧道即可。这台服务器目前**没有任何防火墙**
 *      （ufw inactive + iptables INPUT 全 ACCEPT），
 *      开3306 到公网等于把全站用户库暴露出去。
 *   2. RPOP 语义天然防重复消费：消息取走即消失，
 *      不像 HTTP 轮询要额外维护「谁领到了」的标记位。
 *   3. 无需签名/nonce/时间戳那套防重放 —— Redis 认证已挡住外部调用。
 *
 * 之所以敢「先扣积分再入队」，是因为**扣分和入队都在下单事务内**：
 * 入队失败会抛异常让整个事务回滚，积分原样退回。
 * 反之（先发放后扣分）才会产生「钱发了但没扣分」的资损。
 *
 * ============================ 三个必须守住的设计点 ============================
 *
 * 1. **订单强绑定具体角色，不是只绑定 uid**
 *    CMI 的货币是存在**玩家名**上的，deposit("Steve", 100) 扣的是 Steve 的钱。
 *    如果只记 uid，兑换时不知道该给哪个角色，就会出现
 *    「A 花了积分，钱进了 B 的角色」。所以下单时必须从 players 表里
 *    挑一个**本人名下**的角色存进订单。
 *    若用户只有一个角色（single-player-limit 的约束）就免选，
 *    多个角色必须显式选，且必须是 uid 本人的。
 *
 * 2. **幂等靠 (order_no, action) 唯一键，不靠「查一下状态」**
 *    MC 端可能在「已发放、结果回传失败」时重推同一条结果。
 *    若用 `SELECT status` 判状态再更新，两个并发回传会都通过检查。
 *    正确做法是给 ledger 写流水时用 UNIQUE(order_no, action)，
 *    让**数据库唯一索引**做唯一防线，撞了就当幂等命中。
 *    （与 kokuu-credit 的 event_id 幂等同一思路。）
 *
 * 3. **积分与订单必须在同一事务里扣**
 *    先扣积分成功、再建订单失败 → 玩家白亏积分。
 *    反之订单建成、积分没扣 → 刷单。
 *    所以整个下单走 DB::transaction，账本复用 kokuu-credit 的
 *    kokuu_credit_ledger()（它内部自带事务，这里用嵌套事务，
 *    Laravel 会用 savepoint 处理，不会真的开两个连接）。
 *
 * ============================ 状态机 ============================
 *
 *     pending ──MC 发放成功──> done
 *        │                       ▲
 *        ├──(MC 报告失败)──> failed ──refund──> refunded
 *        │
 *        ├──(超期未发放)──> refunded
 *        │
 *        └──(管理员作废)──> voided ──refund──> refunded
 *
 *   pending  已扣积分，已入Redis 队列等MC 端发放
 *   done     MC 端确认 CMI 发放成功（终态）
 *   failed   MC 端报告发放失败，积分已退回
 *   refunded 积分已退回，用户可重新下单（终态）
 *   voided   管理员作废，积分已退回（终态）
 *
 * ⚠️ 辅助函数必须定义在下方「return 闭包」之**前**。
 *    PHP 的 include/require 一旦在文件顶层遇到 return 就停止执行剩余部分，
 *    导致函数永远不被声明（Call to undefined function）。
 */

if (! function_exists('kokuu_exchange_config')) {
    /**
     * 读取插件配置（存 options 表，键前缀 kokuu_exchange_）。
     */
    function kokuu_exchange_config(): array
    {
        $defaults = [
            // 每多少积分换1 单位货币。避免浮点，用整数。
            'cost_per_unit'   => 100,
            // 每次兑换最少给多少单位货币
            'min_units'       => 1,
            // 单笔上限（单位货币），0 = 不限
            'max_units'       => 0,
            // 冷却期（秒），两次兑换的最小间隔
            'cooldown'        => 300,
            // 每日兑换上限（单位货币），0 = 不限
            'daily_limit'     => 0,
            // 订单有效期（秒）。超时未领取自动退款，避免用户被永久锁死
            'order_ttl'       => 86400,
            // 发放队列 key（下单时 LPUSH，MC 端 BRPOP 消费）
            // ⚠️ 必须与 MC 端 exchange-bridge 的 config.yml queue-key 完全一致。
            //    历史教训：插件从 bs-exchange 改名 kokuu-exchange 时这里跟着改了前缀，
            //    但 MC 端没同步 → 皮肤站 RPOP 一个不存在的键，订单永远停在 pending。
            //    两边不一致的后果是**静默失效**（不报错），排查代价极高。
            'queue_key'       => 'bs:exchange:queue',
            // 结果队列 key（MC 端 LPUSH，皮肤站 BRPOP 消费）
            'result_key'      => 'bs:exchange:result',
            // 自助退款开关：发放失败时由 MC 端直接触发退款，还是留给管理员
            'auto_refund'     => true,
            'notice'          => '',
        ];

        $values = [];
        foreach ($defaults as $k => $_) {
            $v = app('options')->get('kokuu_exchange_' . $k);
            // 空字符串同样视为「未设置」，回落到默认值。
            // ⚠️ 其他插件（forum/credit/home）都有 `|| $v === ''`，
            //    这里曾漏掉 → 管理员清空 notice 保存时行为与其他插件不一致。
            $values[$k] = ($v === null || $v === '') ? $defaults[$k] : $v;
        }

        // 数值型统一转成int，避免 options 表取出来是字符串导致比较出错
        foreach (['cost_per_unit', 'min_units', 'max_units', 'cooldown',
                  'daily_limit', 'order_ttl'] as $k) {
            $values[$k] = (int) $values[$k];
        }
        foreach (['queue_key', 'result_key', 'notice'] as $k) {
            $values[$k] = (string) $values[$k];
        }
        $values['auto_refund'] = (bool) $values['auto_refund'];

        return $values;
    }
}

if (! function_exists('kokuu_exchange_save_config')) {
    function kokuu_exchange_save_config(array $payload): void
    {
        $keys = ['cost_per_unit', 'min_units', 'max_units', 'cooldown', 'daily_limit',
                 'queue_key', 'result_key', 'order_ttl',
                 'auto_refund', 'notice'];

        foreach ($keys as $k) {
            if (array_key_exists($k, $payload)) {
                $v = $payload[$k];
                if (in_array($k, ['auto_refund'], true)) {
                    $v = $v ? 1 : 0;
                } elseif (in_array($k, ['queue_key', 'result_key', 'notice'], true)) {
                    $v = (string) $v;
                } else {
                    $v = (int) $v;
                }
                app('options')->set('kokuu_exchange_' . $k, $v);
            }
        }

        // ⚠️ 必须删storage/options.php，否则配置永远不生效。
        // App\Services\Option 构造函数**优先读** storage/options.php，
        // 存在即 return，根本不查数据库。内核自己的做法就是这个。
        @unlink(storage_path('options.php'));
    }
}

if (! function_exists('kokuu_exchange_ensure_tables')) {
    /**
     * 惰性建表。
     * 不用内核 migration：插件被删时内核不清理它建的数据，
     * 而订单是**财务数据**（涉及已扣积分），绝不能随插件卸载消失。
     */
    function kokuu_exchange_ensure_tables(): void
    {
        if (! Schema::hasTable('exchange_orders')) {
            Schema::create('exchange_orders', function (Blueprint $table) {
                $table->increments('id');
                // 订单号，对外暴露用（MC 端与后台都按它定位）
                $table->string('order_no', 40)->unique();
                $table->unsignedInteger('uid');
                // ★ 强绑定具体角色：CMI 的货币挂在玩家名上
                $table->unsignedInteger('player_id');
                $table->string('player_name', 64);
                $table->integer('units');            // 发放的货币数量
                $table->integer('cost');             // 扣除的积分数
                $table->string('status', 16)->index();
                $table->timestamp('done_at')->nullable();
                $table->text('fail_reason')->nullable();
                $table->boolean('refunded')->default(false);
                $table->timestamps();
                // 后台按用户 + 时间查最近订单
                $table->index(['uid', 'created_at']);
            });
        }

        if (! Schema::hasTable('exchange_ledger')) {
            Schema::create('exchange_ledger', function (Blueprint $table) {
                $table->increments('id');
                $table->string('order_no', 40);
                // pending / done / failed / refunded / voided
                // UNIQUE(order_no, action) 就是防双发的唯一防线
                $table->string('action', 16);
                $table->string('note', 191)->nullable();
                $table->timestamps();
                $table->unique(['order_no', 'action'], 'uniq_order_action');
            });
        }
    }
}

if (! function_exists('kokuu_exchange_mkorder_no')) {
    /**
     * 生成订单号。带随机段，避免可预测（虽然不可预测不是主要防线，
     * 状态机 + 幂等键才是，但顺手做对不亏）。
     */
    function kokuu_exchange_mkorder_no(): string
    {
        return 'EX' . date('ymdHis') . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (! function_exists('kokuu_exchange_redis')) {
    /**
     * 取 Redis 连接（**不带 Laravel 键前缀**）。
     *
     * ⚠️⚠️ 这里为什么不能用 Illuminate\Support\Facades\Redis —— 这是本插件
     *      最隐蔽的一个坑，曾导致兑换功能**端到端完全失效且零报错**。
     *
     *  背景：Blessing Skin 的 config/database.php 给 redis 设了
     *        'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME','blessing_skin'),'_').'_database_')
     *        本站 .env **没有 APP_NAME** → 落回默认 → 前缀恒为
     *        `blessing_skin_database_`
     *
     *  用门面时，phpredis 客户端会在**扩展层**设 OPT_PREFIX，
     *  所有命令的键名被自动拼接：
     *        门面 ->lpush('bs:exchange:queue')
     *        实际写入 blessing_skin_database_bs:exchange:queue
     *
     *  而 MC 端 exchange-bridge 用的是**裸 Jedis**（无任何前缀），
     *  它 BRPOP 的是字面量 `bs:exchange:queue`。
     *  → 皮肤站往 A 键写、MC 从 B 键读，**永远对不上**。
     *
     *  更麻烦的是表现：不抛异常、不写日志、页面一切正常，
     *  只是订单永远停在 pending，直到 order_ttl(24h) 超期退款。
     *
     *  正确做法：为本插件单开一个**清空前缀**的连接。
     *  不能直接改门面连接的 OPT_PREFIX —— 那是共享单例，
     *  改了会污染同进程内其他消费者的键名。
     */
    function kokuu_exchange_redis()
    {
        static $conn = null;
        if ($conn !== null) {
            return $conn;
        }

        // 从框架配置里取连接参数，但**不继承** options.prefix
        $cfg = config('database.redis.default', []);
        $client = new \Redis();
        $client->connect(
            (string) ($cfg['host'] ?? '127.0.0.1'),
            (int) ($cfg['port'] ?? 6379),
            (float) ($cfg['timeout'] ?? 2.0)
        );

        $pass = (string) ($cfg['password'] ?? '');
        if ($pass !== '') {
            $client->auth($pass);
        }
        if (isset($cfg['database'])) {
            $client->select((int) $cfg['database']);
        }

        // ★ 关键：显式清空前缀，让键名与 MC 端 Jedis 的字面量一致
        $client->setOption(\Redis::OPT_PREFIX, '');

        // 与门面默认一致：不序列化，值就是裸字符串（MC 端 json_decode 才认得）
        $client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);

        return $conn = $client;
    }
}
if (! function_exists('kokuu_exchange_push_job')) {
    /**
     * 下单成功后把订单 LPUSH 进发放队列。
     *
     * ★ 为什么队列用 Redis 而不是让 MC 端轮询 HTTP：
     *   - 单条 LPUSH 本身就是原子的，不需要额外加锁
     *   - MC 端 BRPOP 天然「取走即从队列消失」，不像 HTTP 轮询那样
     *     还要额外做「谁领到了」的标记才能防重复消费
     *   - 不开任何公网端口（Redis 只监听 127.0.0.1）
     *
     * ⚠️ Redis 不可用时**不能静默失败** —— 那会让积分扣了但永远不发放。
     *   所以这里抛异常，让外层事务回滚（积分一起退回去）。
     *   「扣了积分但没入队」比「下单失败」糟糕得多。
     *
     * ⚠️ LPUSH 的返回值是**列表的新长度**，不是成功与否的布尔。
     *   写`(bool)$ok` 或 `if (!$ok)` 都是错的：
     *   队列里已有 1 条时，第二次 lpush 返回 2 ——「2」在 PHP 里是真值，
     *   恰好能过；但若写 `if ($ok !== 1)` 就会在第二笔兑换时抛异常，
     *   表现为「第一次能兑换，第二次永远失败」。
     *   正确判据是「长度 > 0」。
     */
    function kokuu_exchange_push_job(array $job): void
    {
        $cfg = kokuu_exchange_config();

        try {
            $len = kokuu_exchange_redis()->lpush(
                $cfg['queue_key'],
                json_encode($job, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('发放队列不可用（Redis 连接失败）：' . $e->getMessage());
        }

        if ((int) $len < 1) {
            throw new \RuntimeException('发放队列写入失败（lpush 返回 ' . var_export($len, true) . '）');
        }
    }
}

if (! function_exists('kokuu_exchange_drain_results')) {
    /**
     * 消费 MC 端回传的结果队列，把订单标记为 done / failed。
     *
     * 由玩家页面与后台页顺带调用（读操作顺手消费），
     * 不需要常驻进程 —— 这是「无cron/无队列 worker」条件下的取巧做法。
     *
     * ⚠️ 这个取舍要讲清楚：结果回传**依赖有人访问页面**。
     *   若站点一段时间无人访问，MC 端已经发了钱但订单会一直停在 pending，
     *   玩家看到的还是「发放中」。恢复访问后自动补上。
     *   注意这与 order_ttl 的超期退款是**同一个 pending 状态** ——
     *   若玩家超期没等到结果就退款，而MC 端其实已经发了钱，就会双发。
     *   因此 order_ttl 应设得比「MC 端最长离线时间」长。
     *   MC 端侧也必须幂等：它用 RPOP 消费，同一条消息不会被两实例同时拿到。
     *
     * @param int $max 本次最多消费几条
     * @return array 本次处理的订单号=>状态
     */
    function kokuu_exchange_drain_results(int $max = 20): array
    {
        $cfg = kokuu_exchange_config();
        $done = [];

        try {
            $redis = kokuu_exchange_redis();
        } catch (\Throwable $e) {
            return [];                        // Redis 挂了不影响页面本身显示
        }

        for ($i = 0; $i < $max; $i++) {
            try {
                // BRPOP 会阻塞，所以用非阻塞 RPOP
                $raw = $redis->rpop($cfg['result_key']);
            } catch (\Throwable $e) {
                break;
            }
            if (! $raw) {
                break;
            }

            $row = json_decode((string) $raw, true);
            if (! is_array($row) || empty($row['order_no'])) {
                continue;
            }

            $ok = ! empty($row['success']);
            $reason = (string) ($row['reason'] ?? '');
            $action = $ok ? 'confirm' : 'fail';

            try {
                kokuu_exchange_confirm((string) $row['order_no'], $ok, $reason, $action);
                $done[(string) $row['order_no']] = $ok ? 'done' : 'failed';
            } catch (\Throwable $e) {
                // 单条处理失败不能中断整批，放回队首下次再试
                try {
                    $redis->lpush($cfg['result_key'], (string) $raw);
                } catch (\Throwable $_) {
                    // 连回写都失败就靠 order_ttl 超期退款兜底
                }
                break;
            }
        }

        return $done;
    }
}

if (! function_exists('kokuu_exchange_source_label')) {
    /**
     * 本插件在credit_ledger 里用到的 source 的中文标签。
     *
     * ★ 为什么必须自己定义，不能靠 kokuu_credit_source_label()：
     *   那个函数的映射表是**封闭的**，只认 thread/reply/liked/manual/legacy，
     *   传 'exchange' 会 `?? $source` 原样返回英文 ——
     *   玩家会在流水页看到 "exchange" 这种看不懂的词。
     *   那个插件由别人维护（约定不碰它），所以只能在自己这边补映射。
     */
    function kokuu_exchange_source_label(string $source): string
    {
        return [
            'exchange'        => '积分兑换游戏货币',
            'exchange_refund' => '兑换退款',
        ][$source] ?? $source;
    }
}

if (! function_exists('kokuu_exchange_ledger_write')) {
    /**
     * 写 credit_ledger 的一条流水（本插件专用收口）。
     *
     * ★ 这里是「三方积分互通」的关键收口点。
     *
     *   已确认的事实（2026-10-03 线上核对）：
     *   论坛侧的 kokuu/forum-points 扩展明确写着
     *   「论坛只发不扣 —— 负数一律拒绝，不入库」，
     *   皮肤站的 plugin/kokuu-credit/api/earn 也有 `delta <= 0 → 422` 硬拦。
     *   所以**扣分权永久地留在皮肤站侧**，论坛无论怎么调都扣不动用户的分。
     *
     *   本插件正好跑在皮肤站，直接调 kokuu_credit_ledger() 记流水，
     *   不经过论坛中转 —— 这不是"选择"，是被现有架构逼出来的唯一正确做法。
     *
     * ⚠️ delta 的方向由 source 决定，**不能统一断言为负**：
     *     exchange         → 扣分，delta 为**负**
     *     exchange_refund  → 退分，delta 为**正**
     *   （曾经把这条写成「只能写负向流水」，结果退款直接抛异常 ——
     *     自己的安全断言把自己要保护的功能挡掉了。）
     *
     * @param int    $delta 与 source 方向必须一致
     * @param string $source 只能是 exchange / exchange_refund
     * @return bool  true=已写入；false=幂等命中（此前已记过同一笔）
     */
    function kokuu_exchange_ledger_write(int $uid, int $delta, string $source, string $eventId, string $note = ''): bool
    {
        if (! in_array($source, ['exchange', 'exchange_refund'], true)) {
            throw new \InvalidArgumentException("kokuu-exchange 非法 source: {$source}");
        }
        // 方向校验：扣分必须为负，退分必须为正。写反了会让余额越走越歪。
        if ($source === 'exchange' && $delta >= 0) {
            throw new \InvalidArgumentException("exchange（扣分）的 delta 必须为负，收到 {$delta}");
        }
        if ($source === 'exchange_refund' && $delta <= 0) {
            throw new \InvalidArgumentException("exchange_refund（退分）的 delta 必须为正，收到 {$delta}");
        }

        return kokuu_credit_ledger($uid, $delta, $source, $eventId, $eventId, mb_substr($note, 0, 150));
    }
}

if (! function_exists('kokuu_exchange_players_of')) {
    /**
     * 取某用户名下的角色列表。
     * ★ 只能取本人名下的 —— 这是「钱不能进错角色」的第一道闸。
     */
    function kokuu_exchange_players_of(int $uid): array
    {
        return DB::table('players')
            ->where('uid', $uid)
            ->orderBy('name')
            ->get(['pid', 'name'])
            ->map(fn ($p) => ['pid' => (int) $p->pid, 'name' => (string) $p->name])
            ->all();
    }
}

if (! function_exists('kokuu_exchange_daily_units')) {
    /**
     * 今日（服务器时区自然日）已成功发放的货币总数，用于日限额。
     * 只算 done —— pending 的还没给钱，不该占额度。
     */
    function kokuu_exchange_daily_units(int $uid): int
    {
        kokuu_exchange_ensure_tables();

        return (int) DB::table('exchange_orders')
            ->where('uid', $uid)
            ->where('status', 'done')
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('units');
    }
}

if (! function_exists('kokuu_exchange_cooldown_left')) {
    /**
     * 冷却期还剩多少秒。0 = 可以兑换。
     * 只看 pending —— 正在等发放的订单已经扣过分，不该重复扣额度。
     */
    function kokuu_exchange_cooldown_left(int $uid): int
    {
        kokuu_exchange_ensure_tables();

        $last = DB::table('exchange_orders')
            ->where('uid', $uid)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->first();

        if (! $last) {
            return 0;
        }
        $cfg = kokuu_exchange_config();
        if ($cfg['cooldown'] <= 0) {
            return 0;
        }
        $elapsed = time() - strtotime((string) $last->created_at);
        $left = $cfg['cooldown'] - $elapsed;

        return $left > 0 ? $left : 0;
    }
}

if (! function_exists('kokuu_exchange_expire_stale')) {
    /**
     * 把超时未领取的 pending 订单退款。
     *
     * 为什么必须自动退款：pending 状态下积分**已经扣了**。
     * 如果 MC 端永久不上线，用户就被永久锁死这些积分。
     * 宁可让用户重新下单（他积分已经回来了），也不能让积分凭空消失。
     *
     * 返回本次退款数量。
     */
    function kokuu_exchange_expire_stale(): int
    {
        kokuu_exchange_ensure_tables();
        $cfg = kokuu_exchange_config();

        if ($cfg['order_ttl'] <= 0) {
            return 0;
        }
        $deadline = now()->subSeconds($cfg['order_ttl']);

        $stale = DB::table('exchange_orders')
            ->where('status', 'pending')
            ->where('refunded', false)
            ->where('created_at', '<', $deadline)
            ->get();

        $n = 0;
        foreach ($stale as $o) {
            if (kokuu_exchange_refund((int) $o->id, '超时未领取，系统自动退款')) {
                $n++;
            }
        }

        return $n;
    }
}

if (! function_exists('kokuu_exchange_refund')) {
    /**
     * 退款：把积分还回去，并把订单标记为已退款。
     *
     * 幂等：靠 exchange_ledger 的 UNIQUE(order_no, 'refund')。
     * 重复调用会因唯一索引冲突被拦下，不会双退。
     * ★「退了钱」和「标记已退款」必须在同一事务里，
     *   否则并发下可能出现「钱退了两次」或「标记成功但没退钱」。
     */
    function kokuu_exchange_refund(int $orderId, string $reason): bool
    {
        kokuu_exchange_ensure_tables();

        return DB::transaction(function () use ($orderId, $reason) {
            $o = DB::table('exchange_orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $o) {
                return false;
            }
            if ($o->refunded) {
                return false;                      // 已退过，幂等命中
            }
            if (in_array($o->status, ['done'], true)) {
                return false;                      // 已成功发放，不能退
            }

            // 流水占位（唯一键是真正的幂等防线）
            try {
                DB::table('exchange_ledger')->insert([
                    'order_no'   => $o->order_no,
                    'action'     => 'refund',
                    'note'       => mb_substr($reason, 0, 180),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '23000' || $e->getCode() === 23000) {
                    return false;                  // 已退过
                }
                throw $e;
            }

            // 退积分。走 kokuu-exchange 的账本收口，保证 users.score 与流水一致。
            // event_id 用 order_no，退款重试不会重复加。
            // 返回 false = 上次已退过，这次不再加，但仍要把订单标记成已退款。
            kokuu_exchange_ledger_write(
                (int) $o->uid,
                (int) $o->cost,
                'exchange_refund',
                'refund:' . $o->order_no,
                '兑换退款：' . $reason
            );

            DB::table('exchange_orders')->where('id', $orderId)->update([
                'status'      => 'refunded',
                'refunded'    => true,
                'fail_reason' => mb_substr($reason, 0, 180),
                'updated_at'  => now(),
            ]);

            return true;
        });
    }
}

if (! function_exists('kokuu_exchange_create_order')) {
    /**
     * 下单：校验 → 扣积分 → 建订单。全部在一个事务里。
     *
     * 返回 ['ok'=>bool, 'msg'=>string, 'order'=>array|null]
     */
    function kokuu_exchange_create_order(int $uid, int $units, int $playerId): array
    {
        kokuu_exchange_ensure_tables();
        $cfg = kokuu_exchange_config();

        $fail = fn (string $m) => ['ok' => false, 'msg' => $m, 'order' => null];

        if ($cfg['cost_per_unit'] <= 0) {
            return $fail('管理员尚未配置兑换比例');
        }

        /* ★ 角色校验必须放在**最前面**。
         *   它是「钱发到别人角色上」的最后一道闸 ——
         *   用户的 pid 是可以随手改的（改个隐藏字段就能提交别人的 pid），
         *   所以只要这条没拦住，后面无论扣多少分、发多少钱都是发给错的人。
         *
         *   为什么放最前而不是跟着其他校验一起排后面：
         *   排在后面时，余额不足/冷却中会先返回，
         *   攻击者用「余额不足」当掩护，角色校验根本不会执行，
         *   错误日志里也只看到「积分不足」，排查时根本想不到要查角色归属。
         *   校验顺序 = 错误的可读性。
         */
        $player = DB::table('players')
            ->where('pid', $playerId)
            ->where('uid', $uid)
            ->first();
        if (! $player) {
            return $fail('角色不存在或不属于当前账号');
        }

        if ($units < $cfg['min_units']) {
            return $fail("单次至少兑换 {$cfg['min_units']} 单位");
        }
        if ($cfg['max_units'] > 0 && $units > $cfg['max_units']) {
            return $fail("单次最多兑换 {$cfg['max_units']} 单位");
        }

        $cost = $units * $cfg['cost_per_unit'];

        // 先做一次非事务的快速校验，给出友好提示（事务内还会再查一次）
        $balance = (int) DB::table('users')->where('uid', $uid)->value('score');
        if ($balance < $cost) {
            return $fail("积分不足：需要 {$cost}，当前只有 {$balance}");
        }

        $left = kokuu_exchange_cooldown_left($uid);
        if ($left > 0) {
            return $fail('冷却中，请 ' . $left . ' 秒后再试');
        }
        if ($cfg['daily_limit'] > 0) {
            $used = kokuu_exchange_daily_units($uid);
            if ($used + $units > $cfg['daily_limit']) {
                return $fail("超出今日上限（今日已兑换 {$used} / 上限 {$cfg['daily_limit']}）");
            }
        }

        return DB::transaction(function () use ($uid, $units, $cost, $player, $cfg) {
            // ⚠️ 订单号必须在扣分**之前**生成。
            //    账本的幂等键就是 'exchange:' . 订单号，如果先扣分后生成，
            //    第一次的键会是 'exchange:'（空），之后每次下单都撞这个键
            //    被判成幂等命中 → 所有人都兑换失败。
            $orderNo = kokuu_exchange_mkorder_no();

            /* ★ 事务内必须**锁行**再查余额。
             *   kokuu_credit_ledger 内部是 `increment('score', $delta)`，
             *   数据库层自增**不会**拦负数 —— 前面的余额预检只是提示，
             *   两个并发请求可以都通过预检，然后双双扣成负数。
             *   lockForUpdate() 把这一行锁住，第二个事务只能等，
             *   拿到锁时读到的就是第一个事务提交后的新余额。
             *   （账本自身的 increment 仍是最终保证，这里只是把错误拦在前面，
             *     避免出现「积分负数 + 订单已建」的资损。） */
            $balance = (int) DB::table('users')
                ->where('uid', $uid)
                ->lockForUpdate()
                ->value('score');
            if ($balance < $cost) {
                return [
                    'ok'    => false,
                    'msg'   => "积分不足：需要 {$cost}，当前只有 {$balance}",
                    'order' => null,
                ];
            }

            // 事务内扣款。kokuu_credit_ledger 内部用 increment 原子自增，
            // 并在流水里写 balance_after。返回 false = 幂等命中。
            $ok = kokuu_exchange_ledger_write(
                $uid,
                -$cost,
                'exchange',
                'exchange:' . $orderNo,
                '兑换 ' . $units . ' 单位游戏货币'
            );
            if (! $ok) {
                return ['ok' => false, 'msg' => '扣分失败（可能已在处理中）', 'order' => null];
            }

            $id = DB::table('exchange_orders')->insertGetId([
                'order_no'    => $orderNo,
                'uid'         => $uid,
                'player_id'   => (int) $player->pid,
                'player_name' => (string) $player->name,
                'units'       => $units,
                'cost'        => $cost,
                'status'      => 'pending',
                'refunded'    => false,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            /* ★ 入队放在事务内最后一步。
             *   顺序很关键：先扣分+建订单（DB 事务），最后才 LPUSH。
             *   - 若LPUSH 失败抛异常 → 整个事务回滚，积分**退回**，用户什么都没丢
             *   - 若放在事务外 → 扣了分但入队失败 = 积分蒸发、永远不发放
             *   Redis 的写入不受MySQL 事务管辖，但LPUSH 本身是原子的，
             *   所以「入队成功但事务回滚」的窗口只存在于两条语句之间，
             *   此时队列里会多一条**已不存在的订单号** ——
             *   MC 端消费后会回传失败结果，皮肤站按「订单不存在」忽略它即可，
             *   不会误给玩家发钱（因为 pid/player_name 在队列里，但订单查不到）。
             */
            kokuu_exchange_push_job([
                'order_no'    => $orderNo,
                'uid'         => $uid,
                'player_name' => (string) $player->name,
                'units'       => $units,
                'created_at'  => now()->toDateTimeString(),
            ]);

            return [
                'ok'    => true,
                'msg'   => '兑换成功，正在发放到游戏',
                'order' => [
                    'id'          => $id,
                    'order_no'    => $orderNo,
                    'player_name' => (string) $player->name,
                    'units'       => $units,
                    'cost'        => $cost,
                    'status'      => 'pending',
                ],
            ];
        });
    }
}

if (! function_exists('kokuu_exchange_confirm')) {
    /**
     * 处理 MC 端回传的发放结果：成功 → done，失败 → failed（可自动退款）。
     *
     * ⚠️ 幂等关键点：这里**不能**写成「先查 status 再改」，
     *    必须靠 exchange_ledger 的 UNIQUE(order_no, action)。
     *    原因：MC 端在「已发放、结果回传丢包」时会重推同一条结果，
     *    若用 SELECT 判状态，两个并发回传会都通过检查。
     *
     * @param string $orderNo
     * @param bool   $success
     * @param string $reason
     * @param string $action  'confirm' | 'fail'
     */
    function kokuu_exchange_confirm(string $orderNo, bool $success, string $reason, string $action): array
    {
        kokuu_exchange_ensure_tables();

        return DB::transaction(function () use ($orderNo, $success, $reason, $action) {
            $o = DB::table('exchange_orders')
                ->where('order_no', $orderNo)
                ->lockForUpdate()
                ->first();

            if (! $o) {
                return ['ok' => false, 'msg' => '订单不存在'];
            }
            // 已完成/已退款的订单重复回报直接认成功（幂等），
            // 否则 MC 端会一直重推一个已经完结的订单。
            if ($o->status === 'done' || $o->refunded) {
                return ['ok' => true, 'msg' => '已处理'];
            }

            try {
                DB::table('exchange_ledger')->insert([
                    'order_no'   => $orderNo,
                    'action'     => $action,
                    'note'       => mb_substr($reason, 0, 180),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '23000' || $e->getCode() === 23000) {
                    return ['ok' => true, 'msg' => '已处理'];
                }
                throw $e;
            }

            if ($success) {
                DB::table('exchange_orders')->where('id', $o->id)->update([
                    'status'      => 'done',
                    'done_at'     => now(),
                    'fail_reason' => null,
                    'updated_at'  => now(),
                ]);

                return ['ok' => true, 'msg' => '已确认发放'];
            }

            DB::table('exchange_orders')->where('id', $o->id)->update([
                'status'      => 'failed',
                'fail_reason' => mb_substr($reason, 0, 180),
                'updated_at'  => now(),
            ]);

            // 自助退款：把积分还给用户，不必等管理员
            $cfg = kokuu_exchange_config();
            if ($cfg['auto_refund']) {
                kokuu_exchange_refund((int) $o->id, '游戏内发放失败：' . $reason);
            }

            return ['ok' => true, 'msg' => $cfg['auto_refund'] ? '已记录失败并退款' : '已记录失败'];
        });
    }
}

if (! function_exists('kokuu_exchange_stats')) {
    function kokuu_exchange_stats(): array
    {
        kokuu_exchange_ensure_tables();

        return [
            'total'   => (int) DB::table('exchange_orders')->count(),
            'pending' => (int) DB::table('exchange_orders')->where('status', 'pending')->count(),
            'done'    => (int) DB::table('exchange_orders')->where('status', 'done')->count(),
            'failed'  => (int) DB::table('exchange_orders')->where('status', 'failed')->count(),
            'units'   => (int) DB::table('exchange_orders')->where('status', 'done')->sum('units'),
            'today'   => (int) DB::table('exchange_orders')
                ->where('status', 'done')
                ->where('created_at', '>=', now()->startOfDay())
                ->sum('units'),
            'cost'    => (int) DB::table('exchange_orders')
                ->where('status', 'done')
                ->sum('cost'),
        ];
    }
}

if (! function_exists('kokuu_exchange_reset_stuck')) {
    /**
     * 兜底：把「已扣积分但既不在队列、也没收到结果」的孤儿订单退款。
     *
     * ═══ 为什么 Redis 方案下仍然需要这一步 ═══
     * 正常链路是：下单 LPUSH → MC 端 RPOP 消费 → 回传结果 → 标记 done。
     * 但有几种情况会让订单「凭空消失」，此时玩家积分已扣却永远等不到钱：
     *
     *   a) Redis 重启丢数据（AOF everysec 最多丢 1 秒）
     *   b) 队列被误清（redis-cli FLUSHDB、清缓存脚本）
     *   c) LPUSH 成功但 MySQL 事务回滚 → 队列里有条「幽灵订单」
     *      （这条对玩家无害，MC 端消费后回传会找不到订单而被忽略）
     *   d) MC 端 RPOP 拿走消息后崩溃 → 订单还在 pending，但队列里已空
     *
     * (d) 是最要命的：LPOP 的语义就是「取走即消失」，没有 ack。
     * 所以光看队列无法区分「没人消费」和「消费了但崩了」。
     *
     * ═══ 判定方式 ═══
     * pending 且超过 order_ttl 的，一律退款。
     * 此时若 MC 端其实已经发了钱，玩家会拿到双份 ——
     * 这是「玩家被永久卡死」与「极小概率双发」的取舍，
     * 理由与 HTTP 方案一致：卡死是必然发生的坏结果，双发是极小概率。
     * 被重置的订单会重新 LPUSH，管理员可在后台按fail_reason 对账。
     *
     * 不想承担双发风险就把 order_ttl 设大（如 604800=7 天），
     * 极端情况下需要人工介入。
     */
    function kokuu_exchange_reset_stuck(): int
    {
        kokuu_exchange_ensure_tables();
        $cfg = kokuu_exchange_config();

        $timeout = (int) $cfg['order_ttl'];
        if ($timeout <= 0) {
            return 0;
        }
        $deadline = now()->subSeconds($timeout);

        // 查出来逐个处理：退款 + 重新入队
        $stale = DB::table('exchange_orders')
            ->where('status', 'pending')
            ->where('refunded', false)
            ->where('created_at', '<', $deadline)
            ->get();

        $n = 0;
        foreach ($stale as $o) {
            // 超过 order_ttl 仍未被消费 → 认定玩家等不到，改为退款
            if (kokuu_exchange_refund((int) $o->id, '超过兑换有效期仍未发放，积分已退回')) {
                $n++;
            }
        }

        return $n;
    }
}

return function ($plugin) {
    View::addLocation($plugin->getPath() . '/views');

    /* 侧边栏入口。给玩家的放user 区（个人兑换），管理员的放 admin 区。 */
    \Illuminate\Support\Facades\Event::listen(\App\Events\ConfigureUserMenu::class, function ($event) {
        $event->menu['user'][] = [
            'title' => '积分兑换',
            'link'  => 'plugin/kokuu-exchange',
            'icon'  => 'fa-exchange-alt',
        ];
    });
    \Illuminate\Support\Facades\Event::listen(\App\Events\ConfigureAdminMenu::class, function ($event) {
        $event->menu['admin'][] = [
            'title' => '兑换管理',
            'link'  => 'plugin/kokuu-exchange/orders',
            'icon'  => 'fa-exchange-alt',
        ];
    });

    Hook::addRoute(function ($router) use ($plugin) {
        /* ---- 玩家页：兑换与查看自己的订单 ---- */
        // ⚠️ 内层闭包必须**再写一次** use ($plugin)。
        //    PHP 闭包有独立作用域，外层的 use 不会继承到内层；
        //    漏写时路由注册完全正常，只有真访问才 Undefined variable $plugin → 500。
        $router->get('plugin/kokuu-exchange', function (Request $request) use ($plugin) {
            $user = $request->user();
            abort_if (! $user, 403);

            kokuu_exchange_ensure_tables();

            // 顺手消费 MC 端回传的结果（无cron/无 worker 的取巧做法）
            kokuu_exchange_drain_results();
            kokuu_exchange_expire_stale();

            $uid = (int) $user->uid;

            return view()->file($plugin->getPath() . '/views/user/exchange/index.twig', [
                'cfg'      => kokuu_exchange_config(),
                'balance'  => (int) DB::table('users')->where('uid', $uid)->value('score'),
                'players'  => kokuu_exchange_players_of($uid),
                'cooldown' => kokuu_exchange_cooldown_left($uid),
                'dailyUsed'=> kokuu_exchange_daily_units($uid),
                'orders'   => DB::table('exchange_orders')
                    ->where('uid', $uid)
                    ->orderByDesc('id')
                    ->limit(20)
                    ->get(),
            ]);
        })->middleware(['web']);

        /* ---- 下单接口 ---- */
        $router->post('plugin/kokuu-exchange/create', function (Request $request) {
            $user = $request->user();
            abort_if (! $user, 403);

            $units = (int) $request->input('units', 0);
            $playerId = (int) $request->input('player_id', 0);

            $r = kokuu_exchange_create_order((int) $user->uid, $units, $playerId);

            if (! $r['ok']) {
                return response()->json(['code' => 1, 'message' => $r['msg']], 422);
            }

            return response()->json([
                'code'    => 0,
                'message' => $r['msg'],
                'order'   => $r['order'],
            ]);
        })->middleware(['web']);

        /* ---- 管理员：订单列表 ---- */
        $router->get('plugin/kokuu-exchange/orders', function (Request $request) use ($plugin) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            kokuu_exchange_ensure_tables();
            kokuu_exchange_drain_results();
            kokuu_exchange_expire_stale();

            return view()->file($plugin->getPath() . '/views/admin/orders.twig', [
                'cfg'     => kokuu_exchange_config(),
                'stats'   => kokuu_exchange_stats(),
                'orders'  => DB::table('exchange_orders')->orderByDesc('id')->limit(100)->get(),
            ]);
        })->middleware(['web']);

        /* ---- 管理员：保存配置 ---- */
        $router->post('plugin/kokuu-exchange/settings', function (Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            kokuu_exchange_save_config($request->only([
                'cost_per_unit', 'min_units', 'max_units', 'cooldown', 'daily_limit',
                'queue_key', 'result_key', 'order_ttl', 'auto_refund', 'notice',
            ]));

            return back()->with('success', '兑换配置已保存');
        })->middleware(['web']);

        /* ---- 管理员：手动退款（单笔） ---- */
        $router->post('plugin/kokuu-exchange/refund', function (Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            $id = (int) $request->input('id', 0);
            $ok = kokuu_exchange_refund($id, '管理员手动退款');

            return back()->with($ok ? 'success' : 'warning',
                $ok ? '已退款' : '该订单无法退款（可能已完成或已退款）');
        })->middleware(['web']);

        /* ---- 自检接口（供 MC 端/运维排查用，需登录） ---- */
        $router->get('plugin/kokuu-exchange/health', function (Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            kokuu_exchange_ensure_tables();
            $cfg = kokuu_exchange_config();

            $redis = ['ok' => false, 'error' => null, 'queue' => 0, 'result' => 0];
            try {
                $c = kokuu_exchange_redis();
                $redis['ok']    = true;
                $redis['queue'] = (int) $c->llen($cfg['queue_key']);
                $redis['result']= (int) $c->llen($cfg['result_key']);
            } catch (\Throwable $e) {
                $redis['error'] = mb_substr($e->getMessage(), 0, 180);
            }

            return response()->json([
                'code' => 0,
                'data' => [
                    'tables' => [
                        'exchange_orders'   => Schema::hasTable('exchange_orders'),
                        'exchange_ledger'   => Schema::hasTable('exchange_ledger'),
                        'credit_ledger'     => Schema::hasTable('credit_ledger'),
                    ],
                    'redis' => $redis,
                    'config' => [
                        'cost_per_unit' => $cfg['cost_per_unit'],
                        'queue_key'     => $cfg['queue_key'],
                        'result_key'    => $cfg['result_key'],
                        'order_ttl'     => $cfg['order_ttl'],
                        'auto_refund'   => $cfg['auto_refund'],
                    ],
                    'stats'  => kokuu_exchange_stats(),
                    'credit' => function_exists('kokuu_credit_ledger') ? 'loaded' : 'MISSING',
                ],
            ]);
        })->middleware(['web']);
    });
};
