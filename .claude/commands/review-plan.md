# Multi-Perspective Plan Review (Parallel Agents)

Review the current plan using **independent parallel agents**, each with its own context window and dedicated personas. This eliminates shared-bias from single-context sequential reviews and produces deeper, more diverse analysis.

Use opus for all agents (orchestrator and subagents).

---

## Phase 1: Gather Context

1. **Read** `docs/REVIEW_PERSONAS.md` (full persona definitions)
2. **Find the active plan**: Glob for `~/.claude/plans/*.md`, read the most recently modified plan file
3. **Read key source files** referenced in the plan (if any)
4. Store the plan content and persona definitions — you'll include them directly in agent prompts

---

## Phase 2: Smart Persona Selection

**Always include:**
- Senior Developer (all plans)
- Project Manager (all plans)

**Analyze plan content for keywords to determine if these personas are relevant:**
- **Documentation Manager**: .env, config, integration, cron, schedule, dependency, extension, nginx, deploy, install, artisan command, route
- **Halo API Specialist**: Halo, HaloClient, API, endpoint, sync, prepay, contract, invoice, ticket, client data
- **Security & Compliance**: auth, SSO, Entra, token, credential, secret, permission, encrypt, session, vulnerability
- **AI Expert**: AI, LLM, prompt, Claude, Anthropic, OpenAI, triage, tool_use, agentic, transcription, model, tokens, pipeline
- **MSP Operations Manager**: ticket, contract, billing, SLA, prepaid hours, client onboarding, workflow, reconciliation
- **ITIL Expert**: incident, change management, problem management, SLA, service catalogue, CMDB, audit
- **Staff User (Technician)**: UI, view, dashboard, page, form, button, workflow, blade, interface, navigation
- **Solo MSP Owner-Operator**: automation, default, billing, invoice, dashboard, solo, small, simple, onboarding, setup, mobile, time saving, batch
- **Client Persona**: client-facing, portal, report, statement, communication, client view, billing summary
- **The Critic**: migration, schema change, irreversible, breaking change, complex dependency, data model
- **The Wildcard**: new feature, major redesign, novel, innovative, unconventional

**If uncertain, be inclusive.** When in doubt, include the persona.

---

## Phase 3: Map Personas to Agent Clusters

Group selected personas into review agents. **Only create agents whose personas were selected.**

| Agent | Personas | Launch When |
|-------|----------|-------------|
| **Architecture** | Senior Developer | Always |
| **Documentation** | Documentation Manager | Config/deploy/integration/dependency keywords |
| **Halo Integration** | Halo API Specialist | Halo/API keywords |
| **Security & Risk** | Security & Compliance + The Critic | Security keywords, schema changes, complex deps |
| **AI Integration** | AI Expert | AI/LLM/prompt/triage/tool_use keywords |
| **Operations** | MSP Operations Manager + ITIL Expert | Workflow/billing/SLA keywords |
| **User Experience** | Staff User + Client Persona + Solo MSP Owner-Operator | UI changes, new features, client-facing, automation, defaults |
| **Project Scope** | Project Manager | Always |
| **Strategy** | The Wildcard | New features, major redesigns |

**Typical plan = 3-5 agents.** Small backend changes might be 3; major features might be 6-7.

---

## Phase 4: Launch Parallel Review Agents

Launch ALL selected agents **simultaneously in a single message** using the Task tool.

**For each agent:**
- `subagent_type`: `"general-purpose"`
- `model`: `"opus"`
- `description`: short label like `"Architecture review"`, `"Security review"`, etc.

**Construct each agent's prompt by including:**
1. Their specific persona definition(s) copied from REVIEW_PERSONAS.md
2. The project context block (below)
3. The full plan content
4. The review format instructions

### Project Context Block (include in every agent prompt)

```
SOUND IT PSA CONTEXT:
Sound PSA — a modern, self-hosted PSA for managed service providers. Standalone-first: every module works natively with its own schema and logic. Halo PSA sync is available for teams migrating from Halo and will be deprecated once no longer needed.
- Tech: Laravel 12 + Blade + Bootstrap 5.3 CDN. No Node.js/Vite. PHP 8.3 + Composer native on Linux dev VM (soundit-dev).
- Auth: Entra ID SSO (single-tenant per deployment, socialiteproviders/microsoft)
- Architecture: Standalone-first. HaloClient service used for migration sync only — thin controllers, business logic in services.
- Database: MariaDB soundit_psa_dev (local dev at ~/repos/soundit-psa/), MariaDB soundit_psa (production VPS at /var/www/psa/)
- Deploy: git push → SSH pull on VPS (your-vps / <deploy-user>@<your-vps>), artisan migrate + cache
- Users: MSP staff per deployment (no client-facing auth, no public registration). Self-hosted, no SaaS tier.
- Related repos: external branding/asset repos as configured locally
- Key principle: Sound PSA is a standalone product, not a Halo companion app. Halo sync is temporary migration tooling. Build features that stand alone and improve on what Halo offered.
```

### Agent Prompt Template

For each agent, construct a prompt following this pattern:

```
You are conducting an independent plan review for the Sound PSA project. You have been assigned specific review personas. Analyze the plan ONLY from these perspectives — be specific, concrete, and opinionated. Don't hedge.

YOUR REVIEW PERSONAS:
[Paste the full persona definition(s) from REVIEW_PERSONAS.md for this agent's assigned personas]

[Project context block from above]

THE PLAN TO REVIEW:
[Paste the full plan content]

REVIEW FORMAT:
For EACH of your assigned personas, provide:

### [Persona Name]

**Notices:**
- [What stands out — both positive and concerning. Be specific, reference plan sections.]

**Recommendations:**
- [Specific, actionable suggestions. Not vague "consider X" — say exactly what should change.]

**Calls for Additional Review:** (optional)
- [Persona Name]: [Why their input is needed — only if you spot something genuinely outside your expertise]

Then end with:

### Agent Severity Summary
- **Red flags:** [Critical — must fix before proceeding]
- **Yellow flags:** [Worth addressing or noting]
- **Green lights:** [Done well]
```

### Important Notes
- **Include plan content directly** in each prompt — don't make agents search for it
- **Include persona text directly** — each agent only gets its own 1-3 personas, not all 10
- **If the plan references specific source files**, tell the agent: "You may read [file path] for additional context on the current implementation."

---

## Phase 5: Synthesize Results

After all agents complete, synthesize their independent findings:

### 5a. Report Persona Selection

```
## Review Configuration

**Agents launched:** [N] agents, [N] personas
**Personas:** [list all]
**Skipped:** [list with reasons]
```

### 5b. Organize Findings by Category

Group all agent findings into themed sections:

- **Architecture & Technical** (from Architecture agent)
- **Documentation Impact** (from Documentation agent)
- **Halo Integration** (from Halo Integration agent)
- **Security & Risk** (from Security agent)
- **Operations & ITIL** (from Operations agent)
- **User Experience** (from User Experience agent)
- **Project Scope** (from Project Scope agent)
- *(additional sections as applicable)*

### 5c. Identify Cross-Agent Agreement

Look for:
- Issues flagged **independently by 2+ agents** = HIGH CONFIDENCE concern
- Strengths praised **independently by 2+ agents** = HIGH CONFIDENCE positive
- **Contradictions** between agents = needs discussion

```
## Cross-Agent Findings

**High-confidence concerns** (flagged by multiple independent agents):
- [Issue] — flagged by [Agent A] and [Agent B]

**High-confidence strengths:**
- [Strength] — praised by [Agent A] and [Agent B]

**Contradictions to resolve:**
- [Agent A] says X, but [Agent B] says Y — [your assessment]
```

### 5d. Process "Calls for Additional Review"

Collect all cross-agent review requests. If any persona was called that wasn't in the initial launch, note it for the second pass.

---

## Phase 6: Second Pass (If Warranted)

**Launch follow-up agents if:**
- First-pass agents called for personas that weren't initially included
- Multiple agents flagged the same area with different concerns
- A red flag needs verification from another perspective

**Skip this phase** if first-pass reviews converged cleanly.

---

## Phase 7: Final Report

Present the consolidated report:

```
# Plan Review Report

## Review Configuration
**Agents launched:** [N] (pass 1) + [N] (pass 2, if any)
**Personas reviewed:** [list]
**Skipped:** [list with reasons]

---

## Findings

### Architecture & Technical
[Merged findings]

### Halo Integration
[Merged findings]

### Security & Risk
[Merged findings]

### Operations & ITIL
[Merged findings]

### User Experience
[Merged findings]

### Project Scope
[Merged findings]

---

## Cross-Agent Analysis

**High-confidence concerns** (independent agreement):
- [Issue] — [Agent A] + [Agent B]

**High-confidence strengths** (independent agreement):
- [Strength] — [Agent A] + [Agent B]

**Contradictions:**
- [Description + resolution]

---

## Summary

**Red Flags** (must fix):
- [Issue] — flagged by [persona(s)]

**Yellow Flags** (consider):
- [Issue] — flagged by [persona(s)]

**Green Lights** (well done):
- [Aspect] — noted by [persona(s)]

**Recommendation:** [Proceed as-is / Revise specific sections / Reconsider approach]
```

---

## When to Use This Command

**Use for:**
- New features or modules being added to the PSA
- Halo API integration changes
- Schema changes or migrations
- Auth or security changes
- Client-facing features
- Architectural decisions with long-term impact

**Skip for:**
- Trivial bug fixes
- CSS/branding tweaks
- Documentation-only updates
- Internal tooling changes

---

**Note**: Personas are maintained in `docs/REVIEW_PERSONAS.md`. Edit that file to change personas — this command picks up changes automatically.
