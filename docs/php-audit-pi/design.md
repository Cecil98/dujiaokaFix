# PHP_AUDIT_SKILLS → pi 全量审计适配设计

- 状态：设计 + 可运行适配骨架
- 上游：`tools/php-audit-skills/`（PHP_AUDIT_SKILLS `696383ab0a41a79113eff957fb18db20cf90dfcf`）
- 适配目标：在 pi 的 `Agent` / `SubagentWorkflow` 上运行 **可恢复、可验证、可报告** 的 PHP 全量安全审计
- 目标项目：独角数卡 `upgrade/php83`（Laravel 12 / PHP 8.3 / dcat 2.2.5 / 仅易支付）

---

## 1. 目标与非目标

### 目标

1. 保留上游 121 个 skill/team/schema 资产作为**知识库**，不重写 21 类 auditor 的规则。
2. 用 pi 原生能力替换 Claude Code Agent Teams 的编排原语。
3. 支持三种运行模式：
   - `static`：静态全量，按 5 个域分组审计，成本可控；
   - `deep-static`：逐项 sink 审计 + 逐 finding 对抗验证；
   - `full`：`deep-static` + 可选的非破坏性本地动态验证。
4. 所有结论必须有 `file:line`、source → sink 路径、可达性和证据；验证者独立尝试证伪。
5. 审计产物可提交、可复现、可增量重跑；原始容器/数据库/PoC 不进 Git。

### 非目标

- 不自动攻击生产环境。
- 不把上游的 `TeamCreate`/`TaskUpdate` 语义硬编码到 pi。
- 不承诺“跑完即 0 漏洞”；输出包含覆盖率与未覆盖项。
- 不在默认流程中运行破坏性 payload、DoS、持久化或数据外带。

---

## 2. 上游与 pi 的能力差异

| 上游（Claude Code Agent Teams） | pi 对应物 | 适配策略 |
|---|---|---|
| `TeamCreate` / teammate 生命周期 | `SubagentWorkflow` + `Agent` | 用确定性 workflow 阶段代替团队对象 |
| `TaskCreate` / `blockedBy` | `phase()` + `pipeline()` / `parallel()` | 阶段即任务图；pipeline 表达依赖 |
| `TaskUpdate` 状态 | agent 返回值 + 磁盘 artifact | 每个 agent 返回结构化 schema；gate 检查 artifact |
| `SendMessage` / shutdown | agent return / `steer_subagent` | 默认一次性返回；长任务可人工 steer |
| QC 独立团队 | verifier agent + report gate | 每个 finding 独立证伪；报告前 coverage gate |
| Claude hooks | pi workflow + agent frontmatter | 用 prompt 契约和 schema 代替 hook |
| 断点恢复 | `SubagentWorkflow` `resumeFromRunId` + artifacts | 已完成的阶段前缀走缓存；原始产物落盘 |
| 子代理模型选择 | `agent({ model, effort })` | recon=medium，audit=high，verify=high，report=low |
| 1 个 agent 可读写所有文件 | 自定义 agent `tools` 白名单 | recon/audit 只读；reporter 才可写报告 |

**关键结论**：pi 不需要模拟 Agent Teams。用 **workflow 负责确定性编排，agent 负责单点推理，verifier 负责证伪，artifact 负责状态** 更可靠。

---

## 3. 总体架构

```text
                         ┌──────────────────────────────┐
                         │  .pi/workflows/php-audit-full.js │
                         │  phase / parallel / pipeline  │
                         └──────────────┬───────────────┘
                                        │
        ┌───────────────┬───────────────┼───────────────┬────────────────┐
        ▼               ▼               ▼               ▼                ▼
  Recon agents    Triage agent    Domain auditors   Verifiers       Reporter
  (5 并行)        (1)             (5 或逐项)        (逐 finding)    (1)
        │               │               │               │                │
        └───────────────┴───────────────┴───────────────┴────────────────┘
                                        │
                              docs/security-audit/<target>/
                              ├── raw/*.json
                              ├── 审计报告.md
                              ├── findings.json
                              └── sarif.json
```

### 3.1 文件布局

```text
tools/php-audit-skills/                 # 上游知识库（vendored）
├── SKILL.md
├── phases/ teams/ shared/ schemas/ skills/ tools/
└── PROJECT-USAGE.md

.pi/agents/
├── php-audit-recon.md                  # 只读侦察
├── php-audit-auditor.md                # 域/单项审计
├── php-audit-verifier.md               # 对抗证伪
├── php-audit-reporter.md               # 报告/SARIF
└── php-audit-dynamic.md                # 可选本地动态验证（默认关闭）

.pi/workflows/
└── php-audit-full.js                   # 全量编排入口

docs/php-audit-pi/
├── design.md                           # 本设计
└── README.md                           # 快速使用/参数说明

docs/security-audit/<target>/<run>/     # 每次审计产物（可提交摘要）
```

---

## 4. 全量审计阶段

| 阶段 | 目标 | 执行者 | 产物 | Gate |
|---|---|---|---|---|
| P0 授权与准备 | 确认目标、环境、凭据、回滚 | 主会话 | `scope.md` | 用户确认非生产目标 |
| P1 环境侦察 | 框架/PHP/依赖/Docker/路由/认证 | 5 个 recon agents 并行 | `raw/framework.json` 等 | 5 个 artifact 至少 4 个成功 |
| P2 风险定级 | sink 去重、可达性初判、优先级 | triage agent | `raw/priority.json` | 队列非空或明确说明无风险 |
| P3 认证/追踪 | 登录态、角色、路由中间件、数据流 | 可选；`deep-static/full` | `raw/auth.json`、`raw/traces/` | 认证矩阵存在或标记降级 |
| P4 深度审计 | 21 类 auditor → 5 域/逐项 | audit agents | `raw/findings/*.json` | 每个 finding 有证据 |
| P4.5 关联/利用 | 攻击链、PoC（仅本地） | 可选 dynamic agent | `raw/attack-graph.json` | 仅 `allowDynamic=true` |
| P5 报告 | 合并、去重、评分、SARIF | reporter | `审计报告.md`、`findings.json`、`sarif.json` | 验证者确认 + 覆盖率声明 |
| P6 修复回归 | 修复、重跑、验证 | 主会话/实现 agent | 修复 commit + 复测 | 原 finding 关闭或标记接受 |

### P1 侦察 agent 拆分

| Agent | 读取 | 产出 |
|---|---|---|
| framework | `composer.json/lock`、`config/app.php`、Dockerfile、`php -v` | 框架/版本/扩展/兼容性风险 |
| routes-auth | `php artisan route:list --json`、`routes/`、`config/admin.php` | 路由/中间件/guard/CSRF/安装器/支付入口 |
| dependencies | `composer audit`、`vuln_intel.sh`、lock | 依赖公告、abandoned、供应链 |
| sinks | `sink_finder.php app` | AST sink 清单与统计 |
| security-config | `.env.example`、session/admin/Pay/Home/nginx/Docker/SQL | 密钥/上传/限流/Cookie/默认账号/错误处理 |

### P2 风险定级规则

优先级从高到低：

1. 支付回调/签名/金额/幂等、订单完成；
2. 认证/授权、安装器、上传；
3. 用户输入 → SQL/命令/文件/包含/SSRF/反序列化；
4. XSS/CSRF/Session/加密/业务逻辑/竞态；
5. 配置/依赖/信息泄露。

每条队列项必须有 `domain`、`severity`、`file`、`reason`、`mustAudit`。`mustAudit=false` 只进入报告附录。

---

## 5. pi workflow 设计

### 5.1 入口参数

```js
{
  target: ".",                              // 目标项目路径
  output: "docs/security-audit/dujiaoka",   // 产物目录
  mode: "static",                           // static | deep-static | full
  maxAuditItems: 12,                        // 静态默认 12；deep 默认 24
  allowDynamic: false                       // full 模式下才可能为 true
}
```

### 5.2 阶段与并发

```text
Recon:    parallel(5 recon agents)          # 最多 5 并发
Triage:   1 agent                            # 汇总 + 定级
Audit:    static -> parallel(5 domain agents)
          deep/full -> pipeline(items, audit, verify)
Verify:   每个 finding 独立 verifier          # 对抗证伪，默认 isReal=false
Dynamic:  full + allowDynamic -> 最多 3 个高危 finding 的本地非破坏性验证
Report:   1 reporter agent
```

- `pipeline` 优先于 `parallel`：某个 item 审计完立即进入验证，不等其他 item。
- 每个 agent 都带 `phase` 标签，进度按阶段分组，避免 `phase()` 全局状态竞争。
- 失败 item 返回 `null` 并在报告中记录为覆盖缺口；不静默丢弃。
- 总并发由 pi workflow 自动限制；item 数上限由 `maxAuditItems` 控制。

### 5.3 断点/幂等

- 同参数重跑可 `resumeFromRunId` 复用已完成前缀。
- 不在 workflow 中使用 `Date.now()`/`Math.random()`；`runId` 由调用方传入或使用固定 suffix。
- 原始 artifact 落盘；报告只依赖磁盘文件，不依赖聊天上下文。
- 增量模式（后续）：`git diff` 变更文件 < 10 时，只审计变更文件 + 其调用方。

### 5.4 成本控制

| 模式 | Agent 数量（典型） | 适用 |
|---|---:|---|
| static | 5 recon + 1 triage + 5 domain + N verify + 1 report | 日常 PR/发布前 |
| deep-static | 5 + 1 + 12~24 item + N verify + 1 | 重大版本/支付变更 |
| full | deep-static + ≤3 dynamic | 预发/隔离环境专项 |

> 模型建议：recon `medium`；audit `high`；verifier `high`；reporter `low`。主会话可按预算覆盖。

> 注意：workflow `agent()` 不支持 `maxTurns` 参数（该参数只属于主会话 `Agent` 工具）；适配层用 `maxAuditItems` + pipeline 控量，避免传入非法 option。

---

## 6. 数据契约与产物

### 6.1 原始产物

```text
<output>/raw/
├── framework.json
├── routes-auth.json
├── dependencies.json
├── sinks.json
├── security-config.json
├── priority.json
├── findings/<domain>.json
└── traces/                  # 仅 deep/full
```

### 6.2 最终产物

```text
<output>/
├── 审计报告.md               # 中文，含范围/方法/确认漏洞/误报/覆盖率
├── findings.json             # 仅 verifier 确认的 finding
├── sarif.json                # SARIF 2.1.0，供 GitHub/VS Code
└── raw/                      # 原始证据（可按 .gitignore 排除）
```

### 6.3 Finding 最小字段

| 字段 | 说明 |
|---|---|
| `id` | 稳定 ID（domain + 短哈希/序号） |
| `title` | 简短标题 |
| `severity` | critical/high/medium/low/info |
| `file`, `line` | 证据位置 |
| `source` | 用户可控输入/数据对象 |
| `sink` | 危险函数/操作 |
| `reachability` | 路由/任务/命令/认证前置 |
| `preconditions` | 权限/配置/环境 |
| `impact` | 影响 |
| `evidence` | 代码片段/命令输出（脱敏） |
| `remediation` | 修复建议 |
| `confidence` | 0–1 |
| `verdict` | verifier 的 `isReal`/理由 |

### 6.4 证据规则

- 必须引用当前 commit 的 `file:line`。
- 必须有 source → sink 的可达路径；仅“函数危险”不算 finding。
- 静态/常量参数、框架自动转义、签名校验、参数化查询要显式排除。
- 证据中不得出现 `.env` 值、Cookie、支付密钥、数据库密码。

---

## 7. Agent 编制

| Agent | 工具 | 职责 | 禁止 |
|---|---|---|---|
| `php-audit-recon` | read/bash/grep/find/write | 5 类侦察与 artifact | 改代码/跑 exploit |
| `php-audit-auditor` | read/bash/grep/find | 域/单项审计、source→sink | 改代码/跑 exploit |
| `php-audit-verifier` | read/bash/grep/find | 独立证伪 | 轻信 finding |
| `php-audit-reporter` | read/write/bash | 报告/findings/SARIF | 写入未验证 finding |
| `php-audit-dynamic` | read/bash/write | 可选本地非破坏性验证 | 生产/破坏性/外带 |

所有 agent 都必须先读：

```text
tools/php-audit-skills/shared/anti_hallucination.md
tools/php-audit-skills/shared/evidence_contract.md
tools/php-audit-skills/shared/data_contracts.md
```

---

## 8. 覆盖矩阵

### 8.1 21 类 auditor → 5 个审计域

| 域 | 覆盖 auditor | 本项目重点 |
|---|---|---|
| payment-authz | authz、business_logic、race_condition、csrf、session、crypto | 易支付验签/金额/幂等、订单完成、后台权限、安装锁 |
| injection | sqli、rce、lfi、filewrite、ssrf、xxe、deserial、nosql、ldap、crlf、logging | 安装器、支付回调、订单查询、API Hook、Geetest/Coinbase |
| xss-csrf-session | xss_ssti、csrf、session、crypto | Blade `{!! !!}`、后台菜单/toastr、支付表单、Cookie |
| business-logic | business_logic、race_condition | 重复回调、库存、优惠码、卡密、订单状态机 |
| config-supplychain | config、infoleak、dep_scanner、wordpress(不适用) | `.env`、Nginx、Docker、composer、dcat fork |

### 8.2 本项目专项检查

- 易支付：`YipayController` 签名/金额/`trade_no` 幂等/重复通知；
- 订单：`OrderProcessService::completedOrder` 事务、金额 `bccomp`、重复发货；
- 后台：`AuthController` 限流、默认密码强制修改、代理信任；
- 安装器：`install.lock`、`.env` 写入、SQL 导入、输入换行/URL 校验；
- 支付密钥：加密 cast、后台打码、`pay:encrypt-secrets/decrypt-secrets`；
- 上传：`/uploads`、`/storage` 禁 PHP；dcat 上传盘；
- 邮件/队列：空收件人、From 回退、failed_jobs；
- 供应链：dcat 自持 fork、`box/spout` abandoned。

---

## 9. 动态测试安全边界

`full` 模式默认**不执行**动态利用；只有 `allowDynamic: true` 且用户确认后，`php-audit-dynamic` 才可运行：

1. 目标必须是隔离的本地/预发 Docker，不能是生产或真实用户环境。
2. 必须有数据库快照/回滚；没有快照 → 记录 coverage gap，停止。
3. 只允许非破坏性验证：读、登录、参数探测、单次请求；禁止 `DROP`、删除文件、持久化、DoS、外带。
4. 所有请求限速；不扫描第三方域名；不绕过目标授权范围。
5. 证据脱敏；不保存 Cookie/Token/支付密钥。
6. 结束后清理容器、临时库、上传文件。

---

## 10. 运行方式

### 10.1 在 pi 中触发

让主会话执行：

```text
run php-audit-full target="." mode="static" output="docs/security-audit/dujiaoka-php83"
```

主会话会调用：

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

### 10.2 三种模式

```text
static       日常/发布前；5 域分组，成本低
deep-static  重大版本/支付变更；逐项 sink，最多 24 项
full         deep-static + 可选本地动态；需 allowDynamic=true + 用户授权
```

### 10.3 增量重跑

- 同参数 + 同 `scriptPath` 使用 `resumeFromRunId` 复用前缀。
- 修复后重跑：只对原 finding 的 file/domain + 变更文件重新审计；报告保留历史 `fixed` 状态。

---

## 11. 全量执行计划

| 阶段 | 内容 | 验收 |
|---|---|---|
| A0 | 适配层落地：agents + workflow + 设计文档 | `node --check`；静态 smoke 可跑 |
| A1 | 本项目 static 全量 | 5 个 recon artifact；queue；报告 |
| A2 | 本项目 deep-static（支付/安装器/订单） | 每项有 verifier 结论 |
| A3 | 修复 + 回归 | PHPUnit/E2E/旧库升级/镜像 |
| A4 | 预发 full（可选） | 动态结论仅限隔离环境 |
| A5 | 报告归档 | `docs/security-audit/...` + SARIF |

---

## 12. 质量门禁

1. 每个 finding 必须有 verifier；未验证的只能进“待复核”。
2. `isReal=false` 必须写明缺失的证据或阻断控制。
3. 报告必须声明：
   - 未运行的扫描器（Semgrep/Psalm/PHPStan/CodeQL 等）；
   - 未运行的动态阶段；
   - 未覆盖的路由/认证/第三方集成；
   - 依赖数据库时间点。
4. 不允许把“扫描器 0 告警”写成“无漏洞”。
5. 报告不得包含 secrets；提交前可运行 `gitleaks`/`git diff --check`。

---

## 13. 风险与取舍

| 风险 | 处理 |
|---|---|
| LLM 幻觉/误报 | 强制 verifier 证伪；要求 file:line + source→sink；静态/常量显式排除 |
| 成本过高 | static 分组；maxAuditItems；pipeline 并行；按需 deep |
| 动态测试破坏数据 | 默认关闭；隔离 Docker + 快照；禁止破坏性 payload |
| 上游与 pi 不同步 | 上游 vendored；适配层独立在 `.pi/`，升级上游不覆盖 |
| 工具缺失 | recon 记录 coverage gap；PHP/composer 用 Docker；不伪造结果 |
| 报告泄密 | reporter 脱敏；原始 artifact 不进 Git；`.env` 永不读取值 |

---

## 14. 待确认决策

1. **是否执行 full 动态阶段**：需要隔离预发、数据库快照、测试账号；默认不跑。
2. **目标范围**：仅 `app/` + 配置/部署，还是包含 `vendor/`/dcat fork？建议排除 vendor，单独审计 fork。
3. **成本上限**：static 12 项 / deep 24 项是否合适；可按需调整。
4. **产物是否入库**：建议只入库 `审计报告.md`/`findings.json`/`sarif.json`，`raw/` 与 PoC 不入库。
5. **模型档位**：是否需要为 verifier 固定更高档位。

---

## 15. 当前实现边界（重要）

本适配层是“全量设计 + 可运行骨架”，不是把上游 121 个文件全部自动执行：

| 上游阶段 | pi 适配状态 | 说明 |
|---|---|---|
| Phase 1 环境/框架 | ✅ recon 覆盖 | framework/routes/deps/sinks/config 五路 |
| Phase 2 静态侦察 | ✅ recon + triage | 使用 `sink_finder`/`composer audit`/`vuln_intel` |
| Phase 3 认证模拟/Xdebug 追踪 | ⏳ 设计已覆盖，未自动执行 | 需要登录态、Xdebug、隔离容器 |
| Phase 4 21 类 auditor | ✅ 5 域分组 / 逐项 pipeline | 可按域扩展为 21 个独立 auditor |
| Phase 4.5 攻击图/关联/PoC | ⏳ 设计已覆盖，未自动执行 | `php-audit-dynamic` 仅做非破坏性确认 |
| Phase 5 报告 | ✅ Markdown/JSON/SARIF | 只报告 verifier 确认项 |
| SQLite 攻击记忆/QC 池 | ⏳ 未接入 | 用 workflow 缓存 + 磁盘 artifact 替代 |

因此 `full` 模式目前是 **deep-static + 可选非破坏性动态确认**，不等于上游完整的 8 轮对抗利用。需要完整动态链时，再按第 9 节的安全边界扩展。

## 16. 快速开始（当前已落地）

```bash
# 语法检查
node --check .pi/workflows/php-audit-full.js

# 静态扫描基线（已有）
php tools/php-audit-skills/tools/sink_finder.php app

# 依赖情报（已有）
bash tools/php-audit-skills/tools/vuln_intel.sh composer.lock docs/security-audit/php83-upgrade/dependency-intel
```

pi 中运行全量：

```text
run php-audit-full target="." mode="static" output="docs/security-audit/dujiaoka-php83"
```

当前适配层实现的是 **static / deep-static 可运行骨架**；`full` 的动态阶段需要显式 `allowDynamic=true` 和隔离环境。
