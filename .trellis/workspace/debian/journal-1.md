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
