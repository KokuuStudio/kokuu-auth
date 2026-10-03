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

---

## 七、部署

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

## 八、验证

| 脚本 | 项数 | 覆盖 |
|---|---|---|
| `ops/verify_coupon.php` | 64 | 后端全链路：生成/兑换/一次性幂等/跨用户重放/底仓放行与拒绝/冷却/每日上限/概率/清理自检 |
| `ops/coupon_cdp_guard.js` | 42 | 前端真实点击：侧边栏入口/渲染/CSRF 注入/反向对照/兑换/抽奖/空码短路 |

```bash
# 后端
ops/rsh.sh --put ops/verify_coupon.php /tmp/verify_coupon.php
ops/rsh.sh 'cd /var/www/kokuu-auth && sudo -u www-data php /tmp/verify_coupon.php'

# 前端（CDP 必须与 headless Edge 同一条命令启动）
bash ops/_with_edge.sh 9354 node ops/coupon_cdp_guard.js
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

---

## 九、相关仓库

| 仓库 | 作用 |
|---|---|
| [kokuu-credit](https://github.com/KokuuStudio/kokuu-credit) | 积分账本（`users.score` + `credit_ledger`），**唯一账本** |
| [kokuu-exchange](https://github.com/KokuuStudio/kokuu-exchange) | 积分兑换成游戏内货币，与本插件**共用底仓配置** |
| [kokuu-forum](https://github.com/KokuuStudio/kokuu-forum) | 论坛侧展示 |
| [kokuu-forum-points](https://github.com/KokuuStudio/kokuu-forum-points) | 论坛 SSO |

---

## 十、许可

与 kokuu 系列其它插件一致。
