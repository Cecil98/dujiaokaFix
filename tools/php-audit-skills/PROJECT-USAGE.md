# PHP_AUDIT_SKILLS — local usage notes

Source: https://github.com/yunmengya/PHP_AUDIT_SKILLS
Vendored at commit `696383ab0a41a79113eff957fb18db20cf90dfcf` (2026-10-24) for reproducible audits.

This upstream framework is written for Claude Code Agent Teams. In this
repository it is used as a **phase guide + static toolchain**, not as an
automatic `TeamCreate` orchestrator:

- `tools/sink_finder.php` — AST sink inventory.
- `tools/vuln_intel.sh` / `composer audit` — dependency checks.
- `phases/`, `teams/`, `skills/auditors/` — checklists for targeted review.
- `tools/audit_db.sh` — optional SQLite memory store (requires `sqlite3`).

Audit outputs live under `docs/security-audit/`. Keep raw containers, databases,
and exploit payloads in `/tmp` or an untracked directory; never commit secrets,
session cookies, or live payment credentials.

Local adjustment: `tools/sink_finder.php` also looks for
`../../../vendor/autoload.php` so the project's existing `nikic/php-parser`
can be reused without a second Composer install.

## Local fixes / notes

- `tools/vuln_intel.sh` upstream had a jq shape bug (`{name: .name}` applied to
  the whole array); patched to stream each package first.
- `tools/sink_finder.php` now resolves `../../../vendor/autoload.php`.
- This run: 50 sinks, all triaged in
  `docs/security-audit/php83-upgrade/审计报告.md`.

## pi adapter

For the pi-native workflow/agents, see `docs/php-audit-pi/design.md` and
`docs/php-audit-pi/README.md`. Entry point: `.pi/workflows/php-audit-full.js`.
