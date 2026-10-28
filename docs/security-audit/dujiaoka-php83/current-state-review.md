# 当前状态安全复查（2026-10-24）

> 复查对象：`upgrade/php83`（在 `c8219a9` 基础上继续加固）。
> 方式：源码复核 + `security-audit.sh` + `composer audit` + `phpunit`。
> **不是动态渗透测试**：未启动真实支付、未做并发压测、未发送外部回调。

## 1. 工具结果

| 检查 | 结果 |
|---|---|
| `.agents/skills/security-audit/scripts/security-audit.sh .` | **0 errors / 2 warnings**（strict_types 覆盖率、宿主无 composer） |
| `composer audit --locked` | **0 advisories**；`box/spout` 为 abandoned（无已知公告） |
| `phpunit` | **52 tests / 187 assertions** |
| semgrep / gitleaks / trivy / gh | 本机未安装，**未执行**；不能据此声称“干净” |
| 动态支付/并发测试 | 未执行 |

## 2. 本轮新增修复

| 项 | 内容 |
|---|---|
| HSTS 子域策略 | `SecurityHeaders` 去掉 `includeSubDomains`，与 nginx 配置一致，避免未全量 HTTPS 的子域被强制 HSTS |
| PHP-FPM 端口 | 从基础 `docker-compose.yml` 删除 `9000:9000`。当前镜像 FPM 只监听回环，映射本身不可达，但删除后避免未来 pool 改为 `0.0.0.0` 时意外暴露 FastCGI |
| 易支付回调/返回限流 | `/pay/yipay/notify_url` `throttle:120,1`；`/pay/yipay/return_url` `throttle:30,1`。Anubis 按设计放行 `/pay/*`，所以 Laravel 层必须自己限流 |
| 回归测试 | `tests/Feature/ExampleTest` 增加易支付回调/返回限流断言 |

## 3. 仍未解决/需要运营决策

### 3.1 中危：匿名安装器（PQ-002）

- `InstallCheck` 只以 `install.lock` 是否存在为闸门。
- Docker 默认 `INSTALL=false` 会自动创建锁；但如果裸机部署、`INSTALL=true` 残留、锁文件丢失，`POST /do-install` 可重写 `.env`、执行 `install.sql`、重置管理员。
- 建议：一次性安装令牌 + 回环/私网限制，或改成 CLI 安装；先原子写锁再改配置。

### 3.2 中危：dcat 通用表单反射/ACL（PQ-007）

- `vendor/dcat/laravel-admin/.../HandleFormController.php` 按请求 `_form_` 实例化任意 Form 子类并调用 `handle()`；dcat-api 路由整体绕过 ACL，Form 授权默认 true。
- 影响需要已登录后台会话；默认单超管部署风险有限。
- 建议：在自持 dcat fork 中加 `is_subclass_of(Widgets\Form)` + 表单白名单；dcat-api 逐路由 ACL；`InstallFromLocal` 仅超管可用。

### 3.3 低危：上传扩展名/同源内容（PQ-007-UPLOAD）

- dcat 上传端点仍以客户端扩展名落盘，`accept('txt')` 只是前端限制。
- 当前 nginx 已禁止 `/uploads`、`/storage` 下 PHP，并为这些目录下发 `CSP default-src 'none'; sandbox`，所以不是现成 webshell。
- 建议：在 fork 中服务端强制 MIME/扩展名白名单，拒绝 svg/html/php/phar。

### 3.4 低危：管理员可配置的 SSRF / TLS 校验（PQ-008）

- `ApiHook` 对 `goods.api_hook` 直接 `file_get_contents()`；`BarkPush` 对 `bark_server` 直接 Guzzle 请求；两者都只允许管理员写入。
- `BarkPush`、`WorkWeiXinPush` 使用 `verify => false`，会跳过 TLS 证书校验，推送内容（含订单邮箱/金额）可被中间人读取。
- 建议：https-only、拒绝回环/私网/CGNAT、固定解析 IP；把 `verify` 改为可配置且默认 true。

### 3.5 低危：后台 HTML 字段与 CSP（PQ-009）

- `footer`、`notice`、商品 `description` / `buy_prompt` 仍以 `{!! !!}` 输出；这是后台“自定义 HTML”能力。
- 当前 nginx CSP 只有 `frame-ancestors/base-uri/object-src`，没有 `script-src`，所以后台账号被攻陷时仍可能注入脚本。
- 建议：保存期 HTML 白名单净化；至少剥离 `script`/`on*`；后台前缀单独 CSP；限制低权限管理员编辑这些字段。

### 3.6 低危：订单号作为 bearer token

- `/detail-order-sn/{orderSN}`、`/bill/{orderSN}`、`/pay-gateway/...` 以及零价订单自动完成，都依赖 16 位随机 `order_sn` 作为能力凭据。
- 订单号若通过邮件、Referer、截图、日志泄露，知道它的人可以查看卡密或触发零价订单完成。
- 建议：详情页优先要求 `dujiaoka_orders` cookie 或一次性签名链接；零价 `completedOrder` 前校验 `browserOwnsOrder()`。

### 3.7 低危：会话/后台记住我（PQ-010）

- `.env.example` 已不再写死 `SESSION_SECURE_COOKIE=false`，默认由框架按 HTTPS 自动判断。
- 但仍需部署侧保证 HTTP→HTTPS 跳转、`TRUSTED_PROXIES` 正确；dcat `remember` 默认仍是 400 天（`config/admin.php:151-152`）。
- 建议：入口 nginx 统一 301 + HSTS；后台关闭或缩短 remember；生产确认 `Set-Cookie` 有 `Secure; HttpOnly; SameSite=Lax`。

### 3.8 运维风险：旧明文备份

- 你恢复配置时生成的 `/tmp/system-setting.json`、旧 `./redis/dump.rdb` 可能仍是**明文** Telegram/SMTP 凭据。
- 当前数据库 `admin_settings` 和 Redis cache 已是 `enc:` 密文；请把旧明文备份移到加密/离线位置，或确认后删除。
- `env.conf` 含 `APP_KEY`，必须 600，和数据库备份分开放。

## 4. 未发现

- 未发现未认证 RCE。
- 未发现已知 CVE 命中的 Composer 依赖版本。
- 未发现新的 SQL 注入/XXE/命令执行 sink（grep 基线 0 errors）。
- 系统设置 `admin_settings` 现在使用 APP_KEY 加密，cache 也是密文；旧明文行需要 `system-setting:persist` 才会转密文。

## 5. 建议优先级

1. 预发/生产补一次真实易支付回调 E2E + 并发重复回调。
2. 安装器加令牌或改 CLI（PQ-002）。
3. 给 dcat fork 打表单白名单/ACL 补丁（PQ-007）。
4. 上传服务端白名单 + 后台 HTML 净化（PQ-007-UPLOAD / PQ-009）。
5. ApiHook/Bark 出站 URL 校验 + 开启 TLS verify（PQ-008）。
6. 订单详情加 cookie/签名链接（bearer token 收窄）。
