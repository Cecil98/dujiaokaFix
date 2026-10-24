# Project usage notes (dujiaokaFix)

This directory contains the upstream `security-audit` skill (Netresearch DTT GmbH,
MIT + CC-BY-SA-4.0). See `SKILL.md` and `README.md` for the full methodology.

Local adjustments for this Laravel project:

- `scripts/security-audit.sh` auto-detects `app/` in addition to the upstream
  `src/` and `Classes/` directories. Everything under `app/` is scanned.
- `checkpoints.yaml` still uses TYPO3-oriented globs such as `Classes/**/*.php`.
  When applying a checkpoint manually to this repository, map that glob to
  `app/**/*.php` (and use `config/**/*.php` / `routes/**/*.php` for
  configuration and route checks).
- Run the PHP baseline from the repository root:

  ```bash
  ./.agents/skills/security-audit/scripts/security-audit.sh .
  ```

- Optional external scanners (`semgrep`, `gitleaks`, `trivy`, `gh`) are not
  installed in every environment; the grep-based checks still run. Do not
  report a clean result when an external scanner was skipped — record it as a
  coverage gap.

## Baseline (2026-10-24)

- `security-audit.sh` reports **0 errors / 2 warnings** on the PHP tree after
  fixing weak `rand()` token/pay-id generation and escaping the Yipay/Paysapi
  form output.
- Remaining warnings are coverage notes, not vulnerabilities:
  - `declare(strict_types=1)` is not used across the legacy app (104 files).
  - Composer is not installed on the host; run
    `composer audit --locked --no-plugins --no-scripts` inside the PHP container.
- Local false-positive filters added to the vendored scanner: `app/` scanning,
  a word boundary for command-execution functions (so `curl_exec` no longer
  matches), and an anchored `eval(`/`assert(` check (so `$codevalidate` no
  longer matches).
