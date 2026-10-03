# kokuu-credit · 论坛积分账本 + 账号互通（OAuth2 服务端）

Blessing Skin（皮肤站）侧的**唯一积分账本**，同时是两站账号互通的 **OAuth2 授权服务器**。

- 论坛发帖/回复产生的加分事件，通过 HMAC 签名接口写入本插件，落`users.score` 并留`credit_ledger` 流水
- 论坛只发不扣（扣分场景如建角色/衣柜/兑换仍走内核原有逻辑）
- 账号互通：论坛作为 OAuth2 客户端，本插件基于内核自带的 Passport 提供授权码 + PKCE 流程

![Blessing Skin](https://img.shields.io/badge/Blessing%20Skin-6.0-0FA3A3?style=flat-square)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-8B7FD4?style=flat-square)
![版本](https://img.shields.io/badge/version-1.0.0-8B7FD4?style=flat-square)

---

## 一、积分账本

### 为什么账本放皮肤站而不是论坛

论坛的定位是「**加分事件来源**」，不是账本。皮肤站的 `users.score` 是唯一事实来源：

- 扣分场景（建角色、衣柜、兑换）全在皮肤站，账本自然在那边
- 论坛只读展示余额，**没有任何扣分入口**——`Pusher` 侧`delta <= 0` 直接拒
- 账本只有一份，就不会有「两边余额对不上」的问题

### 加分接口

```
POST /plugin/kokuu-credit/api/earn
```

服务端到服务端调用，带 HMAC-SHA256 签名：

| 参数 | 说明 |
|------|------|
| `ts` | 时间戳，超时直接拒（防重放） |
| `nonce` | 随机串，首次 200，二次 **409** |
| `event_id` | 幂等键，形如 `thread:9` / `reply:33`，重复返回 `already applied` |
| `email` | 论坛用户邮箱，用于定位皮肤站账号 |
| `delta` | 加分值，**单笔上限**超了 422 |
| `source` | `thread` / `reply` / `liked` |
| `ref` | 来源引用，如 `/d/10#post-33` |
| `sign` | 签名 |

`nonce` + `event_id` 双层防重：前者防「同一次请求重放」，后者防「不同请求刷同一事件」。

### 失败时的行为

未知邮箱返回 **404**，且会**记一笔 `delta=0` 流水**便于排查——
不记的话线上出问题完全查不到是哪一步断的。

写入失败时论坛侧落`pending` 记录，由 `kokuu-points:retry` 定时任务（每 5 分钟）自动补推。

### 路由

| 路径 | 用途 | 中间件 |
|------|------|--------|
| `api/earn` | 论坛加分上报 | `api` |
| `api/balance` | 查询余额 | `api` |
| `api/ledger` | 流水分页 | `api` |
| `api/selftest` | 连通性自检 | `web` |
| `settings` | 后台配置页 | `web` |
| `ledger` | 流水页面 | `web` |
| `sso` | SSO 配置区块 | `web` |
| `api/sso-status` | SSO 状态查询 | `api` |
| `api/sso-links` | 账号绑定列表 | `web` |
| **`api/userinfo`** | **OAuth2 userinfo 端点** | `api` |

⚠️ **服务端到服务端的 API 路由不能用 `web` 中间件** ——
`VerifyCsrfToken` 会拦成 `419 Page Expired`。后台页面保留 `web`（需要 session）。

---

## 二、账号互通（OAuth2）

### 为什么不用跨库直连

内核市场有 `forum-integration` 插件直接读写论坛表。**评估后否决**：

- 跨库裸写论坛表，绕过 Flarum ORM 与模型事件
- 靠 `config('secure.cipher') === 'BCRYPT'` 猜对面是不是 Flarum
- 硬编码 `is_email_confirmed` / `joined_at` / `regip` 等字段
- 密码 hash 跨库搬运，两站 hasher 配置不同会静默不一致

改为标准 OAuth2：论坛侧走 Flarum 模型建号，事件、监听器、将来扩展的钩子都能挂上。

### 授权流程

```
浏览器                     论坛                          皮肤站
  │                        │                              │
  │ 点「用皮肤站账号登录」  │                              │
  ├───────────────────────>│                              │
  │                        │ 302 /auth/kokuu              │
  │                        │  (生成 state + PKCE)         │
  │                        ├─────────────────────────────>│
  │                        │                              │ 登录 + 显示授权页
  │                        │                              │
  │<─────────────────────────────────────────────────────┤
  │   302 /oauth/authorize?client_id=1&scope=User.Read   │
  │                        │                              │
  │ 登录 / 点「授权」───────────────────────────────────>│
  │<─────────────────────────────────────────────────────┤
  │   302 /auth/kokuu/callback?code=...&state=...        │
  │                        │                              │
  │                        │ 换 token（带 client_secret）  │
  │                        ├─────────────────────────────>│
  │                        │<─────────────────────────────┤
  │                        │  取 userinfo（Bearer token）  │
  │                        ├─────────────────────────────>│
  │                        │<─────────────────────────────┤
  │<───建立论坛登录态──────┤  对齐账号 → SessionAccessToken │
```

- **scope** = `User.Read`（最小权限，只读身份）
- **PKCE S256 强制**，无`code_verifier` 一律拒绝
- `state` 存 session，回调时 `hash_equals` 逐字比对，**取出即清**（一次性），TTL 600 秒
- `client_secret` 只存 options 表，**不进任何前端资源**

### userinfo 端点为什么要自己写

内核的 `Passport\Token::user()` 在这套环境里**用不了**：

它依赖 `config('auth.guards.api.provider')` 和 `config('auth.providers.*.model')`，
而 Blessing Skin 只有 `web` / `jwt` / `oauth` 三个 guard，**没有 `api`**，
两个 config 都是 null → Eloquent 抛 `Class name must be a valid object or a string`。

而且 `Passport\Token::findToken()` 在 Passport v11 **已被移除**
（只剩 `TokenRepository::find($id)`），外层 `catch` 会把它**静默吞掉**，
伪装成「token 无效」——排查时极具误导性。

access_token 本身是 **JWT（RS256，约 995 字符 3 段）** 而非随机串，
`TokenRepository::find()` 只接受 `jti`（80 字符），传整串 JWT 必然返回 null。

所以 `kokuu_sso_user_from_token()` 自己实现了完整校验链：

1. `openssl_verify` 用 `storage/oauth-public.key` 验签
2. 解 JWT payload，取 `jti`
3. 查 `oauth_access_tokens` 校验 `revoked` / `expires_at`
4. **用库里的 `user_id` 查用户，不信任 JWT 的 `sub`**

公钥文件读不到时**必须返回 null 拒绝**——没有验签就放行等于不设防。

### 返回字段

```json
{
  "sub": "1",
  "user": {
    "uid": 1, "nickname": "Kokuu", "email": "kokuu@kokuu.local",
    "verified": true, "score": 22, "permission": 2,
    "register_at": 2026, "last_seen_at": 2026
  }
}
```

**不含 `password`**。`last_seen_at` 读的是 `users.last_sign_at`
（最后一次签到）——Blessing Skin **没有 `last_login_at` 列**，写错会 500。

---

## 踩过的坑

### 1. `Hook::addRoute` 里的闭包要`use ($plugin)`

```php
Hook::addRoute(function ($router) use ($plugin) {
    $router->get('...', function () use ($plugin) { /* ... */ });
});
```

不写 `use ($plugin)`，命中该路由时会「未定义变量」。

### 2. `Plugin::getConfigView()` 不传任何参数

它是 `view()->file($path)`，不是控制器。所以配置页里的实时值
**不能靠 `View::share`**（那是启动时快照，保存后仍显示旧值），
要用 `@php` 在视图里直接调 `kokuu_credit_config()` / `kokuu_sso_config()`。

### 3. 保存配置后必须删 options 文件缓存

`App\Services\Option` 构造函数**优先读 `storage/options.php`**，存在即 `return`，
根本不查数据库。不删这个文件会出现「保存返回成功但配置永远不变」。

```php
@unlink(storage_path('options.php'));
```

### 4. FormData 对未勾选的 checkbox 不含 key

SSO 配置页的「启用」「自动建号」复选框，保存时必须显式 append
`'0'`/`'1'`，否则未勾选时后端收不到键，会当成缺省值。

### 5. Passport v11 的授权码是加密 blob

`oauth_auth_codes.id` 存的是 `league/oauth2-server` 用 `app.key` **加密后的 JSON**，
直接往表里插明文 code 换 token 会报 `Cannot decrypt the authorization code`。

payload 字段名必须严格是 `expire_time` 而不是 `expires_at`。

### 6. 表名与列名都别猜

| 表 | 关键列 |
|---|---|
| `users` | `uid`(主键) `email` `nickname` `score` `password` `verified` `permission` `register_at` `last_sign_at` |
| `credit_ledger` | `id` `event_id` `uid` `delta` `balance_after` `source` `ref` `note` `created_at` |
| `kokuu_sso_links` | `id` `skin_uid` `forum_user_id` `last_email` `last_ip` `created_at` `updated_at` |

⚠️ 表名是 `credit_ledger` **没有 `kokuu_` 前缀**。
⚠️ 没有 `last_login_at` 列，只有 `last_sign_at`。
⚠️ `credit_ledger` **没有 `reason` 列**，来源是 `source`。

---

## 部署

```bash
cp -r kokuu-credit /var/www/kokuu-auth/plugins/
chown -R www-data:www-data /var/www/kokuu-auth/plugins/kokuu-credit

cd /var/www/kokuu-auth
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan cache:clear
systemctl reload php8.1-fpm
```

⚠️ `artisan` 必须用**FPM 的运行用户**（本机是 `www-data`）。
用别的用户跑会因为读不到 `.env` 而报
`Access denied for user 'forge'@'localhost'`。

## 配置项

| 键 | 默认 | 说明 |
|----|------|------|
| `skin_url` | `https://auth.kokuu.org` | 皮肤站地址 |
| `secret` | 空 | 与论坛侧一致的 HMAC 密钥 |
| `enabled` | `1` | 是否启用积分互通 |
| `score_thread` | `10` | 发帖加分 |
| `score_reply` | `2` | 回复加分 |
| `score_liked` | `0` | 点赞加分（默认关） |
| `max_delta` | `100` | 单笔加分上限，超出返回 422 |
| `sso_enabled` | `0` | 是否启用账号互通 |
| `sso_client_id` | 空 | OAuth 客户端 ID |
| `sso_auto_register` | `1` | 皮肤站账号未注册论坛时是否自动建号 |

## License

MIT
