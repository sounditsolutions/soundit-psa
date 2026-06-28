---
type: plan
tags: [soundit-dev, ai-technician, escalation, increment-h, teams, trip-critical]
created: 2026-06-28
related: ["[[soundit-dev]]", "[[trip-mission-aug1]]", "[[2026-06-26-ai-technician-action-surface-roadmap]]", "[[2026-06-24 ai-technician-guidance-loop (full spec)]]"]
---
# AI Technician — Increment H: the Escalation Primitive (agent → Teams "I'm over my head")

> Mayor spec, 2026-06-28. The next build after psa-u97k, greenlit by Charlie ("move on to Teams").
> Grounded in the already-5-lens-panel-reviewed guidance-loop spec (`…2026-06-24-guidance-loop-design`)
> and the action-surface roadmap (H = next, "small", pure-internal, ship first). `#review-me`

## ⚠️ UPDATE (2026-06-28, after the dev's source-orientation) — read this first
Two corrections to the design below, both now decided:
1. **The primitive already ~80% exists as the agent's `flag_attention` tool** (it is NOT a missing
   "silent no-op"). `flag_attention` already files a held **Flagged** `TechnicianRun` with role-mapping
   categories (`needs_decision`→Charlie / `needs_hands_onsite`+`needs_overflow`→Justin); the cockpit
   "Flagged" lane already exists (its `CockpitQuery` comment literally says "(Increment H)"); and its
   docblock says notify/routing is "deferred to a later increment" = THIS one. → **Build A: COMPLETE
   `flag_attention`** — wire the deferred role-routed notify + degradation onto it (reuse `escalationChain`/
   `isAvailableForEscalation`/the `EmergencySweep` reping engine). Do **NOT** add a duplicate
   `escalate_to_human` tool and do **NOT** rename `flag_attention` (it's live in the soak). Wherever §2–§7
   below say "new tool escalate_to_human (tool #2)", read **"complete the existing `flag_attention`."**
2. **Notification channel = the shared "Day to Day" Teams chat the bot already lives in** (Charlie's call —
   no per-person DMs; per-person proactive Graph DM is a MS Protected API anyway). The bot proactively posts
   to that chat and **@mentions the role-routed person** (server-side from the flag category, never
   model/injection-chosen); **email** to that person is the secondary fallback. (Delivery fallback if the
   live bot's channel ref isn't readily available: the existing `TeamsNotifier`/channel webhook to that chat.)
Everything else (deterministic floor, dedup, dormant+flag-gated, output-scan, no-migration, TDD/SDD, held
PR, internal-only/zero-client-risk) stands.

## 1. Why (the mission gap this closes)
The Aug-1 bar: the AI **acts by default and escalates when over its head.** Today the "over its head"
path is a **silent no-op** — when the agent can't resolve a ticket it files nothing and pings no one
(`needsAttention()` is fed only by the legacy draft pipeline; held proposals don't notify anyone). So the
literal promise "Teams-escalates when stuck" is **UNBUILT**. H builds the cheapest, highest-leverage version:
the agent **flags that it needs a human and proactively notifies the right person (Charlie/Justin) via the
live Teams bot + the cockpit** — outbound only. Nothing the AI can't handle goes unseen during the trip.

## 2. Scope — deliberately MINIMAL (this is "small")
**In H (outbound-only):**
- A new agent tool **`escalate_to_human`** (the agent's 2nd mutating tool, beside `propose_close`): the agent
  calls it when it assesses a human is needed, with a structured payload: **one crisp blocker statement +
  the role it needs (judgment vs hands-on) + optional proposed-next-step**. Output-scanned (`WikiRedactor::scan`).
- A durable **escalation record** on the ticket/run (reuse `technician_runs` — new terminal-ish state
  `escalated` / or a `needs_human` flag + an `Escalation`-kind `AssistantConversation` message as the record,
  mirroring the guidance-loop's reuse of the "AI Conversation" timeline entry). It shows in a cockpit lane.
- **Role-based routing** (roadmap D4): a **judgment/business-decision** blocker → **Charlie**; an **on-site /
  overflow / ticket-takeover / hands-on** blocker → **Justin**. The agent picks the role; the routing
  (role → person, availability-aware) is **server-side config** (reuse `TechnicianConfig::escalationChain()`),
  NOT model free-choice of recipient. Wire the Charlie/Justin routing rules **into the prompt** (per roadmap),
  don't rely on incidental wiki search.
- **Notification** via the **live Teams teammate bot** (the prod `@SoundIT`/PSA-native bot, [[teams-bot-live-2026-06-27]])
  as the primary channel + **cockpit escalation lane** (record/fallback) + **email fallback**. Message =
  "AI Technician needs you on #<id> (<client> — <subject>): <blocker>. Open: <signed cockpit deep-link>."
- **Degradation — never silently stuck** (guidance-loop §8): unacknowledged within a (long, configurable)
  window → re-nudge → escalate up the chain (Justin → Charlie). Defaults long for a traveling operator.
- **Dedup/throttle:** one open escalation per run/ticket-blocker; the agent re-escalating the same blocker is a
  no-op (don't spam). A genuinely new blocker on the same ticket is allowed.
- **Dormant + enable-flagged:** gated behind agent enablement + a new `agent_escalation_enabled` flag.

**Explicitly NOT in H (these are the deferred full loop D / bridge E):**
- ❌ No **inbound** Teams round-trip, no multi-turn conversation, no operator "steer" ingestion.
- ❌ No **identity-bound signed grants** / per-person Entra binding (H authorizes NO action — it only notifies).
- ❌ No client-facing send. H is internal-only → **zero client risk, no send-authorization surface.**
The operator handles the escalated ticket via the **existing cockpit** (the agent doesn't need a reply back).
H is the outbound half; the inbound steer loop is the post-trip upgrade.

## 3. The trigger (keep it honest)
A distinct, conservative, **config-gated** assessment — NOT bolted onto `propose_close`/`SignificanceGate`
(which are close-only today). After the agent's loop, if it can neither resolve nor confidently propose-close
AND a human is genuinely needed, it MAY call `escalate_to_human`. Categories (name them, don't leave a raw
threshold): (a) ambiguous resolution needing a **business/owner decision**; (b) missing **contract/billing/
policy** decision; (c) **conflicting instructions/signals**; (d) needs **hands-on/on-site** work the AI can't do.
**Deterministic floor** (mirror 1A): the model can *raise* "I'm stuck"; injected ticket text must not be able
to *force* an escalation or *choose the recipient*. **Conservative default** (don't over-escalate — calibrate
against the soak; surface escalation counts by category in the digest, like the guidance trigger).

## 4. Reuse (verified-real machinery — don't rebuild)
- **Live Teams teammate bot** (outbound notify path). ⚠️ **Open build question for the dev to resolve against the
  live bot:** can it send a **proactive** message (bot-initiated DM/post), or is it reply-only today? Proactive
  Bot-Framework messaging needs a stored conversation reference per user. **MVP fallback if proactive-per-person
  isn't ready:** post to a **Teams channel Charlie/Justin monitor** via the existing `TeamsNotifier`/`OperatorNotifier`
  webhook (works today) — reliable now; per-person proactive DM is the enhancement. Pick the reliable path; flag
  the choice in the PR.
- `OperatorNotifier` (extend; keep it the one-way notify seam), `TechnicianConfig::escalationChain()`,
  `technician_runs` + states, the cockpit + `CockpitQuery` (add an "Escalations / Needs-you" lane if not present),
  the agent loop + tool-dispatch (add tool #2), `AssistantConversation`/`AssistantMessage` for the record,
  the digest builder (add an escalations section).

## 5. Safety (much lighter than the full loop — because outbound-only)
- H **authorizes no action** → none of the grant/identity/send-hardening surface of the guidance loop applies.
- Escalation **content is output-scanned** (`WikiRedactor::scan`) like every agent output; the blocker text is
  internal (to Teams/cockpit), never client-facing.
- **Recipient is server-chosen** from role+config, never model/injection-chosen (the only injection surface).
- Inert/dormant by flag; reversible; **no migration if avoidable** (additive columns/flags; reuse the run-state
  enum — confirm the new state needs no schema change, per guidance-loop §8).

## 6. Build / delivery
- **Owner:** `developer` agent, **TDD + subagent-driven-development**, per-task quality gates, opus whole-branch
  review + `/soundpsa-review-pr`, **HELD PR → Mayor reviews + merges + deploys dormant**, fold into the live soak.
- **Tests** (mirror the Technician suite): tool fires/suppresses per category + deterministic floor; escalation
  record + cockpit lane; **role→recipient routing is server-side** (injection cannot redirect); notify via the
  chosen Teams path + email fallback; dedup/throttle (re-escalate same blocker = no-op); degradation re-nudge +
  chain escalation; dormant/flag-gated; output-scan on the blocker text.
- **Ships dormant**, soaks before Aug 1. Calibrate the trigger during the soak (digest counts).

## 7. Success criteria
When the live agent is over its head, it **stops being a silent no-op**: it files a durable escalation, the
**right person (Charlie or Justin) gets a Teams ping with a one-tap cockpit link**, nothing ages out silently,
and **no injected ticket text can force an escalation or pick the recipient**. Internal-only, zero client risk,
dormant until enabled. This is the trip's safety valve — the "ask on exceptions" half of "act by default."
