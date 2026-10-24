# Anubis 接入说明（全站挑战，支付除外）

> 目标：给独角数卡全部 HTTP 路由加 Anubis 反爬/反自动化挑战，但放行支付发起、支付回调和支付返回。
>
> 适用：Docker Compose 部署优先；原生 Nginx 部署在最后说明。

## 1. Anubis 是什么

Anubis（`TecharoHQ/anubis`）是一个位于反向代理和业务应用之间的 **Proof-of-Work 挑战代理**，主要用来抬高 AI/爬虫批量抓取的成本。

它**不是**：

- 不是登录认证
- 不是 WAF
- 不能替代 Laravel 的 CSRF、支付验签、权限控制
- 不能阻止针对性攻击者（对方可以跑 JS 解挑战）

它的工作方式：

```text
浏览器/客户端
   │
   ▼
TLS 反向代理（Nginx/Caddy，可选）
   │
   ▼
Anubis :8080  ── 挑战通过 ──►  web:80（Nginx + PHP-FPM）
   │
   └── /pay/*、/pay-gateway/* 直接放行，不挑战
```

挑战通过后 Anubis 给浏览器一个独立 cookie（默认 7 天），后续请求不再重复挑战。

## 2. 本仓库提供的文件

```text
conf/anubis/botPolicy.yaml      # 挑战策略：支付放行 + 其他全部挑战
docker-compose.anubis.yml       # 可选 overlay，不修改默认 compose
docs/anubis-integration.md      # 本文件
```

默认 `docker-compose.yml` **不会**自动启用 Anubis；必须显式叠加 overlay。

## 3. 放行规则

策略文件里第一条就是支付放行：

```yaml
- name: allow-payment
  path_regex: '^/(pay|pay-gateway)(/|$)'
  action: ALLOW
```

它覆盖：

- `/pay/yipay/{payway}/{orderSN}`：发起支付
- `/pay/yipay/notify_url`：易支付异步回调（服务端到服务端）
- `/pay/yipay/return_url`：支付完成返回
- `/pay-gateway/...`：支付跳转辅助
- 其他保留渠道的 `/pay/*` 路由

**不要**把规则缩小成只放行 `notify_url`。支付返回页和支付发起页也可能没有 Anubis cookie，缩小会导致支付流程中断。

另外放行：

- `/\.within\.website/*`：Anubis 自己的挑战页资源
- `/.well-known/*`、`/favicon.ico`、`/robots.txt`

其余全部：

```yaml
- name: challenge-all
  path_regex: '^/.*$'
  action: CHALLENGE
```

即 **管理员后台、首页、商品页、下单、订单查询、上传文件等全部先挑战**。

> 如果站点有外部 API、监控探针、非支付 webhook 或 RSS，它们不会执行 JS，会被挑战挡住。需要给这些路径/来源加 `ALLOW`，或者不要使用严格模式。

## 4. Docker Compose 启用

### 4.1 直接启用（Anubis 对外 80）

```bash
docker compose -f docker-compose.yml -f docker-compose.anubis.yml up -d
```

ARM64：

```bash
docker compose -f docker-compose.arm64.yml -f docker-compose.anubis.yml up -d
```

overlay 会做两件事：

1. 新增 `anubis` 服务，镜像固定为 `ghcr.io/techarohq/anubis:v1.28.2`；
2. **移除 `web` 对宿主机的端口发布**（包括原来的 `80` 和 `9000`），只让 Anubis 访问 `web:80`。

确认：

```bash
docker compose -f docker-compose.yml -f docker-compose.anubis.yml ps
docker compose -f docker-compose.yml -f docker-compose.anubis.yml logs -f anubis
```

`docker compose config` 里 `web` 不应再出现 `published: "80"` 或 `published: "9000"`。

### 4.2 前面还有一层 TLS 反代（推荐生产）

如果宿主机 Nginx/Caddy 负责 443，Anubis 不要直接占 80，改为只监听回环：

```bash
ANUBIS_PORT=127.0.0.1:8080 \
docker compose -f docker-compose.yml -f docker-compose.anubis.yml up -d
```

宿主 Nginx：

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

此时 Anubis：

```dotenv
ANUBIS_USE_REMOTE_ADDRESS=false
ANUBIS_COOKIE_SECURE=true
ANUBIS_COOKIE_SAME_SITE=Lax
# 有子域时设置根域，例如：
# ANUBIS_COOKIE_DOMAIN=example.com
```

宿主反代必须把真实客户端 IP 通过 `X-Real-IP` / `X-Forwarded-For` 传给 Anubis；否则 Anubis 看到的是反代 IP。

### 4.3 纯 HTTP 测试

```dotenv
ANUBIS_COOKIE_SECURE=false
ANUBIS_COOKIE_SAME_SITE=Lax
```

否则浏览器不会发送 Secure cookie，会无限挑战。

## 5. Laravel 真实 IP：必须设置 `TRUSTED_PROXIES`

接入 Anubis 后，Laravel 看到的直接来源是 Anubis 容器，不是真实用户。

本 overlay 使用固定 Docker 子网：

```yaml
networks:
  dujiao-anubis:
    ipam:
      config:
        - subnet: 172.30.0.0/24
```

本 overlay 会自动给 `web` 注入：

```yaml
TRUSTED_PROXIES=172.30.0.0/24
```

如果你修改了 overlay 里的 subnet，必须同步修改这个值（或改 `.env`）。然后：

```bash
docker compose exec -T web php artisan optimize:clear
```

不设置会有什么问题：

- 所有用户看起来都来自 Anubis 容器 IP
- 后台登录限流、`create-order` 限流、`search-order` 限流会按同一个 IP 计算
- 日志里的客户端 IP 全部错误

> 只有在 `web` 完全不对外发布、Anubis 是唯一入口时，才可以临时用 `TRUSTED_PROXIES=*`；不建议长期使用。
>
> 如果 `172.30.0.0/24` 和宿主机现有网段冲突，改 overlay 里的 subnet 和 `.env` 同步修改。

## 6. 验证

### 6.1 支付路径必须直接到后端

```bash
curl -i http://你的域名/pay/yipay/notify_url
```

预期：不是 Anubis 挑战页，而是 Laravel 返回的 `fail`/`success` 或 404/参数错误。

### 6.2 普通路径必须出现挑战

```bash
curl -i http://你的域名/
curl -i http://你的域名/admin
```

预期：HTTP 200，HTML 标题类似：

```html
<title>Making sure you're not a bot!</title>
```

浏览器打开后会自动解挑战，随后正常进入站点。

### 6.3 挑战通过后

浏览器访问首页 → 等待挑战完成 → 再访问后台/商品页应正常；检查 Anubis cookie 是否存在。

### 6.4 检查真实 IP

```bash
docker compose -f docker-compose.yml -f docker-compose.anubis.yml logs -f anubis
```

日志里应出现真实客户端 IP，而不是全部是 Docker 网关 IP。

## 7. 常见问题

### 支付回调失败 / 一直返回挑战页

检查 `conf/anubis/botPolicy.yaml` 的第一条 `allow-payment` 是否被删除或改窄；`^/(pay|pay-gateway)(/|$)` 必须在 `challenge-all` 之前。

### 后台登录一直挑战 / 无限循环

通常是：

- HTTPS 下 `ANUBIS_COOKIE_SECURE=false`，或 HTTP 下为 `true`
- `ANUBIS_COOKIE_DOMAIN` 设置错误
- 前面还有一层反代没有传 `X-Real-IP` / `X-Forwarded-For`
- 多实例 Anubis 但使用默认随机 key

### 多实例 / 重启后挑战失效

默认使用内存存储，重启会丢失挑战状态。多副本需要：

```yaml
# 示例：Valkey/Redis 存储 + 固定 ED25519 key
# 参考 https://anubis.techaro.lol/docs/admin/policies#storage-backends
```

单实例重启后浏览器重新挑战一次即可，不影响业务。

### 监控/API 被挡住

严格模式会挑战所有非支付路径。给监控路径加 `ALLOW`，例如：

```yaml
- name: allow-health
  path_regex: '^/health$'
  action: ALLOW
```

或者对内部网段放行：

```yaml
- name: allow-internal
  remote_addresses:
    - 172.30.0.0/24
  action: ALLOW
```

注意：不要把整个 `/admin` 或 `/api` 放行。

### 静态资源/图片被挑战

首次访问会挑战，通过后 cookie 生效；不会每次请求都挑战。不要在 CDN 缓存挑战页。

## 8. 回滚

```bash
# 停掉 Anubis overlay
docker compose -f docker-compose.yml -f docker-compose.anubis.yml down

# 恢复普通 compose（web 重新发布 80）
docker compose up -d
```

`.env` 里的 `TRUSTED_PROXIES=172.30.0.0/24` 可以保留，也可以改回空；如果不再有 Anubis，建议改回空，避免信任错误来源。

## 9. 原生 Nginx 部署（不用 Docker overlay）

思路相同，Nginx 拆成两层：

1. 外层 TLS server 把请求代理到 Anubis；
2. Anubis 再把通过挑战的请求代理到应用 Nginx 的 80/Unix socket。

示例：

```nginx
upstream anubis {
    server 127.0.0.1:8080;
}

server {
    listen 443 ssl;
    server_name example.com;

    location / {
        proxy_pass http://anubis;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Anubis：

```dotenv
BIND=:8080
TARGET=http://127.0.0.1:80
POLICY_FNAME=/opt/dujiaoka/conf/anubis/botPolicy.yaml
COOKIE_SECURE=true
COOKIE_SAME_SITE=Lax
USE_REMOTE_ADDRESS=false
```

应用 Nginx 的 `TRUSTED_PROXIES` 指向 Anubis 所在地址；如果同机回环，可设为 `127.0.0.1`。

---

参考：

- https://github.com/TecharoHQ/anubis
- https://anubis.techaro.lol/docs/admin/installation
- https://anubis.techaro.lol/docs/admin/policies
- https://anubis.techaro.lol/docs/admin/environments/nginx
