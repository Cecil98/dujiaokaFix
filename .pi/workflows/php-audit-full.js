export const meta = {
  name: 'php-audit-full',
  description: 'Pi-native PHP security audit: recon, sink triage, domain audit, adversarial verify, report',
  whenToUse: 'Use when the user asks for a full PHP security audit or to run PHP_AUDIT_SKILLS on pi.',
  phases: [
    { title: 'Recon', detail: 'framework, routes/auth, dependencies, sinks, security config' },
    { title: 'Triage', detail: 'rank sinks and build the audit queue' },
    { title: 'Audit', detail: 'domain audit or per-item audit' },
    { title: 'Verify', detail: 'adversarial verification of every finding' },
    { title: 'Dynamic', detail: 'optional non-destructive local verification' },
    { title: 'Report', detail: 'write Markdown, findings JSON and SARIF' },
  ],
}

// Inputs (all optional):
//   target: project path, default "."
//   output: artifact directory, default docs/security-audit/<target-slug>
//   mode: "static" | "deep-static" | "full", default "static"
//   maxAuditItems: number, default 12 (static) / 24 (deep-static/full)
//   allowDynamic: boolean, default false; requires an isolated local/staging target
const target = String(args?.target ?? '.')
const slug = target.replace(/[^a-zA-Z0-9._-]/g, '_').replace(/^_+/, '') || 'target'
const outputDir = String(args?.output ?? ('docs/security-audit/' + slug))
const mode = String(args?.mode ?? 'static')
const maxAuditItems = Number(args?.maxAuditItems ?? (mode === 'static' ? 12 : 24))
const allowDynamic = args?.allowDynamic === true

const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info']
const DOMAINS = ['payment-authz', 'injection', 'xss-csrf-session', 'business-logic', 'config-supplychain']

const RECON_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['summary', 'artifacts', 'risks'],
  properties: {
    summary: { type: 'string' },
    artifacts: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        required: ['kind', 'path'],
        properties: {
          kind: { type: 'string' },
          path: { type: 'string' },
          notes: { type: 'string' },
        },
      },
    },
    risks: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        required: ['title', 'severity', 'evidence'],
        properties: {
          title: { type: 'string' },
          severity: { enum: SEVERITIES },
          evidence: { type: 'string' },
        },
      },
    },
  },
}

const TRIAGE_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['items', 'coverageNotes'],
  properties: {
    coverageNotes: { type: 'string' },
    items: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        required: ['id', 'domain', 'severity', 'title', 'file', 'reason', 'mustAudit'],
        properties: {
          id: { type: 'string' },
          domain: { enum: DOMAINS },
          severity: { enum: SEVERITIES },
          title: { type: 'string' },
          file: { type: 'string' },
          line: { type: 'integer' },
          sinkType: { type: 'string' },
          reason: { type: 'string' },
          mustAudit: { type: 'boolean' },
        },
      },
    },
  },
}

const FINDINGS_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['domain', 'findings', 'rejected'],
  properties: {
    domain: { type: 'string' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        required: ['id', 'title', 'severity', 'file', 'line', 'source', 'sink', 'reachability', 'impact', 'evidence', 'remediation', 'confidence'],
        properties: {
          id: { type: 'string' },
          title: { type: 'string' },
          severity: { enum: SEVERITIES },
          file: { type: 'string' },
          line: { type: 'integer' },
          source: { type: 'string' },
          sink: { type: 'string' },
          reachability: { type: 'string' },
          preconditions: { type: 'string' },
          impact: { type: 'string' },
          evidence: { type: 'string' },
          remediation: { type: 'string' },
          confidence: { type: 'number', minimum: 0, maximum: 1 },
        },
      },
    },
    rejected: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        required: ['id', 'title', 'reason'],
        properties: {
          id: { type: 'string' },
          title: { type: 'string' },
          reason: { type: 'string' },
        },
      },
    },
  },
}

const VERDICT_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['isReal', 'confidence', 'severity', 'reason', 'evidence', 'remediation'],
  properties: {
    isReal: { type: 'boolean' },
    confidence: { type: 'number', minimum: 0, maximum: 1 },
    severity: { enum: SEVERITIES },
    reason: { type: 'string' },
    evidence: { type: 'string' },
    remediation: { type: 'string' },
  },
}

const REPORT_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['reportPath', 'findingsPath', 'verifiedCount', 'summary', 'coverageGaps'],
  properties: {
    reportPath: { type: 'string' },
    findingsPath: { type: 'string' },
    sarifPath: { type: 'string' },
    verifiedCount: { type: 'integer' },
    summary: { type: 'string' },
    coverageGaps: { type: 'array', items: { type: 'string' } },
  },
}

const commonContext = `TARGET=${target}\nOUTPUT_DIR=${outputDir}\nMODE=${mode}\nUse UTF-8 and repository-relative paths in artifacts. Never print secrets or .env values.`

function reconPrompt(kind, artifact, instructions) {
  return `You are the PHP_AUDIT_SKILLS Phase-2 recon agent (${kind}) running on pi.
${commonContext}
ARTIFACT=${artifact}

${instructions}

First read the relevant upstream guidance under tools/php-audit-skills/shared/
(anti_hallucination.md, data_contracts.md, evidence_contract.md, sink_definitions.md,
php_specific_patterns.md, framework_patterns.md) and the matching teams/team2 file.
Create the artifact directory with bash (mkdir -p) and write the JSON artifact.
Do not modify application code. If php/composer is unavailable on the host, use the
project Docker image (webdevops/php:8.3) with the repository mounted at /app.
Return the structured summary; the workflow will log the artifact path.`
}

phase('Recon')
const reconTasks = [
  {
    label: 'recon:framework',
    prompt: reconPrompt('framework', outputDir + '/raw/framework.json', `Map the runtime and framework: PHP version/constraints, Laravel version, dcat version, installed packages, PHP extensions, Docker base images and composer.lock risks. Include whether the app is bootable.`),
  },
  {
    label: 'recon:routes-auth',
    prompt: reconPrompt('routes-auth', outputDir + '/raw/routes-auth.json', `Map routes and authentication. Run \`php artisan route:list --json\` if possible; otherwise parse routes/. Identify admin guard/middleware, pay routes (especially /pay/yipay), CSRF exceptions, install routes, upload endpoints and public state-changing endpoints. Record middleware names and auth preconditions.`),
  },
  {
    label: 'recon:dependencies',
    prompt: reconPrompt('dependencies', outputDir + '/raw/dependencies.json', `Run \`composer audit --locked --no-plugins --no-scripts\` if possible and run \`bash tools/php-audit-skills/tools/vuln_intel.sh composer.lock ${outputDir}/raw/dependency-intel\` when curl/jq are available. Record advisories, abandoned packages and any coverage gaps. Do not invent vulnerabilities.`),
  },
  {
    label: 'recon:sinks',
    prompt: reconPrompt('sinks', outputDir + '/raw/sinks.json', `Run \`php tools/php-audit-skills/tools/sink_finder.php app\` (or the equivalent source directory) and write the JSON output. Summarize counts by sink_type and flag the highest-risk sinks (RCE/SQLi/LFI/deserialization/file-write/SSRF/XXE/authz). Do not classify reachability yet; that is the triage/audit phase.`),
  },
  {
    label: 'recon:security-config',
    prompt: reconPrompt('security-config', outputDir + '/raw/security-config.json', `Review security-sensitive configuration and code: .env.example (never the real .env values), config/session.php, config/admin.php, app/Admin/Controllers/AuthController.php, app/Http/Controllers/Home/HomeController.php, app/Models/Pay.php, app/Http/Controllers/Pay/, conf/default.conf, conf/dujiaoka-nginx.conf, Dockerfile, Dockerfile-ARM64 and database/sql/. Check install-lock behavior, default credentials, upload execution, session cookie flags, proxy trust, security headers, payment-secret handling and queue/mail failure handling.`),
  },
]
const recon = (await parallel(reconTasks.map(t => () => agent(t.prompt, {
  label: t.label,
  phase: 'Recon',
  agentType: 'php-audit-recon',
  schema: RECON_SCHEMA,
  effort: 'medium',
})))).filter(Boolean)

log(`Recon complete: ${recon.length}/${reconTasks.length} artifacts`)
const reconJson = JSON.stringify(recon, null, 2)

// Diagnostic mode: run reconnaissance only (cheap smoke test).
if (mode === 'recon') {
  return { target, mode, outputDir, reconCount: recon.length, recon }
}

phase('Triage')
const triage = await agent(`You are the PHP_AUDIT_SKILLS Phase-2 triage/risk classifier running on pi.
${commonContext}

Recon summaries:
${reconJson}

Build a bounded audit queue. Prefer:
1. payment callback/signature/idempotency and order-completion code;
2. authentication/authorization, installer, upload handling;
3. user-controlled sources reaching SQL/command/file/include/SSRF/deserialization sinks;
4. XSS/CSRF/session/crypto and business-logic/race conditions;
5. configuration/supply-chain/info-leak issues.

Use the upstream shared/severity_rating.md and teams/team2/risk_classifier.md.
Do not invent findings. For each item record file:line, a short reason and whether it must be audited.
Return at most ${maxAuditItems} items in priority order.`, {
  label: 'triage:queue',
  phase: 'Triage',
  agentType: 'php-audit-auditor',
  schema: TRIAGE_SCHEMA,
  effort: 'high',
})

let queue = (triage?.items ?? []).filter(i => i.mustAudit).slice(0, maxAuditItems)
if (queue.length === 0) {
  queue = recon.flatMap(r => (r.risks ?? []).map((risk, i) => ({
    id: 'risk-' + i,
    domain: 'config-supplychain',
    severity: risk.severity,
    title: risk.title,
    file: 'unknown',
    reason: risk.evidence,
    mustAudit: true,
  }))).slice(0, maxAuditItems)
}
log(`Triage queue: ${queue.length} item(s); coverage: ${triage?.coverageNotes ?? 'n/a'}`)

const auditResults = []

if (mode === 'static') {
  // Grouped domain audit: cheaper and good for a first full pass.
  const groups = DOMAINS.map(domain => ({
    domain,
    items: queue.filter(i => i.domain === domain),
  })).filter(g => g.items.length > 0)

  const grouped = (await parallel(groups.map(g => () => agent(`You are a PHP audit domain auditor running on pi.
${commonContext}

DOMAIN=${g.domain}
ITEMS:
${JSON.stringify(g.items, null, 2)}

Read the upstream shared/anti_hallucination.md, evidence_contract.md, sink_definitions.md,
php_specific_patterns.md and the relevant teams/team4/*_auditor.md and skills/auditors/ files.
Trace each item from source to sink in the current code. Reject static/constant or
unreachable cases explicitly. Return evidence-backed findings and rejected items.
Do not run destructive payloads.`, {
    label: 'audit:' + g.domain,
    phase: 'Audit',
    agentType: 'php-audit-auditor',
    schema: FINDINGS_SCHEMA,
    effort: 'high',
  })))).filter(Boolean)
  auditResults.push(...grouped)
} else {
  // Per-item pipeline: audit each queue item, then verify immediately.
  const perItem = await pipeline(
    queue,
    (item) => agent(`You are a PHP audit item auditor running on pi.
${commonContext}

ITEM:
${JSON.stringify(item, null, 2)}

Read the upstream auditor guidance, then trace the exact code path for this item.
Return one FINDINGS_SCHEMA object: findings plus rejected items. Do not run
destructive payloads and do not edit code.`, {
      label: 'audit:' + item.id,
      phase: 'Audit',
      agentType: 'php-audit-auditor',
      schema: FINDINGS_SCHEMA,
      effort: 'high',
    }),
    (result, item) => {
      if (!result || !Array.isArray(result.findings) || result.findings.length === 0) {
        return { item, audit: result, verdicts: [] }
      }
      return parallel(result.findings.map(f => () => agent(`Adversarially verify this PHP audit finding.
${commonContext}

FINDING:
${JSON.stringify(f, null, 2)}

Read the current code and try to refute it: is the source user-controlled, is the
sink reachable, and is there a validator/escaping/auth check? Default to isReal=false
when evidence is missing. Do not run destructive payloads. Redact secrets.`, {
        label: 'verify:' + f.id,
        phase: 'Verify',
        agentType: 'php-audit-verifier',
        schema: VERDICT_SCHEMA,
        effort: 'high',
      }))).then(verdicts => ({ item, audit: result, verdicts }))
    },
  )
  auditResults.push(...perItem.filter(Boolean))
}

// Collect findings and verify grouped-mode findings (per-item mode already verified).
const allFindings = []
const rejections = []
for (const result of auditResults) {
  if (result?.findings) {
    allFindings.push(...result.findings)
    rejections.push(...(result.rejected ?? []))
  }
}

let verified = []
if (mode === 'static') {
  const verdicts = await parallel(allFindings.map(f => () => agent(`Adversarially verify this PHP audit finding.
${commonContext}

FINDING:
${JSON.stringify(f, null, 2)}

Read the current code and try to refute it. Return isReal, confidence, severity,
reason, evidence and remediation. Do not run destructive payloads.`, {
    label: 'verify:' + f.id,
    phase: 'Verify',
    agentType: 'php-audit-verifier',
    schema: VERDICT_SCHEMA,
    effort: 'high',
  })))
  verified = allFindings
    .map((finding, i) => ({ ...finding, verdict: verdicts[i] }))
    .filter(x => x.verdict)
} else {
  for (const result of auditResults) {
    const findings = result?.audit?.findings ?? []
    for (let i = 0; i < findings.length; i++) {
      const verdict = result.verdicts?.[i]
      if (verdict) verified.push({ ...findings[i], verdict })
    }
  }
}

const confirmed = verified.filter(x => x.verdict?.isReal === true)
const dynamic = []
if (mode === 'full' && allowDynamic && confirmed.length > 0) {
  phase('Dynamic')
  log('allowDynamic=true: running non-destructive local verification for top findings')
  const picks = confirmed
    .filter(x => ['critical', 'high'].includes(x.verdict.severity))
    .slice(0, 3)
  const results = await parallel(picks.map(x => () => agent(`Perform a NON-DESTRUCTIVE local dynamic check for this confirmed finding.
${commonContext}

FINDING:
${JSON.stringify(x, null, 2)}

Only use an isolated local/staging Docker target. Never target production. Do not
delete data, persist, exfiltrate, or run DoS loops. If the target/snapshot is not
available, return a coverage gap instead of guessing.`, {
    label: 'dynamic:' + x.id,
    phase: 'Dynamic',
    agentType: 'php-audit-dynamic',
    schema: VERDICT_SCHEMA,
    effort: 'high',
  })))
  dynamic.push(...results.filter(Boolean))
}

phase('Report')
const report = await agent(`You are the PHP audit reporter running on pi.
${commonContext}

Recon: ${JSON.stringify(recon.map(r => ({ summary: r.summary, risks: r.risks })), null, 2)}
Triage coverage: ${triage?.coverageNotes ?? 'n/a'}
Confirmed findings:
${JSON.stringify(confirmed, null, 2)}
Rejected/suspected:
${JSON.stringify(rejections, null, 2)}
Dynamic results (may be empty):
${JSON.stringify(dynamic, null, 2)}

Write the artifacts under ${outputDir}:
- 审计报告.md (Chinese; scope, method, confirmed findings, rejected items, accepted risks, coverage gaps)
- findings.json (only confirmed findings plus verdict)
- sarif.json (SARIF 2.1.0; optional but preferred)
Never include secrets, cookies, live credentials or .env values. State explicitly if
external scanners or the dynamic phase were skipped. Return the paths and counts.`, {
  label: 'report:write',
  phase: 'Report',
  agentType: 'php-audit-reporter',
  schema: REPORT_SCHEMA,
  effort: 'low',
})

return {
  target,
  mode,
  outputDir,
  reconCount: recon.length,
  queueCount: queue.length,
  confirmedCount: confirmed.length,
  dynamicCount: dynamic.length,
  report,
}
