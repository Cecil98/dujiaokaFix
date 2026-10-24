# PHP_AUDIT_SKILLS on pi

Full design: [design.md](./design.md)

## What is implemented

- `.pi/workflows/php-audit-full.js` — deterministic pi workflow
- `.pi/agents/php-audit-{recon,auditor,verifier,reporter,dynamic}.md`
- `tools/php-audit-skills/` — upstream knowledge base + AST/dependency tools
- Audit artifacts under `docs/security-audit/<target>/`

## Modes

| Mode | Behavior |
|---|---|
| `recon` | 5 recon agents only; cheap smoke/diagnostic |
| `static` | recon + triage + 5 grouped domain audits + verification + report |
| `deep-static` | recon + triage + per-item audit + verification + report |
| `full` | deep-static + optional dynamic checks when `allowDynamic: true` |

## Run in pi

```text
run php-audit-full target="." mode="static" output="docs/security-audit/dujiaoka-php83"
```

Equivalent:

```js
SubagentWorkflow({
  name: "php-audit-full",
  args: {
    target: ".",
    mode: "static",
    output: "docs/security-audit/dujiaoka-php83",
    maxAuditItems: 12
  }
})
```

Pass `args` as an object (a JSON string is also accepted for CLI-style callers).
Use `mode: "recon"` first to validate tooling:

```text
run php-audit-full target="." mode="recon" output="/tmp/php-audit-recon"
```

## Safety

- The dynamic phase is **off by default**.
- `allowDynamic: true` requires an isolated local/staging target and a database
  snapshot. Never target production.
- Report only verified findings; state external-scanner and dynamic coverage gaps.
- Keep raw containers, databases and PoCs out of Git.

## Current project baseline

The existing static audit and findings are in
`docs/security-audit/php83-upgrade/审计报告.md`.
