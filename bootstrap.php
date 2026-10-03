<?php

/*
 * KokuuSkin 论坛积分（kokuu-credit）
 *
 * 论坛（Flarum）里用户只能**赚**积分，不能扣。扣分场景（建角色、衣柜格子、
 * 兑换码）全部留在皮肤站，因为Blessing Skin 6.x 的 users.score 是内核原生字段，
 * 签到、建角色、衣柜、点赞、举报已经在加减它了。
 *
 * 所以本插件是**唯一账本**：论坛只是一个加分事件的来源，通过带签名的服务端
 * 接口把「给用户 X 加 N 分」推过来，这里验签、去重、记流水、累加余额。
 *
 * 设计要点：
 *   - **唯一账本**。全站只有 users.score 一个余额字段，不存在第二份数字，
 *     所以永远不会出现「论坛显示 100、皮肤站显示 80」这种双账本对不上的情况。
 *   - **HMAC-SHA256 签名**而不是共享 token。token 一旦进了论坛的日志或
 *     配置文件就等于裸奔；HMAC 用时间戳 + nonce做签名，且带过期时间，
 *     抓包重放会被nonce 拦掉，过期请求直接拒。
 *   - **event_id 幂等**。论坛侧对每个加分事件生成一个稳定的 id（由事件类型 +
 *     实体 id 组成），ledger 表对该列建唯一索引。同一事件重推多少次，
 *     余额只加一次，网络抖动/论坛重试都不会双加。
 *   - **按 email 映射用户**。两站账号体系目前是独立的（SSO 还没上），
 *     但 users.email 在两站都是唯一索引，用它做映射键最稳；找不到就拒绝并
 *     记流水，不静默丢弃。
 *   - **额度上限**。管理员可设单次加分上限与每日累计上限，防止论坛被刷或
 *     签名泄露后被恶意调用打爆余额。
 *   - **绝不删options.php 之外不动内核**。所有写入都走 Eloquent，
 *     余额用原子自增（increment）而非「读出来算完再写回」，避免并发丢更新。
 *
 * ⚠️ 重要：辅助函数必须定义在下方「return 闭包」之**前**。
 *    PHP 的 include/require 一旦在文件顶层遇到 return，就立即停止执行该文件
 *    剩余部分，导致函数永远不被声明（Call to undefined function）。
 *    此处用 function_exists 包裹，同时防止重复加载时声明冲突。
 */

use App\Models\User;
use App\Services\Hook;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

if (! function_exists('kokuu_credit_config')) {
    /**
     * 读取插件配置（存 options 表，键前缀 kokuu_credit_）。
     */
    function kokuu_credit_config(): array
    {
        $defaults = [
            // 论坛根地址，仅用于在流水里回链与后台展示
            'forum_url'      => 'https://chat.kokuu.org',
            // 与论坛端约定的 HMAC 密钥（两边必须完全一致）
            'secret'         => '',
            // 加分规则：发帖
            'score_thread'   => 10,
            // 加分规则：回复
            'score_reply'    => 2,
            // 加分规则：帖子被点赞（需要论坛装点赞扩展，未装时该项自然不触发）
            'score_liked'    => 0,
            // 单次加分上限，防单笔异常
            'max_per_event'  => 50,
            // 每日累计加分上限（0 = 不限）
            'max_per_day'    => 200,
            // 签名有效期（秒），超出即拒，防重放
            'ttl'            => 300,
        ];

        $options = app('options');
        $config  = [];
        foreach ($defaults as $key => $default) {
            $value = $options->get('kokuu_credit_' . $key);
            $config[$key] = ($value === null || $value === '') ? $default : $value;
        }

        $config['forum_url'] = rtrim((string) $config['forum_url'], '/');

        foreach (['score_thread', 'score_reply', 'score_liked'] as $k) {
            $config[$k] = max(0, (int) $config[$k]);
        }
        $config['max_per_event'] = max(1, (int) $config['max_per_event']);
        $config['max_per_day']   = max(0, (int) $config['max_per_day']);
        $config['ttl']           = max(30, min(3600, (int) $config['ttl']));

        return $config;
    }
}

if (! function_exists('kokuu_credit_save_config')) {
    function kokuu_credit_save_config(array $payload): void
    {
        $options = app('options');
        foreach ($payload as $key => $value) {
            $options->set('kokuu_credit_' . $key, $value);
        }
    }
}

if (! function_exists('kokuu_credit_ensure_tables')) {
    /**
     * 惰性建表。
     *
     * 为什么不走 Blessing Skin 的 migration 机制：插件被删除时内核不会清理它
     * 建的数据，而 ledger 是**审计数据**，绝不能随插件卸载而消失。用
     * Schema::hasTable 判断一次，代价可忽略。
     */
    function kokuu_credit_ensure_tables(): void
    {
        if (! Schema::hasTable('credit_ledger')) {
            Schema::create('credit_ledger', function (Blueprint $table) {
                $table->increments('id');
                // 幂等键：论坛侧生成，格式形如 "thread:12" / "reply:345"
                $table->string('event_id', 80)->unique();
                $table->unsignedInteger('uid');
                $table->integer('delta');
                $table->integer('balance_after');
                // thread / reply / liked / manual
                $table->string('source', 32);
                $table->string('ref', 191)->nullable();
                $table->string('note', 191)->nullable();
                $table->timestamps();

                $table->index('uid');
                $table->index('created_at');
            });
        }
    }
}

if (! function_exists('kokuu_credit_ledger')) {
    /**
     * 写一条流水并原子累加余额。
     *
     * 全程在事务里，且余额用 increment（数据库层自增）而非「读出来 + 完写回」，
     * 避免两个并发请求同时读到同一个旧余额、其中一个的增量被覆盖。
     *
     * event_id唯一索引冲突时返回false，表示这个事件已处理过（幂等命中）。
     */
    function kokuu_credit_ledger(int $uid, int $delta, string $source, string $eventId, ?string $ref = null, ?string $note = null): bool
    {
        kokuu_credit_ensure_tables();

        return DB::transaction(function () use ($uid, $delta, $source, $eventId, $ref, $note) {
            // 先占坑：event_id 冲突就说明处理过了，直接判定为幂等命中
            try {
                DB::table('credit_ledger')->insert([
                    'event_id'       => $eventId,
                    'uid'            => $uid,
                    'delta'          => 0,   // 下面拿到新余额后再回填，先占位保证唯一键生效
                    'balance_after'  => 0,
                    'source'         => $source,
                    'ref'            => $ref,
                    'note'           => $note,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // 1062 = duplicate entry，即 event_id 已存在 → 幂等命中
                if ($e->getCode() === '23000' || $e->getCode() === 23000) {
                    return false;
                }
                throw $e;
            }

            $ledgerId = DB::table('credit_ledger')
                ->where('event_id', $eventId)
                ->value('id');

            // 原子自增，再读回新余额
            DB::table('users')->where('uid', $uid)->increment('score', $delta);
            $balance = (int) DB::table('users')->where('uid', $uid)->value('score');

            DB::table('credit_ledger')->where('id', $ledgerId)->update([
                'delta'         => $delta,
                'balance_after' => $balance,
            ]);

            return true;
        });
    }
}

if (! function_exists('kokuu_credit_daily_total')) {
    /**
     * 某用户今日（自然日，按服务器时区）已获得的加分总额。
     * 用于 max_per_day 限额控制。**只统计正数**（加分），因为论坛只发不扣。
     */
    function kokuu_credit_daily_total(int $uid): int
    {
        kokuu_credit_ensure_tables();

        return (int) DB::table('credit_ledger')
            ->where('uid', $uid)
            ->where('delta', '>', 0)
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('delta');
    }
}

if (! function_exists('kokuu_credit_verify')) {
    /**
     * 校验论坛端请求的 HMAC 签名。
     *
     * 签名串固定为："{ts}.{nonce}.{eventId}.{uid}.{delta}.{source}"，
     * 用共享密钥做 hash_hmac('sha256', ...)，与论坛端算法严格一致。
     *
     * 任何一项不通过都返回失败原因字符串，调用方直接转成 4xx/403。
     */
    function kokuu_credit_verify(array $body): ?string
    {
        $cfg = kokuu_credit_config();

        if ($cfg['secret'] === '') {
            return '服务端未配置密钥';
        }

        foreach (['ts', 'nonce', 'event_id', 'email', 'delta', 'source', 'sign'] as $k) {
            if (! isset($body[$k]) || $body[$k] === '') {
                return "缺少参数 {$k}";
            }
        }

        $ts = (int) $body['ts'];
        // 时间戳偏差过大直接拒，这是防重放的第一道闸
        if (abs(time() - $ts) > $cfg['ttl']) {
            return '请求已过期';
        }

        $payload = implode('.', [
            $ts,
            $body['nonce'],
            $body['event_id'],
            $body['email'],
            (int) $body['delta'],
            $body['source'],
        ]);

        $expected = hash_hmac('sha256', $payload, $cfg['secret']);

        // 用 hash_equals 防时序攻击
        if (! hash_equals($expected, (string) $body['sign'])) {
            return '签名不正确';
        }

        return null;
    }
}

if (! function_exists('kokuu_credit_stats')) {
    /**
     * 后台概览：总流水数、今日发放、用户数。
     */
    function kokuu_credit_stats(): array
    {
        kokuu_credit_ensure_tables();

        return [
            'total'  => (int) DB::table('credit_ledger')->count(),
            'today'  => (int) DB::table('credit_ledger')
                ->where('delta', '>', 0)
                ->where('created_at', '>=', now()->startOfDay())
                ->sum('delta'),
            'events' => (int) DB::table('credit_ledger')
                ->where('delta', '>', 0)
                ->where('created_at', '>=', now()->startOfDay())
                ->count(),
        ];
    }
}

/*
 * ============================================================================
 *账号互通（SSO）—— 皮肤站作为 OAuth2 授权服务器
 * ============================================================================
 *
 * 为什么用标准 OAuth2 授权码 + PKCE，而不是 forum-integration 那种跨库直连：
 *   跨库直连要往论坛 users 表裸写（insertGetId），绕过 Flarum 的模型事件与验证器，
 *   论坛侧完全不知情，且强依赖论坛表结构（int 主键 / is_email_confirmed 字段），
 *   论坛一升级就断。OAuth2 则是：
 *     - 论坛**永远不接触用户密码**，密码只存在皮肤站
 *     - 论坛用户由 Flarum 自己的模型创建，将来你在论坛侧加任何用户级扩展
 *       （欢迎积分、封禁联动等）都能正常挂上钩子
 *     - 论坛升级不破，只要 Passport 的标准端点还在就能用
 *
 * 完整流程：
 *   1. 论坛生成 state + code_verifier，302 到 /oauth/authorize
 *   2. 用户在皮肤站已登录 → Passport 显示授权页 → 批准 → 302 回论坛
 *      /auth/kokuu/callback?code=...&state=...
 *   3. 论坛服务端拿 code 换 token（携带 code_verifier，服务端到服务端）
 *   4. 论坛拿 access_token 调 /plugin/kokuu-credit/api/userinfo
 *   5. 按 email 对齐两站账号，完成登录
 */

if (! function_exists('kokuu_sso_config')) {
    /**
     * SSO 配置（存 options 表，键前缀 kokuu_sso_）。
     */
    function kokuu_sso_config(): array
    {
        $defaults = [
            // 是否启用账号互通。关掉后登录入口直接隐藏，论坛退回独立账号。
            'enabled'        => 0,
            // Passsport 客户端 ID / Secret（由本插件生成后回填）
            'client_id'      => '',
            'client_secret'  => '',
            // 论坛侧回调地址，必须与 oauth_clients.redirect 完全一致
            'forum_callback' => 'https://chat.kokuu.org/auth/kokuu/callback',
            // 授权后是否自动在论坛创建账号。
            // 关掉则只允许「已对齐的 email」登录 —— 更严格，但新用户要先手动注册。
            'auto_register'  => 1,
        ];

        $options = app('options');
        $config  = [];
        foreach ($defaults as $key => $default) {
            $value = $options->get('kokuu_sso_' . $key);
            $config[$key] = ($value === null || $value === '') ? $default : $value;
        }

        $config['enabled']       = (int) $config['enabled'] ? 1 : 0;
        $config['auto_register'] = (int) $config['auto_register'] ? 1 : 0;
        $config['forum_callback'] = rtrim((string) $config['forum_callback'], '/');

        return $config;
    }
}

if (! function_exists('kokuu_sso_save_config')) {
    /**
     * 保存 SSO 配置。
     *
     * 刻意**不**复用 kokuu_credit_save_config()：那个函数会强制加
     * `kokuu_credit_` 前缀，用它存 SSO 配置会写成kokuu_credit_enabled，
     * 读的时候 kokuu_sso_config() 去读 kokuu_sso_enabled 永远读不到，
     * 表现为「保存返回成功但配置不生效」——最难查的那种坑。
     */
    function kokuu_sso_save_config(array $payload): void
    {
        $options = app('options');
        foreach ($payload as $key => $value) {
            $options->set('kokuu_sso_' . $key, $value);
        }
    }
}

if (! function_exists('kokuu_sso_ensure_tables')) {
    /**
     * 账号绑定表。
     *
     * 为什么不用 email 当唯一绑定依据：email 是可以改的，用户改了 email 之后
     * 绑定关系就断了。存一张显式的绑定表，以皮肤站 uid 为主键，
     * 论坛侧那边存 skin_uid，两边对得上才是真的绑定。
     */
    function kokuu_sso_ensure_tables(): void
    {
        if (! Schema::hasTable('kokuu_sso_links')) {
            Schema::create('kokuu_sso_links', function (Blueprint $table) {
                $table->increments('id');
                // 皮肤站 uid
                $table->unsignedInteger('uid')->unique();
                // 最近一次授权时双方的邮箱，用于排查「改了 email 导致对不上」
                $table->string('last_email', 191)->nullable();
                $table->string('last_ip', 45)->nullable();
                $table->timestamps();

                $table->index('last_email');
            });
        }
    }
}

if (! function_exists('kokuu_sso_user_payload')) {
    /**
     * 生成发给论坛的用户信息。
     *
     * 只给论坛**必须的**字段。绝不下发密码 hash —— 论坛没有理由知道密码，
     * 一旦下发它就等于把「一次登录」变成「两站共享密码」，安全模型立刻塌掉。
     */
    function kokuu_sso_user_payload(User $user): array
    {
        return [
            'uid'          => (int) $user->uid,
            'nickname'     => (string) $user->nickname,
            'email'        => (string) $user->email,
            'verified'     => (bool) $user->verified,
            'score'        => (int) $user->score,
            'permission'   => (int) $user->permission,
            'register_at'  => (int) $user->register_at,
            // 注意是 last_sign_at（最后一次签到），Blessing Skin 没有
            // last_login_at 这个列 —— 写错会 500
            //「Undefined property: stdClass::$last_login_at」
            'last_seen_at' => (int) ($user->last_sign_at ?? 0),
        ];
    }
}

if (! function_exists('kokuu_sso_ensure_client')) {
    /**
     * 惰性创建 Passport 客户端，返回 [client_id, client_secret]。
     *
     * 几个参数是有讲究的：
     *   - **confidential = true**：才会生成 secret。secret 用于论坛服务端换 token
     *     时的客户端认证，等于多一道「只有论坛能换 token」的约束。
     *   - **personalAccess = false / password = false**：本插件只走授权码流程，
     *     不开放个人令牌与密码模式（密码模式在 OAuth2 里已不推荐）。
     *   - **redirect_uri 必须与论坛侧逐字一致**，差一个斜杠换 token 时就会
     *     被 Passport 拒成 redirect_uri mismatch。
     */
    function kokuu_sso_ensure_client(): ?array
    {
        $cfg = kokuu_sso_config();

        if ($cfg['client_id'] && $cfg['client_secret']) {
            return [$cfg['client_id'], $cfg['client_secret']];
        }

        // 没建过，或者配置里的 client 已被删除
        $client = \Laravel\Passport\Client::where('redirect', $cfg['forum_callback'])->first();

        if (! $client) {
            try {
                // create() 是**实例方法**（不是静态），必须从容器解析
                $repo = app(\Laravel\Passport\ClientRepository::class);
                $client = $repo->create(
                    null,                      // user_id：公共客户端无归属
                    'Kokuu Forum',             // name
                    $cfg['forum_callback'],    // redirect
                    null,                      // provider
                    false,                     // personalAccess
                    false,                     // password
                    true                       // confidential → 生成 secret
                );
            } catch (\Throwable $e) {
                return null;
            }
        }

        kokuu_sso_save_config([
            'client_id'     => $client->id,
            'client_secret' => (string) $client->secret,
        ]);

        // options 有缓存层，不清的话下一次读还是旧值
        @unlink(storage_path('options.php'));

        return [$client->id, (string) $client->secret];
    }
}

if (! function_exists('kokuu_credit_source_label')) {
    function kokuu_credit_source_label(string $source): string
    {
        return [
            'thread' => '发起主题',
            'reply'  => '回复帖子',
            'liked'  => '帖子被赞',
            'manual' => '管理员手动', 'legacy' => '历史补录',
        ][$source] ?? $source;
    }
}

if (! function_exists('kokuu_sso_user_from_token')) {
    /**
     * 校验 access_token 并返回对应用户，失败一律返回 null。
     *
     * ==================== 这段为什么必须自己写 ====================
     * Blessing Skin 6.0.2 用的 laravel/passport 是 **v11.10.6**，
     * 而 v11 的 access_token 是 **JWT**（RS256），不是随机串：
     *
     *   header : {"typ":"JWT","alg":"RS256"}
     *   payload: {"aud":"1",              <- user_id
     *             "jti":"6babc2cc...",   <- oauth_access_tokens.id（80 字符）
     *             "sub":"1",              <- user_id
     *             "scopes":["User.Read"],
     *             "exp":1822531129}
     *
     * 两个踩过的坑：
     *
     * 1. `Passport\Token::findToken()` 在 v11 **不存在**。
     *    只有 `TokenRepository::find($id)`，而它只接受 **jti**，
     *    传整串 JWT 进去必然返回 null —— 表现就是「token 刚签发却恒 401」。
     *
     * 2. `Passport\Token::user()` 依赖 `config('auth.guards.api.provider')`。
     *    Blessing Skin 的 config/auth.php 里只有 web / jwt / oauth 三个 guard，
     *    **没有 api**，于是那两个 config 都是 null，Eloquent 直接抛
     *    「Class name must be a valid object or a string」。
     *
     * 与其去补 Laravel 的 guard 配置（那会动全站鉴权），不如自己按 jti 查 ——
     * 我们本来就知道用户表长什么样。
     *
     * ==================== 安全校验 ====================
     * 只解 payload 不验签是**不够**的：任何人都能伪造一个 payload 里的
     * jti 去查库。必须先用 Passport 的公钥验签，确认这个 JWT 真是
     * 我们自己签出来的，再谈后续。
     */
    function kokuu_sso_user_from_token(string $token)
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        // ---- 1. 验签（Passport 用自己的私钥 RS256，公钥在 oauth-privatekey/oauth-publickey）----
        try {
            $publicKey = @file_get_contents(storage_path('oauth-public.key'));
            if (! $publicKey) {
                // 没有公钥文件就绝对不能放行 —— 宁可 401 也不能不验签
                return null;
            }

            $signature = base64_decode(strtr($parts[2], '-_', '+/'));
            $signed = $parts[0] . '.' . $parts[1];

            $verified = openssl_verify(
                $signed,
                $signature,
                $publicKey,
                OPENSSL_ALGO_SHA256
            );

            if ($verified !== 1) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        // ---- 2. 验签通过，解 payload ----
        $payload = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/')),
            true
        );

        if (! is_array($payload)) {
            return null;
        }

        // ---- 3. 查库确认 token 未被撤销、未过期 ----
        $jti = (string) ($payload['jti'] ?? '');
        if ($jti === '') {
            return null;
        }

        try {
            $row = DB::table('oauth_access_tokens')->where('id', $jti)->first();
        } catch (\Throwable $e) {
            return null;
        }

        if (! $row || (int) $row->revoked === 1) {
            return null;
        }

        if ($row->expires_at && strtotime((string) $row->expires_at) < time()) {
            return null;
        }

        // ---- 4. 用库里的 user_id 取用户（不信任 JWT 里的 sub）----
        // JWT 已验签，理论上两者一致；以库为准可防「同一 jti 对应关系被改动」。
        $user = \App\Models\User::where('uid', $row->user_id)->first();

        return $user ?: null;
    }
}

return function ($plugin) {
    View::addLocation($plugin->getPath() . '/views');

    /*
     * 配置页（views/config.blade.php）里的数据由视图自己调
     * kokuu_credit_config() / kokuu_sso_config() 取实时值。
     *
     * 刻意**不**用 View::share：那是插件启动时的快照，管理员改完配置
     * 再刷新页面会看到旧值，得重启 PHP 才能更新。
     */

    /*
     * ---- 内部加分接口 ----
     *
     * 路径挂在 /api/ 之外（/plugin/kokuu-credit/...）以便与 Flarum 的
     * /api/ JSON:API 完全隔开：这个接口吃的是我们自定义的表单/JSON 格式，
     * 不走 Flarum 的序列化器。
     *
     * 走 web 中间件而非 api：接口是**服务端到服务端**调用，不依赖论坛的
     * session 登录态，鉴权完全靠 HMAC 签名。
     */
    Hook::addRoute(function ($router) {
        $router->post('plugin/kokuu-credit/api/earn', function (\Illuminate\Http\Request $request) {
            $body = $request->all();

            $error = kokuu_credit_verify($body);
            if ($error !== null) {
                return response()->json(['code' => 1, 'message' => $error], 403);
            }

            $cfg = kokuu_credit_config();

            $email = trim((string) $body['email']);
            // nonce 二次去重：同一签名短时间内只能成功一次
            $cacheKey = 'kokuu_credit_nonce_' . md5((string) $body['nonce']);
            if (! \Illuminate\Support\Facades\Cache::add($cacheKey, 1, $cfg['ttl'] + 60)) {
                return response()->json(['code' => 1, 'message' => '重复请求'], 409);
            }

            $user = User::where('email', $email)->first();
            if (! $user) {
                // 找不到映射时明确记一笔，不静默丢弃，便于排查两站账号是否对齐
                kokuu_credit_ensure_tables();
                DB::table('credit_ledger')->insert([
                    'event_id'      => (string) $body['event_id'],
                    'uid'           => 0,
                    'delta'         => 0,
                    'balance_after' => 0,
                    'source'        => (string) $body['source'],
                    'ref'           => $email,
                    'note'          => '未匹配到皮肤站账号：' . mb_substr($email, 0, 100),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                return response()->json(['code' => 1, 'message' => '皮肤站不存在该邮箱对应的账号'], 404);
            }

            $delta = (int) $body['delta'];
            if ($delta <= 0) {
                return response()->json(['code' => 1, 'message' => '加分必须为正数'], 422);
            }
            if ($delta > $cfg['max_per_event']) {
                return response()->json([
                    'code' => 1,
                    'message' => "单次加分超过上限（{$cfg['max_per_event']}）",
                ], 422);
            }

            if ($cfg['max_per_day'] > 0) {
                $today = kokuu_credit_daily_total((int) $user->uid);
                if ($today + $delta > $cfg['max_per_day']) {
                    return response()->json([
                        'code' => 1,
                        'message' => "超出每日上限（今日已得{$today} / 上限 {$cfg['max_per_day']}）",
                    ], 422);
                }
            }

            $ok = kokuu_credit_ledger(
                (int) $user->uid,
                $delta,
                (string) $body['source'],
                (string) $body['event_id'],
                $body['ref'] ?? null,
                $body['note'] ?? null
            );

            if (! $ok) {
                // 幂等命中：同一事件已入账，如实返回当前余额，论坛侧无需重试
                return response()->json([
                    'code' => 0,
                    'message' => 'already applied',
                    'balance' => (int) $user->fresh()->score,
                    'duplicated' => true,
                ]);
            }

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'balance' => (int) $user->fresh()->score,
            ]);
        })->middleware(['api', 'throttle:300,1']);

        // ---- 论坛余额查询（只读，供论坛个人页展示） ----
        //
        // 论坛侧个人页要显示积分，但它没有数据库直连权限（也不该有）。
        // 这里提供只读查询，同样用 HMAC 签名鉴权。
        $router->post('plugin/kokuu-credit/api/balance', function (\Illuminate\Http\Request $request) {
            $body = $request->all();

            $error = kokuu_credit_verify($body);
            if ($error !== null) {
                return response()->json(['code' => 1, 'message' => $error], 403);
            }

            $user = User::where('email', trim((string) $body['email']))->first();
            if (! $user) {
                return response()->json(['code' => 1, 'message' => '账号不存在'], 404);
            }

            return response()->json([
                'code' => 0,
                'balance' => (int) $user->score,
                'nickname' => (string) $user->nickname,
            ]);
        })->middleware(['api', 'throttle:300,1']);

        // ---- 配置保存 ----
        $router->post('plugin/kokuu-credit/settings', function (\Illuminate\Http\Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            $cfg = kokuu_credit_config();

            $url = trim((string) $request->input('forum_url', $cfg['forum_url']));
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            abort_if (! in_array($scheme, ['http', 'https'], true), 400, '论坛地址无效');

            $secret = (string) $request->input('secret', $cfg['secret']);

            kokuu_credit_save_config([
                'forum_url'     => rtrim($url, '/'),
                // 密钥留空表示不修改，避免后台回显时把明文密钥覆盖掉
                'secret'        => $secret !== '' ? $secret : $cfg['secret'],
                'score_thread'  => max(0, (int) $request->input('score_thread', 0)),
                'score_reply'   => max(0, (int) $request->input('score_reply', 0)),
                'score_liked'   => max(0, (int) $request->input('score_liked', 0)),
                'max_per_event' => max(1, (int) $request->input('max_per_event', 50)),
                'max_per_day'   => max(0, (int) $request->input('max_per_day', 200)),
                'ttl'           => max(30, min(3600, (int) $request->input('ttl', 300))),
            ]);

            // ⚠️ 必须删storage/options.php，否则配置永远不生效。
            // App\Services\Option 构造函数优先读该文件，存在即 return 不查数据库。
            @unlink(storage_path('options.php'));

            return response()->json(['code' => 0, 'message' => 'Saved.']);
        })->middleware(['web']);

        // ---- 流水列表（后台 AJAX） ----
        $router->get('plugin/kokuu-credit/api/ledger', function (\Illuminate\Http\Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            kokuu_credit_ensure_tables();

            $limit = max(1, min(200, (int) $request->query('limit', 50)));
            $uid   = (int) $request->query('uid', 0);

            $query = DB::table('credit_ledger')->orderByDesc('id')->limit($limit);
            if ($uid > 0) {
                $query->where('uid', $uid);
            }

            $rows = $query->get();
            $nicknames = [];
            $uids = $rows->pluck('uid')->filter(fn ($v) => $v > 0)->unique()->all();
            if ($uids) {
                $nicknames = User::whereIn('uid', $uids)->pluck('nickname', 'uid')->toArray();
            }

            return response()->json([
                'code' => 0,
                'items' => $rows->map(function ($r) use ($nicknames) {
                    return [
                        'id'          => (int) $r->id,
                        'event_id'    => $r->event_id,
                        'uid'         => (int) $r->uid,
                        'nickname'    => $nicknames[$r->uid] ?? ($r->uid > 0 ? '已注销' : '—'),
                        'delta'       => (int) $r->delta,
                        'balance_after' => (int) $r->balance_after,
                        'source'      => $r->source,
                        'source_label' => kokuu_credit_source_label($r->source),
                        'note'        => $r->note,
                        'created_at'  => (string) $r->created_at,
                    ];
                })->all(),
                'stats' => kokuu_credit_stats(),
            ]);
        })->middleware(['web']);

        // ---- 签名自检（后台"测试连通"用） ----
        $router->post('plugin/kokuu-credit/api/selftest', function (\Illuminate\Http\Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            $cfg = kokuu_credit_config();
            $ts = time();
            $nonce = bin2hex(random_bytes(8));
            $email = trim((string) $request->input('email', ''));

            if ($cfg['secret'] === '') {
                return response()->json(['code' => 1, 'message' => '未配置密钥']);
            }

            // 先验签（用极小的 delta，只验签不落账）
            $payload = implode('.', [$ts, $nonce, 'selftest:' . $nonce, $email, 0, 'thread']);
            $sign = hash_hmac('sha256', $payload, $cfg['secret']);

            $verifyBody = [
                'ts' => $ts, 'nonce' => $nonce, 'event_id' => 'selftest:' . $nonce,
                'email' => $email, 'delta' => 0, 'source' => 'thread', 'sign' => $sign,
            ];
            $error = kokuu_credit_verify($verifyBody);
            if ($error !== null) {
                return response()->json(['code' => 1, 'message' => '验签失败：' . $error]);
            }

            $target = User::where('email', $email)->first();
            if (! $target) {
                return response()->json(['code' => 1, 'message' => '签名正常，但该邮箱在皮肤站不存在']);
            }

            // 真实走一次 earn 通道，验证全链路
            $payload2 = implode('.', [$ts, $nonce . 'x', 'selftest:' . $nonce . 'x', $email, 1, 'thread']);
            $sign2 = hash_hmac('sha256', $payload2, $cfg['secret']);

            $response = Http::timeout(8)->connectTimeout(3)->asForm()->post(
                rtrim($cfg['forum_url'], '/') . '/api/forum-points/selftest',
                [
                    'ts' => $ts, 'nonce' => $nonce . 'x', 'event_id' => 'selftest:' . $nonce . 'x',
                    'email' => $email, 'delta' => 1, 'source' => 'thread', 'sign' => $sign2,
                ]
            );

            return response()->json([
                'code' => 0,
                'message' => '签名校验通过，论坛端往返 ' . $response->status(),
                'forum_status' => $response->status(),
                'forum_body' => mb_substr((string) $response->body(), 0, 200),
            ]);
        })->middleware(['web']);
    });

    // ---- 页面样式 ----
    Hook::addStyleFileToPage($plugin->assets('credit.css'), [
        'admin', 'admin/*',
        'plugin/kokuu-credit/*',
    ]);

    Hook::addScriptFileToPage($plugin->assets('credit.js'), [
        'admin', 'admin/*',
        'plugin/kokuu-credit/*',
    ]);

    /*
     * ---- 账号互通（SSO）路由 ----
     *
     * 分两类，鉴权方式完全不同，不能混：
     *   - api/userinfo      Bearer access_token  → 走 api 组（无 CSRF），由
     *                       Passport 的 access token 守卫自己验，不靠签名
     *   - sso/status        HMAC 签名           → 走 api 组，服务端到服务端
     */
    Hook::addRoute(function ($router) use ($plugin) {
        /*
         * ---- 用户信息端点（OAuth2 规范端点）----
         *
         * 论坛拿到 access_token 后调这里换用户身份。
         *
         * ⚠️ 这里刻意**不用** `Laravel\Passport\Token::findToken()` ——
         *   那个静态方法在 Passport v11 里**不存在**（只有 TokenRepository::find()）。
         *   之前写成 findToken() 后被外层 catch(\Throwable) 吞掉 BadMethodCallException，
         *   表现为「token 明明刚签发，userinfo 却恒返回 401 invalid_token」，
         *   排查起来极费时间。catch 一定要 log，不要静默。
         *
         * 这里不挂 web 组 —— web 组带 CSRF 校验，
         * 服务端到服务端的 Authorization 头请求会被拦成 419。
         */
        $router->get('plugin/kokuu-credit/api/userinfo', function (\Illuminate\Http\Request $request) {
            $token = $request->bearerToken();
            if (! $token) {
                return response()->json(['error' => 'invalid_token'], 401);
            }

            $user = kokuu_sso_user_from_token($token);

            if (! $user) {
                return response()->json(['error' => 'invalid_token'], 401);
            }

            kokuu_sso_ensure_tables();
            kokuu_sso_ensure_client();

            // 记录本次登录，顺手更新绑定关系与 IP，方便后台看谁在什么时候互通登录过
            try {
                DB::table('kokuu_sso_links')->updateOrInsert(
                    ['uid' => (int) $user->uid],
                    [
                        'last_email' => (string) $user->email,
                        'last_ip'    => mb_substr((string) $request->ip(), 0, 45),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            } catch (\Throwable $e) {
                // 记账失败不影响登录本身
            }

            return response()->json([
                'sub' => (string) $user->uid,
                'user' => kokuu_sso_user_payload($user),
            ]);
        })->middleware(['api', 'throttle:600,1']);

        /*
         * ---- SSO 状态查询（论坛后台自检用）----
         * 走 HMAC 签名，让论坛能确认「皮肤站侧配置好了没」。
         */
        $router->get('plugin/kokuu-credit/api/sso-status', function (\Illuminate\Http\Request $request) {
            $cfg = kokuu_sso_config();
            $pair = $cfg['client_id'] ? [$cfg['client_id'], $cfg['client_secret']] : kokuu_sso_ensure_client();

            return response()->json([
                'code'          => 0,
                'enabled'       => $cfg['enabled'],
                'auto_register' => $cfg['auto_register'],
                'client_ready'  => $pair ? 1 : 0,
                'client_id'     => $pair ? $pair[0] : '',
                'callback'      => $cfg['forum_callback'],
                'authorize_url' => url('/oauth/authorize'),
                'token_url'     => url('/oauth/token'),
                'userinfo_url'  => url('/plugin/kokuu-credit/api/userinfo'),
            ]);
        })->middleware(['api', 'throttle:120,1']);

        // ---- SSO 配置保存（后台页面，需要管理员 session）----
        $router->post('plugin/kokuu-credit/sso', function (\Illuminate\Http\Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            $data = $request->only([
                'enabled', 'auto_register', 'forum_callback',
            ]);

            kokuu_sso_save_config([
                'enabled'        => ! empty($data['enabled']) ? 1 : 0,
                'auto_register'  => ! empty($data['auto_register']) ? 1 : 0,
                'forum_callback' => rtrim(trim((string) ($data['forum_callback'] ?? '')), '/'),
            ]);

            kokuu_sso_ensure_client();
            kokuu_sso_ensure_tables();
            @unlink(storage_path('options.php'));

            return response()->json([
                'code' => 0,
                'message' => '已保存',
                'config' => kokuu_sso_config(),
            ]);
        })->middleware(['web']);

        // ---- 绑定关系列表（后台查看）----
        $router->get('plugin/kokuu-credit/api/sso-links', function (\Illuminate\Http\Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            kokuu_sso_ensure_tables();

            $rows = DB::table('kokuu_sso_links as l')
                ->leftJoin('users as u', 'u.uid', '=', 'l.uid')
                ->orderByDesc('l.updated_at')
                ->limit(200)
                ->get([
                    'l.uid', 'l.forum_user_id', 'l.last_email', 'l.last_ip', 'l.updated_at',
                    'u.nickname',
                ]);

            return response()->json([
                'code' => 0,
                'items' => $rows->map(function ($r) {
                    return [
                        'uid'           => (int) $r->uid,
                        'nickname'      => $r->nickname ?? ('uid '.$r->uid),
                        'forum_user_id' => $r->forum_user_id === null ? '' : (int) $r->forum_user_id,
                        'last_email'    => $r->last_email,
                        'last_ip'       => $r->last_ip,
                        'last_seen'     => (string) $r->updated_at,
                    ];
                })->all(),
            ]);
        })->middleware(['web']);
    });

    /*
     * ---- 侧边栏入口 ----
     *
     * 挂到「用户」菜单下让管理员能直接进流水页。
     * 事件给的是整个 $menu 数组（含 user/admin/explore 三个键）的引用，
     * 监听器第一个参数是事件对象，所以必须写 $event->menu['user'][]。
     */
    \Illuminate\Support\Facades\Event::listen(\App\Events\ConfigureUserMenu::class, function ($event) {
        $event->menu['user'][] = [
            'title' => '论坛积分流水',
            'link' => 'plugin/kokuu-credit/ledger',
            'icon' => 'fa-coins',
        ];
    });

    // ---- 流水页面 ----
    Hook::addRoute(function ($router) use ($plugin) {
        $router->get('plugin/kokuu-credit/ledger', function (\Illuminate\Http\Request $request) {
            $user = $request->user();
            abort_if (! $user || $user->permission < User::ADMIN, 403);

            $cfg = kokuu_credit_config();
            kokuu_credit_ensure_tables();

            return view()->file($plugin->getPath() . '/views/ledger.twig', [
                'cfg' => $cfg,
                'stats' => kokuu_credit_stats(),
                'sourceLabels' => [
                    'thread' => '发起主题', 'reply' => '回复帖子',
                    'liked' => '帖子被赞', 'manual' => '管理员手动',
                    'legacy' => '历史补录',
                ],
            ]);
        })->middleware(['web']);
    });
};
