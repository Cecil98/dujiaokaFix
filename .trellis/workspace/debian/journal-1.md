# Journal - debian (Part 1)

> AI development session journal
> Started: 2026-10-24

---


## 2026-10-24 — PHP 8.3 / Laravel 12 upgrade

- Upgraded runtime/deps to PHP 8.3, Laravel 12.x, dcat 2.2.5 (self-hosted fork).
- Removed Alipay/Wepay/PayPal direct gateways; epay-only default with legacy
  channels retained but disabled.
- Added idempotent DB upgrade SQL, encrypted/masked payment credentials, admin
  login throttling, forced default-password change, uploads PHP denial,
  security headers, safer Docker permissions and installer lock defaults.
- Verified PHPUnit 8/8, epay E2E (success/duplicate/amount mismatch), admin
  CRUD + xlsx export, queue/mail, old-schema upgrade, x86/arm64 image builds.
- Vendored `tools/php-audit-skills`; static sink audit (50 sinks, all triaged)
  and dependency intel in `docs/security-audit/php83-upgrade/`.
- Local tag: `v2.1.0-php83`; not pushed remotely.

## 2026-10-24 — PHP_AUDIT_SKILLS pi 适配 + 全量静态审计整改

- 新增 `.pi/workflows/php-audit-full.js` 与 `.pi/agents/php-audit-{recon,auditor,verifier,reporter,dynamic}.md`，
  把上游 PHP_AUDIT_SKILLS 的 6 阶段 / Agent Teams 映射为 pi 的 SubagentWorkflow + Agent；
  设计文档 `docs/php-audit-pi/design.md`。
- 以 `mode=static` 跑完整全量审计：`docs/security-audit/dujiaoka-php83/`
  （14 项确认发现：4 high / 5 medium / 5 low；static-only，动态阶段未执行）。
- 按审计结果修复并提交：
  - 易支付占位密钥（PQ-001）：三处凭据校验 + 出厂全渠道关闭 + 升级 SQL 保留运营者开关；
  - 匿名邮箱查单（PQ-003）：cookie/search_pwd 归属证明 + 限流；
  - Stripe（PQ-004/GET）：POST+CSRF+cookie+强制 context、服务端金额与汇率缓存、异常不回显；
  - 卡密/优惠券/库存/订单过期（PQ-005/011/02）：行锁 + CAS + 异常单不回退待支付；
  - 极验死代码（PQ-006）、后台 textarea XSS、Secure Cookie 默认值。
- 验证：PHPUnit 41/41（147 assertions）、`php -l` 全通过、security-audit 0 errors/2 warnings、
  `composer audit` 0 advisories（box/spout abandoned）、SQL 升级连跑两次幂等。
- 提交：`b272110`（code/tests/spec）、`35e62d1`（audit report + remediation）。
- 残留：安装器 INSTALL 令牌、dcat 表单 ACL/上传白名单、ApiHook 出站守卫、CSP/HSTS，
  已在 `整改说明.md` 标注；未远程 push。
