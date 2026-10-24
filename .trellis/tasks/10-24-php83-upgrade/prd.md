# PRD — PHP 8.3 + Laravel 12 升级（仅易支付）

## Goal

把独角数卡从 PHP 7.4 / Laravel 6 / dcat 2.0.24 升级到 **PHP 8.3 / Laravel 12 / 自持 dcat fork**。
本期支付只启用**易支付（epay）**，其他渠道代码保留但默认禁用；数据库只做增量变更，旧库可平滑升级；同时完成一轮安全加固。

## Background / confirmed facts

- 当前 `composer.json`：`php ^7.2.5|^8.0`、`laravel/framework ^6.20.26`、`dcat/laravel-admin 2.*`。
- 当前 Dockerfile：`webdevops/php-nginx:7.4`。
- 支付实现：易支付 = `app/Http/Controllers/Pay/YipayController.php`（纯 PHP + MD5，无 SDK）。
- 官方直连支付宝/微信 = `AlipayController` / `WepayController`，依赖 `yansongda/pay v2`（与 Laravel 12 冲突）。
- PayPal = `PaypalPayController` + `paypal/rest-api-sdk-php`（与 Laravel 12 冲突）。
- 其他自写渠道：V免签、Paysapi、码支付、PAYJS、Epusdt、TokenPay、Coinbase、Stripe。
- 数据库：`database/sql/install.sql` 一次性建表，无 app migrations；`migrations` 表为空。
- dcat 自持 fork：`Cecil98/dcat-admin`，branch `2.2.4`，tag `v2.2.5`，commit `8ff1f822`。
- 已在 Docker 验证：Laravel 12 + dcat `v2.2.5` 可安装/`admin:install`/启动；PHP 8.3 登录页 200 且 E_ALL 无 deprecation。

## Requirements

- R1 运行时升级到 PHP 8.3（最低支持 8.2），Laravel 12（≥12.69）。
- R2 后台使用自持 dcat fork `dcat/laravel-admin:2.2.5`（VCS repository），namespace 仍为 `Dcat\Admin\`。
- R3 删除 `yansongda/pay`、`AlipayController`、`WepayController`、`PaypalPayController`、`paypal/rest-api-sdk-php` 及其路由。
- R4 删除 `simplesoftwareio/simple-qrcode`、`fideloper/proxy`、`germey/geetest`、`amrshawky/laravel-currency`（用 `amrshawky/currency` 替代）。
- R5 保留其他自写渠道代码，默认所有 `pays.is_open=0`；易支付配置好真实凭据后再手动启用，升级脚本不自动覆盖运营者的开关。
- R6 易支付链路保持现有行为：下单 → 跳转 → 异步回调验签 → 金额校验 → 幂等发货。
- R7 数据库只做增量、幂等变更：新增 `admin_extensions`、`admin_extension_histories`、`failed_jobs.uuid`、4 条 dcat migrations 记录；修正 `pays` 数据。
- R8 旧库升级后，旧订单/商品/卡密/优惠码/后台账号均可正常读写；回滚旧镜像无需删表删列。
- R9 完成安全加固：默认 `admin/admin` 处理、`.env` 移出版本库并轮换 APP_KEY、`/uploads` 禁 PHP 执行、后台登录限流、Session/Cookie 安全、支付密钥加密/打码、安全响应头、`composer audit` 门禁。
- R10 Dockerfile / Dockerfile-ARM64 / conf 切换到 PHP 8.3；`composer install` 去掉 `--ignore-platform-reqs`。
- R11 邮件代码适配 Laravel 9+ 的 Symfony Mailer（`Mail::purge()` + 新 `mail.mailers` 配置）。
- R12 语言包从 `resources/lang` 移到 `lang/`。
- R13 已有测试（支付网关绑定、回调安全、订单完成契约）继续通过；不新增破坏性测试。

## Acceptance Criteria

- [ ] AC1 全新安装（`install.sql`）和旧库升级（`upgrade-php83.sql`）两种路径都能完成，后台可登录。
- [ ] AC2 `php artisan route:list` 通过，`admin/*`、`dcat-admin-api/*` 路由存在；不存在 `pay/alipay`、`pay/wepay`、`pay/paypal` 路由。
- [ ] AC3 配置真实凭据并启用易支付后：易支付-支付宝 / 微信 / QQ 下单 → 回调 → 订单完成；重复回调不重复发货；金额不一致被拒绝；占位凭据被拒绝。
- [ ] AC4 默认所有渠道 `is_open=0`，前台不显示支付方式；配置并启用易支付后仅易支付显示，其他渠道代码文件仍保留。
- [ ] AC5 旧库升级后 `php artisan admin:update` 输出 `Nothing to migrate`；`failed_jobs` 有 `uuid` 列；旧业务数据可正常查询。
- [ ] AC6 `composer audit` 无 high/critical；`composer install` 不再需要 `--ignore-platform-reqs`。
- [ ] AC7 后台 商品/订单/卡密/优惠码/支付配置/邮件模板 CRUD 正常；卡密导出 xlsx 正常。
- [ ] AC8 邮件测试 + 下单邮件（队列）正常。
- [ ] AC9 安全清单通过：无 `admin/admin`、`/uploads/*.php` 403、后台登录限流、Cookie `Secure`+`SameSite=Lax`、支付密钥不明文显示、安全响应头齐全。
- [ ] AC10 `php artisan queue:work` 正常；无高频 `Deprecated` 日志。
- [ ] AC11 回滚演练：切回旧镜像 + 旧 tag，旧代码可正常读库。

## Out of scope

- 微信支付 APIv3 / 支付宝 v3 官方直连（本期删除，未来按 yansongda v3 另立任务）。
- PayPal 重新接入。
- Stripe / PAYJS / V免签 等功能性迁移（保留代码、默认禁用，仅做 PHP 8.3 兼容）。
- 后台 UI 重写 / Filament 迁移。
- 正式渗透测试 / 全量 SAST（dcat 已做定向审计，主仓库安全项按 R9 执行）。
- PHP 8.4（已验证可行，但需要 captcha 约束补丁；本期不做）。

## Key decisions

| 决策 | 结论 |
|---|---|
| PHP | 8.3（8.2 最低，8.2 EOL 2026-12） |
| Laravel | 12.x（≥12.69） |
| 应用结构 | 保留 Laravel 6/10 旧骨架（Kernel/RouteServiceProvider/Handler） |
| 后台 | 自持 fork `Cecil98/dcat-admin` tag `v2.2.5` |
| 支付 | 只启用易支付；删除 yansongda/官方直连/PayPal |
| 数据库 | 只加不删；幂等脚本 |
| 默认后台账号 | 升级时改随机强密码 + 首次登录强制改密 |

## Risks

- dcat fork 是小项目维护基线 → 已自持 tag + `PATCHES.md`，后续可继续打补丁。
- 易支付不同服务商字段/签名差异 → 以现有配置回归为准。
- 轮换 APP_KEY 会让现有会话失效（可接受，需预发验证）。
- 支付密钥加密与 APP_KEY 绑定 → APP_KEY 必须单独备份。
- 无 CI → 先在本地/预发用 Docker 跑完整回归。
