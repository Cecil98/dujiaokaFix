# Research — PHP 8.3 / Laravel 12 升级关键结论

> 精简版上下文，完整分析见 `docs/php8.2-upgrade-plan.md`。

## 目标版本

- PHP **8.3**（`webdevops/php-nginx:8.3`），最低 8.2；8.2 于 2026-12 EOL。
- Laravel **12.x（≥12.69）**，保留旧应用骨架（`app/Http/Kernel.php`、`RouteServiceProvider`、`config/app.php` providers）。
- dcat：自持 fork `Cecil98/dcat-admin`，tag **`v2.2.5`**（commit `8ff1f822`），package 名仍是 `dcat/laravel-admin`，namespace `Dcat\Admin\`。

## 支付范围（已确认）

- **只启用易支付**：`app/Http/Controllers/Pay/YipayController.php`（纯 PHP + MD5，无 SDK）。
  - 路由：`/pay/yipay/{payway}/{orderSN}`、`/pay/yipay/notify_url`、`/pay/yipay/return_url`。
  - `pays` 表 id 14/15/16（易支付-支付宝/微信/QQ）。
- **删除**：`yansongda/pay`、`AlipayController`、`WepayController`、`routes/common/pay.php` 的 `alipay/*`/`wepay/*`；`PaypalPayController`、`paypal/*`、`paypal/rest-api-sdk-php`。
- **保留但禁用**：V免签、Paysapi、码支付、PAYJS、Epusdt、TokenPay、Coinbase、Stripe（代码不删，`pays.is_open=0`）。
- 汇率：`amrshawky/laravel-currency` → `amrshawky/currency ^1.0`（Stripe 用）。

## 依赖变更

删除：`yansongda/pay`、`paypal/rest-api-sdk-php`、`simplesoftwareio/simple-qrcode`、`fideloper/proxy`、`germey/geetest`、`amrshawky/laravel-currency`。

升级：`mews/captcha ^3.5.1`、`stripe/stripe-php ^18`、`spatie/laravel-ignition ^2.9`、`nunomaduro/collision ^8`、`phpunit ^11`、`fakerphp/faker ^1.23`、`mockery ^1.6`、`laravel/tinker ^2.11`。

## 框架适配（L6 → L12）

| 文件 | 变更 |
|---|---|
| `app/Exceptions/Handler.php` | `report(Throwable)` / `render($request, Throwable)` |
| `app/Http/Kernel.php` | `CheckForMaintenanceMode` → `PreventRequestsDuringMaintenance`；`$routeMiddleware` → `$middlewareAliases` |
| `app/Http/Middleware/TrustProxies.php` | 用 `Illuminate\Http\Middleware\TrustProxies`；删 `config/trustedproxy.php` |
| `config/mail.php` | `default` + `mailers.smtp`（L9+ 结构） |
| `app/Jobs/MailSend.php`、`app/Admin/Forms/EmailTest.php` | `config([...])` + `Mail::purge('smtp')` |
| `resources/lang` → `lang/` | 语言目录 |
| `config/filesystems.php` | 保留 `admin` disk = `public/uploads` |
| `config/admin.php` | dcat 2.2.5 基线 + 保留 `upload.disk='admin'` |
| `bootstrap/app.php` / `public/index.php` / `artisan` | 不改（旧骨架 L12 仍支持） |
| `database/factories/OrderFactory.php` | 旧 `$factory->define` 需改类式或删除 |
| `phpunit.xml` | PHPUnit 11 schema；`MAIL_DRIVER`→`MAIL_MAILER` |

## 数据库（只加不删、幂等）

脚本：`database/sql/upgrade-php83.sql`

1. `CREATE TABLE IF NOT EXISTS admin_extensions` / `admin_extension_histories`（dcat 2.2 需要）。
2. `migrations` 插入 4 条 dcat 记录（`WHERE NOT EXISTS`）。
3. `failed_jobs` 增加 `uuid`（可空 + 唯一索引；Laravel 8+ 需要）。
4. `UPDATE pays SET is_open=0; UPDATE pays SET is_open=1 WHERE pay_handleroute='/pay/yipay';`
5. `install.sql` 同步以上内容。

不改业务表（orders/goods/pays/carmis/coupons/emailtpls）结构。

## 安全加固（P4）

- 默认 `admin/admin` → 随机强密码 + 首次登录强制改密（`install.sql:214` 已确认是 admin/admin）。
- `.env` 在 git 中（含 APP_KEY、`APP_DEBUG=true`）→ `git rm --cached .env`、轮换 APP_KEY、生产 `APP_DEBUG=false`。
- `/public/uploads` 可执行 PHP → nginx `/uploads` 禁 `.php`。
- 后台登录无限流（dcat `AuthController::postLogin` 无 `ThrottlesLogins`）→ RateLimiter。
- Session：`secure=false`、`same_site=null` → `Secure` + `SameSite=Lax`。
- 支付密钥明文 → 兼容 cast 加密 + 后台打码（先轮换 APP_KEY 再启用）。
- 安全响应头、`expose_php=Off`、`composer audit` 门禁、容器非 root、目录权限。

## 关键验证命令

```bash
composer validate
composer audit
php artisan route:list
php artisan admin:update        # 期望 Nothing to migrate
php artisan queue:work --stop-when-empty
php -l <changed files>
```

## 已知风险

- dcat fork 为小项目维护基线：已自持 tag + `PATCHES.md`。
- 轮换 APP_KEY 会让现有会话失效。
- 支付密钥加密与 APP_KEY 绑定，APP_KEY 需单独备份。
- 无 CI：本地/预发用 Docker 跑完整回归。
