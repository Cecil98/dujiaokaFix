# Implement — PHP 8.3 + Laravel 12 升级

> 顺序：P0 → P6。每步完成即验证，不通过不进入下一步。
> 详细背景见 `docs/php8.2-upgrade-plan.md`，技术设计见 `design.md`。

## P0 准备

- [x] `git checkout -b upgrade/php83`（从 master）
- [ ] 备份当前生产 `.env` / 数据库快照（部署时）
- [x] 记录升级前基线：`composer audit`、现有测试结果
- [x] 确认 PHP 8.3 镜像可拉取：`docker pull webdevops/php-nginx:8.3`

验证：

```bash
php -v   # 容器内 8.3.x
```

## P1 框架 & 依赖

- [x] 改 `composer.json`：php ^8.3、Laravel ^12.69、dcat VCS `2.2.5`、删废弃包
- [x] `composer update`（Docker PHP 8.3）
- [x] `app/Exceptions/Handler.php` 签名改 `Throwable`
- [x] `app/Http/Kernel.php`：`PreventRequestsDuringMaintenance`、`$middlewareAliases`
- [x] `app/Http/Middleware/TrustProxies.php` 用 Laravel 内置基类；删除 `CheckForMaintenanceMode.php`
- [x] `config/mail.php` 新结构；`MailSend` / `EmailTest` 用 `Mail::purge()`
- [x] `resources/lang` → `lang/`
- [x] `config/filesystems.php` / `config/admin.php` 合并
- [x] `AppServiceProvider` 移除 geetest provider；内置 geetest
- [x] `phpunit.xml` 适配 PHPUnit 11；`OrderFactory` 改类式或删除

验证：

```bash
composer validate
php artisan route:list
php artisan config:cache && php artisan config:clear
php -l <changed files>
```

## P2 后台 dcat

- [x] `composer require dcat/laravel-admin:2.2.5`（VCS repo）
- [x] `php artisan admin:publish --assets --force`
- [x] `php artisan admin:publish --lang --force`
- [x] 执行 `database/sql/upgrade-php83.sql`（2 张表 + migrations + failed_jobs.uuid）
- [x] `php artisan admin:update` → `Nothing to migrate`
- [x] 修 dcat 动态属性/弃用告警（E_ALL 跑后台页面）

验证：

```bash
php artisan admin:install --no-interaction   # 仅全新库
php artisan admin:update
```

## P3 支付收敛

- [x] 删除 `AlipayController` / `WepayController` / `PaypalPayController`
- [x] 删除 `routes/common/pay.php` 对应路由
- [x] 删除 `yansongda/pay`、`paypal/rest-api-sdk-php`
- [x] `amrshawky/laravel-currency` → `amrshawky/currency`（Stripe 汇率 2 处）
- [x] 保留渠道 `pays.is_open=0`；易支付三条 `is_open=1`
- [x] 易支付验签/金额/幂等回归

验证：

```bash
php artisan route:list | grep -E "pay/(yipay|alipay|wepay|paypal)"
# 期望只有 pay/yipay*
```

## P4 安全加固

- [x] 默认 `admin/admin` 重置/强制改密
- [x] `.env` 移出版本库；轮换 APP_KEY；`APP_DEBUG=false`
- [x] nginx `/uploads` 禁 PHP；`expose_php=Off`
- [x] 后台登录限流
- [x] Session/Cookie 安全配置
- [x] 支付密钥加密/打码
- [x] 安全响应头
- [x] `composer audit` 接入发布门禁
- [x] 旧库/新库支付密钥加密：`php artisan pay:encrypt-secrets`（回滚前 `pay:decrypt-secrets`）

验证：见 `docs/php8.2-upgrade-plan.md` 第 13.4 节。

## P5 全量回归

- [x] 安装向导（全新库）
- [x] 旧库升级（备份 + `upgrade-php83.sql`）
- [x] 首页/商品/下单/查询/详情
- [x] 后台 CRUD + 卡密导出 + 邮件测试
- [x] 易支付三通道下单/回调/发货/幂等
- [x] 其他渠道默认不显示
- [x] `queue:work`
- [x] `E_ALL` 日志无高频 deprecation
- [x] 现有 PHPUnit 测试通过

## P6 发布

- [x] Dockerfile / Dockerfile-ARM64 / conf 切 8.3
- [x] 构建并打 tag `2.1.0-php83`（本地镜像已验证 x86/arm64）
- [ ] 预发部署 + 冒烟（本地已完成 E2E/CRUD/队列/导出）
- [ ] 生产备份 + 灰度 + 观察
- [x] 更新 README / `debian_manual.md` / `docs/php83-upgrade-deployment.md`

## 风险文件（改动前先备份/复审）

- `app/Service/OrderProcessService.php`（订单完成/发货/金额/幂等）
- `app/Http/Controllers/PayController.php`（网关校验/验签）
- `app/Http/Controllers/Pay/YipayController.php`（易支付）
- `database/sql/install.sql`（全新安装）
- `Dockerfile` / `conf/*`（部署）
- `.env`（密钥/调试）

## 回滚点

- P1 后：保留旧 `composer.lock` / 旧镜像 tag
- P2 后：DB 增量脚本可保留，旧代码忽略新表/新列
- P3 后：保留旧支付控制器文件的 git 历史，必要时 revert
- 发布后：旧镜像 tag + 旧代码 tag 一键回滚
