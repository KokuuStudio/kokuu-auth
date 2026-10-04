# kokuu-coupon · 兑换码与抽奖（Blessing Skin 侧）

给 [`kokuu-credit`](https://github.com/KokuuStudio/kokuu-credit) 的积分经济补上「**产出端**」：

```
管理员批量生成兑换码  →  用户输入兑换码  →  ① 直接到账积分
                                       →  ② 或到账抽奖次数  →  抽奖  →  积分
```

兑换码既能**发积分**也能**发抽奖次数**，抽奖再吐回积分，形成闭环。
抽奖次数也可以直接花积分买（默认 50 分/次），所以即使手上没码也能玩。

![Blessing Skin](https://img.shields.io/badge/Blessing%20Skin-6.0-0FA3A3?style=flat-square)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-8B7FD4?style=flat-square)
![版本](https://img.shields.io/badge/version-1.0.0-8B7FD4?style=flat-square)

> ⚠️ **本插件依赖 [`kokuu-credit`](https://github.com/KokuuStudio/kokuu-credit)**（积分账本）。
> 未启用时兑换码的「发积分」类功能会明确报错，不会静默吞掉。

---

## 一、两条路径

```
管理员后台「兑换码管理」批量生成
   ├─ 类型 = 积分  → 面值 50 积分的码 ─┐
   └─ 类型 = 抽奖次数 → 3 次抽奖的码 ─┤
                                       │
用户侧边栏「兑换码」                        │
   ├─ 输码 → 兑换  → 积分直接进 users.score │
   └─ 抽奖  → 优先扣抽奖次数（不花钱）      │
              没次数则扣积分（默认 50/次）  │
              → 按权重抽奖，中奖积分即时入账  │
```

侧边栏入口：**用户区「兑换码」**（`fa-ticket`），
管理员另有 **「兑换码管理」** 入口（`/plugin/kokuu-coupon/manage`）。

---

## 二、数据表

| 表 | 作用 | 关键约束 |
|---|---|---|
| `coupon_codes` | 兑换码本体 | `code` **唯一索引** |
| `coupon_redemptions` | 兑换记录 | `coupon_id` **唯一索引** ← 一次性靠它，不靠「先查 used_count」 |
| `lottery_wallets` | 抽奖次数钱包 | `uid` **唯一索引** |
| `lottery_draws` | 抽奖流水 | 兼作冷却与每日上限的计数来源 |
| `lottery_prizes` | 奖品与权重 | `weight=0` 永不抽中 |

### 为什么一次性靠唯一索引而不是「先查再 UPDATE」

朴素写法是「查 `used_count` → 判断 → `UPDATE used_count+1`」。
两个并发请求会**同时**查到旧值，双双通过判断 —— 同一个码被兑换两次、发两份积分。

这里改成：**先插 `coupon_redemptions`（`coupon_id` 唯一）**。
第二个请求撞唯一索引直接失败，数据库层面保证只有一次能成功。
积分发放放在同一个事务里，插入失败即回滚，天然原子。

> 同理，积分发放复用 `kokuu_credit_ledger()`，它内部用
> `credit_ledger.event_id` 唯一索引（`coupon:<rid>` / `draw:<did>`）作幂等键。

---

## 三、底仓（min_keep）与 kokuu-exchange 共用

抽奖花积分时**不会把余额扣穿**，会保留 `kokuu_exchange_min_keep` 指定的底仓
（默认 100，**与积分兑换插件读同一个 option**）。

```
余额 1000，底仓 100，单抽 50
  → 允许抽奖，直到余额落到 100 为止
  → 余额 149 时拒绝（149-100=49 < 50）
```

**本插件只读这个配置，不写它。** 两个插件若各写各的，会出现
「兑换插件按 100 拦、抽奖按 0 拦」，把余额扣穿。

放行/拒绝两侧守卫都验了：

| 场景 | 期望 |
|---|---|
| 余额 = 底仓 + 消耗 + 500 | ✅ 放行（`via=cost`） |
| 余额 = 底仓 + 消耗 − 1 | ❌ 拒绝，且余额**不变**（未透支） |
| 余额 = 底仓 − 10 | ❌ 拒绝，提示点明「保留」 |

---

## 四、配置项

全部存在 `options` 表，前缀 `kokuu_coupon_`；**没设就用默认值**。

| key | 默认 | 说明 |
|---|---|---|
| `code_prefix` | `KOKUU` | 兑换码前缀，便于用户分辨 |
| `code_length` | `12` | 随机部分长度（6–32） |
| `code_alphabet` | 去易混字符集 | 已剔除 `0 O 1 I L` 等手抄易错字符 |
| `code_ttl_days` | `0` | 有效期天数，0 = 永久 |
| `default_amount` | `100` | 批量生成时默认面值（积分） |
| `default_draws` | `1` | 批量生成时默认次数（抽奖） |
| `max_uses` | `1` | 每个码可兑换次数 |
| `daily_redeem_limit` | `20` | 每人每日兑换次数上限 |
| `draw_cost` | `50` | 花积分抽奖的单次消耗 |
| `draw_cooldown` | `3` | 抽奖冷却秒数 |
| `daily_draw_limit` | `50` | 每人每日抽奖次数上限 |
| `notice` | 空 | 用户页顶部公告 |

> ⚠️ 改完配置要手动刷 options 缓存：
> `cd /var/www/kokuu-auth && sudo -u www-data php artisan options:cache`
> （`Option::set()` 只写 DB，不刷 `storage/options.php`。）

### 兑换码字符集

用 `random_int()` 逐字符生成（**CSPRNG**，非 `rand()`）。
凭据类随机数可预测就等于可刷，积分直接受影响。

字符集默认剔除了 `0/O`、`1/I/L` —— 用户是**手抄**兑换码的，
少一次「这不是 0 吧？」的返工。

---

## 五、奖品与概率

默认奖品表（首次启用自动创建，可在后台改）：

| 奖品 | 积分 | 权重 | 概率 |
|---|---:|---:|---:|
| 谢谢参与 | 0 | 500 | 50% |
| 5 积分 | 5 | 250 | 25% |
| 10 积分 | 10 | 150 | 15% |
| 20 积分 | 20 | 70 | 7% |
| 50 积分 | 50 | 25 | 2.5% |
| 100 积分 | 100 | 5 | 0.5% |

- 抽签用 `random_int` 按权重区间，**不用浮点累加**（避免精度累积偏差）
- `weight=0` 的奖**永不抽中**（守卫抽 40 次验证过）
- 概率百分比由 PHP 算好再传模板（见下）

---

## 六、踩坑清单（都是本项目真踩过的）

### 1. CSRF：代码里写了 header ≠ 请求带上了 token

本项目 2026-10-04 出过 100% 报 `CSRF token mismatch.` 的事故：
`exchange.js` 的 fetch 明明有 `headers` 块，只是里面**漏了** `X-XSRF-TOKEN`。
**源码读起来完全正常，线上全挂。**

所以本插件的修法：

```js
'X-XSRF-TOKEN': readCookie('XSRF-TOKEN') || CSRF
```

- 值取 `XSRF-TOKEN` cookie，**只 `decodeURIComponent`，不要剥 CookieValuePrefix**
  （Laravel 服务端会自己剥，多剥一次就错位）
- **提交时实时再读一次** cookie，不复用加载时的快照
  （session/token 会轮换，用快照必 419）

### 2. 419 与 422 语义相反

| 状态 | 含义 |
|---|---|
| **419** | CSRF 层拦截 —— 请求**根本没到**业务逻辑 |
| **422** | 业务失败 —— 说明**已经穿过** CSRF，进入业务并正常返回 |

所以验证「CSRF 通不通」的判据是「**非 419**」，绝不是 `=== 200`。
业务失败返回 422 恰恰是 CSRF 正常的证据。

### 3. Twig 沙箱没有 `max` / `min` 过滤器

用 `|max` 会整页 500（`Unknown "max" filter`），且**同插件其他页面正常**，
极具迷惑性（症状隐蔽 → 容易误判成「同插件能用就是没事」）。

→ **所有派生量在控制器算好传进模板，模板只负责显示。**
概率百分比、期望值、底仓差额一律在 PHP 侧算。

### 4. 插件自定义函数在 Twig 里不可调用

`kokuu_coupon_min_keep()`、`csrf_token()` 在模板里**一律不可用**。
后台配置页需要 `minKeep` 时，由控制器 `'minKeep' => kokuu_coupon_min_keep()` 传入。

### 5. 配置类页面必须整页自包含

后台管理页走 `view()->file()`，**不继承布局链**：

- `@extends` 只能指向真实存在的视图（`admin._base` **全站不存在**）
- `addStyleFileToPage` / `addScriptFileToPage` **对它完全无效**
  （钩子只写进内核 Twig 布局）

→ 管理页自带 `<link>`（内核 css + `apple.css`）与内联 JS。

### 6. 侧边栏 title：插件项才是中文

`config('menu')` 里内核项是 lang key（`general.dashboard`、`general.my-reports`…），
**只有插件自己追加的项才可能是中文**。注册方式：

```php
Event::listen(\App\Events\ConfigureUserMenu::class, function ($event) {
    $event->menu['user'][] = [
        'title' => '兑换码', 'link' => 'plugin/kokuu-coupon', 'icon' => 'fa-ticket',
    ];
});
```

### 7. PHP 标识符允许 0x80–0xFF 字节

`"$VAR，全角"` 会把全角逗号吞进变量名 → `Undefined variable $VAR,`，
且**语法报错指向的行会严重偏移**（实测报错在第 51 行，真凶在第 73 行）。

→ 说明文字里凡「`$变量` + 中文全角标点」相邻，一律用字符串拼接。
**`grep -nE '\$[A-Za-z_][A-Za-z0-9_]*[，。；：、（）]'` 扫一遍更省事。**

### 8. 闭包不自动捕获外层变量

`function ($q) { ... $TAG ... }` 会报 `Undefined variable $TAG`，必须 `use ($TAG)`。

### 9. `Option::set()` 不刷缓存，且没有 delete

- 只写 DB，不刷 `storage/options.php` → 部署后要手动 `options:cache`
- `App\Services\Option` **只有** `get` / `set` / `all`，**没有 delete**。
  想「删掉某个配置项」只能直接 `DB::table('options')->where('option_name', …)->delete()`
- ⚠️ 若 `set($k, null)` 会留下一行空值残值：此刻读着正常（回落默认值），
  但**下次 `options:cache` 会把残值烤进缓存**，配置静默变错

### 10. `App\Models\Plugin` 不存在

Blessing Skin 内核**没有**这个模型，用 `app('plugins')`（`App\Services\PluginManager`）：
`all()` / `get($name)` / `enable($p)` / `isEnabled()` / `getManifestAttr('name')`。

**上传代码 ≠ 已注册 ≠ 已启用**，三步是分开的。

### 11. `addStyleFileToPage` 是 `Hook` 的静态方法，不是全局函数

```php
Hook::addStyleFileToPage('/plugins/kokuu-coupon/assets/dash.css');   // ✅
addStyleFileToPage('/plugins/kokuu-coupon/assets/dash.css');         // ❌ Call to undefined function
```

`app/Services/Hook.php:64`。写裸函数名**直接 500**，而且是在 grid 回调里 ——
`loadBootstrapper()` 的 `catch(\Throwable){ report($th); }` 会静默吞掉一部分，
表现为「卡片完全不出现」而不是「插件报错」。

### 12. `odds_table()['rows']` 的元素是**关联数组**，不是对象

```php
$r->label    // ❌ Attempt to read property "label" on array
$r['label']  // ✅
```

且 `percent` 已在那里算好并 round 到 2 位，**不要重复做除法** —— 权威口径只有一处。

### 13. `users` 表**没有 `username` 列**

只有 `uid` / `email` / `score` / … 。写查询前先 DESCRIBE，别照抄别的项目的字段名。

### 14. 期望值必须**同步**重算，不能只算一半

管理页的概率列随权重输入实时重算，但期望值原来是服务端渲染的**旧值** ——
改完权重后「概率」变了、「单抽期望」没变，管理员会被误导。
`recalc()` 里必须同时更新 `p-expect`，且**用同一种加权口径**（`Σ(a×w)/Σw`）。

### 15. 内联 JS 注释里**不能出现反引号**

```js
const r = await ev(`(function(){
  // 描述 grid.twig 的 `{{ include(widget) }}` 结构   ← 这一行把模板串截断了
  return 1;
})()`);
```

反引号会**当场**闭合模板字符串 → `SyntaxError: missing ) after argument list`，
而 Node 报的行号指向**模板起始行**（第 1 行），跟真正出错的第 2 行差很远。

**`node --check` 就能可靠抓到这类截断** —— 本轮试过写个 Python 静态检查器，
结果它自己先假 PASS 了一版、又误报了 51 处，反而制造噪音。
**工具自己有 5% 误报率就等于没有工具**（同「假绿」的另一种形式）。

### 16. 守卫跑完必须**自己收尾**，不能靠人记得

`coupon_widget_guard.js` 验成功抽奖时会**真扣积分**。收尾只打印提示不够 ——
本轮就是因为「跑完忘了」，叠加 `cp_topup.php` 自身的符号 bug
（`$amt` 传进来已是负数却又取 `-$amt`），把 `uid=1` 余额搞成了 `-583`。

修法：`ops/cp_restore.php` 把余额与抽奖记录一并还原（带核对与复查输出），
守卫在成功路径结束时**明确打印该跑什么**。

---

## 七、用户中心「幸运抽奖」widget

抽奖功能在**用户中心仪表盘**（`/user`）有一个独立容器，兑换码兑换仍在独立页
`/plugin/kokuu-coupon`（输入一串字符的操作不适合塞进仪表盘）。

### 挂载方式

```php
app(\Blessing\Filter::class)->add('grid:user.index', function ($grid) {
    View::share('kokuuCouponDraw', kokuu_coupon_draw_widget_data(auth()->user()));
    Hook::addStyleFileToPage('/plugins/kokuu-coupon/assets/dash.css');
    Hook::addScriptFileToPage('/plugins/kokuu-coupon/assets/dash.js');

    $our = 'KokuuStudio\\KokuuCoupon::user.coupon.widget';
    if (! in_array($our, $grid['widgets'][0][1] ?? [], true)) {
        array_unshift($grid['widgets'][0][1] ?? [], $our);
    }
    return $grid;
});
```

落在**右列（`col-md-5`）开头** —— 右列原本只有 kokuu-forum 的社区卡，
抽奖是互动内容，放开头与左列顶部的邮箱验证卡平齐，不挤左列的积分卡。

### 四条硬约束（改这个 widget 前先读）

1. **必须在传入的 `$grid` 上增量改，不能 `return` 一个全新数组。**
   `grid:user.index` 上挂了多个回调（本插件 / kokuu-credit / kokuu-forum /
   Yggdrasil），是**链式**的：前一个的返回值是后一个的输入。
   直接 return 新数组 → 别人的卡被挪走，且 `layout` 行数与 `widgets` 行数不匹配
   → `grid.twig` 取 `layout[1]` 越界 → `class="col-"` 无宽度 → 内容掉行。

2. **视图名用 manifest 的 `namespace` 原值**：`KokuuStudio\KokuuCoupon::...`
   （双反斜杠）。写短名会 500 且**不提示正确名字**。

3. **`Hook::addStyleFileToPage` 对 `view()->file()` 的配置类页面完全无效**
   （只写进内核 Twig 布局）。所以管理页 `manage.twig` 必须**整页自包含**，
   而 `/user` 是正常页面 → 钩子有效。

4. **所有派生量在 `kokuu_coupon_draw_widget_data()` 里算好再传。**
   Twig 沙箱**没有 `max` / `min` 过滤器**（`|max` 直接整页 500）；
   插件自定义 PHP 函数在模板里也**一律不可调用**。

### 消耗规则

**抽奖次数优先** —— 有次数就花次数，没有次数才按后台配置的 `draw_cost` 扣积分
（判定在 `kokuu_coupon_draw()`，widget 与独立页共用同一套）。
底仓 `min_keep` 与 kokuu-exchange 共用，**管理页不提供修改入口**
（避免两个真相源）。

---

## 八、抽奖设置（管理页）

`/plugin/kokuu-coupon/manage` 的「抽奖设置」区块可改：

| 配置项 | 说明 |
|---|---|
| `draw_cost` | 每次消耗积分（0 = 只允许用抽奖次数） |
| `draw_cooldown` | 冷却秒数，防连点 |
| `daily_draw_limit` | 每日抽奖上限（0 = 不限） |
| `notice` | 抽奖公告，空则不显示 |

- 写入走 `POST /plugin/kokuu-coupon/config` → `kokuu_coupon_save_config()`。
- **每一项独立校验并回显**，越界直接报 422 并指出字段名，
  **不静默 clamp**（静默改成边界值 = 管理员填 999999 保存成 1000000，下次翻记录会怀疑人生）。
- ⚠️ `Option::set()` 只写 DB，**不刷** `storage/options.php`。
  响应里带 `hint` 提醒，需要立即全站生效就跑
  `sudo -u www-data php artisan options:cache`。

---

## 九、部署

```bash
# 上传
ops/rsh.sh --put kokuu-coupon /var/www/kokuu-auth/plugins/kokuu-coupon

# 权限
chown -R www-data:www-data /var/www/kokuu-auth/plugins/kokuu-coupon

# ⚠️ assets 有两份，nginx 服务的是 public/ 那份
mkdir -p /var/www/kokuu-auth/public/plugins/kokuu-coupon   # 新插件首次必须先建，否则同步会失败
kokuu-sync-assets kokuu-coupon

# 启用（上传 ≠ 启用）
sudo -u www-data php /tmp/enable_coupon.php

# 清缓存（view:clear **不清** storage/framework/views/twig/ 下的 256 个 hash 子目录）
cd /var/www/kokuu-auth
sudo -u www-data php artisan cache:clear
sudo -u www-data php artisan view:clear
rm -rf storage/framework/views/twig/*
sudo -u www-data php artisan options:cache
chown -R www-data:www-data storage/ bootstrap/cache/
systemctl reload php8.1-fpm
```

---

## 十、验证

| 脚本 | 项数 | 覆盖 |
|---|---|---|
| `ops/verify_coupon.php` | 66 | 后端全链路：生成/兑换/一次性幂等/跨用户重放/底仓放行与拒绝/冷却/每日上限/概率/期望值加权口径/清理自检 |
| `ops/coupon_cdp_guard.js` | 42 | 独立页真实点击：侧边栏入口/渲染/CSRF 注入/反向对照/兑换/抽奖/空码短路 |
| `ops/coupon_admin_guard.js` | 52 | 管理页（管理员视角）：整页自包含/三类接口 419 对照/类型切换/概率与期望值交叉验证/真实生成/清理/抽奖设置保存与落库验证 |
| `ops/coupon_widget_guard.js` | 30 | 仪表盘 widget：容器位置（右列、不挤积分卡）/CSS·JS 注入/`View::share` 数据链路/disabled 反向对照/真实点击抽奖（成功与拒绝两条路径）/冷却生效 |

```bash
# 后端
ops/rsh.sh --put ops/verify_coupon.php /tmp/verify_coupon.php
ops/rsh.sh 'cd /var/www/kokuu-auth && sudo -u www-data php /tmp/verify_coupon.php'

# 前端（CDP 必须与 headless Edge 同一条命令启动）
bash ops/_with_edge.sh 9374 node ops/coupon_admin_guard.js
bash ops/_with_edge.sh 9377 node ops/coupon_widget_guard.js
```

### 会改数据的守卫需要配套工具

`coupon_widget_guard.js` 验成功抽奖会**真扣积分**，配套：

| 脚本 | 用途 |
|---|---|
| `ops/cp_topup.php` | 临时给 `uid=1` 加分（余额低于底仓时验不了成功路径）。冲销要传**同一 event_id** |
| `ops/cp_restore.php` | 把余额与 `lottery_draws` 记录还原到测试前，带核对与复查输出 |
| `ops/cp_bal.php` | 查各账号余额、抽奖钱包、当前配置（诊断用） |

```bash
ops/rsh.sh --put ops/cp_topup.php /tmp/cp_topup.php
ops/rsh.sh 'sudo -u www-data php /tmp/cp_topup.php 400'      # 加分
ops/rsh.sh 'sudo -u www-data php /tmp/cp_topup.php -400 <EVENT_ID>'   # 冲销
```

### 守卫本身的设计原则

这套守卫踩过的坑比它验的 bug 还多，记录在此以免重蹈：

1. **`ok(条件, '标签', extra)` —— 条件在第一个参数。**
   写反的话标签字符串恒为真 → **断言全绿**。症状：输出 `PASS true`。
   → 写完用
   `grep -nE "ok\(\s*['\"]|ok\(\s*[a-zA-Z_][a-zA-Z0-9_]*\s*," <file>`
   检查首参是不是条件表达式。

2. **必须真实点击页面自己的按钮**，不能自己复刻一份 fetch。
   「复刻一遍逻辑再断言」= 验证自己的假设，不是验证线上代码。

3. **必须带反向对照。** 只验「带 token 能过」是假绿 ——
   万一 CSRF 中间件被误删，那也「过」。所以另加一条「不带 token 必 419」。

4. **别把守卫自己发起的探测算进失败统计。**
   本脚本故意发 419（对照）和 422（业务失败），那是**要证明的东西**，不是缺陷。

5. **Body 流只能读一次。** 拦截响应看 body 必须 `r.clone().text()`；
   直接 `r.text()` 会把插件自己的 `r.json()` 读空，
   表现为「服务器返回异常（HTTP 422）」——**守卫制造出来的假象**。

6. **选择器要 dump 真实 DOM，别凭印象写。**
   最初写 `.sidebar-menu a` 查不到入口，误以为功能没做；
   实际容器是 `.main-sidebar`。同一课的另一面：
   菜单项的 `title` 在内核里是 lang key，**不能照抄结构**去比对。

7. **`waitFor` 的条件必须包含断言对象本身。** 只等服务端 HTML 出来的元素不够 ——
   打主题类的 `apple-scope.js` 在 `DOMContentLoaded` 才执行。

8. **disabled 按钮的 `click()` 被浏览器静默忽略**，一个请求都不发，`status` 恒 `null`，
   看起来像「CSRF 没修好」。先 dump 状态，必要时强制 `disabled=false` 再点
   （要验的是传输层，不是业务闸）。

9. **每个守卫要能重复执行。** `verify_coupon.php` 连跑两次仍是 64/0 且无残留：
   临时改的限流配置按「行原本存不存在」精确还原
   （`Option` 没有 `delete`，`set(null)` 会留残值被 `options:cache` 烤进缓存）。
   守卫自己留下的地雷比不守卫更糟。

10. **任何「拒绝」验证都要有「本应成功」的对照组**，否则
    「全部失败」可能只是格式错的假象。

11. **`waitFor` 要等「真正被断言的对象」，不是「页面渲染了」的代理指标。**
    管理页那次踩得很典型：条件写的是 `g-go` / `p-table`（模板第 104~146 行），
    而 `<script>` 在**第 258 行** → 解析器读到 146 行就返回 true，
    script 还没执行 → `window.CPN_ADMIN` 全 `undefined`，
    DOM 只有 11491 字节（完整 18733）。
    **看起来像「模板注入全失效」，其实是读早了。**
    → 凡是断言某个 JS 全局，`waitFor` 就必须等那个全局本身，
    并加 `document.readyState === 'complete'` 兜底。**DOM 里有 id ≠ 脚本已执行。**

12. **断言口径要选「与历史状态无关」的那个。**
    「净变化 = 奖品 - 消耗」这种口径依赖基准是最新的 ——
    前一次守卫跑过并中奖后，基准就失效了，直接假 FAIL。
    改成业务区间 `Δ ∈ [消耗 - 最大奖品, 消耗]` 才稳。
    同一条教训的另一个面：**前一步发了积分，后面拿旧值比 = 假失败**
    （`verify_coupon.php` 第 7 节踩过一次）。

13. **反向对照也能用来验「什么都没发生」。**
    验证 disabled 按钮时，不只要断言「点了没反应」，
    更要断言「**一个请求都没发出**」—— 否则「点了但请求失败」也会通过。

---

## 十一、相关仓库

| 仓库 | 作用 |
|---|---|
| [kokuu-credit](https://github.com/KokuuStudio/kokuu-credit) | 积分账本（`users.score` + `credit_ledger`），**唯一账本** |
| [kokuu-exchange](https://github.com/KokuuStudio/kokuu-exchange) | 积分兑换成游戏内货币，与本插件**共用底仓配置** |
| [kokuu-forum](https://github.com/KokuuStudio/kokuu-forum) | 论坛侧展示 |
| [kokuu-forum-points](https://github.com/KokuuStudio/kokuu-forum-points) | 论坛 SSO |

---

## 十二、许可

与 kokuu 系列其它插件一致。
