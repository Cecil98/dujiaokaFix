# Security Guidelines

Project-specific security contracts for the Dujiaoka PHP 8.3 / Laravel 12 codebase.

## Secrets and configuration

- `.env` is **never** tracked. Keep `.env.example` placeholder-only and use safe
  production defaults (`APP_ENV=production`, `APP_DEBUG=false`).
- Payment credentials (`pays.merchant_id`, `merchant_key`, `merchant_pem`) are
  stored through `App\Casts\EncryptedOrPlaintext`. Legacy plaintext rows remain
  readable, but run `php artisan pay:encrypt-secrets` after deployment.
- Before rolling back to code without the cast, run
  `php artisan pay:decrypt-secrets`.
- Do not log credential values. Admin grids/details/forms must keep the
  `********` masking and the `saving` callback that preserves masked values.

## Uploads and Nginx

- `/uploads/` and `/storage/` must never execute PHP. Keep the `location ^~`
  blocks in `conf/default.conf` and `conf/dujiaoka-nginx.conf`.
- The uploads disk is `config('filesystems.disks.admin')` rooted at
  `public/uploads`. Never add a PHP extension allow-list bypass.

## Authentication and sessions

- Admin login is rate-limited in `App\Admin\Controllers\AuthController`
  (5 attempts/minute/username+IP).
- Default/legacy `admin/admin` passwords are forced to change through
  `App\Http\Middleware\ForceAdminPasswordChange`; do not remove the middleware
  alias from `config/admin.php`.
- `TRUSTED_PROXIES` is empty by default. Only set it when a known reverse proxy
  terminates TLS; otherwise `X-Forwarded-For` can bypass IP throttling.
- Production HTTPS must set `SESSION_SECURE_COOKIE=true` and `APP_DEBUG=false`.

## Installer

- The installer is only available before `install.lock` exists.
- Docker deployments default to `INSTALL=false`; first install must explicitly
  set `INSTALL=true`, then switch back.
- Validate installer inputs (no CR/LF in `.env` values), keep `.env` values
  quoted, and never return raw DB/Redis exception messages to the browser.

## Payment callbacks

- Verify the gateway signature with `hash_equals`/`secureCompare`.
- Compare signing fields strictly (`===`), reject non-scalar callback values,
  and verify the gateway route and order amount.
- `OrderProcessService::completedOrder` must stay idempotent: the same
  `trade_no` on an already-processed order must not ship or email twice.
- Rejected business callbacks (amount mismatch, duplicate trade number) return
  `fail`, not HTTP 500.

## Verification commands

```bash
vendor/bin/phpunit
php artisan route:list --except-vendor
composer audit --locked --no-plugins --no-scripts
./.agents/skills/security-audit/scripts/security-audit.sh .
php tools/php-audit-skills/tools/sink_finder.php app
```

Static sink results must be triaged in `docs/security-audit/`; do not report a
clean result when an external scanner was skipped.

## Audit automation

- Use `.pi/workflows/php-audit-full.js` for pi-native audits; start with
  `mode="recon"`, then `static`/`deep-static`.
- Upstream knowledge/tools live in `tools/php-audit-skills/`; do not copy
  findings into Git without verifying them.
- The dynamic phase is opt-in only (`allowDynamic=true`) and requires an
  isolated local/staging target with a database snapshot.
- Keep raw artifacts and PoCs out of Git; commit only the sanitized report,
  `findings.json` and `sarif.json` when appropriate.
