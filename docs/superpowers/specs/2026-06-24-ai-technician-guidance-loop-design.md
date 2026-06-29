# AI Technician — Guidance Loop (v1) Design Spec

> **Status:** Design approved via brainstorm + a 5-lens panel review (2026-06-24). **Build sequenced AFTER Phase 2** (the deterministic emergency backstop). This spec captures the converged design; the implementation plan (`writing-plans`) is written when the guidance loop's build turn comes.
>
> **Type:** `note` · **tags:** [soundit-dev, decision, ai-technician, guidance-loop] · **created:** 2026-06-24

## 1. Goal

Let the AI Technician **determine when it's in over its head, ask the operator for a specific steer, and proceed on that steer** — so the away operator (Charlie, Scotland trip Aug 1–15) supplies **judgment, not labor**. This is a third path between the two the system already has: 1B *approval* (operator approves a finished draft) and the 1B *"Needs you"* lane (the AI gives up and a human takes over). Here the AI keeps the work and asks one good question to unblock itself.

## 2. Scope

**In v1:**
- Self-assessed uncertainty → the AI opens a **guidance ask** (a single crisp question + its proposed action) instead of dropping to "Needs you."
- **Multi-turn conversation** between operator and AI to arrive at the steer, stored on the ticket.
- **Single-step execution:** the resolved steer drives exactly one directed action (re-draft → held for approval; or a directed action), through the existing `TechnicianActionGate` + disclosure + audit.
- **Teams-first** bidirectional channel (the operator can steer without logging into the PSA), with cockpit as fallback + system-of-record and email/SMS as nudges.

**Deferred (designed-in seams, NOT v1):**
- **SOP layer:** consult stored SOPs (reuse the wiki) *before* interrupting a human, and save a steer as a new SOP (the learning loop). v1 leaves the "consult guidance" seam.
- **Conditional / multi-step execution** (e.g. "email the user to confirm, and if they confirm, release it" — a persisted conditional carried across the client's async reply).
- **Richer AI ticket-journaling** beyond the guidance ask.

## 3. Decisions (Charlie-approved; panel revisions folded in)

1. **Autonomy = directable single-step instruction.** The steer is an instruction the AI executes exactly. It IS the human authorization for that specific action → human-DIRECTED, not auto-execute → consistent with the trip's hold-all-sends / defer-auto-execute rules.
2. **Multi-turn conversation, single-step execution.** Converse to arrive at the steer; execute one step.
3. **Ticket = source of truth; reuse the existing "AI Conversation" timeline entry.** The exchange is stored as an **`AssistantConversation` + `AssistantMessage`** record — the multi-turn transcript already rendered in the ticket timeline as **"AI Conversation"** (`resources/views/tickets/_timeline-ai-chat.blade.php`; merged at `tickets/show.blade.php:93`; linked by `context_type='ticket'`). This is Charlie's original instinct — **verified correct.** It is NOT a `NoteType` enum case (the panel checked only `NoteType` and missed the polymorphic timeline entry) but it IS a first-class AI-conversation type that preserves the transcript natively AND that `ContextBuilder::buildConversationContext` already ingests. Small extensions the plan must add: a **`kind`/`source` discriminator** (guidance vs the existing staff-initiated assistant chat — for the trigger, the cockpit lane, and rendering); **AI-initiated attribution** (`AssistantConversation.user_id` is non-nullable today and assumes an operator started it → attribute the guidance conversation to the AI-actor user or make it nullable); and confirm it stays **internal / non-portal**. (The separate safety concern — feeding the *operative steer* as a trusted directive rather than as fenced conversation — is §6, independent of storage.)
4. **Channel: Teams-first round-trip (Charlie's explicit choice), cockpit as fallback + record.** Teams is the primary bidirectional channel (steer without a PSA login); the cockpit always exists as record + fallback; SMS is a nudge only; email is a fallback notify.
5. **Run state `awaiting_guidance`** = the pipeline's pause button; resumes when a steer arrives. As inert as `awaiting_approval` (no sleeping send job; resume re-runs the full gate + disclosure + scan).
6. **Trigger = self-assessed, tunable, conservative default.** The drafter/classifier emits `needs_guidance` + a formulated question when *a steer would unblock it* (ambiguous ask / missing business decision / conflicting signals / unsure whether to send). A distinct step run after a failed/low-confidence draft — NOT bolted onto the ownable/confidence classifier. Deterministic-floored like 1A (the model can *raise* "I'm stuck"; injected text can't *force* an ask). Config: threshold + per-category on/off; **conservative default**.
7. **Safety — dual-natured steer.** AUTHENTICATE the source so the instruction may authorize the action; **FENCE the content** so it can't jailbreak past disclosure/safety. The AI acts only WITHIN the steer; resulting client sends stay disclosed + gated + audited. (Detailed in §6.)
8. **Degradation — never silently stuck.** No steer within a tunable window → one re-nudge → the run ages into the 1B "Needs you" lane (+ Phase 2 escalation if flagged an emergency). Windows are LONG for a traveling operator (defaults: re-nudge ~6h, age-to-needs-you ~18h; configurable) and longer than the Phase 2 emergency SLA.
9. **Single-question + proposed-action UX** (panel #1 UX fix). The ask is a structured one-message form — "I plan to tell Acme X — send it, or prefer Y?" — answerable in one word/tap, NOT an open chat invite. Multi-turn is the exception for genuinely open-ended cases, not the model (a long conversation is slower than the cockpit).
10. **Dormant + enable-flagged.** Gated behind `TechnicianConfig::enabled()` + a guidance-loop enable flag.

## 4. Architecture

### 4.1 The loop
1. **Detect.** After a failed/low-confidence draft, the guidance step self-assesses "a steer would unblock me." If yes (and the category is enabled), it formulates a single crisp question + a proposed action. Output-scanned (`WikiRedactor::scan`) like the drafter.
2. **Ask.** Open an `AssistantConversation` (kind = guidance) on the ticket — the "AI Conversation" timeline entry that is the record — with the AI's question as the first `AssistantMessage`. Move the `technician_run` `drafting → awaiting_guidance` and stamp `asked_at` (a run column or `proposed_meta['asked_at']`). Notify the operator per the **escalation chain** (Justin → Charlie, reusing Phase 2's chain config — NOT fire-to-all), Teams-first (+ a one-tap signed deep-link into the cockpit guidance lane), SMS nudge + email fallback.
3. **Converse (optional, multi-turn).** Operator replies over Teams (or in the ticket's AI Conversation in the cockpit). Each turn is an `AssistantMessage` on the guidance conversation (the transcript = the record). The AI may ask a follow-up if genuinely needed; default is one question.
4. **Resume.** A new operator `AssistantMessage` on the guidance conversation newer than `asked_at` re-enters the pipeline via the existing `RunTechnicianLoop` (add a hook where the operator turn is recorded — the inbound Teams bridge / cockpit reply / `AssistantService` — none dispatch the loop today; mirror the 1B client-reply re-key). `DraftPipeline` learns a new branch: a run in `awaiting_guidance` with a post-`asked_at` steer → supersede + re-draft (or execute the directed action).
5. **Execute (single-step).** Re-draft → `awaiting_approval` (held; standard cockpit approval). Directed send/release → identity-bound signed grant → `TechnicianActionGate` → disclosed + audited. **Idempotency:** guidance runs use a deliberate stable key (NOT the content-hash, which has no settled content during a guidance turn).

### 4.2 The Teams bidirectional bridge (the critical-path build dependency)
- Today: Teams is **outbound-only** (`TeamsNotifier` posts a MessageCard to a webhook). There is **no** inbound Bot Framework receiver, and the one bot→PSA path (`/api/mcp/staff`) uses a **shared service-account token with no per-person identity** (`McpStaffController` psa-axy note). App-only Graph chat-posting is a MS Protected API.
- v1 builds: a **PSA-side inbound Bot Framework activity receiver** that (a) verifies the activity (signed, fail-closed — follow the repo's HMAC reference middleware `VerifyTacticalWebhookKey`/`VerifyLevelWebhookSignature`, NOT the fail-open Plivo/Graph patterns), (b) **maps the sender's Entra/AAD Object-ID → a PSA user** (the per-person identity that makes a Teams steer trustworthy to authorize), (c) routes the activity to the open `awaiting_guidance` run, (d) writes the steer as an `AiGuidance` note attributed to that user, (e) for a directed *send*, mints the identity-bound signed grant.
- This is the SAME foundation as the deferred **July-20 in-Teams one-tap approval spike** — pulled forward into the guidance loop. It carries a **reliability go/no-go gate**: if the bridge isn't boringly reliable (delivery, identity, soak), v1 **falls back to cockpit-only** (record + steer in the authenticated cockpit; Teams degrades to a one-way nudge). The cockpit path uses ONLY already-merged machinery and is the safety net.
- Move outbound Teams to **Adaptive Cards** (the legacy `MessageCard` can't do interactive tap-to-answer). Compose the bridge as a sibling of `OperatorNotifier` (outbound stays `OperatorNotifier`; inbound is a new `TeamsBridgeController`), not by overloading the one-way notifier.

### 4.3 Channels (summary)
| Channel | Role in v1 | Can authorize a client send? |
|---|---|---|
| **Teams** (bidirectional, Entra-identity-bound) | Primary steer channel | **Yes** — once per-person Entra identity → identity-bound grant is built |
| **Cockpit** (authenticated session) | Record + fallback + the reliability-gate safety net | **Yes** — the existing signed cockpit grant |
| **SMS** (Plivo, greenfield) | Nudge only ("you're needed on #123") | **No** — spoofable; never authorizes a send |
| **Email** (existing) | Fallback notify / record | No |

## 5. The trigger (detail)
A new, config-gated, conservative-default step distinct from `TechnicianClassifier` (which only emits ownable/confidence). Categories that justify an ask (name them, don't leave as a raw threshold): (a) **ambiguous resolution path** where a yes/no business decision unlocks a standard draft; (b) **missing business/contract/billing decision**; (c) **conflicting instructions/signals**. The model proposes "I'm stuck + here's my question + here's my proposed action"; a deterministic floor prevents injected text from forcing an ask. Per-category on/off + a global enable, conservative default so it doesn't over-ask.

## 6. Safety model (the crux — panel-driven)
- **Dual-nature steer.** *Authenticate the source* (Teams Entra Object-ID → PSA user; or cockpit session) → the instruction may authorize the action. *Fence the content* — feed the steer to the re-draft as an explicit **`OPERATOR DIRECTIVE` segment OUTSIDE the untrusted client fence** (resolves the fence-vs-authorize contradiction: the steer recorded in the AI Conversation, when re-ingested by `ContextBuilder`, would otherwise land inside the client fence the model is told to ignore). The directive is length-capped and explicitly **subordinated to disclosure/safety**: it may shape content/tone/approach but may NOT remove disclosure, change the recipient, or raise autonomy.
- **PromptFence hardening:** add **NFKC + zero-width strip** before the existing ASCII regexes (the current fence is ASCII-only → homoglyph/zero-width bypass). Benefits every Technician prompt.
- **Sends release ONLY via an identity-bound, content-hash-bound, single-use signed grant** — sourced from the cockpit session OR a verified-Entra Teams approval. **Never** from a bare SMS keyword (spoofable, not content-bound, replayable). A re-draft steer always lands in `awaiting_approval` for a fresh approval of the *new* content (the operator hasn't seen it yet).
- **Single-use enforcement:** add a real single-use store (a `consumed_at` + unique constraint, atomic UPDATE) for out-of-band grants, OR require the run-state CAS latch on every release path and prove it by test. Don't extend the stateless HMAC token onto an async channel without one of these.
- **Durable attribution:** persist `approver_user_id` + the steer-note id on the executed `technician_action_logs` row (today the approver lives only in the 600s-TTL grant → forensic gap for a loop whose whole story is "who steered/approved what").
- **Inert pause state:** a run in `awaiting_guidance` holds no sleeping send job; resume routes back through `TechnicianActionGate::dispatch` from scratch (kill-switch re-checked, fresh grant required). Assert by test.
- **No side-door:** the steered send must route through the gate (assert "the Loop/guidance path holds no direct `EmailService` reference", extending the existing gate test). Use the hardened `TechnicianReplyDrafter` (fenced + scanned), never the legacy unfenced `ReplyDraftService`.
- **Enumerated steer verbs:** v1 maps a steer to a small server-enumerated verb set (`redraft`, `gather_more`, `answer_question`, `hold`); "send/release" is NOT a loop-executable verb — it returns to grant-gated approval. Anything the model proposes outside the enumerated verb → `awaiting_approval` / "Needs you", never executed. Makes "act within the steer" a server invariant, not a model promise.

## 7. UX
- **Single-question + proposed-action** (§3.9). One Teams message / one cockpit card; answerable in a word or tap.
- **Dedicated "Awaiting your guidance" lane** in the cockpit (a third lane between "Awaiting approval" and "Needs you") + a **third digest section** ("Awaiting your guidance — N tickets; client, subject, the question, age"), else guidance asks age out invisibly. Surface guidance-ask **counts by category** in the digest during soak (calibration signal) + per-category suppress links.
- **Routing per the escalation chain** (Justin first, then Charlie on no-response) — not fire-to-all.
- **Degradation windows long** (defaults re-nudge ~6h, age-to-needs-you ~18h; configurable), longer than the Phase 2 emergency SLA. The aged-out path is legible to the operator ("moved to Needs you").
- **Dropped-connection safety:** if a steer arrives after the run aged out, record the conversation turn anyway (it's the record) and **revive** the run into `awaiting_guidance` ("your steer arrived — re-engaging"). The inbound handler checks run state and revives rather than silently discarding.

## 8. Dormant + config
Enable-flagged (`TechnicianConfig::enabled()` + guidance-loop flag). New settings: guidance enable, per-category triggers, re-nudge/age windows, the escalation-chain reuse, the Teams bridge config (bot endpoint, Entra mapping), SMS nudge config. Merging dormant is safe (the `awaiting_guidance` enum value needs no schema change; guidance columns/notes are additive; everything gated).

## 9. Seams (deferred, designed-in)
- **SOP consult-first + learn:** the "consult guidance" step (before asking a human) reuses the wiki; a steer can be saved as an SOP. (Decoupled, next after v1.)
- **Conditional/multi-step execution:** the "email-to-confirm-then-release" workflow engine (wait-for-client-reply state + AI evaluation + conditional action).
- **Richer AI journaling.**

## 10. Sequencing & dependencies
- **Phase 2 first** (the trip-critical deterministic backstop = the safety guarantee; the guidance loop is a load-reducer). Finish this spec now; build Phase 2 (plan → review → build → soak); then build the guidance loop.
- **Shared foundations:** the bidirectional-Teams + Entra-identity bridge is shared with the deferred in-Teams approval; outbound SMS (nudge) is shared with Phase 2's outbound SMS escalation — build the SMS primitive once.
- **Reliability go/no-go gate** on the Teams bridge; cockpit fallback if it doesn't clear.
- Build via `superpowers:subagent-driven-development` with per-task spec+quality gates + an opus whole-branch review; ship dormant; soak before Aug 1.

## 11. Reuse-claim corrections (verified by the panel against the code)
- **"AI Conversation" type: EXISTS (panel missed it; Charlie was right).** It is NOT a `NoteType` enum case but IS a first-class **`AssistantConversation`/`AssistantMessage`** timeline entry rendered as "AI Conversation" (`resources/views/tickets/_timeline-ai-chat.blade.php`; merged at `tickets/show.blade.php:93`), already ingested by `ContextBuilder::buildConversationContext`. **REUSE it** for the guidance transcript + add a `kind`/`source` discriminator (guidance vs staff chat) + AI-initiated attribution (`user_id` non-nullable today) + confirm internal/non-portal.
- **Drafter re-ingesting the steer note: don't rely on it** — `TechnicianReplyDrafter` calls `ContextBuilder::buildForTicket(skipNotes:true)`; feed the steer as an EXPLICIT trusted directive segment (§6), not via incidental re-ingestion.
- **Bidirectional Teams: GREENFIELD** (outbound webhook only; no inbound; no per-person identity). The critical path.
- **Plivo SMS: GREENFIELD** (voice-only; no SMS client/inbound; voice webhook auth fails open).
- **Real & reusable:** `TechnicianActionGate`, `technician_runs` + states, `PromptFence` (ASCII-only — harden it), `OperatorNotifier` (one-way), 1B cockpit + `CockpitQuery`, `ai_authored`, signed grants (`TechnicianApprovalGrant` — extend for single-use + Entra identity).

## 12. Risks & open questions
- **Teams bridge reliability** is the #1 risk (delivery, identity binding, soak). Mitigated by the go/no-go gate + cockpit fallback.
- **Per-person Entra identity** (psa-axy) must be solved for Teams to authorize sends — confirm the realistic path with the `claude-teams-teammate` bot (absorb into PSA on the MSP server?).
- **Trigger calibration** (too chatty vs too silent) — tune during soak via the digest counts.
- **SMS opt-out/compliance** if SMS nudges go out (build once with Phase 2).

## 13. Testing
Mirror the Technician suite (sqlite `:memory:`, `RefreshDatabase`, `Setting::setValue`, `$this->mock(...)`, `$this->artisan(...)`, factories Client/Ticket/User; `TechnicianRun::create` inline). Cover: trigger fires/suppresses per category + deterministic floor; `awaiting_guidance` transition + `asked_at` anchor + revive-after-ageout; steer re-entry re-drafts (held); directed send requires an identity-bound grant; SMS cannot authorize a send; fence the directive (incl. NFKC/homoglyph); inert pause (no sleeping send job); gate+disclosure+audit on the steered send; durable approver attribution; degradation windows + escalation-chain routing; the cockpit guidance lane + digest section; inbound Teams webhook signature-auth fail-closed. Labeled fixtures for the trigger.

## 14. Success criteria
The away operator can, over Teams (with cockpit fallback), receive a crisp guidance ask, answer in a word/tap, and have the AI proceed correctly — reducing interruptions vs the "Needs you" lane — while **no spoofable channel can ever fire a client send**, every send stays disclosed + gated + audited + attributed, and nothing ever goes silently stuck. Ships dormant; clears a soak before Aug 1; the Teams bridge clears its reliability gate or falls back to cockpit.
