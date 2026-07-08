# Verify Completion Against Plan

Compare what was actually implemented against what was planned, and have review personas evaluate completeness.

**IMPORTANT:** This command should be run anytime you (Claude) are about to declare work "Done!", "Complete!", or similar completion language.

---

## Instructions

### Step 1: Locate the Plan

Find the active plan file at `~/.claude/plans/*.md`

If no plan file exists, check:
- Recent conversation context for planned work
- User's original request for stated requirements

If there is no plan and no clear planned work (e.g. this was invoked as a standalone check), skip to Step 4 and report only what you can verify.

### Step 2: Extract Planned Items

Parse the plan and create a checklist of ALL planned deliverables:

```
## Planned Deliverables

### Code Changes
- [ ] Item 1
- [ ] Item 2

### Database
- [ ] Migration 1

### Configuration
- [ ] Config change 1

### Documentation
- [ ] Doc update 1

### Follow-up Items (explicitly deferred)
- [ ] Deferred item 1
```

Include:
- Database changes (tables, columns, migrations)
- Route changes
- Controller/service/model changes
- View/blade template changes
- Configuration changes (services.php, .env)
- Halo API integration changes
- Documentation updates

### Step 3: Verify Each Item

For EACH planned item, verify completion:

1. **Check git diff/status** for file changes
2. **Check recent commits** for related work
3. **Read the actual files** to verify changes are correct
4. **Check deployed state** if deployment was part of the plan

Mark each item:
- ✅ **Done** — Fully implemented and verified
- ⚠️ **Partial** — Started but incomplete
- ❌ **Skipped** — Not implemented
- ➡️ **Deferred** — Explicitly moved to follow-up

### Step 4: Parallel Persona Review

Launch **The Critic** and **Project Manager** as parallel agents **simultaneously in a single message** using the Task tool.

For each agent:
- `subagent_type`: `"general-purpose"`
- `model`: `"opus"`
- Include the full verified checklist from Steps 2/3 directly in the prompt — do not make agents search for it

**The Critic agent prompt:**
```
You are The Critic reviewing completion of work on the Sound PSA project.

Context: Sound PSA is a modern, self-hosted PSA for MSPs built on Laravel 12 + MariaDB. Every module is standalone-first with its own schema and logic. Halo PSA sync is temporary migration tooling and will be deprecated. Features should stand alone and improve on what Halo offered.

Here is the verified completion checklist:
[paste full checklist with ✅/⚠️/❌/➡️ status from Step 3]

Examine without mercy:
- Are there hardcoded values that should be in .env or config?
- Are there unhandled edge cases (empty results, null values, API failures)?
- Is error handling complete — what happens when the Halo sync API is down or returns unexpected data?
- Are there TODO/FIXME/dd()/dump() calls left in code?
- Is the implementation actually complete or just "good enough for now"?
- Could any local DB data get out of sync with Halo during the transition period?
- Are there second-order effects on other features or scheduled commands?
- Are there migration risks — is the schema change reversible if needed?

For each concern, reference the specific file and line if possible.

**Gaps Found:** [list with file references]
**Verdict:** Complete / Incomplete / Needs Work
```

**Project Manager agent prompt:**
```
You are the Project Manager reviewing completion of work on the Sound PSA project.

Context: Sound PSA is a modern, self-hosted PSA for MSPs. It is a standalone product, not a Halo companion app. Halo PSA sync is temporary migration tooling. Each MSP deployment has its own small team of staff users.

Here is the verified completion checklist:
[paste full checklist with ✅/⚠️/❌/➡️ status from Step 3]

Examine:
- Does this fully meet the original stated requirements, or only partially?
- Is this a genuine improvement on the equivalent Halo workflow?
- Are all follow-up/deferred items explicitly noted for tracking?
- Has the user been informed of any limitations, caveats, or known gaps?
- Was deployment part of the plan? If so, has it been completed and verified?
- Did scope creep occur — were things added or changed beyond the original plan?
- Is this ready for real daily use by the team?

**Gaps Found:** [list specific gaps]
**Verdict:** Ready to Ship / Needs Attention / Block Release
```

After both agents return, synthesize their findings into the completion report.

### Step 5: Generate Completion Report

```
## Completion Report

### Plan Completion Summary
- **Planned Items:** X
- **Completed:** Y (Z%)
- **Partial:** A
- **Skipped:** B
- **Deferred:** C

### Completed Work
✅ [List all completed items]

### Incomplete Work
⚠️ [List partial items with what's missing]
❌ [List skipped items]

### Deferred to Follow-up
➡️ [Description of deferred work]

### Reviewer Verdicts
- **The Critic:** [Verdict]
- **Project Manager:** [Verdict]

### Recommendation
[One of:]
- ✅ **Ready to close** — All planned work complete
- ⚠️ **Close with caveats** — Minor gaps documented, follow-ups noted
- ❌ **Do not close** — Significant work remains
```

---

## What to Check

### Code Quality
- [ ] No TODO/FIXME comments left unaddressed
- [ ] No debug statements left (dd(), dump(), Log::debug for temp use)
- [ ] No hardcoded values that should be in .env or config
- [ ] Error handling implemented for Halo API calls
- [ ] Edge cases considered

### Integration
- [ ] HaloClient used via injection, not instantiated directly
- [ ] API responses validated (not assuming success)
- [ ] Token refresh handled (auto-retry on 401)
- [ ] Pagination handled if endpoint returns paginated data

### Deployment
- [ ] Changes committed and pushed
- [ ] VPS deployment completed (if applicable)
- [ ] Migrations run on production
- [ ] Config/route/view caches rebuilt
- [ ] Health check passing

### Security
- [ ] No credentials in code (all in .env)
- [ ] Input validation on user-facing forms
- [ ] CSRF protection on all forms
- [ ] SSO flow unchanged or properly updated

---

## When This Command Runs

This command should be invoked:
1. When Claude says "Done!", "Complete!", "Finished!", or similar
2. Before creating a completion commit
3. When user asks "is this done?"
4. When user runs `/verify-done`

If gaps are found, Claude should:
1. Offer to complete the missing items
2. Or note them as follow-up work
3. Update the completion message to reflect actual state
