# KokuuSkin 部署与优化指南

面向**低配服务器**（小内存 / 弱 CPU）的部署与调优说明。
每项都标注了预期收益与验证方法。

---

## 一、环境要求与最低配置

| 资源 | 最低 | 建议 | 说明 |
| --- | --- | --- | --- |
| 内存 | 512 MB | 1 GB | PHP-FPM 每个 worker 约 30–60 MB |
| CPU | 1 核 | 2 核 | SQLite 读多写少，单核够用 |
| 磁盘 | 10 GB | 20 GB SSD | 纹理图片占空间，**务必用 SSD** |
| PHP | 8.1+ | 8.2 / 8.3 | 需 `imagick`、`pdo_sqlite`、`zip`、`openssl` |

> ⚠️ **不要用机械硬盘**。纹理读取是随机 I/O，HDD 会让页面慢 5–10 倍。

---

## 二、Web 服务器（最重要的优化）

**绝对不要用 `php artisan serve`** —— 它是 PHP 单进程开发服务器，
每个请求都要重新解析 PHP 文件、请求串行处理。

实测对比（本项目，同一页面）：

| 方式 | 首页响应 | 说明 |
| --- | --- | --- |
| `artisan serve` | ~700 ms | 单进程，无 OPCache 持久化 |
| **nginx + php-fpm** | **通常 60–150 ms** | 快 5–10 倍 |

### 推荐配置

**nginx**（最小可用）：

```nginx
server {
    listen 80;
    server_name your.domain.com;
    root /path/to/kokuu-skin-server/public;
    index index.php;

    # 静态资源直接由 nginx 返回，不进 PHP
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    # 纹理/皮肤图片：长缓存（文件名含内容哈希或不变）
    location ~* \.(png|jpg|jpeg|gif|webp|svg)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # 前端构建产物：内容哈希，可永久缓存
    location /app/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    client_max_body_size 16m;   # 上传皮肤需要
}
```

---

## 三、PHP 配置（关键）

在 `php.ini` 或 PHP-FPM pool 中设置：

```ini
; ===== OPcache：最重要的单项 =====
; 关闭它 PHP 每次请求都要重新解析所有 PHP 文件
opcache.enable=1
opcache.memory_consumption=192      ; MB，按物理内存的 25% 分配
opcache.max_accelerated_files=20000 ; Laravel 项目文件多，1 万不够
opcache.revalidate_freq=0           ; 开发时改代码立即生效；生产可设 60
opcache.save_comments=1             ; 保留注释（某些包依赖）
opcache.jit=0
opcache.jit_buffer_size=0

; ===== 内存与执行 =====
memory_limit=256M
max_execution_time=60
post_max_size=16M
upload_max_filesize=16M

; ===== 生产环境错误信息不外泄 =====
display_errors=Off
log_errors=On
```

**验证 OPcache 是否生效**：

```bash
php -r "var_dump(opcache_get_status(false)['opcache_enabled']);"
# 期望输出：bool(true)
```

> 💡 低配服务器内存紧张时，`opcache.memory_consumption` 建议 128M 起步，
> 观察 `memory_get_usage()` 再调。

---

## 四、Laravel 缓存

```bash
php artisan config:cache      # 配置缓存，免去每次解析 config/*.php
php artisan route:cache       # 路由缓存（本项目已修复上游 bug，见下）
php artisan optimize          # 等价于 config + route
```

**代码更新后必须清缓存**：

```bash
php artisan optimize:clear    # 或分别 config:clear / route:clear / view:clear
```

### 已修复的上游 bug

上游 `routes/web.php` 中：

```php
Route::view('manage', 'admin.plugins')->name('view');
Route::post('manage', 'PluginController@manage')->name('view');  // ← 同名
```

两条路由用了同一个名字 `plugins.view`，导致 `route:cache` 直接抛异常
**完全无法启用**。本仓库已改为 `view` / `manage`，现在可正常使用。

> `view:cache` 命令不适用于本项目 —— Blessing Skin 用 **Twig** 而非 Blade，
> 该命令只扫描 `*.blade.php`。Twig 自身有独立缓存
> （编译结果在 `storage/framework/views/`，首次访问后自动生成）。

---

## 五、数据库索引（本仓库已内置）

上游建表迁移**未对任何业务查询字段建索引**。数据量增长后这些查询会全表扫描：

| 查询 | 触发场景 | 索引 |
| --- | --- | --- |
| `users.email` | 每次登录 | ✅ 唯一索引 |
| `textures.hash` | 每次上传（去重） | ✅ |
| `textures.uploader` | 「我上传的」 | ✅ |
| `textures.type` / `public` | 皮肤库列表 | ✅ |
| `user_closet.user_uid` / `texture_tid` | 衣柜页 | ✅ |
| `players.uid` | 角色列表 | ✅ |
| `users.nickname` | 后台搜索 | ✅ |

**实测收益**（5000 纹理 / 500 用户）：

| 查询 | 无索引 | 有索引 | 提升 |
| --- | --- | --- | --- |
| 登录 `WHERE email` | 0.084 ms | 0.055 ms | **1.5×** |
| 纹理去重 `WHERE hash` | 0.269 ms | 0.049 ms | **5.5×** |
| 衣柜 `WHERE user_uid` | 0.043 ms | 0.044 ms | 1.0×（数据量小） |

> 数据量越大差距越悬殊。10 万条纹理时，去重查询会从**几百毫秒**降到
**亚毫秒**。衣柜表当前数据少所以持平，但增长后同样受益。

**部署时自动生效**（迁移文件已随仓库分发）：

```bash
php artisan migrate
```

---

## 六、SQLite 调优

小内存服务器用 SQLite 是合理选择，但要注意：

### 1. 启用 WAL 模式（显著提升并发读写）

```php
// app/Providers/AppServiceProvider.php 的 boot() 中
if (\DB::connection()->getDriverName() === 'sqlite') {
    \DB::statement('PRAGMA journal_mode=WAL;');   // 读写不互相阻塞
    \DB::statement('PRAGMA synchronous=NORMAL;'); // 略降持久性，大幅提速
    \DB::statement('PRAGMA cache_size=-32000;'); // 32MB 页缓存
}
```

> 本仓库已集成上述配置（见 `app/Providers/AppServiceProvider.php`）。

### 2. MySQL 用户建议

如果数据量较大或并发较高，可换 MySQL/MariaDB：

```sql
-- utf8mb4_general_ci 大小写不敏感（用户名/邮箱查找更快）
CREATE DATABASE kokuu CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

并在 `config/database.php` 中确保索引长度适配（`utf8mb4` 下
`VARCHAR(255)` 唯一索引需指定前缀长度，Laravel 的 `Schema` 会自动处理）。

---

## 七、静态资源与缓存头

### 前端构建产物

本仓库**已分发 `public/app/`**（含内容哈希的 JS/CSS），clone 后无需
`npm run build`。nginx 已配置 `immutable` 长缓存。

### 浏览器端

- 插件 CSS/JS 由 `CopyPluginAssets` 发布到 `public/plugins/`，同样建议长缓存
- 若修改了插件资源，文件名**不变**，浏览器可能拿到旧缓存 →
  开发时用 `Ctrl+F5`，生产环境建议给插件资源加版本号查询串
  （插件 URL 已自动携带 `?v=<version>`，升级插件版本号即失效缓存）

---

## 八、定时任务（可选）

Blessing Skin 可配置定时清理任务，`routes/console.php` 中已定义。
用 cron 调用 Laravel scheduler：

```cron
* * * * * cd /path/to/kokuu-skin-server && php artisan schedule:run >> /dev/null 2>&1
```

---

## 九、部署检查清单

```bash
# 1. 依赖
composer install --no-dev --optimize-autoloader

# 2. 环境配置
cp .env.example .env
php artisan key:generate
# 编辑 .env：APP_ENV=production、APP_DEBUG=false、APP_URL、数据库

# 3. 缓存（务必在 APP_ENV=production 之后）
php artisan config:cache
php artisan route:cache
php artisan migrate --force

# 4. 目录权限
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# 5. 启用插件（按依赖顺序！）
php artisan plugin:enable kokuu-home
php artisan plugin:enable kokuu-ui
php artisan plugin:enable kokuu-quote
```

### `.env` 生产配置要点

```ini
APP_ENV=production        # ← 关键，影响错误处理与调试
APP_DEBUG=false           # ← 必须 false，否则错误页会泄露路径
APP_URL=https://your.domain.com

# OPcache 相关（若在 php.ini 则无需放这里）
CACHE_DRIVER=file
SESSION_DRIVER=file
SESSION_LIFETIME=120
```

---

## 十、性能自检清单

部署后按顺序验证：

```bash
# 1. OPcache
php -r "var_dump(opcache_get_status(false)['opcache_enabled']);"   # bool(true)

# 2. 缓存已生成
ls -la bootstrap/cache/config.php bootstrap/cache/routes*.php

# 3. 索引已建立
php artisan tinker --execute="print_r(\Illuminate\Support\Facades\DB::select('PRAGMA index_list(textures)'));"

# 4. APP_DEBUG 已关闭
grep APP_DEBUG .env    # APP_DEBUG=false

# 5. 页面响应时间
curl -o /dev/null -w "首页: %{time_total}s\n" https://your.domain.com/
# 期望 < 0.3s（php-fpm + 缓存 + 索引齐备）
```

---

## 附：性能优化优先级

如果服务器资源有限，按**性价比**排序：

| 优先级 | 措施 | 收益 | 成本 |
| --- | --- | --- | --- |
| ⭐⭐⭐⭐⭐ | 换 php-fpm（别用 serve） | **5–10×** | 改配置 |
| ⭐⭐⭐⭐⭐ | 启用 OPcache | **2–3×** | 改 php.ini |
| ⭐⭐⭐⭐ | 启用 config/route 缓存 | 1.3–1.5× | 3 条命令 |
| ⭐⭐⭐ | 数据库索引（已内置） | 随数据量增长 | 已完成 |
| ⭐⭐⭐ | SQLite 开 WAL | 1.2–2× | 一行代码 |
| ⭐⭐ | 静态资源长缓存头 | 减少重复传输 | nginx 配置 |
| ⭐ | `APP_DEBUG=false` | 减少异常开销 | 一行配置 |

**结论**：前两项（php-fpm + OPcache）是决定性的，
本仓库已内置的索引与路由修复是第二梯队。
