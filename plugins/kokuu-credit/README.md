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

### ⚠️ 配置端点返回的字段名（前端别猜）

配置页的内联 JS 直接消费这些端点，字段名对不上就是静默的「表格空白」：

| 端点 | 关键字段 |
|---|---|
| `api/ledger` | `created_at` / `nickname` / `delta` / `balance_after` / `source_label` |
| `api/sso-status` | **`client_ready`**（不是 `ready`） |
| `api/sso-links` | **`nickname` / `last_email` / `last_seen`**（不是 `email` / `created_at`） |

最后两行的字段名都踩过：写成直觉名字时接口正常 200、页面就是不显示数据，
因为 JS 读 `undefined` 不会报错。

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

### ⚠️ 来源标签表只有一处定义

`credit_ledger.source` → 中文标签的映射**必须**只维护一份：

```php
kokuu_credit_source_labels(): array   // 权威映射表
kokuu_credit_source_label($source)    // 单值查询，内部委托上面那个
```

历史教训：这份表曾散在**三个地方**（仪表盘内联数组 / `kokuu_credit_source_label()` / 流水路由），
结果 `manual` 在仪表盘写「手动调整」、在流水页写「管理员手动」——
**同一笔流水在两个页面上文案不一样**。现在仪表盘 `foreach` 和流水路由的
`sourceLabels` 都改成调 `kokuu_credit_source_labels()`，只有一处定义就不会再漂移。

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

## 六、管理员调分

用户需求：「给管理员加一个可以操作所有用户积分的功能」。

### 落点：就在流水页顶部，不单独开一页

面板是流水页顶部一个**默认收起的 `<details>`**（`#kc-adj`），不新建路由页。

理由：调分和流水是**同一件事的两半** —— 改完立刻能在下方看到那条
`source=manual` 记录，不用来回跳。侧边栏也就不用再加一项
（这个项目的侧边栏已经精简过一轮，见「九、侧边栏」）。

### 三种模式

| mode | 语义 | amount | 换算出的 delta |
|---|---|---|---|
| `add` | 增加 | 正数 | `+amount` |
| `sub` | 扣减 | 正数 | `-amount` |
| `set` | 直接设为 | 目标余额（可0） | `amount - 当前余额` |

`set` 模式下 `min=0`，因为**清零是合法操作**，前端不能提前挡住；
`add`/`sub` 下 `min=1`。数额标签（「增加」/「扣减」/「设为」）跟着模式实时变 ——
不然界面写着「增加」实际发的是 `sub`，是很容易出账的错。

### 余额从不由前端算

三处都只信服务端：

1. 提交后**用响应里的 `score`覆盖**页面显示，不自己加减（可能有并发改动）；
2. 数额输入框旁边的「调整后 X」是**预览**，只给人看，不参与提交计算；
3. 提交后重新拉一次 `/api/users` 核对落库结果。

### 幂等

`event_id = manual:<操作者uid>:<目标uid>:<nonce>`，nonce 由前端每次点击生成。

所以：**双击是两次独立操作**（符合直觉，每次点击一个新 nonce），
而**网络重试/回放同一个 nonce 不会重复生效**（撞 `credit_ledger` 唯一索引，
服务端返回 422 + 「已经执行过了」）。

### 校验清单（服务端，缺一不可）

| 检查 | 不满足 |
|---|---|
| `permission >= ADMIN` | 403 |
| `uid > 0` 且用户存在 | 422 |
| `mode ∈ {add,sub,set}` | 422 |
| 原因非空且 ≤100 字 | 422 |
| 数额非空、数字、绝对值 ≤1e8 | 422 |
| `add`/`sub` 的 amount > 0；`set` 的 amount ≥ 0 | 422 |
| 调分后余额 **≥ 0** | 422 |

⚠️ **管理员调分不受底仓 `min_keep` 约束**。底仓是给「用户自己兑换/抽奖」
设的自保线；管理员是站点所有者，需要能纠错（发错要收回、刷分要清零）。
但**负余额仍然拒绝**。

### 两个新接口

```
GET  /plugin/kokuu-credit/api/users?q=<uid|昵称|邮箱>   # 搜索，20 条
POST /plugin/kokuu-credit/api/adjust                # 调分
```

`users` 走 GET 不需要 CSRF；`adjust` 挂 `web` **必须**带 CSRF
（页面侧 POST 的通例，见「踩过的坑」第 12 条）。

⚠️ **`users` 表没有 `username` 列**（只有 `uid` / `email` / `nickname` / `score`）。
搜 `nickname + email`，纯数字当 uid 精确查。写新查询前先 DESCRIBE。

⚠️ 搜索的 `LIKE` 模式串要把 `%` 和 `_` 转义（`\%` `\_`），
否则管理员搜 `%` 会捞出全表。

### 前端事件绑定的脆弱性

模式切换的 `change` 监听**直接绑在三个 radio 上**，不绑外层容器：

```js
Array.prototype.forEach.call(
  document.querySelectorAll('input[name="kc-adj-mode"]'),
  function (r) { r.addEventListener('change', syncAmountMode); }
);
```

绑容器就得依赖 change 冒泡。一旦将来有人加 `stopPropagation`，
或用 `new Event('change')`（**默认不冒泡**）派发，标签和 min 就会悄悄不再更新 ——
界面显示「增加」实际发 `sub`。这个 bug 在本轮真的发生过（守卫用
`new Event('change')` 就复现了），改成直接绑 radio 后根除。

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

### 2b. 配置页必须**自包含**（不能 `@extends`）

配置页走的是 `PluginController@config` → `$plugin->getConfigView()` →
**`view()->file($path)`** —— 这是一次**独立 Blade 渲染，不经过布局继承链**。

后果：

- `@extends('admin._base')` 会直接 `View [admin._base] not found`
  （顺便说，`admin._base` 这个视图**全站根本不存在**，唯一引用就是它）
- `Hook::addStyleFileToPage` / `addScriptFileToPage` 对配置页**完全无效**。
  这两个钩子最终由 `HeadComposer` 消费，写进的是**内核 Twig 布局**的 head/页脚；
  配置页压根不走布局，钩子即使「匹配成功」（`request()->is('admin/*') === true`）
  也没有人渲染它。

实测：抓 `/admin/plugins/config/kokuu-credit`，HTML 里**一个 `<script>` 都没有**。

所以 `views/config.blade.php` 从 `<!DOCTYPE html>` 开始整页自包含：
自己 `<link>` 内核 `app/style.*.css` + `kokuu-ui/apple.css`，
CSS 与 JS 全部内联（`kokuu-quote` 早就这么做了，这是本仓库的既定范式）。

### 2c. 内联脚本要避开 `.kc-table`（深色流水页专用）

`assets/dash.css` 里 `.kc-table` 是**深色系**（`#EEEDFE` 文字 / `#534AB7` 边框），
只服务流水页。但 `credit.js` 往 `#kc-ledger` 塞的也是 `.kc-table`，
在配置页的浅色底上会**白字白底**。处理办法是只在这几个容器内覆盖回浅色，
不去动全局 `.kc-table`：

```css
#kc-ledger .kc-table td, #kc-sso-links .kc-table td, #kc-sso-detail .kc-table td { /* 浅色 */ }
```

另：`credit.js` 会造 `div.kc-toast`，但 `credit.css` **没有 `.kc-toast` 规则**
（在流水页它也是裸文字）。配置页补上了完整样式。

### 2d. 改完 Blade 要清 `storage/framework/views/*.php`

`artisan view:clear` 与手动删 `views/twig/` **都不清** Blade 编译缓存。
症状：改了 `config.blade.php`，HTTP 仍返回旧的 17754 字节，
而 CLI `php` 直接渲染却是新的 26326 字节 —— **两者不一致就是缓存问题**。

```bash
rm -f /var/www/kokuu-auth/storage/framework/views/*.php
systemctl reload php8.1-fpm
```

### 2e. `public/plugins/<插件>/` 目录属主也要是 `www-data`

静态资源有两份，nginx 服务的是 `public/plugins/` 那份。
`App\Listeners\CopyPluginAssets` 在插件启用/版本变化时把
`plugins/<name>/assets` 复制过去 —— **是以 PHP-FPM 身份（`www-data`）写的**。

如果 `public/plugins/<插件>/` 本身是 `root:root`，下次复制会：

```
copy(...): Failed to open stream: Permission denied
```

→ 插件页 500。`kokuu-sync-assets` 原本只 `chown` 了子目录 `assets/`，
漏了**父目录**，已修补成同时 chown `$SITE/public/plugins/$p`。

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

### 15. CDP 的 `requestId` 在 `params` 上，不在 `params.response` 里

抓响应体时踩了三轮才对：

```js
//❌ requestId 是 undefined —— response 对象里根本没有这个字段
responses.push({ url: r.url, status: r.status, id: r.requestId });

// ✅ 在 m.params.requestId
responses.push({ url: r.url, status: r.status, id: m.params.requestId });
```

症状**极具欺骗性**：接口其实完全正常（200 + 正确 JSON），
只是断言读不到 body，看起来像「后端返回空」。
`loadingFinished` 时抓回来的 body 存进 Map 后永远匹配不上（key 是 `undefined`），
`bodyOf()` 只能等超时返回空串。

⚠️ 三条配套的纪律：

1. **不能事后按 requestId 补取** —— 响应结束后 CDP 已释放 body，
   `getResponseBody` 抛 `No resource with given identifier`。
   必须在 `Network.loadingFinished` 到达时抓。
2. **`getResponseBody` 必须带 `sessionId`**。CDP 连的是 browser 级 WS，
   页面在 `Target.createTarget` 出来的子 target 里，flat 模式下
   漏掉 sessionId **不报错**，只是静默作用在 browser 上 → 拿不到 body。
3. **必须有「守卫自检」断言**：先断言「body 拿得到」，再断言 body 里的字段。
   否则拿着 `{}` 去判 `code === 0`，会得出「后端返回了空」的错误结论 ——
   本轮差点因此去改本来完全正确的后端代码。

🔴 **守卫自己的 bug 和被验证对象的 bug 必须能区分开**。
连续三轮都在「修后端」，而问题全在守卫里。
判据：**先打印事件流**（`resp200 rid=undefined adjust`），
一眼就能看出 rid 是 undefined，而不是继续猜时序。

### 16. 守卫的收尾基准必须是**固定常量**，不能是「本轮起点」

`--restore` 原本把「本轮开始时读到的余额」写回去。看着对，其实会**逐轮漂移**：

```
第1轮：基准 22 → 还原到 22     ✓
第2轮：基准 22 → 还原到 22     ✓
第3轮：基准 22 → 还原到 22     ✓
```

但如果中间有别的脚本动过余额（调试时随手发了 1 分），
「本轮起点」就变成 23，还原到 23 —— **每跑一轮往上漂一点**。

症状隐蔽：每轮都「还原成功」、退出码也是 0，
只有拿原始基准去对才看得出漂了。

正确做法：基准写成常量 `BASE_SCORE`，并且**在动手之前**就断言
「开跑前余额 == 基准」，不一致立刻报（可能是别人真改过这个账号），
而不是默默盖掉。

配套的清理脚本（`ops/cr_restore.php`）要做**倒推核对**：

```php
$sum = (int) $rows->sum('delta');
$implied = $score - $sum;         // 删掉这些流水后，余额应该是多少
if ($implied !== $expectScore) {
    // 只报告，不删 —— 期间有别的变动混进来了，删了账本就对不上
}
```

⚠️ **删 `credit_ledger` 流水不会回退 `users.score`**（score 才是权威账本）。
所以清理脚本必须分两步：先删流水并核对，再**显式**写回 `users.score`。
本轮就是靠这个倒推核对发现余额差 2 分（两条调试留下的 `dbg` 记录）。

### 17. 收尾操作本身也会留下痕迹

「把余额还原」这个动作本身也是一次 `manual` 调分，**它自己也留一条流水**。
所以 `--restore` 之后还需要 `cr_restore.php` 删流水 —— 两步是分开的，
脚本输出里必须把这个命令原样打出来（不能靠人记得）。

反过来看这也是对的：**余额改动就该有流水痕迹，不留才是隐患**。

---

## 部署

```bash
cp -r kokuu-credit /var/www/kokuu-auth/plugins/
chown -R www-data:www-data /var/www/kokuu-auth/plugins/kokuu-credit

cd /var/www/kokuu-auth
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan route:clear
sudo -u www-data php artisan cache:clear
rm -f  storage/framework/views/*.php        # 见坑 2d（Blade 编译缓存）
rm -rf storage/framework/views/twig         # 见坑 11（Twig 编译缓存）
chown -R www-data:www-data storage/ bootstrap/cache/
systemctl reload php8.1-fpm
/usr/local/bin/kokuu-sync-assets kokuu-credit   # 静态资源有 public/ 和 plugins/ 两份
```

⚠️ `artisan` 必须用**FPM 的运行用户**（本机是 `www-data`）。
用别的用户跑会因为读不到 `.env` 而报
`Access denied for user 'forge'@'localhost'`。

⚠️ **opcache 会让新代码「部分生效」**，症状极像「文件没传上去」。
改完 PHP 必须 `systemctl reload php8.1-fpm`，光 `cache:clear` 不够。

### 验证部署成功的判据

| 改了什么 | 怎么验证 |
|---|---|
| PHP 代码 | `md5sum` 线上文件 == 本地；`php -l` 无错；日志无新异常 |
| CSS/JS 资源 | `curl -sI <URL>` 的 `Content-Length` == 磁盘文件字节数 |
| Twig 模板 | 渲染输出字节数变化；清 `views/twig/` 后复验 |
| Blade 模板 | 抓页面看**字节数变化** + 关键字（如 `<script>` 个数）；<br>CLI 渲染与 HTTP 渲染字节数**不一致 = 缓存没清** |

### 端到端守卫

```bash
# 管理员调分面板（65 项）
bash ops/_with_edge.sh 9379 node ops/credit_adjust_guard.js --restore
# 清理守卫留下的测试流水（含"还原"那一条）
bash ops/rsh.sh --put ops/cr_restore.php /tmp/cr_restore.php
bash ops/rsh.sh "sudo -u www-data php /tmp/cr_restore.php"
```

🔴 **`--restore` 必须带**，否则测试数据留在线上。
不带时脚本会在结尾把补救命令打出来，不会默默跑完就忘。

守卫覆盖：面板渲染（8）、资源注入（3）、用户搜索（5）、
**419 反向对照**（2，证明 CSRF 层真在拦且什么都没发生）、
业务校验 9 种非法输入（10）、幂等重放（3）、
真实点击三种模式（15）、落库双查（5）、运行时健康（2）。

### 守卫设计原则（本轮又验证了一遍）

1. 🔴 **`ok($cond, $label, $detail)` —— 条件在第一个参数。**
   写反成`ok('标签', 条件)` → 标签字符串恒为真 → 全绿假通过。
   症状：`[PASS] 1`。写完必grep 首参自查。
2. 🔴 **必须有「守卫自检」**。像「body 能不能拿到」这种**守卫自己**的能力，
   要单独断言。抓不到 body 却继续判body 里的字段，
   就是「验证自己的假设」而不是验证线上代码。
3. 🔴 **守卫的 bug 和被验证对象的 bug 要能区分**。分不清就会去改本来正确的后端。
   判据：先**打印事件流**，别猜时序。
4. **收尾基准用固定常量**，且在动手前断言，不一致立刻报（见坑 16）。
5. **每轮跑完清一次**（`cr_restore.php`），不要攒。

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
