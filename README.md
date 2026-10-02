# KokuuSkin Server

> 雨落下之后，就交给时间。

**[KokuuSkin](https://github.com/KokuuStudio/kokuu-home)** —— 谷雨工作室皮肤站与登录验证服务
，基于 [Blessing Skin Server](https://github.com/bs-community/blessing-skin-server) 的定制版本。

[![Fork](https://img.shields.io/badge/fork-blessing--skin--server-5C6BC0?style=flat-square)](https://github.com/bs-community/blessing-skin-server)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

---

## 这是什么

上游 Blessing Skin Server 的**定制发行版**。内核保持与官方 `dev` 分支一致
（便于日后 `git pull` 升级），定制内容全部以**插件形式**实现，不修改内核源码。

| | |
| --- | --- |
| 上游版本 | `dev` @ 52f6fef（2026-03-30） |
| 应用名 | `KokuuSkin` |
| 定制方式 | 100% 插件化，内核零改动 |

## 配套插件

| 插件 | 说明 |
| --- | --- |
| **[kokuu-home](https://github.com/KokuuStudio/kokuu-home)** | 全站主题：Apple 扁平首页（MC 场景轮播）、双主题、表单式自定义 |
| **[kokuu-ui](https://github.com/KokuuStudio/kokuu-ui)** | 后台界面重塑：Apple 扁平设计系统、流畅动画、提瓦特元素 |
| **[kokuu-quote](https://github.com/KokuuStudio/kokuu-quote)** | 提瓦特一言：顶栏短句，可后台增删 |

**安装顺序**：`kokuu-home` → `kokuu-ui` → `kokuu-quote`

> Blessing Skin 扫描插件时，若发现 `require` 依赖未启用会**自动禁用**该插件，
> 因此必须按上述顺序安装与启用。

## 快速开始

### 环境要求

| | 版本 |
| --- | --- |
| PHP | ≥ 8.1（需 `imagick`、`pdo_sqlite`/`pdo_mysql`、`zip`、`openssl`） |
| Composer | ≥ 2 |
| Node.js | ≥ 16（仅构建前端时需要） |

### 安装

```bash
git clone git@github.com:KokuuStudio/kokuu-skin-server.git
cd kokuu-skin-server

composer install
npm install && npm run build        # 构建前端资源到 public/app/

cp .env.example .env                # 生成环境配置
php artisan bs:install              # 交互式初始化（建库、创建管理员）
```

### 启用插件

将插件 zip 上传到 **管理后台 → 插件管理 → 安装插件**，或解压到 `plugins/` 后：

```bash
php artisan plugin:enable kokuu-home
php artisan plugin:enable kokuu-ui
php artisan plugin:enable kokuu-quote
```

启用后核心会自动把各插件的 `assets/` 同步到 `public/plugins/<name>/assets/`。

## 生产环境建议

**不要用 `php artisan serve`** —— 它是 PHP 单进程开发服务器，请求串行处理，
在皮肤库/衣柜这类数据页面上通常慢 5–10 倍。推荐：

| 方案 | 说明 |
| --- | --- |
| **nginx + php-fpm**（推荐） | 生产标准配置 |
| Caddy + php-fpm | 配置更简洁，自带 HTTPS |
| Windows + IIS + PHP-FPM | 纯 Windows 环境可用 |

配合以下优化项：

```bash
php artisan config:cache     # 配置缓存
php artisan route:cache      # 路由缓存
php artisan view:cache       # 视图缓存
php artisan event:cache      # 事件缓存（可选）
```

并确保 PHP 启用 **OPcache**（`opcache.enable=1`、`opcache.revalidate_freq=0`
用于开发，生产建议 60）。

## 与上游的差异

```
内核源码   与官方 dev 分支保持一致（仅 CRLF 行尾符差异）
应用名     config/app.php 的 APP_NAME 默认值改为 KokuuSkin
新增       plugins/{kokuu-home,kokuu-ui,kokuu-quote}/
```

升级上游：

```bash
git remote add upstream https://github.com/bs-community/blessing-skin-server.git
git fetch upstream dev
git merge upstream/dev          # 内核无本地改动，通常可直接合并
```

## 目录速览

```
kokuu-skin-server/
├── app/                 # 内核：模型、控制器、服务
├── resources/
│   ├── assets/          # 前端源码（React + emotion）
│   └── views/           # Twig 视图
├── plugins/             # ← 定制内容全在这里
│   ├── kokuu-home/
│   ├── kokuu-ui/
│   └── kokuu-quote/
├── public/app/          # 前端构建产物（npm run build 生成）
├── routes/
└── config/
```

## 许可

内核代码版权归 [BS-Community](https://github.com/bs-community) 及贡献者所有，
遵循其 MIT 许可。本仓库的定制部分（三个插件）以 MIT 发布。

「Minecraft」相关商标归 Mojang Studios 所有；「原神 / 提瓦特」相关名称与内容
权利归 miHoYo 所有，相关插件仅作个人学习与非商业展示。
