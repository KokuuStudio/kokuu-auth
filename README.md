# KokuuAuth · 皮肤站 + 积分经济

KOKUU 谷雨工作室的皮肤站与积分中线，合并为单仓 monorepo。

2026-10-05 由 4 个独立仓库合并而成（git subtree，完整提交历史保留）：

| 路径 | 原仓库 | 说明 |
| --- | --- | --- |
| `server/` | `kokuu-skin-server` | KokuuSkin 服务端。基于 [Blessing Skin Server](https://github.com/bs-community/blessing-skin-server) 的定制发行版：内核与官方 `dev` 分支一致，定制全部插件化 |
| `plugins/kokuu-credit` | `kokuu-credit` | 积分**唯一账本**（`users.score` + `credit_ledger` 流水），账号互通的 OAuth2 授权服务端 |
| `plugins/kokuu-exchange` | `kokuu-exchange` | 积分兑换游戏货币：订单状态机 + 幂等键 + 超时自动退款，与 MC 侧执行器成对使用 |
| `plugins/kokuu-coupon` | `kokuu-coupon` | 兑换码与抽奖：积分产出端，码发积分 / 发抽奖次数，抽奖吐回积分 |

## 部署关系

```
server/                    ← 皮肤站本体（nginx + php-fpm）
plugins/kokuu-credit       ← 必装：积分账本 + OAuth2 服务端
plugins/kokuu-exchange     ← 可选：积分 → 游戏货币（需 MC 侧执行器配合）
plugins/kokuu-coupon       ← 可选：积分产出（依赖 kokuu-credit）
```

Blessing Skin 插件即目录：把 `plugins/<name>` 拷/链接到皮肤站的 `plugins/` 下即可，
各插件自己的 README 有详细安装步骤。

## 为什么合并

- 一条业务线一个仓库：皮肤站与积分插件同发布、同版本演进，分散多仓徒增同步成本
- 插件间依赖（exchange/coupon → credit）在 monorepo 里一目了然
- 改动跨插件时一个 PR / 一次提交搞定

## 历史

合并前各仓库的开发记录（含全部踩坑文档）在各子目录的提交历史里，
原仓库已归档只读。subtree merge 提交：

- `server/` ← kokuu-skin-server@`56d537a`
- `plugins/kokuu-credit` ← kokuu-credit@`1beec9d`
- `plugins/kokuu-exchange` ← kokuu-exchange@`cbbcc32`
- `plugins/kokuu-coupon` ← kokuu-coupon@`f3ed63c`
