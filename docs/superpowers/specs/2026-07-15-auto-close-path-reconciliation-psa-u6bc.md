# Auto-close: reconciling every path (psa-u6bc)

**Status:** PROPOSAL — design-first, for the manager → Charlie. **Nothing here is built.** No threshold is set, dormant or otherwise.
**Bead:** psa-u6bc (Charlie's so-whdf ask 2). **Gates:** psa-y4ft (the auto-close band P1, HELD pending this).
**Inputs:** `docs/superpowers/specs/2026-07-07-auto-close-state-dedup-gate-psa-y4ft.md`, vault plans 2026-07-05 / 2026-07-07. All predate Path C.
**Method:** every claim below was verified against the working tree with a `file:line` the author read. Claims that could not be verified are marked UNVERIFIED and are not stated as fact.

---

## 0. What Charlie actually has to decide

Three decisions, in dependency order. Everything else in this document is evidence for them.

| # | Decision | Recommendation | Reversible? |
|---|---|---|---|
| **D1** | Keep, envelope, or kill **Path C** (the hourly review auto-close)? | **Kill its auto-close; keep its recommend.** §4 | Yes — it is near-certainly inert today |
| **D2** | Ratify the **eligibility allow-list** `{Resolved, PendingClient}` — specifically, should `PendingThirdParty` stay excluded? | **Ratify as-is.** §5 | Yes — a one-line allow-list |
| **D3** | Should `agent_enabled = false` stop **every** AI close? Today it stops one path, misses one, and **wakes** a third. | **Yes — make "off" mean off.** §6 | Yes |

**One decision has already been made and is being re-asked by mistake.** The "eligibility floor on direct close" is **not** an open build question: it shipped 2026-07-07 in PR #179 (`31f368a`, an ancestor of `origin/main`, 22 tests green, carried in the 07-12 ship `966fdf0`). See §7. The live question is D2 — *ratify the floor's shape* — not whether to add one.

---

## 1. The headline

The AI surface states **one** safety invariant about auto-closing tickets. It is stated in `CloseAutoEligibility`'s docblock, and it is the right call:

> The agent's `confidence` scalar is model-produced and therefore **spoofable by a prompt-injection buried in the ticket text**. Before the gate may AUTO-close on confidence, this backstop must INDEPENDENTLY agree using only facts the model cannot fabricate. […] Fail-closed throughout […] **Confidence ALONE can never auto-close.**

There are **five** paths that auto-close a ticket. Two honour the invariant. One contradicts it — and that one is the path both living docs say does not exist.

---

## 2. THE MAP — every auto-close path (derived from the source, not inherited)

> The brief that reached this desk said "three paths (A/B/C)". **There are five.** The map below was derived by grepping every `changeStatus` caller in `app/`, not by trusting any list — including the brief's, and including this document's. That the handed-down enumeration was itself incomplete is not a footnote; it is the disease this bead exists to treat (§9).

| | **A — propose_close band** | **B — direct set_ticket_status** | **C — ConversationReviewer** | **D — Junk filter** | **E — close-resolved cron** |
|---|---|---|---|---|---|
| **Trigger** | agent loop | Chet's MCP token | hourly review pass | on ticket create | daily 06:00 |
| **Site** | `ProposeCloseTool.php:207` | `StaffPsaActionToolExecutor.php:271` | `ConversationReviewer.php:134` | `TriagePipeline.php:397` | `CloseResolvedTickets.php:56` |
| **Decides how?** | AI confidence **+ backstop** | AI judgment **+ backstop** | **AI confidence ALONE** | deterministic pattern (high) / AI confirm (medium) | deterministic (age) |
| **Closes which statuses** | `{Resolved, PendingClient}` | `{Resolved, PendingClient}` | **`{New, InProgress, PendingClient, PendingThirdParty}`** | `New` (junk) | `{Resolved}` |
| **`CloseAutoEligibility` backstop** | ✅ `TechnicianTierClassifier.php:42` | ✅ `StaffPsaActionToolExecutor.php:247` | ❌ **none** | ❌ none | n/a (deterministic) |
| **Held / human approval** | ✅ (band dormant → held today) | ❌ by design | ❌ | ❌ | ❌ |
| **One-click undo** | ✅ | ✅ (#273) | ❌ | ❌ | ❌ |
| **`technician_kill_switch` stops it** | ✅ | ✅ | ❌ **no** | ❌ **no** | ❌ no |
| **Default state in code** ⚠️ | dormant — `proposeCloseAutoThreshold()` defaults `null` = never auto | no dormancy flag: **live wherever the MCP token grants `set_ticket_status`** | latent — needs the box ticked **and** the agent off (§3) | **ON by default** — `stageEnabled()` returns true when unset | off by default — `auto_close_resolved_days = 0` |
| **Honestly documented?** | yes | partly | **DENIED by both docs** | yes (CLAUDE.md) | not in either doc |

> ⚠️ **That row is what the *code* does, not what *prod* is doing.** The working tree proves code paths, defaults and guards; it cannot prove prod's settings, token grants, or deployed SHA, and this document does not probe prod. Every prod-state reading below is therefore **UNVERIFIED from dev** and needs Charlie or gus to confirm: that Chet's prod token grants `set_ticket_status` (the basis for calling B "live"), that `agent_enabled` is on, that `triage_review_auto_close` was never ticked, and that prod runs `966fdf0`+. The narrative reasons from B being live and the agent being on because Chet is observably proposing in prod — that is **inference, not evidence**, and it is the one place this document leans on something it cannot see.

**Honest severity, so this map is not read as five alarms:**
- **A and B are fine.** They are the designed envelope and they work. B carries no dormancy flag, so it acts wherever its token is granted — guarded, and (per the ⚠️ above) *understood* to be granted in prod on inference rather than evidence.
- **E is fine.** Deterministic, age-based, off by default, and it is the legitimate owner of the `{Resolved}` grace-period case that `CloseAutoEligibility`'s docblock names. *(One minor defect: `CloseResolvedTickets.php:43` uses `User::first()?->id` as the actor rather than the configured system user, so an automated close is attributed to whichever human is first in the table. Small, real, unrelated to the decisions above.)*
- **D is defensible and is not an argument to change it.** Its high-confidence branch is **deterministic** pattern matching (`JunkDetector::classify`), not a spoofable model scalar; it has real guards (`shouldSkip:187`, `MONITORING_ALLOWLIST:17`, `SECURITY_KEYWORDS:169`); its medium branch requires AI confirmation that defaults to the safe side; it is documented. It is on this map for exactly one reason: **the emergency brake cannot stop it** (psa-0d0t), and it is ON by default (`TriageConfig::stageEnabled():38-42` returns true when unset).
- **C is the problem.** The rest of this document is mostly about C.

---

## 3. Path C, precisely

`ConversationReviewer.php:99` — C stands down when the setting is off **or the agent is on**:

```php
if (! TriageConfig::reviewAutoCloseEnabled() || \App\Support\AgentConfig::enabled()) {
    return null;
}
```

Then `:117` gates the close on `$result->meetsThreshold($threshold)` — the model-produced scalar, alone — and `:134` closes.

**It has guards.** "Un-gated" (its own comment, `:96-98`) undersells them: a priority cooldown (`:41`), a 4-hour human-touch skip (`:48`), an assessment allow-list of `resolved|junk` (`:106`), and a race-refresh (`:112`). It is not reckless. It is *differently* designed — to a model that has since been superseded everywhere else.

**Three sharp edges, all verified:**

1. **The default threshold sits below the inferred score.** `ReviewResult::fromArray` falls back to `inferScore()` when the model omits a numeric score; `inferScore('high') = 85` (`ReviewResult.php:53`). `TriageConfig::reviewAutoCloseThreshold()` defaults to **80** (`TriageConfig.php:129-134`). So a model returning merely `{"confidence":"high"}` with **no numeric score** clears the default bar and closes. The knob is finer-grained than the signal it gates.

2. **It reads the one field the other path deliberately avoids.** C's human-touch check reads **`noted_at`** over **4 hours** (`ConversationReviewer.php:196`). `CloseAutoEligibility` reads **`created_at`** over **14 days** and its docblock says why in as many words: *"Reads created_at (the row-write time), **not the user-settable noted_at**, so the signal cannot be backdated out of the window"* — and counts soft-deleted notes (`withTrashed`) because *"a recently-deleted client reply is still evidence the client engaged."* Same repo, same hazard, one path hardened against backdating and deletion, the other not, on an 84× shorter window.

3. **It is the last surviving instance of the design Charlie already killed.** Charlie, 07-03: *"a static threshold is the wrong shape for a reasoning agent; ditch it."* The 07-07 spec rebuilt A and B around confidence-agnostic state rules on that steer. C still runs on `triage_review_auto_close_threshold`. **Nobody told C — because nobody knew it was an auto-close path.** That is the same root cause as the docs denying it exists (psa-3xbv).

**Is it live?** LATENT, and the honest answer has a hole in it only Charlie can fill. Firing needs `triage_review_auto_close` ticked (default **false**) **and** the agent off. Chet is actively proposing in prod ⇒ `agent_enabled` is almost certainly **on** ⇒ C is inert today. **UNVERIFIED from dev:** whether that box was ever ticked in prod. That single check decides whether any of this is live or purely structural.

---

## 4. D1 — the finding that decides it: A and C are not strict-vs-loose. They are **nearly inverted**.

This is the crux, and it reframes "reconcile the paths" from a tuning exercise into a choice between two theories.

- `CloseAutoEligibility::AUTO_SAFE_STATUSES` = **`{Resolved, PendingClient}`**
- Path C's candidate set = `Ticket::open()` (`Ticket.php:176-181`) = **`{New, InProgress, PendingClient, PendingThirdParty}`**, minus a refresh-guard skipping `{Closed, Resolved}` (`ConversationReviewer.php:112-115`).
- **Intersection = `{PendingClient}`. That is all.**

| Status | A + B | C |
|---|---|---|
| `New` | ❌ refuse — *"awaiting US"* → *"human eyes, even at 1.0"* | ✅ **can auto-close** |
| `InProgress` | ❌ refuse — same | ✅ **can auto-close** |
| `PendingThirdParty` | ❌ refuse — *"vendor-blocked, NOT abandoned… AUTO is removed. Ratify/widen before enabling auto here"* | ✅ **can auto-close** |
| `PendingClient` | ✅ allow — *"the classic stale ghost"* | ✅ allow |
| `Resolved` | ✅ allow — **the safest case**, *"work done; the grace-period auto-close"* | ❌ never (not in `open()`) |

**C auto-closes precisely the three statuses A and B refuse at confidence 1.0, and never touches the one A and B consider safest.** Read `CloseAutoEligibility`'s exclusion comments as prose and they are an unwitting indictment of C's exact behaviour.

### The options, and why the middle one collapses

**Option 1 — KILL C's auto-close, keep its recommend. ✅ RECOMMENDED**
- **It is redundant, not unique.** Path A *is* the designed, audited, envelope-covered implementation of "the AI judges a ticket can close." C is a second, weaker implementation of the same idea.
- **It makes the docs true as written.** CLAUDE.md:203 and INSTALL.md:783 already say "recommend-only, no auto-close." Killing the auto-close dissolves psa-3xbv with **no doc-vs-code judgment call** — the doc stops being a lie by the code changing to match it, which is the only direction that is safe when the doc describes the *safer* behaviour.
- **It removes the invariant violation** (§1), **the worst half of the inversion** (§6, psa-s72e), and **the last threshold Charlie already killed** (§3.3).
- **Near-zero risk:** the fallback is exactly today's behaviour, because C is near-certainly inert.
- **Cost:** if an operator genuinely relies on "close stale tickets while the agent is off", that need goes unmet. It should be met by Path E (deterministic) or by leaving the agent on — **not** by an un-backstopped AI closer. Flagging honestly rather than burying it.

**Option 2 — Bring C under the envelope (add backstop + kill switch + undo). ⚠️ collapses into a worse Option 1**
- Apply `CloseAutoEligibility` to C and its close set becomes `{New, InProgress, PendingClient, PendingThirdParty} ∩ {Resolved, PendingClient}` = **`{PendingClient}` only**. C would stop doing ~everything it currently does.
- And "auto-close stale `PendingClient` tickets on AI judgment + backstop" **is Path A**, which does it better (held phase, undo, dedup, kill switch).
- So Option 2 buys a **duplicate of A**, at the cost of a second code path, while leaving a live checkbox that implies far more than it does. It looks like the moderate option and is actually the expensive one.

**Option 3 — Keep C as-is.** Only coherent if Charlie affirmatively wants a threshold-based, un-backstopped closer for when the agent is off. Given the 07-03 steer, presented for completeness, not recommended.

---

## 5. D2 — ratify the eligibility allow-list

Not "add a floor" (§7) — **ratify the floor's shape.** `CloseAutoEligibility` deliberately excludes `PendingThirdParty` and its own docblock asks for exactly this ruling: *"vendor-blocked ≠ abandoned; **ratify/widen before auto-enable**."*

- **Recommendation: ratify `{Resolved, PendingClient}` as-is.** A vendor-blocked ticket is waiting on a third party, not abandoned by the client; auto-closing it loses the thread while the work is still real. The agent can still *propose* closing it for a human.
- Note `->Resolved` is deliberately **not** gated (07-07 spec: *"the safety target is autonomous closing, not resolving"*). If anyone means "gate resolve too," that is **new scope**, not this decision.

---

## 6. D3 — "off" does not mean off

Verified across all four readers of `AgentConfig::enabled()`. Setting `agent_enabled = false` — the intuitive *"stop the AI closing tickets"* action:

| Path | Effect | Expected? |
|---|---|---|
| **A** | **stops** (`RunTechnicianAgent.php:58`) | ✅ yes |
| **B** | **unaffected** — zero `AgentConfig` gates in `StaffPsaActionToolExecutor`; it is the MCP lane (token + kill switch). Chet keeps closing. | ❌ no |
| **C** | **wakes up** — `:99` stands down only *while the agent is on*; turning it off **releases** the stand-down | ❌ **dangerously no** |

The operator's most intuitive safety action fails to stop the live path **and** activates the unguarded one. Neither half is visible: `agent_enabled` has **no UI anywhere** (DB/tinker only); the "Auto-close resolved/junk" checkbox *is* visible (`integrations.blade.php:3053`) with no hint that it is inert now and live the moment the agent goes off.

Tracked as **psa-s72e**. The **Path-B half survives even if Charlie kills C** — it is about what `agent_enabled` *means*, not about C.

**Recommendation:** make "off" mean off. Whatever D1 lands on, `agent_enabled = false` should stop every AI-initiated close, and the control should say what it does and does not stop.

---

## 7. The already-answered question (§0, in full)

The fork raised on psa-y4ft at **07-07 17:04** — *"direct close bypasses the eligibility backstop + one-click undo"* — is **fully answered**, and has been for eight days:

| | |
|---|---|
| **07-07 17:04** | fork flagged on psa-y4ft |
| **07-07 19:11** | **#179 merges the eligibility backstop** (`31f368a`) — **2h07m later** |
| **07-08 02:13** | the Mayor reviews and approves #179 *on that bead*, writing *"Eligibility gate correctly scoped to `->Closed`"* |
| **07-12 11:54** | `966fdf0` ships, containing it |
| **07-13** | psa-y4ft.1 ships the **undo** half (#273, `df8a20b9`) |

Evidence the floor is real, live code:
- `StaffPsaActionToolExecutor.php:247` gates a direct `->Closed` on `CloseAutoEligibility::eligible()` — **unconditional**, no flag, no dormancy.
- `CloseAutoEligibility::AUTO_SAFE_STATUSES == [Resolved, PendingClient]` — *exactly* the "Resolved/PendingClient restriction" described as outstanding.
- `git log -S` on that gate line returns **one** commit: `31f368a`. `git merge-base --is-ancestor 31f368a origin/main` → yes.
- `PsaActionToolsTest`: **22 passed / 146 assertions**, incl. *"direct close of an awaiting us ticket is blocked by eligibility"*, *"direct close of a ticket with a recent client reply is blocked"*.

**The only open sliver is deployment, not construction.** If prod runs `966fdf0`+ the floor is live there; if prod is behind, the remedy is a **deploy**, not a build. UNVERIFIED from dev, and deliberately not probed.

**Why it propagated:** the 17:04 note was never retracted when #179 answered it two hours later. It was then repeated by this author (07-12 triage), half-corrected (07-15 08:02, undo only), and carried into a decisions brief. A **partial correction is worse than none**: it launders the remainder — the surviving half now looks freshly verified. The lesson is recorded in §9.

---

## 8. THE GAPS — the wider AI settings surface

The auto-close paths are the acute case of a general condition: **the AI surface's safety controls are real in code and invisible in the product.** Each verified, each with its own bead.

| Finding | Evidence | Bead |
|---|---|---|
| **The emergency stop has no UI.** `technician_kill_switch`: ~17 production readers, **zero** writers outside tests. Throwing the brake means hand-editing prod MariaDB. | `TechnicianActionGate.php:76,117` + every MCP write executor | psa-2wwh |
| **…and it does not cover the Triage lane** — misses Paths C **and D**; D is ON by default. So the brake is both unreachable **and** incomplete. | zero `kill_switch` hits in `app/Services/Triage/` | **psa-0d0t** |
| **"AI enabled" does not mean AI is enabled.** `ai_enabled` has two functional readers; **25 files** gate on `AiConfig::isConfigured()` ("is a key present") instead. Turning the AI card's toggle off does not stop triage, the Assistant, wiki mining, or nightly AI narratives. The only true global kill is clearing the API key. | `TechnicianAgent.php:60`, `DraftPipeline.php:39`; label at `integrations.blade.php:2884` | psa-s7u8 |
| **Nothing is admin-gated.** `User::isAdmin()` exists with **zero call sites**; every settings route is plain `auth`. Any authenticated staff user — Tech, Billing, Contractor — can flip every AI toggle. | `User.php:90`; `routes/web.php:122` | psa-qbr6 |
| **The Assistant is the only cluster that defaults ON — and it writes.** Pasting an Anthropic key silently activates a staff assistant with unheld `create_ticket` + `add_ticket_note`. Every other cluster ships dormant. | `AssistantConfig.php:9-19`; `AssistantToolDefinitions.php:225,248` | psa-98dq |
| **The agent lane's autonomy controls have no UI.** No `agent_*` or `propose_close_*` key is written by any controller or rendered by any Blade — including `agent_enabled` itself (zero hits in `app/Http`, `resources/views`) and `propose_close_auto_threshold`, the band psa-y4ft is about. **Exception, stated so the rest is credible:** the intake sub-feature *is* built — `intake_call_enabled` / `intake_email_enabled` are written at `IntegrationsController.php:1788-1789` and rendered at `integrations.blade.php:3485,3493`. The gap is the autonomy keys, not the whole lane. | grep over `app/Http`, `resources/views` | psa-s72e (partial) |

**Code/doc rot found along the way** (each verified; the point is the *class*, not the instances):
- `SendReplyTool.php:42-45` asserts the tool "is NOT yet offered to the live agent loop." `TechnicianAgent.php:85-92` offers it. A2b landed; the A2a note was never retracted — **the same failure as §7**, in a docblock instead of a bead.
- `triage_stage_contact_allcaps`: its comment says "off by default"; `stageEnabled()` returns **true** when unset. On by default, opposite of its comment, zero writers.
- The **entire Client Wiki AI subsystem** (`wiki:maintain` daily 03:00 + 9 settings) has **zero** hits in CLAUDE.md and INSTALL.md — a direct breach of the repo's own living-documentation rule.
- `technician:digest` is scheduled, defaults **true**, appears in no doc.
- **Cost trap:** `agent_model` falls back to **Opus** (`AgentConfig.php:103`) and `agent_significance_model` to Haiku, both ignoring the operator's `ai_model` — while INSTALL.md:719 tells operators the default is sonnet/gpt-4o.
- Dead settings that look live: `technician_operator_covering` (docblock calls it "Authoritative"; zero callers) and `technician_max_tokens_per_run` (never enforced; its sibling `technician_daily_token_limit` **is** live).

**A verified policy asymmetry — Charlie's call, flagged not pre-decided:** the cockpit staged reply is AI-drafted → human-approved → sends with **dual credit** ("Drafted by *AI*… Reviewed and sent by *Tech*", psa-u51h). The ticket-UI **Draft** button is AI-drafted → human edits → sends with **no disclosure at all**, under the tech's name. Same situation, opposite answers. Which is the intended policy?

*(Killed during verification, recorded so it is not re-raised: "ReplyDraftService / TeamsReplyService / PortalChatbotService ship unbannered client-facing AI text" — **false, all three**. TeamsReplyService is internal staff chat; PortalChatbot is a chat the client knowingly opened; ReplyDraftService has no send path. Safety-shaped and wrong; relaying it would have been a false alarm.)*

---

## 9. PROPOSED IA — organised by what an operator is trying to do

Charlie's acceptance criterion is *"so we know what does what."* Today the AI controls fail it structurally: the safety-critical ones sit three clicks deep inside a tab labelled **Integrations** (which reads as vendor plumbing), split across two pages, and **the most safety-critical ones have no UI at all**.

Proposed: a first-class **AI** settings section, ordered by operator intent, not by which bead shipped it.

1. **Stop / Panic** — the kill switch, first and unmissable, with an explicit statement of what it does and does not stop (§ psa-0d0t). *Nothing else matters if this is not reachable.*
2. **What the AI may do on its own** — every autonomous action in one list, each with its guard, its blast radius, and its undo. **The five auto-close paths must appear here as one coherent story or not at all.**
3. **What the AI costs** — models actually used (not the ones the doc claims), token ceilings, which are enforced.
4. **What the client sees** — disclosure policy, dual credit, the portal chatbot.
5. **Per-integration plumbing** — where it lives today; the right home for genuine vendor config *only*.

Two rules the IA must encode, both learned the hard way here:
- **A setting that exists but does nothing yet must say so** — shipped-dormant is precisely the confusing kind.
- **Interacting settings must be visible to each other.** `agent_enabled` silently inverting the auto-close checkbox (§6) is the whole disease in one pair.

---

## 10. PROPOSED DOC STRUCTURE

CLAUDE.md's living-documentation rule already requires `docs/INSTALL.md` to track settings, integrations and scheduled commands. **It has drifted, and the drift is itself a finding** (§8).

1. **Fix the safety-shaped lies first** — the auto-close doc claims (psa-3xbv) are the priority. Note the ordering: **D1 decides the text.** If C's auto-close is killed, the docs become true *without being edited* — the cheapest possible fix, and a reason to decide D1 before touching prose.
2. **One AI page per audience.** CLAUDE.md = architecture for the next agent. INSTALL.md = what an operator flips and what breaks. Today they duplicate and contradict each other.
3. **Every setting: key, default, real reader, what it gates, dormant?** The Wiki AI subsystem's total absence and the `agent_model` → Opus cost trap are both this table's job.
4. **Cite the source next to any behavioural claim** — the rule the vendor section already imposes, applied inward.

> **⚠️ The rule this document is bound by — and did not escape.** A doc that overstates the code is worse than no doc. That is not abstract: this pass found CLAUDE.md asserting a safety property the code does not have (§8), a docblock asserting the opposite of its own class's behaviour (§8), and a retracted-by-reality bead note that propagated into a decisions brief and nearly reached the owner as fact (§7).
>
> **Then the review caught this document doing the same thing.** §8 originally claimed *no* `agent_*`/`propose_close_*`/`intake_*` key had any UI. False: the intake card is built and wired (`IntegrationsController.php:1788-1789`, `integrations.blade.php:3485,3493`). The claim was inherited from an earlier survey of this author's own and re-published without re-verifying the `intake_*` third of it — the identical mechanism as §7, three sections after describing it. It is recorded here rather than quietly fixed, because a proposal arguing "verify every claim" that had itself been verified only by its author would be worth very little. It is also the **second** docs-only review in this repo to find false claims inside anti-false-claims content (psa-sslk, `a2a7b44`, was the first) — which is the strongest available argument that the gate is doing real work and that no author, including this one, should be trusted to be their own reviewer.
>
> **A doc, a docblock and a bead comment are all snapshots. The code is the record.** Every claim above carries a `file:line` for exactly this reason — so the next reader re-verifies instead of inheriting.

---

## 11. What happens next

1. Manager relays D1/D2/D3 to Charlie, and **corrects the eligibility-floor premise** (§7) before he rules.
2. **Charlie's one prod check** (nothing else can substitute): is `agent_enabled` on, and was the "Auto-close resolved/junk" box ever ticked? That decides whether §3/§6 are live or structural.
3. On his ruling: psa-3xbv (docs + C's fate), psa-s72e (the inversion), psa-0d0t (brake coverage), then **psa-y4ft resumes to the settled design**.
4. The band **ships dormant regardless**. The fallback while unbuilt is exactly today's behaviour — Chet proposes, a human approves. Holding costs little; building ahead of the design is the bolting-on Charlie asked us to stop.
