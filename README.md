# kokuu-credit · 论坛积分账本 + 账号互通（OAuth2 服务端）

Blessing Skin（皮肤站）侧的**唯一积分账本**，同时是两站账号互通的 **OAuth2 授权服务器**。

- 论坛发帖/回复产生的加分事件，通过 HMAC 签名接口写入本插件，落`users.score` 并留`credit_ledger` 流水
- 论坛只发不扣（扣分场景如建角色/衣柜/兑换仍走内核原有逻辑）
- 账号互通：论坛作为 OAuth2 客户端，本插件基于内核自带的 Passport 提供授权码 + PKCE 流程
- 后台仪表盘接管：只保留「积分（含签到）」与「社区互联」两个 Apple 扁平化容器，
  适配桌面 / 移动 / 深色模式

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

## 三、后台仪表盘

本插件**接管**皮肤站后台的「仪表盘」页（`/admin`），
把老皮肤站时代的四个高饱和色块换成两个 Apple 扁平化容器。

### 为什么不留「角色数量」和「存储空间」

这个站现在**只做账号验证 + 积分**，不再托管皮肤。
`sum.players`（角色数量）和 `sum.storage`（存储空间）
依赖的是 `players` 表和纹理托管，跟当前用途完全无关，
留着就永远是两块死数据。原 grid 是：

```
layout:  [[md-6, md-6]]
widgets: [[[usage, notification], [chart]]]
```

`usage` 里就含角色数量和存储空间。现在整个替换为：

```
layout:  [[md-7, md-5]]
widgets: [[[dashboard.credit], [dashboard.interop]]]
```

### 两个容器

| 容器 | 文件 | 内容 |
|---|---|---|
| 积分 | `views/dashboard/credit.twig` | 余额主卡（46px 大数字 + 今日获得/今日笔数 + 签到状态）+ 最近 4 笔变动 |
| 社区互联 | `views/dashboard/interop.twig` | 互通状态 pill、论坛地址、当前账号、加分规则、前往论坛 CTA |

数据由 `kokuu_credit_dashboard_data()` 一次算好，
在 grid 钩子里 `View::share('kcDash', ...)` 挂上，两个 widget 各自读，
**不会重复查库**。

签到状态读 `users.last_sign_at`（datetime）。
⚠️ 别和 `last_seen_at`（int 时间戳，最后一次访问）搞混，两者语义不同。

### 样式

`assets/dash.css`（约 500 行）**只消费** `kokuu-ui/apple.css` 的 CSS 变量
（`--a-*` / `--k-*`），自己不 redefining，深色模式靠 `body.dark-mode` 覆盖。
作用域一律用 `body .kcDash-col` 开头，原因见坑 10。

断点：`1199.98` / `991.98`（AdminLTE 在此以下把 `col-md-*` 变100% 宽，
两容器自然堆叠）/ `575.98`（明细改上下堆叠）/ `359.98`（超窄屏）。
⚠️ **不要动 `.main-sidebar` 的 width**——AdminLTE `sidebar-mini`
在移动端靠 `margin-left: -250px` 折叠，改宽度会多出一条空白宽栏。

已验证：1440px 两栏（647+462）、390px 堆叠、320px 无溢出、深色模式正常。

---

## 四、用户中心仪表盘

后台接管了，普通用户那边原本还是老样子（深色卡片 + 荧光色块）。
现在 `/user` 也走同一套Apple 扁平化。

### 两个钩子，不是��个

后台 `/admin` 和用户中心 `/user` 是**两套完全独立的 grid**，
`AdminController::index()` 和 `UserController::index()`
各调一次自己的 filter，钩子名也不同：

| 页面 | 钩子 |
|---|---|
| 后台 `/admin` | `grid:admin.index` |
| 用户中心 `/user` | `grid:user.index` |

**只接后台，用户中心不会跟着变**——这是最容易漏的一步。
`View::share` 的键也要分开用（`kcDash` / `kcDashUser`），
因为 `View::share` 是全局的、不按页面隔离，共用一个键会互相覆盖。

### 只能增量改 `$grid`，不能整个 return

`grid:user.index` 上挂了**不止一个** Filter 回调：

- 本插件（换掉 usage widget）
- `kokuu-forum`（注入社区 feed `user.widgets.dashboard.forum`，并移除内核 announcement）

多个回调是**链式**的：前一个的返回值作为后一个的输入。
所以必须沿用内核原布局、只换掉左列的 widget：

```php
// 左列：移除 usage（角色数量/存储空间），插入积分卡
$col = array_values(array_filter(
    (array) $grid['widgets'][0][0],
    fn ($w) => $w !== 'user.widgets.dashboard.usage'
));
array_unshift($col, 'KokuuStudio\\KokuuCredit::user.credit');
$grid['widgets'][0][0] = $col;

// 右列一个字都不动
return $grid;
```

⚠️ 用 `array_filter` + `array_values` 压平：`grid.twig` 按下标 foreach，
中间挖元素会留下不连续下标。

### 用户中心只有两个东西

需求原文：「普通用户登录之后，在用户中心的『仪表盘』只看两个东西，
一个是这个积分，一个是现有的社区」。

于是：

| 位置 | 内容 |
|---|---|
| 左列 | 积分卡 `views/user/credit.twig`：余额、今日获得/笔数、签到条、加分规则、前往论坛 CTA |
| 右列 | **kokuu-forum 的社区 feed**（原有插件，本插件不碰） |

**「现有的社区」指的就是 kokuu-forum 那张 feed**，所以本插件
**不再插自己的社区互联卡**，也**不放「最近变动」逐笔列表**——
插进去就是第三个东西，而且因为回调链式、顺序不确定，
还会被顶到长 feed 下面看不见（实测只在右下角露出一角）。
逐笔明细该去的地方是侧栏那个「论坛积分流水」入口。

---

## 五、积分流水页

`/plugin/kokuu-credit/ledger`，admin-only。

### 重写动机

用户反馈：「我并不需要那么复杂的记录，或者把这个积分流水的侧边栏折叠起来」。

重写前是7 列表格：时间 / 用户 / 来源 / 变动 / **变动后余额** / **事件 ID** / **备注**。
后三列是排查幂等问题用的，管理员一年用不到两次，普通用户完全无意义。

### 现在的形态

- **列表从表格换成卡片流**：7 列在 390px 必须横向滚动，
  而积分变动天然是「一条一条的事」，纵向卡片不用滚动就能读完。
- **主行只给三样**：图标 + 来源 + 时间 + 变动。
- **技术信息收进每条记录自己的 `<details>`**（默认收起），
  内含用户 / 变动后余额 / 事件 ID / 备注。
  这就是「折叠起来」的落点——**不是折叠侧边栏**，
  侧边栏是 AdminLTE 骨架，折叠它会影响所有页面。
- **UID 筛选也收进 `<details>`**：它是管理员工具，不是浏览流水时的常用操作。

视觉从「虚空深色」改成与仪表盘一致的 Apple 扁平化。

⚠️ 后台配置页里那 10 行流水**仍然用表格渲染**
（`renderTable()`，管理员核对到账用，信息密度优先），
与流水页的 `renderRecords()` 并存。
⚠️ 两边的**变动颜色 class 名故意不共用一个函数**：
流水页 `.is-plus`（Apple 风无前缀）、配置页 `.kc-delta-plus`（虚空深色），
配色体系不同，共用会让后台表格里的数字变成无色。

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
| `kokuu_sso_links` | `id` `uid` `last_email` `last_ip` `created_at` `updated_at` |

⚠️ 表名是 `credit_ledger` **没有 `kokuu_` 前缀**。
⚠️ 没有 `last_login_at` 列，只有 `last_sign_at`。
⚠️ `credit_ledger` **没有 `reason` 列**，来源是 `source`。
⚠️ `users` **没有 `username` 列**，昵称字段是 `nickname`。
⚠️ `kokuu_sso_links` **没有 `skin_uid` 也没有 `forum_user_id`**，
   关联列就叫 `uid`；跨站映射靠 `last_email` 在论坛侧按邮箱对齐。
   没有 `last_used_at`，最近使用时间读 `updated_at`。

> 这次为了清测试残留，我照着记忆写了 `$r->forum_user_id`，
> 直接 `Undefined property: stdClass::$forum_user_id`。
> **这张表一共 6 列，写之前先 `DESCRIBE` 一次。**

### 7. 接管后台仪表盘：`Filter` 的注册与执行

`AdminController::index()` 里写的是

```php
$grid = $filter->apply('grid:admin.index', $grid);
```

所以插件可以**完全接管仪表盘布局**，core 一个字都不用改，
卸载插件后自动恢复原样。注册用 `add()`，**不是** `apply()`：

```php
app(\Blessing\Filter::class)->add('grid:admin.index', function ($grid) {
    return ['layout' => [...], 'widgets' => [...]];
});
```

- 取实例必须 `app(\Blessing\Filter::class)`。
  ⚠️ **没有 `app('filter')` 这个字符串绑定**，会抛
  `Target class [filter] does not exist`。
- ⚠️ 这个异常发生在 bootstrap 阶段，而
  `PluginManager::loadBootstrapper()` 有 `try/catch` + `report($th)`，
  **生产环境不重抛** —— 表现是「插件看着启用了，仪表盘毫无变化」，
  **不报错，只是静默失效**。
- 回调签名只有 `function ($grid)` 一个参数
  （`AdminController` 没传 `$args`，多写参数不会被调用）。

返回结构是**三层嵌套**，`shared/grid.twig` 按
`grid.widgets[行][列][widget]` 取：

```php
'layout'  => [['md-7', 'md-5']],          // 与 widgets 的行数一致
'widgets' => [[['ns::dashboard.credit'], ['ns::dashboard.interop']]],
//                     ↑ 行0列0↑widget      ↑ 行0列1 ↑widget
```

⚠️ `layout` 的列数必须和 `widgets` 里那行的列数**完全一致**，否则渲染错位。

### 8. 视图命名空间是 manifest 的 `namespace` 原值（反斜杠）

```php
'KokuuStudio\\KokuuCredit::dashboard.credit'   // ✅
'kokuu-credit::dashboard.credit'              // ❌ 500
```

`PluginManager` 做的是 `$view->addNamespace($plugin->namespace, ...)`，
而 `$plugin->namespace` 就是 manifest 里 `KokuuStudio\KokuuCredit` 的原值。
写短名的报错只有一句
`Template "kokuu-credit::dashboard.credit" is not defined in "shared.grid"`，
**不会提示正确名字**。确认办法：

```php
$app->make('view')->getFinder()->getHints();
```

### 9. Twig 沙箱不放行自定义函数

内核只注册了 `auth_user` / `auth_check` / `auth_guest` / `auth_guard` /
`url` / `trans`。**没有 `auth()`**（`auth()->user()` 报
`Unknown "auth" function`），自定义的 `kokuu_credit_dashboard_data()`
同理会被拒。

数据一律用 `View::share()` 传进视图：

```php
View::share('kcDash', kokuu_credit_dashboard_data(auth()->user()));
```

在 grid 钩子里 share 是安全的：`AdminController` 先 `apply()` 再渲染，
此刻视图还没开始输出。

### 10. 给 `<a>` 设 color 必须写成 `body .kcDash-col X`

全站有两条全局规则会吃掉插件里单类名选择器的颜色：

```css
/* AdminLTE 主题 */
a { color: var(--rain); }
/* kokuu-ui/apple.css:503 */
body.kokuu-apple a { color: var(--k-rain); }
```

`body.kokuu-apple a` 特异性 (0,1,2) 高于 `.kcDash-cta` (0,1,0)。
实测后果：底部 CTA 白字被改成 `--k-rain`，而背景也是 `--k-rain`
→ **整颗按钮变成一块纯青色，一个字都看不见**。

所以 `dash.css` 里每一条给 `<a>` 设颜色的规则都写成
`body .kcDash-col .kcDash-cta`（(0,2,2)），稳稳压过。

⚠️ 排查这个问题时**先确认浏览器拿到的是新文件**——
Edge 会在 `user-data-dir` 里缓存 CSS，`.debug/cdp-profile-*` 不清就会
一直读到旧值，让人以为改了没用。验证脚本里加：

```js
await send('Network.enable');
await send('Network.setCacheDisabled', { cacheDisabled: true });
```

### 11. `artisan view:clear` 不清 Twig 编译缓存

Twig 的编译产物在 `storage/framework/views/twig/`（按 hash 分 256 个子目录），
`view:clear` **不碰这个目录**。改了 twig 模板没生效时手动清：

```bash
rm -rf storage/framework/views/twig
mkdir -p storage/framework/views/twig
chown -R www-data:www-data storage/framework/views
```

### 12. 深色模式的开关是 `#toggle-dark-mode > a`

`shared/dark-mode.twig` 渲染的是 `<li id="toggle-dark-mode"><a class="nav-link">`，
**没有 `href`**，靠 `kokuu-ui/rebuild.js` 事件接管。
自动化测试里别去点 `.dark-mode-toggle` / `[data-theme="dark"]`——
那些选择器在这个站根本不存在，点了没反应还会误判成「深色模式坏了」。
点完要读 `document.body.className` 确认 `dark-mode` 真的加上了。

### 13. `plugin/` 前缀拿不到 kokuu-ui 的 apple 作用域

`kokuu-ui/apple.css` 的规则全写成 `body.kokuu-apple xxx`，
那个类是 `rebuild.js` 在运行时加到 body 上的。
而 `rebuild.js` 只注入这六个前缀：

```php
['user', 'user/*', 'admin', 'admin/*', 'skinlib', 'skinlib/*']
```

**不含 `plugin/*`**。于是 `/plugin/kokuu-credit/ledger` 这类插件自有页面上：

- body 没有 `kokuu-apple` 类 → apple.css 作用域规则一行都不生效
- 页面退回内核紫渐变背景 + 虚空深色表格，和隔壁仪表盘完全不是一套东西

症状很隐蔽：页面能正常渲染、零控制台错误，只是**配色不对**。

解法（不改 `kokuu-ui`）：

```php
Hook::addStyleFileToPage('/plugins/kokuu-ui/assets/apple.css', ['plugin/kokuu-credit/*']);
Hook::addScriptFileToPage($plugin->assets('apple-scope.js'), ['plugin/kokuu-credit/*']);
```

`apple-scope.js` 只干一件事——把 `kokuu-apple` 加到 body 上。

⚠️ **不要**顺手把 `rebuild.js` 的行为也复制一份（侧栏折叠、进场动画）：
两边都往同一个 `localStorage` key 写状态会打架，
表现为折叠状态在两类页面之间互相覆盖。

### 14. `grid` 的 `layout` 行数必须与 `widgets` 行数一致

`shared/grid.twig` 是三层嵌套 `for row → for col → for widget`：

```twig
{% set layout = grid.layout[loop.index0] %}
<div class="col-{{ layout[loop.index0] }}">
```

如果 `widgets` 给了两行而 `layout` 只给一行，
第二行取 `grid.layout[1]` 就是 `undefined` →
渲染成 `class="col-"`（**没有宽度**）→ 那一列内容掉到下面，左边留一大片空白。

症状极具误导性：看起来像「布局没生效」，实际是数组下标越界。

---

## 部署

```bash
cp -r kokuu-credit /var/www/kokuu-auth/plugins/
chown -R www-data:www-data /var/www/kokuu-auth/plugins/kokuu-credit

cd /var/www/kokuu-auth
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan cache:clear
rm -rf storage/framework/views/twig          # 见坑 11
systemctl reload php8.1-fpm
/usr/local/bin/kokuu-sync-assets kokuu-credit# 静态资源有 public/ 和 plugins/ 两份
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
