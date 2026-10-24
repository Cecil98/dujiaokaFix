# Design — PHP 8.3 + Laravel 12 升级

## 1. Target stack

| 层 | 目标 |
|---|---|
| Runtime | PHP 8.3（`webdevops/php-nginx:8.3`），扩展：bcmath/gd/zip/intl/pdo_mysql/redis/opcache/dom/xml/mbstring/curl |
| Framework | Laravel 12.x（`^12.69`），保留旧版应用骨架 |
| Admin | `dcat/laravel-admin` = 自持 fork `Cecil98/dcat-admin` tag `v2.2.5`（VCS repository） |
| Payment | 易支付（`YipayController`）启用；其他渠道代码保留、`is_open=0` |
| Queue/Cache | Redis（保留现状） |
| Web | php-nginx + supervisor（保留现状） |

## 2. Architecture / boundaries

```
routes/common/pay.php
  └─ /pay/yipay/{payway}/{orderSN}   → YipayController@gateway
  └─ /pay/yipay/notify_url           → YipayController@notifyUrl
  └─ /pay/yipay/return_url           → YipayController@returnUrl

PayController (base)
  ├─ loadGateWay(orderSN, payCheck)  校验订单/网关/pay_id
  ├─ isExpectedGatewayRoute()        校验 pay_handleroute
  ├─ secureCompare()                 hash_equals 验签
  └─ OrderProcessService::completedOrder()
        ├─ DB transaction
        ├─ 状态幂等 / trade_no 复用校验
        ├─ bccomp 金额校验
        ├─ 自动/手动发货
        └─ 事件/推送
```

边界原则：

- 支付渠道只负责「下单参数 + 回调解析 + 验签」，订单状态/发货统一走 `OrderProcessService`。
- 本次不改 `OrderProcessService` 的契约，只做框架适配和 PHP 8.3 兼容。
- 删除的渠道不保留路由；保留的渠道路由不变，仅通过 `pays.is_open` 控制前台展示。

## 3. Dependency / config changes

### 3.1 composer.json

```json
{
  "require": {
    "php": "^8.3",
    "laravel/framework": "^12.69",
    "laravel/tinker": "^2.11",
    "dcat/laravel-admin": "2.2.5",
    "dcat/easy-excel": "^1.1",
    "stripe/stripe-php": "^18.0",
    "mews/captcha": "^3.5.1",
    "amrshawky/currency": "^1.0",
    "jenssegers/agent": "^2.6",
    "xhat/payjs-laravel": "^1.6",
    "guzzlehttp/guzzle": "^7.9"
  },
  "repositories": [
    { "type": "vcs", "url": "https://github.com/Cecil98/dcat-admin" }
  ]
}
```

删除：`yansongda/pay`、`paypal/rest-api-sdk-php`、`simplesoftwareio/simple-qrcode`、`fideloper/proxy`、`germey/geetest`、`amrshawky/laravel-currency`。

### 3.2 框架适配点

| 文件 | 变更 |
|---|---|
| `app/Exceptions/Handler.php` | `report(Throwable)` / `render($request, Throwable)` |
| `app/Http/Kernel.php` | `CheckForMaintenanceMode` → `PreventRequestsDuringMaintenance`；`$routeMiddleware` → `$middlewareAliases` |
| `app/Http/Middleware/TrustProxies.php` | `extends Illuminate\Http\Middleware\TrustProxies`；header 常量 |
| `config/mail.php` | `default` + `mailers.smtp` 新结构 |
| `app/Jobs/MailSend.php`、`app/Admin/Forms/EmailTest.php` | `config([...])` + `Mail::purge('smtp')` |
| `resources/lang` → `lang/` | `git mv` |
| `config/filesystems.php` | 保留 `admin` disk（`public/uploads`） |
| `config/admin.php` | 以 dcat 2.2.5 为基线合并，`upload.disk='admin'` |
| `bootstrap/app.php` / `public/index.php` / `artisan` | 保留旧骨架，不改 |
| `database/factories/OrderFactory.php` | 改为类式工厂或删除 |
| `phpunit.xml` | PHPUnit 11 schema |

### 3.3 支付删除清单

| 删除项 | 内容 |
|---|---|
| 依赖 | `yansongda/pay`、`paypal/rest-api-sdk-php` |
| 控制器 | `AlipayController`、`WepayController`、`PaypalPayController` |
| 路由 | `routes/common/pay.php` 中 `alipay/*`、`wepay/*`、`paypal/*` |
| 数据 | 对应 `pays.pay_handleroute` 行 `is_open=0`（保留记录） |

### 3.4 保留但禁用

`YipayController` 保持启用；V免签、Paysapi、码支付、PAYJS、Epusdt、TokenPay、Coinbase、Stripe 代码保留，`pays.is_open=0`。

## 4. Database migration (additive, idempotent)

脚本：`database/sql/upgrade-php83.sql`

1. `CREATE TABLE IF NOT EXISTS admin_extensions (...)`。
2. `CREATE TABLE IF NOT EXISTS admin_extension_histories (...)`。
3. `migrations` 插入 4 条 dcat 记录（`WHERE NOT EXISTS`）。
4. `failed_jobs` 增加 `uuid`（`information_schema` 判断 + 可空唯一索引）。
5. `UPDATE pays SET is_open=0; UPDATE pays SET is_open=1 WHERE pay_handleroute='/pay/yipay';`。
6. `install.sql` 同步以上结构 + 默认数据。

不修改任何旧列；回滚旧代码时新表/新列被忽略。

## 5. Security design

| 项 | 方案 |
|---|---|
| 默认账号 | 升级脚本重置为随机密码；首次登录强制改密（dcat 中间件或登录事件） |
| APP_KEY | `git rm --cached .env`；生产/预发轮换；`.env.example` 只留占位 |
| 上传目录 | nginx `location ^~ /uploads/ { location ~ \.php$ { deny all; } }` |
| 登录限流 | 覆写 `app/Admin/Controllers/AuthController::postLogin`，RateLimiter |
| Session | `SESSION_SECURE_COOKIE=true`、`SESSION_SAME_SITE=lax`、`SESSION_ENCRYPT` 可选 |
| 支付密钥 | `pays.merchant_key/merchant_pem` 用「旧数据兼容」cast（明文 fallback，写入加密）；后台打码 |
| 响应头 | HSTS/Referrer-Policy/Permissions-Policy；`server_tokens off`；`expose_php=Off` |
| 依赖 | 发布前 `composer audit`，high/critical 阻断 |
| 权限 | 容器非 root、目录 755/775、仅 storage/cache/uploads 可写 |

## 6. Deployment

```
git tag v2.1.0-php83
docker build -t <registry>/dujiaoka:2.1.0-php83 .
docker push ...
服务器: docker compose pull && up -d
容器内: php artisan admin:publish --assets --force
        php artisan admin:publish --lang --force
        php artisan admin:update   # Nothing to migrate
```

## 7. Trade-offs

- 选 PHP 8.3 而非 8.4：零 captcha 约束补丁；8.4 验证结果留档。
- 选自持 dcat fork 而非第三方包：供应链可控；代价是后续需自己同步 upstream。
- 保留其他渠道代码但禁用：满足「先别删」；代价是 `composer` 仍有少量依赖（Stripe/PAYJS）。
- 不重写后台：7–10 人日；Filament 重写 20–30 人日，不在本期。

## 8. Rollback

- 代码：保留升级前 tag（`v2.0.6-php74`）与旧镜像 tag。
- 数据库：只增不删；回滚旧镜像无需回滚 DB；`pays.is_open` 可随时改回。
- 加密：若启用，回滚前执行 `php artisan pay:decrypt-secrets`。
- 灰度：预发 → 小流量 → 全量；支付回调域名提前切换。
