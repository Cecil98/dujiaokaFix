# 独角数卡 PHP 8.3 / Laravel 12 升级部署手册

> 适用版本：`v2.1.0-php83`（PHP 8.3 / Laravel 12 / dcat-admin 2.2.5）
> 适用场景：从旧版 PHP 7.4 / Laravel 6 的独角数卡原地升级；也包含全新安装的关键差异。
> 目标：数据库只增不删、旧订单/商品/卡密/后台账号可继续使用；升级后默认只启用易支付。

---

## 0. 升级前必须知道的变化

| 项目 | 旧版 | 升级后 |
|---|---|---|
| PHP | 7.4 | 8.3 |
| Laravel | 6.x | 12.x |
| 后台 | dcat 2.0 | dcat 2.2.5（自持 fork） |
| 支付 | 多渠道 | 默认全部渠道 `is_open=0`；配置好易支付凭据后再手动启用 `/pay/yipay` |
| 语言目录 | `resources/lang` | `lang/` |
| 数据库 | 无 app migrations | 增加 dcat 扩展表、`failed_jobs.uuid`、migrations 记录 |
| 支付密钥 | 明文 | 加密存储，后台打码 |
| 邮件 | Laravel 6 Mail | Laravel 12 Symfony Mailer |
| 后台登录 | 无限流 | 5 次/分钟 + 默认密码强制修改 |
| 上传目录 | 可能可执行 PHP | Nginx 禁止 `uploads/`、`storage/` 下 PHP |

**升级顺序不能颠倒**：备份 → 停流量/维护模式 → 数据库 SQL → 新代码/镜像 → `.env`/APP_KEY → Artisan 命令 → 冒烟 → 放流量。

---

## 1. 准备清单

### 1.1 服务器与账号

- 能登录服务器，具备 Docker / Docker Compose（或 PHP-FPM + Nginx 原生部署能力）。
- 数据库账号具备 `CREATE TABLE`、`ALTER TABLE`、`INSERT` 权限。
- 有旧镜像 tag、旧代码 tag/commit、旧 `.env` 的备份位置。
- 维护窗口：建议 30–60 分钟；APP_KEY 轮换会让所有后台/前台会话失效。

### 1.2 备份（必须做）

```bash
# 变量按实际修改
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=dujiaoka
DB_USER=dujiaoka
BACKUP_DIR=/root/dujiaoka-backup/$(date +%F-%H%M)

mkdir -p "$BACKUP_DIR"

# 1) 数据库逻辑备份
mysqldump --single-transaction --routines --triggers \
  -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p "$DB_NAME" \
  > "$BACKUP_DIR/dujiaoka.sql"

# 2) 上传文件、storage、安装锁、compose 文件
tar czf "$BACKUP_DIR/dujiaoka-files.tgz" \
  public/uploads storage install.lock \
  docker-compose.yml docker-compose.arm64.yml .env 2>/dev/null || true

# 3) 单独备份 .env（包含 APP_KEY/数据库密码，务必限制权限）
cp -a .env "$BACKUP_DIR/env.backup"
chmod 600 "$BACKUP_DIR"/*
```

> **APP_KEY 必须单独保存**。支付密钥加密后与 APP_KEY 绑定；丢失 APP_KEY 会导致支付密钥无法解密。

### 1.3 记录当前状态

```bash
git describe --tags --always
docker compose ps
docker image ls | grep -i dujiaoka
php -v
```

---

## 2. 数据库升级（先做，可回退）

升级 SQL 全部为增量、幂等操作：

```bash
mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p "$DB_NAME" \
  < database/sql/upgrade-php83.sql
```

脚本会：

1. 创建 `admin_extensions`、`admin_extension_histories`；
2. 补齐 4 条 dcat migrations 记录（`WHERE NOT EXISTS`）；
3. 给 `failed_jobs` 增加 `uuid`（若不存在）；
4. 将非易支付渠道、以及凭据为空/仍是占位符的易支付行置 0；已配置真实凭据的易支付行保留原有开关状态；
5. 将 `pays.merchant_id` 扩为 `TEXT`（为加密后的密文留空间）。

验证：

```bash
mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p "$DB_NAME" -e "
  SHOW TABLES LIKE 'admin_extension%';
  SHOW COLUMNS FROM failed_jobs LIKE 'uuid';
  SELECT id,pay_name,is_open FROM pays WHERE is_open=1;
"
```

预期：两张扩展表存在；`failed_jobs.uuid` 存在；其他渠道全部 `is_open=0`；已配置真实凭据的易支付行保留升级前的开关状态，未被自动强制开启。

> **支付渠道默认全部关闭**（安全审计 PQ-01）。升级脚本会把非易支付渠道、以及凭据为空或仍是
> 出厂占位符 `商户号` / `密钥` 的易支付行置 0；已配置真实凭据的易支付行保留原有开关状态，不会被自动改写。
> 因此正确顺序是：**先到后台「支付配置」填好易支付商户号、网关地址（http/https URL）、商户密钥 → 再手动启用易支付**：
>
> ```sql
> UPDATE `pays` SET `is_open` = 1 WHERE `pay_handleroute` = '/pay/yipay';
> ```
>
> 未填写或仍是占位符的易支付渠道，即使 `is_open=1` 也会被拒绝下单/回调。
> 如果你明确还要用其他渠道，请在升级完成后到后台手动开启对应 `pays.is_open`；其他渠道代码保留但不默认启用。

---

## 3. 代码/镜像部署

### 3.1 Docker Compose（推荐）

当前仓库提供两套镜像构建：

```bash
# x86_64
docker build -t dujiaoka:2.1.0-php83 .

# ARM64
docker buildx build --platform linux/arm64 \
  -f Dockerfile-ARM64 \
  -t dujiaoka:2.1.0-php83-arm64 --load .
```

如果走镜像仓库：

```bash
docker tag dujiaoka:2.1.0-php83 registry.example.com/dujiaoka:2.1.0-php83
docker push registry.example.com/dujiaoka:2.1.0-php83
# ARM64 同理
```

修改 `docker-compose.yml` / `docker-compose.arm64.yml`：

```yaml
services:
  web:
    image: registry.example.com/dujiaoka:2.1.0-php83
    # 如果继续本地构建，可保留 build: .
    environment:
      # 已有站点升级必须是 false；首次安装才临时改 true
      INSTALL: "false"
      TZ: Asia/Shanghai
      WEB_DOCUMENT_ROOT: "/app/public"
    volumes:
      - ${DATA_DIR}/dujiaoka/.env:/app/.env
      - ${DATA_DIR}/dujiaoka/install.lock:/app/install.lock
      - ${DATA_DIR}/dujiaoka/storage:/app/storage
      - ${DATA_DIR}/dujiaoka/public/uploads:/app/public/uploads
```

> 已有站点必须有 `install.lock`。如果宿主机不存在该文件，先创建：
>
> ```bash
> echo ok > ${DATA_DIR}/dujiaoka/install.lock
> ```
>
> 否则容器可能把挂载点当成目录，安装器状态会异常。

启动：

```bash
docker compose pull        # 使用远程镜像时
docker compose up -d       # 本地构建时可用 --build
docker compose ps
docker compose logs -f web
```

### 3.2 原生 PHP-FPM + Nginx

参考 `debian_manual.md`，核心差异：

```bash
apt install php8.3 php8.3-fpm php8.3-mysql php8.3-gd php8.3-zip \
  php8.3-opcache php8.3-curl php8.3-mbstring php8.3-intl php8.3-dom \
  php8.3-bcmath php8.3-redis php8.3-fileinfo

cd /path/to/dujiaoka
composer install --no-dev --optimize-autoloader
```

Nginx 必须包含上传目录禁执行（可参考 `conf/default.conf` / `conf/dujiaoka-nginx.conf`）：

```nginx
location ^~ /uploads/ {
    location ~ \.php$ { deny all; }
    location ~ /\.    { deny all; }
    try_files $uri =404;
}

location ^~ /storage/ {
    location ~ \.php$ { deny all; }
    location ~ /\.    { deny all; }
    try_files $uri =404;
}

location ~ /\.(?!well-known).* { deny all; }
```

权限只给可写目录：

```bash
chown -R root:root /path/to/dujiaoka
chown -R www-data:www-data \
  storage bootstrap/cache public/uploads
chmod -R 775 storage bootstrap/cache public/uploads
```

重启：

```bash
systemctl restart php8.3-fpm
nginx -t && systemctl reload nginx
```

---

## 4. `.env` 配置

先备份旧 `.env`，再修改以下项：

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://你的域名

# 留空（不写该行）时框架按请求是否 HTTPS 自动加 Secure；
# 站点始终在 TLS 后面时可显式写 true；纯 HTTP 环境不要写 true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax

# 仅在应用位于可信反向代理之后时设置；直接暴露端口时留空
# 可填 * 或逗号分隔的代理 IP
TRUSTED_PROXIES=

# 文件系统/队列/缓存保持现有生产配置
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
```

注意：

- 不要提交 `.env` 到 Git。
- `TRUSTED_PROXIES=*` 会信任任意来源的 `X-Forwarded-For`；如果应用直接暴露公网，留空，否则后台登录限流可被伪造 IP 绕过。
- `APP_DEBUG=false` 必须确认，否则异常页可能泄露堆栈和配置。
- 如果使用 `ADMIN_HTTPS=true`，确保 `APP_URL` 与实际访问协议一致。

---

## 5. APP_KEY 与支付密钥（关键顺序）

### 5.1 旧库仍是明文支付密钥（首次升级的常见情况）

```bash
# 0) 先清理旧配置缓存，避免新进程继续使用缓存里的旧 APP_KEY
docker compose exec -T web php artisan optimize:clear

# 1) 备份旧 APP_KEY（.env 已备份）
grep '^APP_KEY=' .env

# 2) 生成新 APP_KEY；会让现有会话失效
docker compose exec -T web php artisan key:generate --force

# 如果 .env 是只读挂载、容器内用户不可写：
#   docker compose exec -T web php artisan key:generate --show
# 把输出手工写入宿主机 .env 的 APP_KEY= 行，再继续。

# 3) 用新 APP_KEY 加密旧明文支付密钥
docker compose exec -T web php artisan pay:encrypt-secrets

# 4) 验证
docker compose exec -T web php artisan pay:encrypt-secrets
# 第二次应输出 rows updated: 0
```

### 5.2 如果数据库已经加密过（重复升级/回滚后重升）

必须先用**旧 APP_KEY** 解密，再轮换，再加密：

```bash
# 使用当前（旧）APP_KEY 解密
docker compose exec -T web php artisan pay:decrypt-secrets

# 清理配置缓存，再执行 5.1 的 key:generate + pay:encrypt-secrets
docker compose exec -T web php artisan optimize:clear
```

### 5.3 重要警告

- 不要在**已加密**状态下直接改 `APP_KEY`，否则支付密钥无法解密，易支付会验签失败。
- 回滚到旧代码前，必须执行：

  ```bash
  docker compose exec -T web php artisan pay:decrypt-secrets
  ```

- 如果 APP_KEY 丢失：只能用备份的旧 APP_KEY 恢复，或重新在后台填写支付密钥。
- `APP_KEY` 轮换后，所有用户需要重新登录；这是预期行为。

---

## 6. Artisan 初始化

```bash
docker compose exec -T web php artisan optimize:clear
docker compose exec -T web php artisan admin:update
# 预期最后一行：Nothing to migrate

docker compose exec -T web php artisan config:clear
docker compose exec -T web php artisan view:clear
docker compose exec -T web php artisan queue:restart
```

说明：

- `admin:update` 会发布 dcat 2.2.5 资源/迁移；本仓库已提交发布产物，重复执行应无文件变更。
- 不建议在存在闭包路由时执行 `route:cache`；`config:cache` 可选，但修改 `.env` 后必须重新生成。
- 如果队列使用 Redis，`queue:restart` 让旧 worker 退出，新 worker 使用新代码。

---

## 7. 后台账号处理

旧库默认 `admin/admin` 会在登录后被强制跳转到“个人设置”修改密码；新密码至少 10 位，且不能继续使用 `admin`。

如果旧库不是默认密码，直接用原账号登录即可。

如果完全无法登录，可临时重置（执行后立即修改）：

```bash
docker compose exec -T web php artisan tinker --execute="
\Dcat\Admin\Models\Administrator::where('username','admin')
  ->update(['password' => bcrypt('请替换为新的强密码')]);
"
```

> 不要把真实密码写进脚本、Shell 历史或聊天记录。

---

## 8. 升级后验证

### 8.1 命令行

```bash
# 框架与路由
docker compose exec -T web php artisan --version
docker compose exec -T web php artisan route:list | grep -E 'pay/(yipay|alipay|wepay|paypal)'
# 预期只有 pay/yipay*

# 数据库
docker compose exec -T web php artisan admin:update
# Nothing to migrate

# 支付密钥（第二次应为 0）
docker compose exec -T web php artisan pay:encrypt-secrets

# 队列（没有任务时立即结束）
docker compose exec -T web php artisan queue:work --stop-when-empty

# 依赖安全（镜像内有 composer 时）
docker compose exec -T web composer audit --locked --no-plugins --no-scripts
```

### 8.2 HTTP 冒烟

```bash
# 首页 / 后台登录
curl -I https://你的域名/
curl -I https://你的域名/admin/auth/login

# 上传目录/点文件必须 403
curl -I https://你的域名/uploads/test.php
curl -I https://你的域名/uploads/.env
curl -I https://你的域名/.env
```

预期：首页/后台 200 或正常跳转；三个敏感路径均为 **403**。

### 8.3 业务回归

- 后台：商品、订单、卡密、优惠码、支付配置、系统设置、邮件测试页面可打开。
- 后台：商品新增/编辑/删除；卡密导入/导出 xlsx。
- 前台：首页 → 商品详情 → 下单 → 易支付跳转 → 异步回调 → 订单完成。
- 易支付：同一订单重复回调不重复发货；金额不一致回调返回 `fail`；未配置凭据的易支付渠道下单/回调被拒绝。
- 邮件：后台“邮件测试”或下单邮件能入队/发送；`failed_jobs` 不应持续增长。
- 其他渠道：前台默认不显示；后台 `pays.is_open` 仅已配置并手动启用的渠道为 1。

### 8.4 日志

```bash
docker compose exec -T web tail -n 200 storage/logs/laravel.log
```

重点看：

- 是否有 `Deprecated` 高频刷屏；
- 是否有支付验签/金额异常；
- 是否有 `DecryptException`（通常是 APP_KEY 与加密数据不匹配）。

---

## 9. 回滚手册

数据库只增不删，通常**不需要回滚数据库**；新表/新列旧代码会忽略。

### 9.1 回滚前（如果新代码已加密支付密钥）

```bash
# 使用新 APP_KEY 解密
docker compose exec -T web php artisan pay:decrypt-secrets
```

如果已经无法启动新容器，可在临时 PHP 8.3 容器中挂载代码执行，或恢复升级前数据库备份（会丢失升级后订单，谨慎）。

### 9.2 切回旧镜像/旧代码

```bash
docker compose down
# 把 compose 的 image 改回旧 tag，例如 dujiaoka:2.0.6
docker compose up -d
```

原生部署：

```bash
git checkout <升级前 tag/commit>
composer install --no-dev --optimize-autoloader
systemctl restart php8.3-fpm
```

### 9.3 回滚后检查

- 旧代码能否正常读订单/商品/卡密；
- 易支付是否正常（如果支付密钥已解密）；
- 后台账号能否登录；
- 其他渠道是否需要恢复 `pays.is_open`（升级脚本已把它们置 0）。

### 9.4 回滚注意

- 如果新代码已经加密支付密钥，**未执行 `pay:decrypt-secrets` 就回滚，旧代码会读不到明文，支付会失败**。
- 如果 APP_KEY 已轮换，回滚后旧会话全部失效，用户重新登录即可。
- 新表/新列保留不会影响旧代码；不要为了回滚删除它们。

---

## 10. 维护窗口执行清单（可直接照做）

| # | 操作 | 验证 |
|---|---|---|
| 1 | 通知停服/进入维护模式 | `php artisan down --retry=60` |
| 2 | 备份数据库、`.env`、`public/uploads`、`storage`、`install.lock` | 备份文件存在且可解压 |
| 3 | 记录旧镜像 tag/旧代码 commit | 能回滚 |
| 4 | 执行 `upgrade-php83.sql` | 新表、`failed_jobs.uuid`、pays 状态正确 |
| 5 | 部署 `v2.1.0-php83` 代码/镜像 | 容器启动，日志无 fatal |
| 6 | 修改 `.env`（`APP_DEBUG=false`、`SESSION_SECURE_COOKIE=true` 等） | `config:clear` 后配置生效 |
| 7 | 轮换 APP_KEY → `pay:encrypt-secrets` | 第二次命令 `rows updated: 0` |
| 8 | `admin:update`、`optimize:clear`、`queue:restart` | `Nothing to migrate` |
| 9 | 后台登录、商品/订单/卡密页面 | 200，默认密码被强制修改 |
| 10 | 上传目录/`.env` HTTP 检查 | 403 |
| 11 | 易支付下单→回调→订单完成；重复回调/金额错误 | 不重复发货、金额错误 fail |
| 12 | 队列、邮件、日志 | 无持续 failed_jobs/DecryptException |
| 13 | `php artisan up` 放流量 | 首页/下单正常 |
| 14 | 观察 30–60 分钟 | 支付成功率、错误日志、队列 |

---

## 11. 已知注意事项

1. **APP_KEY 与支付密钥强绑定**：轮换前先解密，轮换后再加密；APP_KEY 必须单独备份。
2. **`SESSION_SECURE_COOKIE`**：`.env.example` 不再写死 `false`，留空时框架会按请求是否 HTTPS 自动加 `secure`；纯 HTTP 环境请保持不设置（写死 `true` 会导致登录 Cookie 不发送），站点始终在 TLS 后面时可显式设为 `true`。
3. **`TRUSTED_PROXIES`**：只有确实位于反向代理之后才设置；否则不要信任 `X-Forwarded-For`。
4. **`INSTALL=false`**：已有站点必须保持 false；首次安装临时 true，安装完成立刻改回。
5. **`pays.is_open`**：升级脚本会关闭其他渠道和未配置/占位符的易支付行，但不会覆盖已配置易支付行的手动开关（PQ-01：出厂占位符 `商户号` / `密钥` 不可用）；如确需其他渠道，升级后手动开启。
6. **`box/spout`**：`dcat/easy-excel` 依赖的导出库已 abandoned，但没有已知安全公告；后续可评估替代。
7. **静态审计**：完整动态利用审计可运行 `.pi/workflows/php-audit-full.js`（需隔离环境）；本仓库已做 PHP_AUDIT_SKILLS 静态审计 + 定向复核，报告见 `docs/security-audit/dujiaoka-php83/审计报告.md`，整改记录见同目录 `整改说明.md`。
8. **镜像**：当前仓库只打了本地 tag `v2.1.0-php83`，未远程 push；上线前需要你自行 push 镜像/tag 或使用 CI。

---

## 12. 快速命令汇总

```bash
# 备份
mysqldump --single-transaction --routines --triggers -h"$DB_HOST" -u"$DB_USER" -p "$DB_NAME" > dujiaoka.sql
tar czf dujiaoka-files.tgz public/uploads storage install.lock .env

# 数据库
mysql -h"$DB_HOST" -u"$DB_USER" -p "$DB_NAME" < database/sql/upgrade-php83.sql

# 镜像
docker build -t dujiaoka:2.1.0-php83 .
docker buildx build --platform linux/arm64 -f Dockerfile-ARM64 -t dujiaoka:2.1.0-php83-arm64 --load .

# 启动
docker compose up -d

# 初始化
docker compose exec -T web php artisan key:generate --force
docker compose exec -T web php artisan pay:encrypt-secrets
docker compose exec -T web php artisan admin:update
docker compose exec -T web php artisan optimize:clear
docker compose exec -T web php artisan queue:restart

# 验证
docker compose exec -T web php artisan route:list | grep pay/
curl -I https://你的域名/uploads/test.php
curl -I https://你的域名/.env
```
