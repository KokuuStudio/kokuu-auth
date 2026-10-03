# kokuu-exchange · 积分兑换（Blessing Skin 侧）

把论坛积分（`users.score`，账本在 [`kokuu-credit`](https://github.com/KokuuStudio/kokuu-credit)）
兑换成游戏内 CMI 货币。

采用「**直连发放**」模式：玩家在皮肤站「点一下」即生成订单，
MC 端 [`exchange-bridge`](https://github.com/KokuuStudio/exchange-bridge) 轮询领取队列并就地发放，
**玩家不需要进游戏输入任何兑换码**。

- 订单**强绑定 `players` 表里的具体角色**（CMI 认玩家名，扣的是那个角色的余额）
- 带**幂等键 + 状态机**，重复领取不会双发
- 订单超时未领取**自动退款**，不会永久锁死玩家积分

![Blessing Skin](https://img.shields.io/badge/Blessing%20Skin-6.0-0FA3A3?style=flat-square)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-8B7FD4?style=flat-square)
![版本](https://img.shields.io/badge/version-1.0.0-8B7FD4?style=flat-square)

> ⚠️ **本插件必须与 [`exchange-bridge`](https://github.com/KokuuStudio/exchange-bridge) 成对使用**，
> 缺一端兑换流程走不通。

---

## 一、流程

```
玩家点「兑换」               皮肤站                          Redis               MC 端
     │                        │                              │                   │
     ├───────────────────────>│                              │                   │
     │                        │ 扣 users.score               │                   │
     │                        │ 写 exchange_orders(pending)  │                   │
     │                        │ LPUSH queue ────────────────>│                   │
     │<─── 「已提交，稍后到账」 │                              │                   │
     │                        │                              │<── BRPOP 领取 ────┤
     │                        │                              │                   │ 切主线程
     │                        │                              │                   │ 调 CMI deposit()
     │                        │<── LPUSH result ─────────────┤                   │
     │                        │ 下一帧 BRPOP result          │                   │
     │                        │ 订单 → done / refunded       │                   │
     │                        │ 失败则退积分                  │                   │
```

**为什么皮肤站侧要自己 BRPOP 结果？**
本方案刻意**不依赖 cron / 常驻 worker** —— 结果队列在玩家下次打开
兑换页（或管理员打开订单页）时**顺手消费**（`kokuu_exchange_drain_results()`）。
对一个低流量私人站，这比维护一个 supervisor 进程更省事。
代价是「到账状态」最多延迟到玩家下次访问，但**钱不会丢**（队列里的消息还在）。

---

## 二、状态机

| 状态 | 含义 | 谁会改 |
|---|---|---|
| `pending` | 已扣积分、已入队，等 MC 端领取 | 下单 |
| `done` | MC 端已发放成功 | 消费 result |
| `failed` | MC 端发放失败（玩家不存在 / 经济插件报错） | 消费 result |
| `refunded` | 已退回积分 | 消费 result 或管理员手动 |
| `expired` | 超过 `order_ttl` 仍未领取，自动退款 | `kokuu_exchange_expire_stale()` |

⚠️ **`pending` 到 `done` 不是由本插件主动轮询推进的** ——
它只在「有人访问兑换页/订单页」时靠 `drain_results()` 推进。
这是刻意的取舍（无 worker），排查时别按「定时任务没跑」的思路查。

---

## 三、路由

| 路径 | 用途 | 中间件 |
|------|------|--------|
| `plugin/kokuu-exchange` | **玩家兑换页**（余额 + 角色选择 + 我的订单） | `web` |
| `plugin/kokuu-exchange/create` | 下单接口 | `web` |
| `plugin/kokuu-exchange/orders` | **管理员订单管理**（统计 + 列表 + 配置） | `web` |
| `plugin/kokuu-exchange/settings` | 保存配置 | `web` |
| `plugin/kokuu-exchange/refund` | 管理员手动退款 | `web` |
| `plugin/kokuu-exchange/health` | 健康检查（队列连通性） | `web` |

⚠️ **所有路由都是 `web` 中间件** —— 因为它面向浏览器 session，不是服务端到服务端。
（对比：`kokuu-credit` 的加分接口是 `api`，因为论坛服务端要带 HMAC 直接调。）
两者不要互相抄中间件。

---

## 四、配置项

存 `options` 表，键前缀 `kokuu_exchange_`。修改走管理员订单页的表单。

| 键 | 默认 | 说明 |
|----|------|------|
| `cost_per_unit` | `100` | 每多少积分换 1 单位货币（整数，避免浮点） |
| `min_units` | `1` | 单笔最少单位数 |
| `max_units` | `0` | 单笔上限（0 = 不限） |
| `cooldown` | `300` | 两次兑换最小间隔（秒） |
| `daily_limit` | `0` | 每日上限（0 = 不限） |
| `order_ttl` | `86400` | 订单有效期（秒），超时自动退款 |
| `queue_key` | `bs:exchange:queue` | **发放队列**，必须与 MC 端 `config.yml` 一致 |
| `result_key` | `bs:exchange:result` | **结果队列**，必须与 MC 端 `config.yml` 一致 |
| `auto_refund` | `true` | 发放失败是否自动退款 |
| `notice` | 空 | 兑换页顶部公告 |

⚠️ **空字符串同样回落到默认值**（`$v === null || $v === ''`）。
其他四个插件（forum / credit / home / quote）都是这个约定，
本插件曾漏写 `|| $v === ''` → 管理员清空 `notice` 保存时行为与它们不一致。

---

## 五、踩过的坑

### 1. 🔴🔴 队列 key 与「隐藏前缀」—— 兑换功能静默失效的真根因

这是本项目**排查代价最高**的一个坑，值得完整读一遍。

**第一层（容易发现）**：队列 key 两边不一致。
插件从 `bs-exchange` 改名 `kokuu-exchange` 时，
`queue_key` 跟着改成了 `kokuu:exchange:queue`，但 MC 端没同步。
→ 皮肤站 RPOP 一个不存在的键，订单永远停在 `pending`，**不报错**。

**第二层（要挖才看得见）**：**phpredis 会偷偷加键前缀**。

内核 `config/database.php:124` 有：

```php
'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'blessing_skin'), '_') . '_database_'),
```

而本站 `.env` **没有 `APP_NAME`**（`env('APP_NAME')` 实测返回 `NULL`）
→ 前缀**恒为** `blessing_skin_database_`。
phpredis 在**扩展层**设这个前缀（`$redis->getOption(Redis::OPT_PREFIX)` 可实测），于是：

```php
Redis::lpush('bs:exchange:queue');   // 实际写入的是
                                     // blessing_skin_database_bs:exchange:queue
```

MC 端 [`exchange-bridge`](https://github.com/KokuuStudio/exchange-bridge) 用**裸 Jedis**
（`jedis.brpop(double, cfg.queueKey)`），键名是字面量、**没有前缀**
→ **双侧永远对不上**，而且完全静默。

> **所以「把 key 名字改成一样」是修不好的** —— 问题不在名字，在前缀。

**正解**：为兑换功能**单开一个清空前缀的连接**（`kokuu_exchange_redis()`）：

```php
$client = new \Redis();
$client->connect($host, $port);
$client->setOption(\Redis::OPT_PREFIX, '');                        // ★ 关键
$client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
```

⚠️ **绝不能改共享门面单例的前缀** —— Laravel 的 `Redis` 门面是单例，
改它的 `OPT_PREFIX` 会**污染同进程其他消费者**（session、cache 全遭殃）。

**自查一条命令**（在皮肤站机器上）：

```bash
redis-cli --scan --pattern '*exchange*'
# 若结果里出现 blessing_skin_database_bs:exchange:* → 就是踩了这个坑
```

⚠️ 连带现象：**`redis-cli` 看到的队列长度与 PHP app 看到的天然不同**
（一个按字面量、一个带前缀）。排查时别急着下「数据丢了」的结论。

### 2. `Hook::addRoute` 内层闭包必须再写一次 `use ($plugin)`

```php
Hook::addRoute(function ($router) use ($plugin) {
    $router->get('...', function (Request $request) use ($plugin) { /* ... */ });
    //                                            ^^^^^^^^^^^^^^ 别漏
});
```

PHP 闭包有**独立作用域**，外层的 `use` **不会**继承到内层。
漏写时路由**注册阶段完全正常**，只有真访问才
`Undefined variable $plugin` → 500。

### 3. 辅助函数必须定义在文件 `return` 闭包之**前**

`bootstrap.php` 顶层一旦遇到 `return` 就停止执行剩余部分,
函数永远不被声明 → `Call to undefined function`。

### 4. 视图用 `view()->file()`，不是布局继承

`plugin/kokuu-exchange` 与 `plugin/kokuu-exchange/orders` 都直接
`view()->file($plugin->getPath() . '/views/...')`。
它们是**独立渲染**，所以：

- 用 `{% extends 'user.base.twig' %}` 拿内核侧边栏必须写 **Twig**（Blade 做不到）
- **可用的 block 只有三个**：`title` / `content` / `before_foot`
  —— **没有 `{% block scripts %}`**，写了不渲染，JS/CSS 不会被加载。
  资源必须放 `before_foot`（在 `</body>` 之前）
- 插件自定义的 `kokuu_xxx()` 函数**在模板里一律不可调用**
  （twigbridge 只桥接了 `trans()` / `url()` / `auth_user()` / `config()` 等）
  → 所有派生计算（相对时间、状态文案、金额换算）必须在 PHP 侧算好再传进来
- `content-header` 已用 `{% block title %}` 渲染了 `<h1>`，
  模板里**不要重复写标题**，否则页面上出现两个

### 5. 浮点要避免

`cost_per_unit` 等一律用整数。积分与货币的量级不值得冒浮点精度风险。

---

## 六、数据表

`kokuu_exchange_ensure_tables()` 幂等建表：

| 表 | 关键列 |
|---|---|
| `exchange_orders` | `id` `uid` `player_id` `units` `cost` `status` `idempotency_key` `created_at` `updated_at` |

订单**强绑定 `player_id`**（`players` 表的具体角色）—— CMI 认玩家名，
扣的是那个角色对应的账户，所以必须有 `player_id` 才能精确发放。

---

## 七、依赖

```json
"require": {
  "kokuu-credit": "^1.0.0",
  "blessing-skin-server": "^6.0",
  "php": ">=8.1"
}
```

⚠️ **必须启用 [`kokuu-credit`](https://github.com/KokuuStudio/kokuu-credit)** ——
兑换扣的是它管的 `users.score`。

---

## License

MIT
