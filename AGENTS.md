<!-- TRELLIS:START -->
# Trellis Instructions

These instructions are for AI assistants working in this project.

This project is managed by Trellis. The working knowledge you need lives under `.trellis/`:

- `.trellis/workflow.md` — development phases, when to create tasks, skill routing
- `.trellis/spec/` — package- and layer-scoped coding guidelines (read before writing code in a given layer)
- `.trellis/workspace/` — per-developer journals and session traces
- `.trellis/tasks/` — active and archived tasks (PRDs, research, jsonl context)

If a Trellis command is available on your platform (e.g. `/trellis:finish-work`, `/trellis:continue`), prefer it over manual steps. Not every platform exposes every command.

If you're using Codex or another agent-capable tool, additional project-scoped helpers may live in:
- `.agents/skills/` — reusable Trellis skills
- `.codex/agents/` — optional custom subagents

Managed by Trellis. Edits outside this block are preserved; edits inside may be overwritten by a future `trellis update`.

<!-- TRELLIS:END -->

## Security audit for PHP

Use the bundled `security-audit` skill for PHP security reviews:

- Read `.agents/skills/security-audit/SKILL.md` before auditing.
- Run the baseline from the repo root:
  `./.agents/skills/security-audit/scripts/security-audit.sh .`
- Local Laravel notes: `.agents/skills/security-audit/PROJECT-USAGE.md`
  (`app/` is scanned; map upstream `Classes/**` checkpoints to `app/**`).
- Record external scanner coverage gaps (semgrep/gitleaks/trivy/gh) instead of
  treating a skipped scanner as a clean result.
