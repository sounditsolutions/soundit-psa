# Sound PSA Review Personas

When reviewing plans, consider these perspectives to catch blind spots and ensure changes are technically sound, operationally practical, and aligned with how an MSP actually works.

## Smart Persona Selection

The `/review-plan` command uses smart selection to include only relevant personas for each review:

**Always Included (Core):**
- Senior Developer — Architecture and maintainability review for all plans
- Project Manager — Scope and priority alignment for all plans

**Conditionally Included (Based on Plan Content):**
- Documentation Manager — For changes that affect install steps, `.env` variables, cron schedules, integrations, dependencies, or deployment procedures
- MSP Operations Manager — For features involving tickets, contracts, billing, SLAs, prepaid hours, or client workflows
- Halo API Specialist — For any changes touching HaloClient, API calls, data sync, or Halo data model
- Security & Compliance — For auth changes, credential handling, API tokens, data access, or VPS configuration
- ITIL Expert — For features involving incident management, change management, SLA tracking, or service catalogue
- AI Expert — For features involving LLM/AI integration, prompt engineering, tool use, agentic loops, or AI-driven automation
- Staff User (Technician) — For UI changes, new workflows, or anything staff interact with daily
- Solo MSP Owner-Operator — For features touching automation, defaults, billing simplicity, or single-person workflows
- Client Persona — For client-facing features (portal pages, reports, communications)
- The Critic — For schema changes, irreversible decisions, complex dependencies, or integration changes
- The Wildcard — For new features, major redesigns, or when fresh perspective is needed

---

## Technical Reviewers

### Senior Developer

**Focus Areas:**
- System architecture and design patterns
- Code maintainability and technical debt
- Laravel conventions and best practices
- Performance and scalability
- Long-term sustainability of solutions

**Typical Concerns:**
- Are we introducing unnecessary complexity?
- Will this be maintainable in 6 months?
- Does this follow Laravel conventions or fight against them?
- Are we reinventing something Laravel already provides?
- What's the blast radius if this breaks?

**Key Questions:**
- Is this the right architecture for the problem?
- Have we considered simpler alternatives?
- Does this follow our existing patterns (thin controllers, service classes)?
- Are we keeping the "no frontend build step" constraint?
- What are the long-term maintenance implications?

**Project-Specific Checks:**
- Services go in `app/Services/`, not controllers
- HaloClient is a singleton — inject via constructor, never instantiate directly
- No Vite/npm — Bootstrap 5.3 + Icons via CDN only
- File drivers for cache and sessions (no Redis)
- MariaDB on both local dev (`soundit_psa_dev`) and production (`soundit_psa`) — confirm migrations use MariaDB-compatible syntax

---

### Halo API Specialist

**Focus Areas:**
- Halo PSA API data model and quirks
- API endpoint selection and parameter usage
- Data sync strategies (read-through, cache, polling)
- Error handling for Halo API failures
- OAuth2 token lifecycle and caching

**Typical Concerns:**
- Are we using the correct Halo endpoint for this operation?
- Does this handle Halo's data format quirks correctly?
- What happens when Halo is down or slow?
- Are we caching appropriately (not over-fetching, not stale)?
- Does this match how Halo actually returns data?

**Key Questions:**
- Have we checked the Halo API index (`docs/Halo/halo-api-index.md`) for this endpoint?
- Does this handle pagination correctly?
- Are we passing `includelines=true` where needed for line items?
- Does `POST` to this endpoint replace or append? (Halo varies by endpoint)
- Are we handling the OAuth2 token refresh (auto-retry on 401)?
- Is `_itemid` vs `item_id` handled correctly for invoice lines?

**Known Halo Gotchas:**
- `POST /ClientContract` with `contract_prepayhistory` **replaces the entire array** — must read first, append, POST all back
- Invoice line item product field is `_itemid` (underscore prefix), not `item_id`
- `GET /RecurringInvoice` requires `includelines=true` to get line items
- `POST /ClientPrepay` creates at client level only, even if you pass `contract_id`
- Halo Portal custom pages use `$CLIENT_ID` (no braces) for variable substitution
- Prepay items: 56 (managed, $300), 57 (break-fix, $600), 58 (generic)

---

### Security & Compliance

**Focus Areas:**
- Authentication and authorization (Entra ID SSO)
- API credential management (Halo, Microsoft, other vendors)
- Client data access controls
- VPS security and secrets management
- OWASP top 10 vulnerability prevention

**Typical Concerns:**
- Are API credentials stored securely (env vars, not code)?
- Can users access data they shouldn't?
- Are we properly sanitizing input?
- Is the SSO flow implemented correctly?
- Could this expose client data if the VPS is compromised?

**Key Questions:**
- Are secrets in `.env` only, never committed to git?
- Does this maintain proper session security?
- Are we validating all user input before passing to Halo API?
- Could this create an injection vulnerability (SQL, command, XSS)?
- Is the Entra ID tenant restriction enforced?
- Are Halo API tokens cached securely (file cache, not exposed)?

**Project-Specific Checks:**
- Entra ID SSO: single-tenant restriction, proper callback validation
- Halo OAuth2: tokens cached via Laravel Cache, auto-retry on 401
- VPS: SSH key auth only, secrets in `/var/www/psa/.env`
- No client-facing auth — all users are MSP staff authenticated via SSO (each MSP deployment has its own staff team)

---

## Domain Experts

### MSP Operations Manager

**Focus Areas:**
- MSP business workflows and daily operations
- Ticket lifecycle and triage processes
- Contract and billing management
- Prepaid hours tracking and reconciliation
- Client onboarding and offboarding
- SLA compliance and reporting

**Typical Concerns:**
- Does this match how we actually work, or how we wish we worked?
- Will this save time or create more admin overhead?
- Does this handle the edge cases we hit every week?
- Is this a genuine improvement over what Halo offered?
- Will this break our existing workflows during the Halo transition?

**Key Questions:**
- Is this better and faster than the old Halo workflow it replaces?
- Does this handle the full lifecycle (create, update, close)?
- What happens when a client has unusual billing arrangements?
- Does this account for our actual contract types (managed, break-fix, hybrid)?
- Are Halo-synced records handled correctly as read-only during transition?
- Are we building for the common case or getting bogged down in edge cases?

**Red Flags to Watch:**
- Features that are no better than what Halo offered — we should be improving, not just porting
- Workflows that don't match the actual order of operations in the field
- Assumptions about billing that don't account for real-world messiness
- Forgetting that technicians are often on-site with limited time/attention

---

### ITIL Expert

**Focus Areas:**
- Incident management best practices
- Change management and risk assessment
- Problem management and root cause tracking
- Service catalogue and service level management
- Configuration management (CMDB)
- Continual service improvement

**Typical Concerns:**
- Does this align with ITIL service management principles?
- Are we tracking the right metrics for SLA compliance?
- Does this support proper change management workflows?
- Is there an audit trail for significant actions?
- Are we distinguishing between incidents, problems, and changes correctly?

**Key Questions:**
- Does this feature support or undermine our SLA commitments?
- Is there proper categorization and prioritization?
- Can we generate meaningful reports from this data?
- Does this create a proper audit trail?
- Are we following the incident lifecycle (log, categorize, prioritize, investigate, resolve, close)?
- Does this support root cause analysis, not just symptom resolution?

**Project-Specific Checks:**
- Sound PSA should improve on Halo's ITIL implementations — are we building something better, or just porting?
- Are ticket statuses and categories well-defined in our own schema (not just mirroring Halo's)?
- Do reports reflect SLA-relevant metrics (response time, resolution time)?
- Is change management supported for contract/billing modifications?

---

## Project Management

### Project Manager

**Focus Areas:**
- Scope management and feature creep
- Priority alignment with business needs
- MVP vs nice-to-have distinctions
- Effort vs impact trade-offs
- Risk assessment

**Typical Concerns:**
- Are we solving the right problem?
- Is this the highest priority right now?
- Can we ship a simpler version first?
- What's the opportunity cost of building this?
- Is this a meaningful improvement over what Halo offered?

**Key Questions:**
- What's the simplest version that delivers value?
- Can we defer any parts of this?
- What's the effort vs impact ratio?
- Does this reduce manual work for the team?
- Is this something we'll actually use daily, or is it a nice idea?
- Does this move us closer to full Halo retirement?

**Project-Specific Checks:**
- Does this serve the MSP technicians and staff who use this daily?
- Does this improve on the equivalent Halo workflow, or just replicate it?
- Is transition sync (read-only Halo data) handled correctly during the migration period?
- Is the deploy path simple (git push, SSH pull, artisan commands)?

---

### Documentation Manager

**Focus Areas:**
- Keeping `docs/INSTALL.md` accurate as the codebase evolves
- Ensuring `README.md` reflects current architecture and capabilities
- Flagging when a plan will invalidate or require updates to existing documentation
- Catching undocumented configuration, dependencies, or deployment steps

**Typical Concerns:**
- Does this plan change anything a deployer needs to know?
- Will `docs/INSTALL.md` still be accurate after this change ships?
- Are new `.env` variables, PHP extensions, or dependencies documented?
- Does this add or change a scheduled command, integration, or artisan command?
- Will the README's architecture section or route table need updating?

**Key Questions:**
- Which documents need updating as part of this plan?
- Are new configuration options documented with clear descriptions and defaults?
- If a new integration is added, is it listed in INSTALL.md Section 9 (Optional Integrations)?
- Does the cron schedule table in INSTALL.md Section 6 still match `routes/console.php`?
- Are there new troubleshooting scenarios to add to INSTALL.md Section 11?
- Would a new MSP deployer be confused or blocked by this change?

**Documents to Track:**

| Document | What to watch for |
|----------|-------------------|
| `docs/INSTALL.md` | New `.env` vars, PHP extensions, integrations, cron commands, Nginx changes, deployment steps |
| `README.md` | Architecture changes, new route groups, stack changes, key file table |
| `CLAUDE.md` | New slash commands, architecture decisions, API gotchas, development patterns |
| `.env.example` | New or renamed environment variables (must stay in sync with INSTALL.md) |

**Output Format:**
When flagging documentation impact, be specific:
- "INSTALL.md Section 3 needs `php8.3-gd` added to the extensions list"
- "INSTALL.md Section 6 cron table needs new `notifications:send` command"
- "README.md route table needs the new `/reports` routes"
- "No documentation impact" (if the change is internal-only)

**Project-Specific Checks:**
- Does `.env.example` match what INSTALL.md Section 4 documents?
- Does the cron table in INSTALL.md match `routes/console.php`?
- Are all Settings UI integrations listed in INSTALL.md Section 9?
- Is the Nginx config template in INSTALL.md still accurate?

---

## User Perspectives

### Staff User (Technician)

**Context:**
- Internal MSP technician handling tickets, client calls, and on-site work
- Busy, often multitasking between several clients
- Transitioning from Halo PSA — familiar with its workflows but frustrated by its limitations
- Wants tools that save time, not add process
- Accesses the PSA app between appointments or during admin time
- Not a developer, but tech-savvy

**Typical Concerns:**
- Is this actually better and faster than the old Halo workflow?
- Can I find what I need in under 3 clicks?
- Will this work on my phone/tablet when I'm on-site?
- Does this show me what I need without information overload?
- Will I remember how to use this if I only use it once a week?

**Key Questions:**
- Is this a genuine improvement on the equivalent Halo workflow?
- Can I see the most important info at a glance?
- Does this work well on mobile for on-site use?
- Are the workflows intuitive, or do I need to remember a specific process?
- Does this surface the right data for my role?
- Will this slow me down during a busy day?

**Red Flags to Watch:**
- Features that require more effort than the manual process
- Dense screens that require scrolling to find key info
- Workflows that don't match how a tech actually moves through their day
- Missing keyboard shortcuts or quick-actions for power users

---

### Solo MSP Owner-Operator

**Context:**
- Runs the entire MSP alone — technician, salesperson, accountant, and manager in one person
- Every minute spent on admin is a minute not spent on billable work or client acquisition
- Has 20–80 endpoints under management, 5–25 clients, no employees
- Wears every hat: field tech, help desk, bookkeeper, project manager, procurement
- Often working from a truck, a client site, or a home office with constant context-switching
- Budget-conscious — this PSA replaces expensive per-tech-seat tools (Halo, ConnectWise, etc.)
- Needs the tool to run itself as much as possible — automation isn't a luxury, it's survival

**Typical Concerns:**
- Does this feature actually save me time, or does it create more busywork?
- Can I get in, do the thing, and get out in under 30 seconds?
- Will this work on my phone while I'm standing in a server room?
- Does this assume I have a dispatcher, a billing department, or a project manager? I don't.
- How much does this cost me in time/money per month — is it worth it at my scale?
- If I ignore this feature for two weeks, will things break or pile up?

**Key Questions:**
- Does this workflow assume multiple roles or handoffs? Solo operators don't hand off — they context-switch.
- Can this be fully automated or at least batched so I deal with it once a week instead of daily?
- Is the default behavior sensible without configuration? I don't have time to tune 15 settings.
- Does this scale *down* gracefully to a 10-client shop, or is it only useful at 50+ clients?
- Can I see my whole business at a glance — open tickets, overdue invoices, expiring contracts — in one place?
- Will this help me look professional to clients even though it's just me behind the curtain?

**Red Flags to Watch:**
- Features that assume team collaboration (assignment queues, escalation tiers, shift scheduling)
- Multi-step workflows that could be a single action with smart defaults
- Dashboards designed for managers reviewing staff, not an operator reviewing their own work
- Billing complexity that requires an accountant to understand
- Notification overload — solo operators can't afford alert fatigue
- Setup wizards or onboarding flows that take hours before the tool is useful
- Reporting that's only valuable with 5+ technicians generating data

---

### Client Persona

**Context:**
- Small business owner or office manager at one of the MSP operator's client companies
- Not technically sophisticated — relies on the MSP operator for IT management
- Cares about uptime, responsiveness, and clear communication
- Interacts through client portal, reports, or emailed summaries
- Wants to understand what they're paying for

**Typical Concerns:**
- Can I see what work has been done for my business?
- Is the language plain English, not tech jargon?
- Do I understand what I'm being billed for?
- Can I find my prepaid balance and usage easily?
- Will I get notified about important changes?

**Key Questions:**
- Is this information presented in business terms, not MSP jargon?
- Can the client self-serve this, or do they need to call us?
- Does the report/view build trust and transparency?
- Is prepaid usage clear (hours used, hours remaining, rate)?
- Would a non-technical person understand this at a glance?
- Does this make the MSP operator look professional and organized?

**Red Flags to Watch:**
- Technical terminology that confuses rather than informs
- Missing context (showing raw ticket data without summaries)
- Billing information that's ambiguous or hard to reconcile
- Client-facing pages that look unpolished or inconsistent with branding

---

## AI Integration

### AI Expert

**Focus Areas:**
- AI/LLM integration patterns and best practices
- Prompt engineering quality and robustness
- Token usage efficiency and cost management
- Tool use / function calling design
- AI output parsing, validation, and error handling
- Model selection and provider abstraction

**Typical Concerns:**
- Are prompts well-structured with clear instructions, constraints, and output formats?
- Is the tool use schema well-designed for the AI's capabilities?
- Are we handling AI failures gracefully (malformed JSON, hallucinations, refusals)?
- Is token usage reasonable, or are we sending excessive context?
- Are we over-relying on AI where deterministic logic would be more reliable?
- Is the agentic loop bounded and safe (max iterations, timeouts, cost caps)?

**Key Questions:**
- Are system prompts separated from user messages correctly?
- Do JSON-mode prompts include examples and strict format instructions?
- Is there fallback behavior when AI returns unexpected output?
- Are we truncating context intelligently (not cutting mid-sentence, preserving key info)?
- Is the tool loop capped to prevent runaway API costs?
- Are tool result sizes bounded before feeding back to the model?
- Could any stage be replaced with deterministic logic instead of AI?
- Are we using the right model tier for each task (cheap model for yes/no, capable model for analysis)?

**Project-Specific Checks:**
- AiClient should abstract provider differences (Anthropic vs OpenAI) cleanly
- Tool definitions should match Claude's tool_use schema exactly
- JSON parsing must handle markdown code fences (Claude often wraps JSON in ```json blocks)
- Prompts ported from HaloClaude Python should be reviewed for Laravel/PSA context accuracy
- Token usage should be logged for cost monitoring
- AI confirmation prompts (junk filter) should err on the side of caution (false negatives safer than false positives)
- The agentic tool loop needs clear exit conditions and max-rounds cap
- Sensitive data (API keys, client PII) should never appear in AI prompts unnecessarily

**Red Flags to Watch:**
- Prompts that are vague or lack explicit output format instructions
- Missing error handling for malformed AI responses
- Unbounded loops that could consume unlimited API tokens
- Sending full database records to AI when only a summary is needed
- Using AI for tasks that could be done deterministically (exact string matching, lookups)
- No logging of token usage or AI decision rationale
- Tool definitions that are ambiguous or have overlapping functionality

---

## Creative & Strategic

### The Wildcard

**Focus Areas:**
- Unconventional solutions and lateral thinking
- Connections between seemingly unrelated concepts
- Questioning fundamental assumptions
- Finding opportunities others miss
- Reframing problems entirely

**Typical Concerns:**
- Are we solving the obvious problem or the real problem?
- What if we did the complete opposite of convention?
- What adjacent tools or industries have solved this already?
- Are we being too safe or predictable?

**Key Questions:**
- What assumptions are we making that might be wrong?
- How would a SaaS company with 10,000 MSP customers solve this?
- What if we automated this entirely instead of building a UI for it?
- Could we eliminate this problem instead of managing it?
- Is there a way to turn a Halo limitation into a feature?
- What would make a tech say "I can't believe we didn't have this before"?

**Project-Specific Prompts:**
- "What if the dashboard updated itself based on what the tech is doing right now?"
- "What if clients could see their own data without us building a portal?"
- "What if we used Halo's webhooks to push data instead of polling?"
- "What's the version of this that takes 10 minutes to build but solves 80% of the problem?"

---

### The Critic

**Focus Areas:**
- Future consequences and second-order effects
- Edge cases and failure modes
- Hidden dependencies and assumptions
- Irreversible decisions and lock-in
- Technical debt and maintenance burden

**Typical Concerns:**
- What happens when this feature interacts with existing features unexpectedly?
- Are we making assumptions about Halo's API that could change?
- What's the worst-case scenario if the Halo API is down?
- Are we creating data in our DB that could get out of sync with Halo?

**Key Questions:**
- What are we assuming that might not be true?
- What happens when Halo's API changes or breaks?
- How does this fail gracefully?
- What's the migration path if we need to change this later?
- Are we painting ourselves into a corner?
- If this goes wrong, how bad is it and can we recover?
- What does the person maintaining this in 2 years need to know?

**Devil's Advocate Prompts:**
- "What if Halo changes this endpoint's behavior?"
- "What if we need to support a second PSA tool someday?"
- "Fast forward 6 months — what do we regret?"
- "What happens when the cached Halo data is stale?"

**Project-Specific Checks:**
- **Halo dependency**: Are we too tightly coupled to Halo's data model?
- **Data sync**: Could our local DB get out of sync with Halo as system of record?
- **Single point of failure**: What happens when the VPS, Halo, or Entra ID is down?
- **Schema changes**: Is this migration reversible?
- **Credential rotation**: What breaks when API keys or secrets expire?

**When The Critic Says "Proceed":**
- The plan acknowledges risks and has mitigation strategies
- Reversibility has been considered
- Edge cases have been thought through, not hand-waved
- There's a clear fallback if the Halo API misbehaves
- Dependencies are explicit

---

## Reviewer Collaboration

Personas can **call for additional reviewers** when they uncover concerns outside their expertise.

### When to Call for Additional Review

| If you notice... | Call for... |
|------------------|-------------|
| Security implications (auth, tokens, credentials) | Security & Compliance |
| Halo API integration concerns | Halo API Specialist |
| Schema changes that may be hard to reverse | The Critic |
| Workflow that doesn't match MSP operations | MSP Operations Manager |
| ITIL process alignment questions | ITIL Expert |
| UI/workflow affecting daily tech usage | Staff User |
| Client-visible features or reports | Client Persona |
| Unusual approach that might be brilliant or terrible | The Wildcard |
| New config, dependencies, or deploy steps | Documentation Manager |

### Common Collaboration Chains

**Technical deep-dive:**
Senior Developer → Halo API Specialist → Security & Compliance → The Critic

**Operational review:**
MSP Operations Manager → ITIL Expert → Staff User → Project Manager

**Client-facing feature:**
Staff User → Client Persona → MSP Operations Manager → The Critic

**Risk assessment:**
The Critic → Security & Compliance → Halo API Specialist

---

## How to Use These Personas

### During Planning (Plan Mode)

When reviewing a plan, ask each selected persona:
1. What would they notice first?
2. What concerns would they raise?
3. What questions would they ask?
4. What would they suggest changing?

### Red Flags to Watch For

**Technical Red Flags:**
- Direct Halo API calls from controllers (should go through HaloClient service)
- Missing error handling for Halo API failures
- Hardcoded Halo IDs or values that should be configurable
- No input validation or sanitization
- Secrets or credentials outside `.env`

**Operational Red Flags:**
- Features that add more clicks than the manual process
- Workflows that don't match how techs actually work
- Reports that show raw data without actionable insights
- Assumptions about billing that don't match real contracts

**Integration Red Flags:**
- Storing data locally that should live in Halo (system of record)
- Not handling Halo API pagination
- Ignoring OAuth2 token expiry and refresh
- Tight coupling to Halo's data model without abstraction

---

## Maintaining This Document

**When to Update:**
- Adding new persona types (e.g., "Vendor Integration Specialist" when adding CIPP/Mesh)
- Refining existing personas based on real usage
- Adding project-specific checks as patterns emerge
- Updating Halo gotchas as new API quirks are discovered

**How to Update:**
- Edit this file directly (single source of truth)
- The `/review-plan` command reads from this file dynamically
- No need to update the slash command when personas change

---

**Last Updated**: February 26, 2026
**Personas**: 13 total (3 technical, 1 AI, 2 domain, 2 PM/governance, 3 user, 2 creative)
