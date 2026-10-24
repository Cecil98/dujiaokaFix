# 独角数卡 PHP 8.2+ 升级分析与实施方案

> 分析对象：`Cecil98/dujiaokaFix`（master，独角数卡 2.0.6 / dujiaoka 2.0.4 分支）
> 目标：运行环境最低 PHP 8.2
> 结论日期：2026-10

---

## 0. 先说结论（TL;DR）

1. **不能只改 PHP 版本**。当前是 Laravel 6.20 + dcat/laravel-admin 2.0.24，官方支持上限是 PHP 8.0，
   并且有多个依赖在 PHP 8.2 / 新 Composer 下**装不上或跑不起来**（详见第 3 节）。必须「PHP + 框架 + 依赖」一起升级。
2. **已确认范围：只启用易支付（epay）**；其他渠道代码保留、默认禁用。
   - 易支付 = `YipayController`（纯 PHP + MD5，无 SDK），升级不用改业务逻辑；
   - **官方直连支付宝/微信（`AlipayController`/`WepayController`）连同 `yansongda/pay` 一起删除**；
   - PayPal 删除；Stripe / PAYJS / Vpay 等保留代码、默认禁用（`pays.is_open=0`）。
3. 推荐目标栈（安全优先）：
   - **PHP 8.3**（满足「8.2 最低」；8.2 于 2026-12 EOL）
   - **Laravel 12.x（≥ 12.69）**
   - **dcat：fork 上游 `jqhph/dcat-admin` 的 `2.0` 分支，再移植 `woodynew` 2.2.4 的 PHP 8.x/安全补丁**（见 2.3）
   - 本次一并做安全加固（默认口令、`.env`、上传目录、登录限流、Cookie、密钥加密等，见第 13 节）
4. 已做可行性验证（Docker + PHP 8.2）：
   - ✅ Laravel 12 + dcat `2.0.x-dev` 可安装、可 `admin:install`、路由/后台可启动
   - ✅ Laravel 12 + `woodynew/dcat-laravel-admin 2.2.4` 同样可安装、`admin:install`、路由/后台可启动
   - ✅ PHP 8.4 下同样能装/启动（但本期选 8.3，见 2.2）
   - ✅ `box/spout 3.3` 在 PHP 8.2 下可正常写 xlsx（后台导出功能可用）
   - ❌ `yansongda/pay v2` 依赖 `symfony/http-foundation ^4|^5`，与 Laravel 10+（Symfony 6/7）**硬冲突**
   - ❌ `yansongda/pay ≤ 3.7.19` 有微信回调签名绕过漏洞 `PKSA-8dgs-n4fh-5pd5`（必须 ≥ 3.7.20）
   - ❌ `paypal/rest-api-sdk-php` 依赖 `psr/log ^1`，与 Laravel 10+（Monolog 3 / psr/log 3）冲突
5. 预估工作量：**7–10 人日**（只保留易支付 + 安全加固；不含后台 UI 重写）。
6. **需要你拍板的事**（见第 10 节）：
   - 默认后台账号的处理方式（随机密码 / 强制改密）
   - 镜像发布到哪个 registry、tag 规则

---

## 1. 现状盘点

### 1.1 运行时与部署

| 项 | 现状 |
|---|---|
| PHP | 7.4（`Dockerfile: webdevops/php-nginx:7.4`） |
| 框架 | Laravel 6.20.42 |
| 后台 | dcat/laravel-admin 2.0.24-beta |
| 数据库 | MySQL（`database/sql/install.sql` 一次性建表，**无 app migrations**） |
| 缓存/队列 | Redis（`CACHE_DRIVER=redis`、`QUEUE_CONNECTION=redis`，另有 file 模式） |
| Web | php-nginx 单容器；`start.sh` 里 `artisan queue:work` + supervisord |
| ARM64 | `Dockerfile-ARM64`：Alpine 3.13 + `php7-*` 包 |

### 1.2 代码规模

| 范围 | 文件数 | 行数 |
|---|---|---|
| `app/` PHP | 125 | ~8,400 |
| Blade 模板 | 47 | ~4,100 |
| `app/Admin/`（dcat 控制器/表单/Grid） | 29 | ~2,100 |
| 支付控制器 | 12 | ~1,900 |
| 测试 | 5 个测试类（含支付网关绑定、回调安全、订单完成契约） | — |

代码量不大，**升级难点不在业务代码，在第三方依赖和后台框架**。

### 1.3 第三方依赖使用情况

| 依赖 | 当前版本 | 实际使用点 | 处置 |
|---|---|---|---|
| laravel/framework | 6.20.42 | 全局 | → `^12.69` |
| dcat/laravel-admin | 2.0.24-beta | 全部后台 | → **自持 fork**：上游 `2.0` @ `315658a8` + 移植 woodynew 2.2.4 补丁，tag `v2.2.5` |
| dcat/easy-excel → box/spout | 1.0.5 / 3.3.0 | `CarmisController` 导出 | 保留（已验证 PHP 8.2 可用），后续可换 `openspout` |
| yansongda/pay | 2.10.2 | 官方直连支付宝/微信 | **删除**（改用易支付） |
| stripe/stripe-php | 7.84 | `StripeController` | → `^18`（API 基本兼容） |
| paypal/rest-api-sdk-php | 1.14.0 | `PaypalPayController` | **删除**（已确认不用） |
| mews/captcha | 3.2.6 | 前台图形验证码 `captcha_check()` | → `^3.5.1` |
| simplesoftwareio/simple-qrcode | 2.0.0 | **代码未使用**（二维码是前端 JS 生成） | 删除 |
| amrshawky/laravel-currency | 4.0.0 | Stripe 汇率折算 | 改用 `amrshawky/currency ^1.0`（去掉 Laravel 壳） |
| jenssegers/agent | 2.6.4 | `HomeController::buy()` 判断移动端 | 保留（或换 `mobiledetect`） |
| germey/geetest | 3.1.0 | 极验行为验证 | **必须替换/内置**（只支持 illuminate ≤7） |
| xhat/payjs-laravel | 1.6.0 | PAYJS 扫码 | 保留（无 Laravel 约束）或内置 3 个类 |
| fideloper/proxy | 4.4.1 | 信任代理 | 删除，用 Laravel 内置 `TrustProxies` |
| facade/ignition | 1.16.15 | dev | → `spatie/laravel-ignition ^2.9` |
| nunomaduro/collision | 3.2.0 | dev | → `^8.9` |
| phpunit/phpunit | 9.5.4 | 测试 | → `^11` |
| laravel/tinker | 2.6.1 | — | → `^2.11` |

### 1.4 现状的安全/可维护性问题（升级的硬理由）

- **默认后台账号 `admin` / 密码 `admin`**（`install.sql:214`，已实际验证）——这是当前最大的单点风险。
- `.env` 被 git 跟踪，里面含 `APP_KEY` 和 `APP_DEBUG=true`。
- `/public/uploads` 下的 PHP 文件可被 nginx 执行（上传盘与 web 根目录重叠）。
- Laravel 6 已 EOL；Packagist 对 laravel/framework 有 30 条安全公告，其中多条影响 6.x。
- **Composer 2.9+ 默认阻止安装有安全公告的版本**（`policy.advisories.block`）。实测：
  - `composer require dcat/laravel-admin:2.2.3-beta` + Laravel 10 → 被安全策略直接拒绝
  - `composer require yansongda/pay:2.10.6` → 被 `PKSA-8dgs-n4fh-5pd5` 拒绝
  - 也就是说「保留旧依赖只升 PHP」在今天的 Composer 下连 `composer install` 都过不了。
- dcat/laravel-admin ≤ 2.1.3 有存储型 XSS 公告；2.2.3+ 已修复。
- `yansongda/pay ≤ 3.7.19` 微信回调签名可被 Host 头绕过（本仓库刚好加固过支付回调，必须用 ≥ 3.7.20）。

---

## 2. 目标技术栈（推荐）

| 组件 | 目标 |
|---|---|
| PHP | **8.3**（`webdevops/php-nginx:8.3`），最低支持 8.2 |
| Laravel | **12.x（`^12.69`）** |
| 应用结构 | **保留 Laravel 6/10 的旧骨架**（`app/Http/Kernel.php`、`RouteServiceProvider`、`config/app.php` providers），不做 L11 新结构迁移 |
| 后台 | **自持 fork `Cecil98/dcat-admin`**：基于上游 `2.0` @ `315658a8`，移植 woodynew 2.2.4 的 PHP 8.x/安全补丁，tag `v2.2.5` |
| 配置缓存 | 不开 `route:cache`（dcat 路由为闭包，保持现状） |
| Web/队列 | php-nginx + supervisor（保留现状） |

> Laravel 官方升级指南明确写了：**从 Laravel 10 升到 11 的应用，不建议迁移到新的应用结构，L11 会兼容旧结构**。
> 因此我们沿用旧骨架，减少改动面（`bootstrap/app.php`、`Kernel`、`Handler`、`RouteServiceProvider` 都保留，只改必要签名）。

### 2.1 为什么是 Laravel 12 而不是 10

| 方案 | 结论 |
|---|---|
| Laravel 6 + PHP 8.0 | 不满足「最低 8.2」，且 8.0 已 EOL |
| Laravel 10 + dcat 2.2.3-beta | 版本上可行，但 Laravel 10 已 EOL，且**当前 Composer 默认拒绝安装**（安全公告），需要主动关闭安全策略，不适合生产 |
| **Laravel 12 + dcat 2.0.x-dev / woodynew 2.2.4** | 依赖生态完整、有安全支持（到 2027-02）、PHP 8.2–8.4 均支持；dcat 的 L11/L12 支持已在 2.0 分支合并（2025-06），woodynew 2.2.4 又加了 PHP 8.2 兼容加固 |
| Laravel 12 + Filament 重写后台 | 长期最优，但要重写 2,100 行后台 + 权限/菜单，成本 20–30 人日，不建议本期 |

### 2.2 PHP 版本：确定用 8.3

| 版本 | 安全支持到 | 结论 |
|---|---|---|
| PHP 8.2 | 2026-12 | 不推荐（快 EOL） |
| **PHP 8.3** | **2027-12** | **本期采用，零补丁** |
| PHP 8.4 | 2028-12 | 实测可用，但需要给 `mews/captcha` 打 composer 约束补丁，本期不做 |

PHP 8.4 实测结论（供以后升级参考，Docker `webdevops/php:8.4` + Laravel 12）：

| 依赖 | 结果 |
|---|---|
| `woodynew/dcat-laravel-admin 2.2.4` | ✅ 安装、`admin:install`、路由/启动均正常 |
| `mews/captcha 3.5.1` | ⚠️ Composer 约束最高只到 `^8.3`，需要打一行补丁；运行正常，helper 都在 |
| `intervention/image 4.x` | ✅（mews/captcha 3.5 的新依赖） |
| `box/spout 3.3`（导出） | ✅ 能写 xlsx，但 8.4 下有 implicit nullable 弃用告警 |
| `composer audit` | ✅ 无安全公告 |

> 结论：本期用 8.3，避免为了 8.4 维护 captcha 补丁；8.4 的验证结果留档，下一轮再切。

### 2.3 dcat fork 方案（已完成）

**仓库**：`https://github.com/Cecil98/dcat-admin`
**分支**：`2.2.4`；**tag**：`v2.2.5`；**commit**：`8ff1f822`
**基线**：上游 `jqhph/dcat-admin` `2.0` @ `315658a8`（2025-06-25，已包含 Laravel 12 支持），**不是 master**（master 只支持到 Laravel 8）。

**移植 woodynew 2.2.4 的补丁**（只取 `src/` 和 `resources/`，不要 CI/README/测试）：

| 补丁 | 文件 | 作用 |
|---|---|---|
| PHP 8.x 兼容 | 多个 `src/**` | `?Type $x = null` 显式可空、`fopen(..., false)`、命令返回 int 等 |
| 扩展安装安全 | `src/Support/Zip.php`、`src/Extend/Manager.php`、`src/Console/ExtensionInstallCommand.php` | ZIP 路径穿越、只允许本地可信 ZIP、包名校验 |
| 扩展信息 XSS | `src/Http/Repositories/Extension.php`、`src/Http/Controllers/ExtensionController.php`、`resources/views/grid/displayer/extensions/name.blade.php` | homepage 只允许 http/https、作者信息转义 |
| LazyRenderable 兼容 | `src/Grid/Displayers/Modal.php`、`src/Widgets/Form.php` | 兼容没有 `payload()` 的实现 |
| 小修 | `src/Extend/VersionManager.php`、`src/Extend/ServiceProvider.php` | `array_slice`、`fopen` 参数 |
| 版本号 | `src/Admin.php` | `VERSION = 2.2.4` |

**不移植**：`.github/workflows`、`README.md`、`CHANGELOG.md`、Dusk 测试改动（与运行无关）。

**额外补丁（woodynew 也没有的）**：

- 给 `src/Support/WebUploader.php` 等动态属性类加 `#[AllowDynamicProperties]`，消除 PHP 8.2 告警。

**操作命令**：

```bash
git clone https://github.com/Cecil98/dcat-admin.git
cd dcat-admin
git remote add upstream https://github.com/jqhph/dcat-admin.git
git fetch upstream 2.0
git checkout -b 2.2.4 315658a8

git remote add woody https://github.com/woodynew/dcat-admin.git
git fetch woody 2.0
git diff 315658a8 woody/2.0 -- src resources > /tmp/woody-hardening.patch
git apply --3way /tmp/woody-hardening.patch

# 补 #[AllowDynamicProperties]，跑一次 E_ALL 验证
git commit -am "feat: PHP 8.2 hardening + security backports from woodynew 2.2.4"
git tag v2.2.5
git push origin 2.2.4 --tags
```

**composer 引用**：

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/Cecil98/dcat-admin" }
],
"require": {
  "dcat/laravel-admin": "2.2.5"
}
```

### 2.4 dcat 安全审计结论

**已做的审计/修复：**

| 项目 | 结论 |
|---|---|
| 4 条已知公告（CVE-2024-54775 等，涉及 2.2.0/2.2.2/2.1.3） | 公告均无官方 patched 版本；上游 `2.0` 分支已包含 2020–2024 的 XSS 修复（`e76cf099`、`61227db7`、`179977d5`） |
| `/admin/auth/extensions` XSS | 已随 woodynew 补丁修复：logo/homepage 转义、homepage 只允许 http(s)、作者名/邮箱转义 |
| `/admin/auth/menu` XSS | **本次修复**：侧边栏菜单标题转义 + 菜单树 payload 的 `title/icon/uri` 转义 |
| Toastr JS 注入 | **本次修复**：`type` 白名单 + `@json($message)` |
| Markdown 文本域 / 颜色预览 | **本次修复**：改为 `{{ }}` 转义 |
| `composer audit` | 无安全公告 |
| 未转义 Blade 输出 | 全量扫描；剩余 `{!! !!}` 均为开发者可控（logo、导航、alerts、debug 异常页、`display` 字段） |
| 危险函数 | 无 PHP `eval` / `unserialize` / `shell_exec`（`ApexCharts` 里的 `eval` 是前端 JS） |

**修复验证（PHP 8.3 + L12）：**

- 菜单标题 payload `<img src=x onerror=alert(1)>` → 输出 `&lt;img ...&gt;`（`MENU_ESCAPED`）
- Toastr 消息 payload `x');alert(1);//` → 输出 `x\u0027);alert(1);\/\/`（`TOASTR_ESCAPED`）
- 后台登录页 HTTP 200，`E_ALL` 无 deprecation

**未覆盖/接受的表面：**

- `partials/alerts.blade.php`、`partials/exception.blade.php` 仍渲染原始 HTML（前者是 dcat 设计上的 HTML 消息；后者仅 debug 模式）
- `form/display.blade.php` 是开发者显式渲染 HTML 的字段
- Grid 列值默认已由 `Column::htmlEntityEncode()` 转义
- 未做正式渗透测试 / SAST 全量扫描（如需可作为独立任务，约 0.5–1 天）

---

## 3. 为什么不能「只切 PHP 8.2」

在 PHP 8.2 下直接跑现有代码会遇到：

1. **Laravel 6.20 官方只支持到 PHP 8.0**，PHP 8.1+ 会持续抛 deprecation；PHP 8.2 的动态属性弃用会污染日志。
2. **dcat 2.0.24 只支持 laravel/framework ≤ 8**，Laravel 12 下 Composer 无法解析。
3. **yansongda/pay v2** 依赖 `symfony/http-foundation ^4|^5`，Laravel 10/11/12（Symfony 6/7）下无解。
4. **paypal/rest-api-sdk-php** 依赖 `psr/log ^1`，与 Laravel 10+ 的 Monolog 3 / psr/log 3 冲突。
5. **germey/geetest v3.1** 只允许 `illuminate/support|routing ^5.5|^6|^7`，且用了 Laravel 9 已移除的 `Blade::extend()`。
6. **fideloper/proxy** 在 Laravel 9+ 已被内置中间件取代。
7. **Composer 2.9 安全公告拦截**（见 1.4）。

---

## 4. 代码改造清单（按模块）

### 4.0 哪些是必须改的？（必要性分级）

| 级别 | 内容 | 不改会怎样 |
|---|---|---|
| 必须 | composer 依赖升级（Laravel / dcat / 支付 SDK / captcha / currency / proxy / qrcode / geetest） | `composer install` 直接失败，项目起不来 |
| 必须 | `Handler` 签名、`Kernel` 维护模式中间件、`TrustProxies` | 启动即 PHP 致命错误 |
| 必须 | `config/mail.php` + `MailSend` / `EmailTest` 两处发信代码 | 邮件测试、下单发信全部失效（L9 起 SwiftMailer 已移除） |
| 必须 | `resources/lang` → `lang/` | 前后台文案全部显示成 `dujiaoka.xxx` key |
| 必须 | dcat 配置合并 + 资源发布 + `admin_extensions` 等 2 张新表 + 4 条 migrations 记录 | 后台 500 或上传/菜单异常 |
| 必须 | `Dockerfile` / `Dockerfile-ARM64` 基础镜像 | 跑的还是 PHP 7.4 |
| 必须 | `install.sql` 补 2 张表 + migrations 记录 | 全新安装的后台缺表 |
| 必要（但属于删除/替换） | 删除 PayPal、simple-qrcode、fideloper/proxy；内置 geetest；替换 laravel-currency | 这些包在 PHP 8.2 / Laravel 12 下装不上 |
| 建议 | Stripe 7 → 18 | 不升也能跑，但用的是 EOL SDK，PHP 8.2 有 deprecation |
| 建议 | dcat fork + `#[AllowDynamicProperties]` 补丁 | 不补也能跑，日志会有弃用告警 |
| 建议 | `phpunit.xml`、`OrderFactory` 现代化 | 只影响测试，不影响线上 |
| 不用动 | Models / Services / 前台 Blade / 业务表结构 | — |

> 换句话说：**「必须」项不是可选项，是让项目能在 PHP 8.2 上装起来、跑起来的最低集合**；
> 但绝大多数是机械性改动，由我执行，你只需要确认支付范围和发布方式。

### 4.1 框架骨架 / 启动

| 文件 | 改动 |
|---|---|
| `composer.json` | `php: ^8.2`；升级依赖；移除 `fideloper/proxy`、`simplesoftwareio/simple-qrcode`、`germey/geetest`；`autoload.classmap` 去掉 `database/seeds`、`database/factories`（或同步改工厂） |
| `app/Exceptions/Handler.php` | `report(Exception)` → `report(Throwable)`；`render($request, Exception)` → `render($request, Throwable)`（否则 PHP 8 报签名不兼容） |
| `app/Http/Kernel.php` | `CheckForMaintenanceMode` → `Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance`；`$routeMiddleware` 建议改名为 `$middlewareAliases`（L12 仍兼容旧名，但新名更稳） |
| `app/Http/Middleware/TrustProxies.php` | 改为 `extends Illuminate\Http\Middleware\TrustProxies`；`$headers` 用 `HEADER_X_FORWARDED_FOR \| HOST \| PORT \| PROTO`（`HEADER_X_FORWARDED_ALL` 已弃用）；删除 `config/trustedproxy.php` |
| `app/Http/Middleware/CheckForMaintenanceMode.php` | 删除（用框架内置的替代） |
| `app/Providers/RouteServiceProvider.php` | 保留（L12 的基类仍支持 `$namespace`），无需改 |
| `bootstrap/app.php`、`public/index.php`、`artisan` | **不动**（旧结构在 L12 仍受支持） |
| `resources/lang/` | 移到根目录 `lang/`（L9+ 默认语言目录变了）；或在 `AppServiceProvider::boot()` 里 `$this->app->useLangPath(resource_path('lang'))` |
| `database/factories/OrderFactory.php` | Laravel 6 的 `$factory->define()` 在 L8+ 已移除，改为类式 `OrderFactory extends Factory`（仅测试用，也可删） |
| `phpunit.xml` | 适配 PHPUnit 11 schema；`MAIL_DRIVER` → `MAIL_MAILER`；`<filter><whitelist>` → `<source><include>` |
| `.env.example` / `.env` | `MAIL_DRIVER` → `MAIL_MAILER`；`FILESYSTEM_DRIVER` → `FILESYSTEM_DISK`（如使用） |

### 4.2 配置

| 文件 | 改动 |
|---|---|
| `config/app.php` | 保留 providers/aliases；移除 `Germey\Geetest\GeetestServiceProvider`（改为内置）；`Jenssegers\Agent\AgentServiceProvider` 视是否保留而定 |
| `config/mail.php` | **必须重写为 Laravel 9+ 结构**：`default => env('MAIL_MAILER')`，`mailers.smtp.*`；旧的 `driver/host/port/encryption` 结构 L12 不识别 |
| `config/filesystems.php` | 补 `default => env('FILESYSTEM_DISK','local')`、`links`；**保留自定义 `admin` disk（root=public/uploads）**，否则后台上传图片 URL 会变 |
| `config/admin.php` | 用 dcat 2.2 的配置为基线合并：保留 `name/logo/title/route.prefix/database.*/upload.disk='admin'`；新增 `favicon` 等 key |
| `config/geetest.php`、`config/trustedproxy.php` | 删除或随内置代码调整 |
| `config/logging.php`、`cache.php`、`session.php`、`queue.php`、`database.php` | 旧结构基本兼容；`database.redis.client` 保持 `phpredis`（依赖镜像里的 ext-redis） |

### 4.3 邮件（Laravel 9 起 SwiftMailer → Symfony Mailer）

现状有两处「运行时改 `mail` 配置 + 重新注册 `MailServiceProvider`」：

- `app/Jobs/MailSend.php:80`
- `app/Admin/Forms/EmailTest.php:53`

L9+ 这种写法不再生效（Mailer 已解析/缓存），必须改成：

```php
config([
    'mail.mailers.smtp.host' => $host,
    'mail.mailers.smtp.port' => $port,
    'mail.mailers.smtp.username' => $user,
    'mail.mailers.smtp.password' => $pwd,
    'mail.mailers.smtp.encryption' => $encryption,
    'mail.from.address' => $from,
    'mail.from.name' => $name,
]);
Mail::purge('smtp');          // 关键：清掉已解析的 mailer
Mail::send('email.mail', ['body' => $body], function ($message) use ($to, $title) {
    $message->to($to)->subject($title);
});
```

> 建议顺手抽一个 `MailConfigService`，两处复用；`Mail::send(['html' => ...])` 的数组写法优先改成字符串视图名。

### 4.4 极验（germey/geetest）

包已废弃且约束不兼容。处理方式（二选一）：

- **内置**：把 `GeetestLib.php` + `Geetest` Facade + `GeetestServiceProvider`（去掉 `Blade::extend()`）复制到 `app/Service/Geetest/`，注册到 `config/app.php`；
- 或换成官方 SDK（`gee-team/gt-php-sdk`，非 Packagist，需 vendor）。

调用点只有：`HomeController::geetest()`、`DujiaoBoot` 里的配置注入、`resources/views/vendor/geetest`。
顺便修掉 `DujiaoBoot` 里明显的 bug：`array_merge(config('mail'), $geetestConfig)` 应为 `config('geetest')`。

### 4.5 PHP 8.2 兼容性

- 业务代码扫描结果：**没有**发现 `each()`、`create_function`、`${var}` 字符串插值、`utf8_encode`、`strftime` 等 8.x 移除/弃用项，业务代码基本安全。
- 主要 deprecation 来自第三方：
  - dcat：`Dcat\Admin\Support\WebUploader` 等类动态属性
  - PayPal SDK 1.x、PayJS、box/spout 也可能有动态属性告警
- 应对：
  1. fork dcat，在相关类上加 `#[AllowDynamicProperties]`（社区 issue #2102 有完整类名清单）；
  2. 生产环境在 `public/index.php` / `artisan` 入口设置 `error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED)`（并在 README 注明原因）；
  3. CI/预发用 `E_ALL` 跑一遍，收集并修高频告警。

---

## 5. 依赖版本矩阵（建议直接写进 composer.json）

```json
{
  "require": {
    "php": "^8.2",
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
  "require-dev": {
    "fakerphp/faker": "^1.23",
    "mockery/mockery": "^1.6",
    "nunomaduro/collision": "^8.9",
    "phpunit/phpunit": "^11.0",
    "spatie/laravel-ignition": "^2.9"
  },
  "repositories": [
    { "type": "vcs", "url": "https://github.com/<your-org>/dcat-admin" }
  ]
}
```

说明：
- 包名不变：`dcat/laravel-admin`（fork 基础上改代码，不改包名）；通过 VCS repository 指向 `Cecil98/dcat-admin` 的 `v2.2.5` tag；
- 基线是上游 `2.0` @ `315658a8`，再移植 woodynew 2.2.4 的 PHP 8.x/安全补丁（见 2.3）；
- 还要补 `#[AllowDynamicProperties]`（woodynew 也没有），消掉 PHP 8.2 的动态属性告警。
- PayPal 已确认废弃：删除依赖、`PaypalPayController`、`paypal` 路由、`pays` 表中的 PayPal 记录（见 6.2）。
- `yansongda/pay` 已确认删除：官方直连支付宝/微信控制器、路由、依赖一起删（见 6.1）。
- `simplesoftwareio/simple-qrcode`、`fideloper/proxy`、`germey/geetest` 直接删除（它们在 PHP 8.2 / Laravel 12 下无法安装）。

---

## 6. 支付渠道影响（本次升级的最大业务风险）

| 渠道 | 现实现 | 目标 | 配置兼容性 | 工作量 |
|---|---|---|---|---|
| 支付宝（官方直连） | yansongda v2 | **删除**（改用易支付） | 移除控制器/路由/依赖，`pays` 记录禁用 | 0.2d |
| 微信（官方直连） | yansongda v2（APIv2） | **删除**（改用易支付） | 同上 | 0.2d |
| Stripe | stripe-php 7 | stripe-php 18 | 基本一致（`Charge::create` / `Source` 仍在） | 0.5d |
| PayPal | rest-api-sdk 1.14 | **删除** | 移除控制器/路由/依赖/`pays` 记录 | 0.2d |
| PAYJS | xhat/payjs-laravel | 不变 | 兼容 | 0 |
| Paysapi / Vpay / Yipay / Mapay / Epusdt / Coinbase / TokenPay | 自写 HTTP/Guzzle | 不变 | 兼容 | 0 |
| 汇率（CNY→USD） | laravel-currency 4 | `amrshawky/currency` 1.x | 代码改 2 处 | 0.2d |

### 6.0 已确认范围：只用易支付（epay），其他渠道保留但禁用

**关于 epay（易支付）：**

- 仓库里**没有** `epay` 插件；易支付就是 `app/Http/Controllers/Pay/YipayController.php`；
- 路由：`/pay/yipay/{payway}/{orderSN}`、`/pay/yipay/notify_url`、`/pay/yipay/return_url`；
- 默认配置（`install.sql`）：id=14 易支付-支付宝、id=15 易支付-微信、id=16 易支付-QQ 钱包；
- 实现方式：纯 PHP + MD5 签名 + 表单跳转，**不依赖第三方 SDK** → PHP 8.2 / Laravel 12 下不用改业务逻辑。

**⚠️ 发现一个数据 bug**：`install.sql` 里 id=14「易支付-支付宝」的 `is_open=2`，
而代码只认 `1`（`BaseModel::STATUS_OPEN=1`），所以全新安装后易支付-支付宝**不会显示**。本次一并修成 `1`。

**本次的渠道处理：**

| 渠道 | 代码 | 依赖 | 默认状态 |
|---|---|---|---|
| 易支付（`YipayController`） | 保留 | 无 | **启用** |
| V免签 / Paysapi / 码支付 / PAYJS / Epusdt / TokenPay / Coinbase | **原样保留**（纯 PHP，已兼容 8.2） | 无 / 无约束 | 禁用（`is_open=0`） |
| 官方直连支付宝（`AlipayController`） | **删除** | 删除 | 禁用 |
| 官方直连微信（`WepayController`） | **删除** | 删除 | 禁用 |
| PayPal | 删除 | 删除 | 移除 |
| Stripe | 保留 | `stripe-php ^18` 升级 | 禁用 |

**为什么官方直连支付宝/微信直接删除？**

- 它们依赖 `yansongda/pay` v2，而 v2 依赖 Symfony 4/5，**无法装进 Laravel 12**；
- 实际只用易支付，删除后不影响任何线上渠道；
- 代码留在仓库里反而会让依赖无法解析，所以本次直接删除（已确认）。
  以后要恢复时，按 yansongda v3 重新接入即可（支付宝半天、微信 APIv3 2–4 天）。

**已有站点的禁用 SQL：**

```sql
-- 只保留易支付三通道，其他全部禁用
UPDATE pays SET is_open = 0;
UPDATE pays SET is_open = 1 WHERE pay_handleroute = '/pay/yipay';

-- 修复 install.sql 遗留的 is_open=2
UPDATE pays SET is_open = 1 WHERE id = 14;
```

**全新安装**：同步修改 `install.sql`，默认只有易支付三行 `is_open=1`，其余为 `0`。

### 6.1 官方直连微信 / 支付宝（本期删除）

用户确认只走易支付，官方直连两条线本期直接删除：

| 删除项 | 内容 |
|---|---|
| 依赖 | `composer.json` 里的 `yansongda/pay` |
| 控制器 | `app/Http/Controllers/Pay/AlipayController.php`、`WepayController.php` |
| 路由 | `routes/common/pay.php` 中 `alipay/*`、`wepay/*` |
| 数据 | `pays` 中 `pay_handleroute` 为 `/pay/alipay`、`/pay/wepay` 的行 `is_open=0`（**保留记录不删**，便于以后恢复） |

以后若要恢复官方直连：按 yansongda v3 接入（微信需要 APIv3 密钥/证书，支付宝配置基本不变），单独排期 2–4 天。

### 6.2 PayPal（删除）

已确认不用，直接移除，需处理：

| 位置 | 处理 |
|---|---|
| `app/Http/Controllers/Pay/PaypalPayController.php` | 删除 |
| `routes/common/pay.php` | 删除 3 条 `paypal*` 路由 |
| `composer.json` | 删除 `paypal/rest-api-sdk-php` |
| `database/sql/install.sql` | 删除 `pays` 表 id=17 的 PayPal 记录（已有站点的库按需删除/停用） |
| `app/Http/Controllers/Pay/StripeController.php` / 汇率 | 汇率只保留 Stripe 用 |
| `tests/Unit/PaymentSecurityHelpersTest.php` | 里面用了 `paypal_total` 作为测试数据，改成中性 key 或保留（纯字符串，不影响） |

> 删除后少一个 SDK、少一套联调，本期工作量减少约 1–2 人日。

### 6.3 回调安全回归

仓库最近有「支付回调加固 / 订单完成校验」提交，升级时必须保证以下行为不被破坏：

- `PayController::isExpectedGatewayRoute()` 的 `pay_handleroute` 校验
- `OrderProcessService::completedOrder()` 的幂等/金额校验
- 已有测试：`tests/Feature/PayGatewayBindingTest.php`、`tests/Unit/OrderCompletionContractTest.php`、`tests/Unit/PaymentSecurityHelpersTest.php`

---

## 7. 数据库 / 安装流程变更（向后兼容）

原则：**只加不删、不改旧列**；所有脚本幂等（可重复执行）；回滚旧镜像后仍能正常读库。

### 7.1 旧库需要做的变更（全部为增量）

| # | 变更 | 原因 | 对旧代码的影响 |
|---|---|---|---|
| 1 | 新建 `admin_extensions`、`admin_extension_histories` | dcat 2.2 扩展管理需要 | 无（旧代码不读这两张表） |
| 2 | `migrations` 表登记 4 条 dcat 迁移 | 避免 `admin:update` 重复建表 | 无 |
| 3 | `failed_jobs` 增加 `uuid` 列（可空 + 唯一索引） | Laravel 8+ 记录失败队列任务需要 | 无（旧代码不写该列） |
| 4 | `pays` 数据：只保留易支付 `is_open=1`，其余 `0`；修正 id=14 的 `is_open=2` | 只启用易支付 | 只影响前台展示，可随时改回 |
| 5 | （可选）支付密钥列加密存储 | 安全加固 | 旧数据保持明文可读，写入时自动加密 |

**不需要变更**：`orders`、`goods`、`carmis`、`coupons`、`emailtpls`、`admin_users`、`admin_roles`、`admin_permissions`、`admin_menu`、`admin_settings`。

> 说明：`admin_users` 的 bcrypt `$2y$10$` 哈希在 Laravel 12 仍兼容；`admin_menu` 已包含 dcat 2.2 需要的 `show`/`extension` 列。

### 7.2 幂等升级脚本（新增 `database/sql/upgrade-php83.sql`）

```sql
-- 1) dcat 2.2 扩展表
CREATE TABLE IF NOT EXISTS `admin_extensions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `version` varchar(20) NOT NULL DEFAULT '',
  `is_enabled` tinyint NOT NULL DEFAULT '0',
  `options` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_extensions_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_extension_histories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` tinyint NOT NULL DEFAULT '1',
  `version` varchar(20) NOT NULL DEFAULT '0',
  `detail` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `admin_extension_histories_name_index` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) 登记 dcat 迁移（幂等）
INSERT INTO `migrations` (`migration`, `batch`)
SELECT v.migration, 1 FROM (
  SELECT '2016_01_04_173148_create_admin_tables' AS migration UNION ALL
  SELECT '2020_09_07_090635_create_admin_settings_table' UNION ALL
  SELECT '2020_09_22_015815_create_admin_extensions_table' UNION ALL
  SELECT '2020_11_01_083237_update_admin_menu_table'
) v
WHERE NOT EXISTS (SELECT 1 FROM `migrations` m WHERE m.migration = v.migration);

-- 3) failed_jobs.uuid（Laravel 8+ 需要；MySQL 5.7/8.0 用 information_schema 判断）
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'failed_jobs' AND COLUMN_NAME = 'uuid'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `failed_jobs` ADD COLUMN `uuid` varchar(255) NULL AFTER `id`, ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) 只启用易支付；修复 id=14 的历史脏数据
UPDATE `pays` SET `is_open` = 0;
UPDATE `pays` SET `is_open` = 1 WHERE `pay_handleroute` = '/pay/yipay';
```

> 如果老库没有 `failed_jobs` 表，用 `CREATE TABLE IF NOT EXISTS` 按 Laravel 12 结构补上（包含 `uuid`）。

### 7.3 服务器升级步骤

```bash
# 1. 备份（必须）
mysqldump -u... -p... dujiaoka > backup-before-php83.sql

# 2. 执行增量脚本
mysql -u... -p... dujiaoka < database/sql/upgrade-php83.sql

# 3. 发布 dcat 资源/语言包（只覆盖 vendor 资源，不动业务代码）
php artisan admin:publish --assets --force
php artisan admin:publish --lang --force

# 4. 验证：应为 Nothing to migrate
php artisan admin:update
```

### 7.4 全新安装（`install.sql` 同步修改）

- 加入 `admin_extensions`、`admin_extension_histories` 两张表；
- `failed_jobs` 直接带 `uuid` 列；
- `migrations` 表预置 4 条 dcat 记录；
- `pays` 默认只开易支付三行（id 14/15/16，`is_open=1`），其余 `is_open=0`；
- 修正 id=14 的 `is_open=2` → `1`。

### 7.5 回滚

- 新增的表/列旧代码会忽略，回滚旧镜像无需删表删列；
- `pays.is_open` 是纯数据，可随时改回；
- 若做了密钥加密，回滚前先把 `pays` 数据解密回明文（提供 `php artisan pay:decrypt-secrets` 命令），避免旧代码读到密文。

---

## 8. 部署变更

| 文件 | 改动 |
|---|---|
| `Dockerfile` | `webdevops/php-nginx:8.3`；`composer install` 去掉 `--ignore-platform-reqs`（保留 `--no-dev`）；确认扩展：bcmath、gd、zip、intl、pdo_mysql、redis、opcache、dom/xml、mbstring、curl |
| `Dockerfile-ARM64` | Alpine 3.13 + `php7-*` 全部换成 Alpine 3.20+ + `php83-*`（或 `php83-fpm`），supervisor 配置同步 |
| `conf/supervisord.conf` | `php-fpm7` → `php-fpm83`（取决于基础镜像路径） |
| `conf/php.ini` | 增加 `opcache.enable=1`、`opcache.validate_timestamps=0`（生产）等可选优化 |
| `docker-compose.yml` | 镜像 tag、健康检查、`/app/storage` 持久化建议 |
| `start.sh` | 增加 `php artisan config:cache`（可选，注意 `.env` 挂载时机） |
| `README.md` / `debian_manual.md` | PHP 版本、扩展、安装命令更新 |

> 镜像里需要 `ext-redis`（`CACHE_DRIVER=redis` / `QUEUE_CONNECTION=redis`），
> 以及 `ext-bcmath`（金额计算）、`ext-gd`（验证码）、`ext-zip/xmlreader/dom`（Excel 导出）。

---

## 9. 分阶段实施计划

| 阶段 | 内容 | 产出/验收 | 预估 |
|---|---|---|---|
| **P0 准备** | 从 master 拉 `upgrade/php83` 分支；Docker 跑起 PHP 8.3 空壳；把现有测试跑绿（7.4 基线）；准备 MySQL/Redis 测试环境；`composer audit` 存档 | 基线测试通过；有回滚 tag | 0.5–1d |
| **P1 框架&依赖** | composer.json；升级/替换依赖；Handler/Kernel/TrustProxies/lang/mail/filesystems 适配；删除/内置无法安装的包；`php artisan route:list`、`config:cache` 通过 | 应用能启动；首页/安装页可访问 | 2–3d |
| **P2 后台 dcat** | fork dcat 打 tag；`admin:publish`；合并 `config/admin.php`；执行 `upgrade-php83.sql`（2 张新表 + migrations 记录 + failed_jobs.uuid）；修动态属性 deprecation | 后台登录、商品/订单/卡密/优惠码 CRUD、导出全部可用 | 1.5–2d |
| **P3 支付收敛** | 易支付链路验证（下单/回调/金额/幂等）；其他渠道 `is_open=0`；**删除官方直连支付宝/微信 + `yansongda/pay`**；删除 PayPal；Stripe 升级但禁用 | 易支付全流程通过；页面只出现易支付 | 1d |
| **P4 安全加固** | 默认口令、`.env` 轮换、上传目录禁执行、后台登录限流、Session/Cookie、密钥加密/打码、安全响应头、验签失败告警（见第 13 节） | 安全检查清单全部勾选 | 1.5–2d |
| **P5 全量回归** | 下单/发货/邮件/队列/并发卡密/回调安全；PHP 8.2 与 8.3 双跑；`E_ALL` 收集 deprecation；压测关键接口 | 回归清单全绿；日志无高频告警 | 2–3d |
| **P6 发布** | 构建 8.3 镜像；预发灰度；数据备份；回滚演练；文档 | 预发稳定 N 天 → 生产 | 1d |

**合计：7–10 人日**（只保留易支付 + 安全加固；如需恢复官方直连支付宝/微信，另加 2–4 天迁 v3）。

### 9.1 验收清单（最小）

- [ ] 安装向导可完成（全新库 + 老库升级两种）
- [ ] 首页 / 商品详情 / 下单 / 订单查询 / 订单详情
- [ ] 后台登录 + 商品/分组/卡密/优惠码/支付配置/订单/邮件模板 CRUD
- [ ] 卡密导入导出（xlsx）
- [ ] 邮件测试 + 下单邮件（队列）
- [ ] **易支付-支付宝 / 易支付-微信 / 易支付-QQ 下单 + 异步回调 + 订单完成**
- [ ] 其他渠道默认禁用，页面只出现易支付
- [ ] 官方直连支付宝/微信代码已删除，路由不存在
- [ ] 确认 PayPal 已完全移除
- [ ] 回调重复通知不重复发货（幂等）；金额不一致被拒绝
- [ ] `php artisan queue:work` 正常
- [ ] 无高频 `Deprecated` 日志
- [ ] 旧库升级后：`admin:update` 输出 Nothing to migrate；`failed_jobs` 有 `uuid`；旧订单/商品数据可正常读写
- [ ] 安全清单（第 13 节）全部通过

### 9.2 回滚方案

- 代码：保留升级前 tag（如 `v2.0.6-php74`），镜像保留旧 tag，可一键回滚。
- 数据库：升级只**新增**表/列，不删不改；回滚旧代码时新表新列不影响旧逻辑。
- 灰度：先预发 → 小流量 → 全量；支付回调域名切换需提前规划。

---

## 10. 需要你确认的决策

1. **后台默认账号 `admin/admin` 怎么处理？**
   - A（推荐）：升级时生成随机强密码 + 首次登录强制改密；
   - B：保留账号但强制改密；
   - C：直接删除默认账号，手工创建。
2. **dcat 管理方式**（已确认）：fork 上游 `2.0` @ `315658a8` + 移植 woodynew 2.2.4 补丁，自持 tag `v2.2.5`。
3. **镜像发布**：Docker Hub 还是自己的 registry？tag 用 `2.1.0-php83` 可以吗？
4. **PHP 版本**（已确认）：8.3，零补丁；8.4 验证结果留档，下一轮再切。
5. **部署形态**：继续用 `webdevops/php-nginx` 单容器，还是切到 Alpine 自建镜像（ARM64 需要）？

---

## 11. 附：已完成的可行性验证（可复现）

环境：Docker `webdevops/php:8.2`，无宿主 PHP 依赖。

```bash
# 1) Laravel 12 空项目
composer create-project "laravel/laravel:^12.0" l12 --no-interaction --prefer-dist

# 2A) upstream dcat 2.0.x-dev（对应 GitHub 2.0 分支 315658a8，支持 Laravel 12）
composer require "dcat/laravel-admin:2.0.x-dev" --no-interaction

# 2B) woodynew fork 2.2.4（有正式 tag，PHP 8.2 加固）
composer require "woodynew/dcat-laravel-admin:2.2.4" --no-interaction

# 3) 发布配置 + 安装（sqlite 验证）
php artisan admin:publish --force
php artisan admin:install --no-interaction

# 4) 验证
php artisan route:list        # 12 条路由，含 dcat-api/*
php artisan admin             # Dcat Admin 正常输出版本
```

结果：

| 验证项 | 结果 |
|---|---|
| L12 + dcat `2.0.x-dev` 安装 | ✅ |
| L12 + `woodynew/dcat-laravel-admin 2.2.4` 安装、`admin:install`、启动 | ✅ |
| PHP 8.4 + dcat 2.2.4 安装/启动 | ✅ |
| PHP 8.4 + `mews/captcha 3.5.1` | ⚠️ 需 patch composer PHP 约束（运行正常） |
| PHP 8.4 + `box/spout 3.3` 写 xlsx | ✅（有 deprecation 告警） |
| 自持 fork `Cecil98/dcat-admin` tag `v2.2.5` + PHP 8.3 + L12 | ✅ composer VCS 安装、`admin:install`、`route:list`、后台登录页 200、E_ALL 无 deprecation |
| `admin:install`（建表 + 种子 + 生成 Admin 目录） | ✅ |
| `route:list` / `artisan admin` | ✅ |
| `box/spout 3.3` 在 PHP 8.2 写 xlsx | ✅（3,188 bytes） |
| dcat 启动 deprecation | ✅ 启动阶段无输出（`WebUploader` 等按需才触发） |
| `yansongda/pay v2` | ❌ 与 Symfony 6/7 冲突 |
| `yansongda/pay 2.10.6` | ❌ 被安全公告 `PKSA-8dgs-n4fh-5pd5` 阻止 |
| `dcat 2.2.3` + Laravel 10 | ❌ Composer 安全策略阻止 Laravel 10 安装 |
| `paypal/rest-api-sdk-php` | ❌ `psr/log ^1` 与 L12 冲突（未实测，依赖约束可判定） |

> 备注：dcat 的 L11/L12 支持目前只在 `2.0` 分支（默认分支）上，**没有正式 release**。
> Packagist 的 `2.0.x-dev` 指向 `315658a8`（2025-06-25）；`woodynew/dcat-laravel-admin 2.2.4` 指向 `971e230a`（2026-09-03）。
> 两者都支持 Laravel 12；后者额外做了 PHP 8.x 兼容加固和扩展安装安全修复，但维护者/下载量很小，建议自持 fork。

---

## 12. 备选方案对比

| 方案 | 说明 | 工作量 | 风险 | 建议 |
|---|---|---|---|---|
| **A. Laravel 12 + woodynew/dcat 2.2.4** | 本文推荐（只保易支付 + 安全加固） | 7–10d | 小型 fork，需自持 | ✅ 推荐 |
| B. Laravel 10 + dcat 2.2.3 | 最小依赖变动，但 Laravel 10 已 EOL，需关闭 Composer 安全策略 | 8–12d | 安全债、装不上 | ❌ 不推荐 |
| C. Laravel 12 + Filament v4 重写后台 | 全栈现代化，长期最好 | 20–30d | 后台/权限/UI 全重写 | 预算充足时考虑 |
| D. 仅升级到 PHP 8.0/8.1 + Laravel 6 | 不满足 8.2 要求，且 8.0/8.1 已 EOL | 2–3d | 安全、依赖仍废弃 | ❌ 不建议 |

---

## 13. 安全加固清单（本次一并做）

> 这一节是「更安全一点」的落地点。每条都给出证据；**高危项必须修**，中危项建议本期修。

### 13.1 高危（必须修）

| # | 问题 | 证据 | 修法 |
|---|---|---|---|
| 1 | 默认后台账号 `admin` / 密码 `admin` | `database/sql/install.sql:214`；已用 `password_verify('admin', hash)` 验证为真 | 安装/升级时生成随机强密码，或首次登录强制改密；删除安装包里的固定 hash |
| 2 | `.env` 被 git 跟踪，含 APP_KEY、`APP_DEBUG=true` | `git ls-files` 显示 `.env` 在版本库；`.env` 内 `APP_KEY=base64:...` | `git rm --cached .env`；`.env.example` 只放占位符；**轮换 APP_KEY**（会让现有会话/加密数据失效，需在预发验证）；生产强制 `APP_DEBUG=false` |
| 3 | `/public/uploads` 下的 PHP 可被执行 | `conf/default.conf` 的 `location ~ \.php$` 全局生效；dcat 上传盘 `admin` 的 root 就是 `public/uploads` | nginx 增加 `location ^~ /uploads/` 并 `location ~ \.php$ { deny all; }`；限制上传类型/后缀；不要给上传目录执行权限 |
| 4 | Dockerfile `chmod -R 777 /app` | 主 `Dockerfile` | 改 755/775，仅 `storage`、`bootstrap/cache`、`public/uploads` 可写；容器内非 root 运行 |
| 5 | PHP 7.4 / Laravel 6 均已 EOL | `Dockerfile`、`composer.lock` | 本次升级到 PHP 8.3 + Laravel 12（≥12.69） |
| 6 | 使用 `composer install --ignore-platform-reqs` | `Dockerfile` | 升级后去掉；让依赖不兼容在构建期暴露 |

### 13.2 中危（建议本期修）

| # | 问题 | 证据 | 修法 |
|---|---|---|---|
| 7 | 后台登录无失败限流/锁定 | dcat `AuthController::postLogin` 没有 `ThrottlesLogins`（已核对 2.0.x-dev 源码） | 在 `app/Admin/Controllers/AuthController.php` 覆写 `postLogin`，按 IP+用户名限流（如 5 次/分钟），失败写日志 |
| 8 | Session Cookie 不安全 | `config/session.php`：`secure=false`、`same_site=null`、`encrypt=false` | 生产：`SESSION_SECURE_COOKIE=true`、`SESSION_SAME_SITE=lax`、可选 `SESSION_ENCRYPT=true` |
| 9 | 支付密钥/邮箱密码明文存储 | `pays.merchant_pem`、`system-setting` cache；`app/Admin/Forms/SystemSetting.php:20` 直接 `Cache::put` | 加一个「旧数据兼容」的自定义 cast：能解密就解密、否则当明文；写入时 `Crypt::encryptString`。`merchant_key`/`merchant_pem` 都是 TEXT，**不需要改表结构**。注意顺序：先轮换 APP_KEY，再启用加密；APP_KEY 必须单独备份（丢了密钥不可恢复）。后台列表/详情页打码显示。 |
| 10 | 易支付返回的 HTML 未转义 | `YipayController` 用字符串拼 `<form>` / `<input value="$val">` | 用 `e()` / `htmlspecialchars()` 包所有输出（尤其 `merchant_key` 作为 form action） |
| 11 | 回调无速率限制、无失败告警 | `routes/common/pay.php` 没有 throttle；`notifyUrl` 验签失败只返回字符串 | 给 `pay/*/notify_url` 加宽松 throttle（如 120/min/IP）；验签失败 `Log::warning` + 告警 |
| 12 | 未校验回调里的商户号 | `YipayController::notifyUrl` 只校验签名/金额 | 增加 `pid`（或 `mchid`）与 `payGateway->merchant_id` 的一致性校验 |
| 13 | 缺少安全响应头 | `conf/default.conf` 只有 3 个 header | 增加 `Referrer-Policy`、`Permissions-Policy`、HSTS（https 时）；`server_tokens off`；`expose_php=Off` |
| 14 | 系统设置只存 cache，清缓存即丢 | `SystemSetting.php` 只 `Cache::put('system-setting')` | 落库 `admin_settings`（或同时写库），避免误清缓存导致配置丢失 |
| 15 | 未接入依赖安全审计 | 没有 CI | 发布前 `composer audit`；把高危/严重公告作为门禁 |

### 13.3 建议（可排期）

- 后台路由前缀不要用默认 `admin`（已有 `ADMIN_ROUTE_PREFIX`，确认已改）
- 后台强密码策略；后续可加 2FA（dcat 无内置，需要扩展或反代层）
- 数据库账号最小权限；Redis `requirepass`；备份加密
- 容器：只读根文件系统（除 storage/uploads）、非 root、`no-new-privileges`、资源限制
- 日志脱敏：不要把 `sign`、`key`、`password` 写进日志
- 依赖锁定：`composer.lock` + dcat fork tag；不使用 `--ignore-platform-reqs`
- 上线后跑：`composer audit`、`php artisan about`、安全头扫描、默认口令扫描

### 13.4 安全验收（发布门禁）

- [ ] 后台不存在 `admin/admin`；首次登录必须改密
- [ ] `.env` 不在 git 里；APP_KEY 已轮换；`APP_DEBUG=false`
- [ ] `/uploads/*.php` 返回 403，不可执行
- [ ] 后台登录连续失败会限流
- [ ] 生产 Cookie：`Secure` + `SameSite=Lax` + `HttpOnly`
- [ ] 支付密钥在数据库/后台均不明文可见
- [ ] 易支付回调：验签失败被拒绝、金额不一致被拒绝、重复通知不重复发货
- [ ] `composer audit` 无 high/critical
- [ ] 安全响应头齐全（HSTS/Referrer-Policy/X-Content-Type-Options）
