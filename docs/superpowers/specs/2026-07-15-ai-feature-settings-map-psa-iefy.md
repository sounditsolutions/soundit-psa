# THE MAP — the native AI feature-set and its settings (psa-iefy)

**Status:** PROPOSAL / reference map — design-first, for the manager → Charlie. **Nothing here is built.** No threshold is set, dormant or otherwise.
**Bead:** psa-iefy (Charlie's so-whdf ask 2 — *"much clearer layout + documentation … so we know what does what"*). Split from psa-u6bc, which delivered its other three sections.
**Companion:** `docs/superpowers/specs/2026-07-15-auto-close-path-reconciliation-psa-u6bc.md` — the auto-close cluster (Paths A–E) is mapped there and is **not** re-derived here. This map is the rest of the surface.
**Verified at:** `origin/main` @ **6ccc660**. Every behavioural claim carries a `file:line` **plus a quoted fragment** — because a bare line number rots (see §7.2, where this document's own predecessor's citations no longer resolve).
**Independently verified:** every load-bearing claim below was checked by someone other than its author. Verdicts in §6.

---

## 1. The headline

**Charlie's question is "what does what". The honest answer is that today you cannot tell from the controls, because the controls do not mean what they say — and they fail in one consistent direction.**

This is not thirty unrelated defects. It is **one defect class, repeated across every cluster**: the control surface and the code have drifted apart, and in nearly every instance the drift runs the same way — **the control claims more authority than it has.**

| The operator believes | Actually |
|---|---|
| "AI enabled" is the master switch | 2 functional readers; **25 files** gate on *"is an API key present"* instead. Clearing the key is the only real kill — **and the UI cannot clear it** (§3.1) |
| "Enable AI Assistant" turns the Assistant off | **Cosmetic.** Every reader is a Blade `@if`. The endpoint and both write tools stay live (§3.7) |
| `agent_enabled = false` stops the AI closing tickets | Stops Path A, **misses** Path B, **wakes** Path C (psa-s72e, mapped in the companion spec) |
| The emergency stop stops the AI | Now reachable (psa-2wwh shipped a UI). Still misses **Triage, Wiki, Assistant, Briefing, Portal, and 4 MCP write tools** — and **fails open when unset** (§4.1) |
| "Tokens per run" caps spend | **Dead** in two clusters. The agent hardcodes **double** the dead setting's default (§4.2) |
| Ticking a relay cell means the alert relays | 9 of 18 catalog event types **have no producer** and can never fire (§3.8) |

**And nothing is admin-gated.** `User::isAdmin()` has zero production call sites; every settings route is plain `auth`. Any authenticated staff user — Tech, Billing, **Contractor** — can mint an MCP token granting `delete_client`, or release the emergency stop mid-incident (§4.3).

**The second-order finding, and the reason this map exists at all:** the companion spec merged at 14:29:53 today. A fix landed at 15:09:24 that made one of its headline gap rows false. **Forty minutes.** Any map of this surface is a snapshot; the only durable defence is that every claim carries the evidence to re-check it (§7).

---

## 2. How to read this map

**The unit of truth is the config helper, not the settings key.** A naive `grep "Setting::getValue('...')"` finds 197 keys and **misses the three most fundamental AI keys in the product** — `ai_provider`, `ai_api_key`, `ai_model` are read through `Setting::settingOrConfig()` via a private `$map` in `AiConfig`, and never appear. There are **four** read idioms plus runtime-built keys:

| Idiom | Sites | What it hides |
|---|---|---|
| `Setting::getValue()` | 270 | — |
| `Setting::getEncrypted()` | 43 | the API keys / tokens |
| `Setting::settingOrConfig()` | 7 | **`ai_provider`, `ai_api_key`, `ai_model`** (via `AiConfig`'s `$map`) |
| `SignalEventTypeSetting::isTypeGloballyEnabled()` | 4 + 1 writer | the Signals per-event-type master toggle — **a different table entirely**, invisible to any `Setting` grep |
| runtime-built keys | — | `triage_stage_{$stage}` (`TriageConfig.php:39`); `getValue($key)` on a variable at `TechnicianConfig.php:568,581`, `AgentConfig.php:173`, `StaffPsaActionToolExecutor.php:2593` |

So: **read the helper, follow its callers.** That is also exactly what the bead asks for (each setting's *reading helper* and *real default read from code, not the comment*), which is why it is the unit of work here.

**The surface, measured:** **142** PHP files across 12 AI service subsystems, **11 config helpers** (~1,800 lines of pure settings-reading code), and **~113 settings keys** — meaningfully more than the 86 a single-idiom grep reports.

> **Even this document's own orientation undercounted.** `ls app/Services/Agent/` reports 14 files; `find app/Services/Agent -name '*.php'` reports **31** — `ls` does not recurse. The lesson is the bead's own: *no enumeration is trustworthy, including this one.* Re-derive.

---

## 3. THE MAP

Conventions: **REAL default** is read from the helper's code path, never a comment. **NO UI** means no `Setting::setValue`/`setEncrypted` writer in `app/Http/Controllers/` and no Blade renderer — i.e. **DB-edit-only**. **DEAD** means zero production readers.

### 3.1 Cluster: Global AI core — `AiConfig`, `AiClient`, transcription

The foundation. Everything else inherits from it.

| Key | Reader | REAL default | Gates | UI | Notes |
|---|---|---|---|---|---|
| `ai_enabled` | `AiConfig.php:62-65` | **ON** (`getValue('ai_enabled','1')==='1'`; no row seeded) | **Only 2 sites**: `TechnicianAgent.php:60`, `DraftPipeline.php:39` | `IntegrationsController.php:444` (**dynamic key** — see below) / `integrations.blade.php:2878` | psa-s7u8 |
| `ai_provider` | `AiConfig.php:33-36` | `'anthropic'` | `AiClient.php:37,160-162`; `TranscriptionConfig.php:22` | `:745` / blade `:2798` | **§4.4 — the two options are not interchangeable** |
| `ai_api_key` | `AiConfig.php:22-31` (enc) | `null` | **25 files / 29 sites** | `:750` / blade `:2811` | **cannot be cleared via the UI** |
| `ai_model` | `AiConfig.php:38-41` | `claude-sonnet-4-6` / `gpt-4o` | `AiClient.php:35,165` | `:746` / blade `:2824` | matches INSTALL.md:732 ✓ |
| `ai_connected_at` | `IntegrationsController.php:175` | `null` | **nothing functional** — badge only | written by `testAi:798` | **sticky: never cleared, so the badge can read "Connected" forever** |
| `ai_reply_guidelines` | `AiConfig.php:76-81` | `null` | `ReplyDraftService.php:96`, `TechnicianReplyDrafter.php:59` | `:747` / blade `:2839` | feeds **both** sides of the disclosure asymmetry (§4.5) |
| `openai_api_key` | `TranscriptionConfig.php:13-27` (enc) | `null`; falls back to `ai_api_key` iff provider is openai | Whisper paths | `:820` / blade `:2916` | also uncleanable |
| `auto_transcribe_calls` | `TranscriptionConfig.php:40-43` | **false** | `PlivoWebhookController.php:299,310` | `:824` / blade `:2933` | matches INSTALL.md:771 ✓ |
| `auto_transcribe_min_seconds` | `TranscriptionConfig.php:49-52` | **30** | same | `:825` / blade `:2946` | matches INSTALL.md:772 ✓ |
| `triage_system_user_id` | `AiActorResolver.php:48-70` | **first user by id** (not null) | ~19 readers | `:1744` / blade `:3089` | §4.6 |

**A methodological trap worth naming:** a literal grep for `setValue('ai_enabled'` returns **only a test** and would yield a false **NO UI** verdict. The writer builds the key dynamically — `IntegrationsController.php:444`, `input('integration').'_enabled'`. **Any audit of `*_enabled` keys in this repo must read `toggleIntegration():416-451`.**

### 3.2 Cluster: Triage pipeline — `TriageConfig`, `TriageSchedule`

19 live keys + 1 dead. All scalars written by `IntegrationsController::updateTriage()`; all rendered on Settings → Integrations.

**The 7 stage keys are runtime-built** (`TriageConfig.php:39`, `"triage_stage_{$stage}"`) and **every one defaults TRUE when unset** (`:41`, `$value === null || (bool) $value`):

| Stage key | Gated at | UI |
|---|---|---|
| `triage_stage_contact_resolution` | `TriagePipeline.php:217` | blade `:3107` |
| `triage_stage_junk_filter` | ← runStage 132,190 | blade `:3111` — **the only product control over auto-close Path D** |
| `triage_stage_classification` | ← runStage 146 | blade `:3115` |
| `triage_stage_asset_assignment` | ← runStage 157,196 + `AssetMatcher.php:122` | blade `:3121` |
| `triage_stage_technical_triage` | ← runStage 163 | blade `:3125` |
| `triage_stage_conversation_review` | ← runStage 207 | blade `:3129` — **the only stage control over Path C** |
| `triage_stage_contact_allcaps` | `ContactResolver.php:77` | **NO UI** — and its comment says *"(off by default)"* while the code returns **true**. On by default, unturnoffable through the product. |

Scalars (reader → real default → gate): `triage_enabled` (`:14` → **false**), `triage_auto_new_tickets` (`:22` → **false**), `triage_auto_review` (`:30` → **false**), `triage_review_frequency_minutes` (`:104` → **60**), `triage_review_auto_close` (`:120` → **false**, Path C), `triage_review_auto_close_threshold` (`:131` → **80**, **§4.7 fail-open**), `triage_default_assignee_id` (`:49` → null), `triage_model` (`:73` → `AiConfig::model()`), `triage_max_tokens_per_run` (`:83` → 200000, **§4.2**), `triage_daily_token_limit` (`:91` → 2000000), `triage_review_batch_size` (`:112` → 20). **`triage_auto` is DEAD** — written by `DevDataSeeder.php:145`, read by nothing (near-certainly a typo for `triage_auto_new_tickets`).

> **The triage cluster is the de-facto master switch for the Technician Agent lane.** `TriageReviewOpen.php:71-73` dispatches `RunTechnicianAgent` inside the review loop. So `triage_enabled` + `triage_auto_review` gate **the agent's close/reply/flag**, `triage_review_batch_size` caps how many tickets **the agent** sees per pass, and `triage_review_frequency_minutes` sets **the agent's tempo**. Three settings presented as "AI Triage" tuning silently govern a different lane. This is also the mechanism behind the psa-s72e inversion.

### 3.3 Cluster: Agent / Chet autonomy — `AgentConfig`

**15 keys. Two have a UI. Thirteen are DB-edit-only.**

| Key | Reader | REAL default | Gates | UI |
|---|---|---|---|---|
| `agent_enabled` | `AgentConfig.php:16-19` | **false** | `RunTechnicianAgent.php:58`, `RunTechnicianLoop.php:76`, `TriageReviewOpen.php:71`, `ConversationReviewer.php:99` | **NO UI** |
| `agent_max_pending` | `:25-30` | 10, floor 1 | `RunTechnicianAgent.php:112`; `FlagAttentionTool.php:112` | **NO UI** |
| `propose_close_auto_threshold` | `:43-51` | **null = never auto** | `TechnicianTierClassifier.php:36` | **NO UI** |
| `propose_close_approve_floor` | `:58-63` | **0.50** | `ProposeCloseTool.php:111` | **NO UI** — **live, not agent-gated** |
| `agent_auto_quiet_days` | `:72-77` | **14** | `CloseAutoEligibility.php:82` | **NO UI** — **live via Path B** |
| `agent_escalation_enabled` | `:83-86` | **false** | `console.php:415`, `EscalationSweep.php:44`, `FlagAttentionTool.php:156` | **NO UI** |
| `agent_escalation_reping_minutes` | **`TechnicianConfig.php:503-508`** | 120, floor 15 | `EscalationSweep.php:48` | **NO UI** — *the only `agent_*` key not read by `AgentConfig`* |
| `agent_significance_model` | `:93-98` | **`claude-haiku-4-5`** — ignores `ai_model` | `SignificanceGate.php:33`, `ChimeInGate.php:30` | **NO UI** |
| `agent_model` | `:105-110` | **`claude-opus-4-8`** — ignores `ai_model` | `TechnicianAgent.php:42`, `TeamsReplyService.php:36` | **NO UI** — **§4.4 cost trap** |
| `agent_situation_context_enabled` | `:113-116` | **false** | `ContextBuilder.php:127` | **NO UI** |
| `intake_enabled` | `:127-130` | **false** | only `:176` (legacy fallback) | **NO UI** |
| `intake_call_enabled` | `:140-143` | absent ⇒ inherits `intake_enabled` | `CallIntakePipeline.php:53` | **HAS UI** — `:1792` / blade `:3485` |
| `intake_email_enabled` | `:153-156` | absent ⇒ inherits | `EmailService.php:790` | **HAS UI** — `:1793` / blade `:3493` |
| `intake_attach_auto_threshold` | `:195-203` | **null = never** | `CallIntakePipeline.php:91` | **NO UI — deliberate** (`:1779-1781`) |
| `intake_spam_block_auto_threshold` | `:220-228` | **null = never** | `CallIntakePipeline.php:165` | **NO UI — deliberate** |

**Two findings that change the psa-s72e picture:**
1. **`agent_max_pending` bounds the dormant lane and not the live one.** It is read at exactly two sites; **`ProposeCloseTool` never reads it** — only a *comment* at `:110` mentions it, inviting the opposite reading. The MCP path (`McpStaffController.php:877-881` → `AssistantToolExecutor.php:84,404` → `ProposeCloseTool::executeHeld`) has **zero `AgentConfig` references**, so it is neither capped by `agent_max_pending` nor stopped by `agent_enabled`. `agent_enabled=false` fails to stop not only Path B's *direct close* but MCP *propose_close*, which keeps filling the cockpit queue uncapped.
2. **Inconsistent truthiness inside one class, on keys that are DB-edit-only.** `agent_enabled` uses `(bool)` (`:18`); `agent_escalation_enabled` (`:85`), `agent_situation_context_enabled` (`:115`), `intake_enabled` (`:129`) use `=== '1'`. So **`agent_enabled = 'false'` ⇒ the agent is ENABLED** (likewise `'no'`, `'off'`, `'00'`). Since none has a UI, **hand-typed tinker values are the only input path** — the loose cast is exposed exactly where it is most likely to be hit.

**Credit where due — the intake card is the cluster's one honest surface.** `IntegrationsController.php:1779-1781` deliberately exposes the two channel toggles and **no auto-act thresholds**, keeping both nulls held-first, and `integrations.blade.php:3500-3502` says so to the operator in plain words. **This is shipped-dormant done right, and it is the working model for the IA in §5.**

### 3.4 Cluster: Technician — `TechnicianConfig` (590 lines, the largest)

**31 keys: 27 operator settings + 4 runtime-state stamps sharing the same namespace, table, and helper.** Full table omitted here for length; the load-bearing facts:

- **`technician_kill_switch`** — the emergency stop. **15 enforcement sites** (`TechnicianActionGate.php:76,117` + 13 MCP write sites). **Now has a UI** (psa-2wwh, `IntegrationsController.php:1921`, blade `:3511`) on its **own route** (`web.php:472`) — deliberately separate so an unrelated form save cannot disarm it, with `engaged` required `in:0,1` so a malformed POST 422s rather than releasing the brake. **Good design.** See §4.1 and §4.3 for what it still does not cover and who can flip it.
- **DEAD but live-looking:** `technician_operator_covering` (docblock: *"Authoritative manual 'covering / not covering' toggle"*; **zero production callers**) and `technician_max_tokens_per_run` (**zero callers** — while `TechnicianAgent.php:132` hardcodes `maxTokenBudget: 200_000`, **double** the dead setting's 100k default).
- **8 live controls with NO UI**, two of them **per-client safety controls in the gate** — `technician_excluded_client_ids` (`TechnicianActionGate.php:81`) and `technician_always_human_client_ids` (`:92`). *"Never let the AI act for this client"* is DB-edit-only. Two more are **Chet's live escalation routing** (`technician_escalation_judgment_user`/`handson_user`).
- **`technician_daily_token_limit` caps one lane of three** — enforced at exactly one site (`DraftPipeline.php:43`). The agent loop has **no** daily ceiling.
- **Writer floor < reader floor** on 2 keys (`technician_escalation_timeout`, `technician_emergency_reping`): writer clamps `max(1,…)`, reader clamps `max(5,…)`. A saved `2` is stored as 2 and silently enforced as 5 — **the stored value is a lie.**
- **`technician_coverage_start_at`** is runtime state **written as a side-effect of an operator toggle** (`:1969,:1971`), and it silently bounds what the emergency sweep will ever see (`EmergencyDetector.php:32-34`). Toggling the backstop off and on re-anchors coverage and makes the existing backlog **permanently invisible to age detection**. Intended (psa-wmqp); completely unsurfaced.
- **The prefix now spans two different things.** `blade:3607-3616` tells the operator *"Superseded by GC Chet… leave the PSA-native Technician **off**"* — yet `TechnicianConfig` also owns the brake for **Chet's live MCP writes** and Chet's escalation routing. The naming tells an operator the brake belongs to the thing they were told to leave off.

### 3.5 Cluster: Client Wiki AI — `WikiConfig`, `WikiBudget`

9 keys, **8 with a real UI** at `settings/general.blade.php:265-321` → `GeneralSettingsController::updateWiki()`. (The "no UI" framing in the brief was wrong — this cluster's problem is documentation, not reachability.) `wiki_stale_open_ticket_days` is the one **NO UI** key.

- **`wiki_max_tokens_per_run` is DECORATIVE — and worse than its `technician_` sibling, because it is *visible*.** Validated (`min:1000,max:200000`), written back, rendered at blade `:298` — and it has **zero production consumers**. An operator lowering "Tokens per mining run" to cap spend gets **no protection**; the real bounds are hardcoded (`WikiOverviewComposer.php:31` `MAX_OUTPUT_TOKENS = 1_200`).
- **`wiki_daily_token_limit` IS genuinely enforced** — 8 sites, one shared pool across mining/compose/draft. Pre-flight gate, so a run can overshoot by one call.
- **`wiki_model` is honoured on 1 of 3 AI paths.** The rebind lives only in `MineTicketKnowledge.php:132-133`. **The 03:00 nightly regen ignores it** (`WikiOverviewComposer` takes `AiClient` by constructor DI; no provider binds it). *Correction to a premise:* `wiki_model` does **not** ignore the operator's `ai_model` the way `agent_model` does — `WikiConfig.php:26` is `$override ?: AiConfig::model()`, which **respects** it. No Opus fallback anywhere in this cluster.
- **Code default and UI-effective default disagree.** `WikiConfig.php:47` returns **ON** when unset; but the UI can never produce "unset-while-wiki-on" — a fresh install renders the box unchecked and saving writes `'0'`. **UI ⇒ maintenance OFF; tinker/seeder `wiki_enabled=1` ⇒ maintenance ON.** A green test (`WikiMaintainCommandTest.php:40-55`) asserts the code default down a path the UI never produces. Fails safe; still two doors with opposite outcomes.
- **No human approval step: it is write-then-curate.** The AI **overwrites page bodies directly** (`WikiOverviewComposer.php:105`, `updateBody(..., WikiAuthorType::Ai, ...)`); revision history is the undo. Mined facts insert as `Unverified` — a **trust tier, not an approval queue** — live immediately and feeding the next compose.
- **Staff-only, with one narrow inference channel.** Zero wiki hits in `routes/portal.php` or `resources/views/portal/`. But the AI-composed overview is injected into **every** ticket's AI context (`ContextBuilder.php:416-421`), so wiki text steers output that *can* reach a client. Separately, a client can *discover* a ticket via `search_my_tickets` matching AI-written `resolution` text (`Ticket::scopeSearch():255`) that they can never *read*. Narrow, real, not a disclosure.

### 3.6 Cluster: MCP surface — `McpConfig`, `McpStaffToken`, `McpToolRegistry`

6 real `mcp_*` keys (+4 `cipp_mcp_*`). **`mcp_staff_scoped_tokens` is DEAD in app code** — a legacy JSON blob whose only production caller is a migration; scoped tokens live in the **`mcp_tokens` table**.

**The authorization model (this is the cluster's substance — the settings table alone will not explain it):**
1. **Transport** — `routes/api.php:52-53` → `VerifyMcpStaffToken` + `throttle:120,1`; 503 unless `isStaffEnabled()`.
2. **Authenticate** — legacy plaintext `hash_equals`, or scoped sha256 → `McpToken::scopeAuthenticatable()` (`revoked_at IS NULL AND activated_at IS NOT NULL AND paused_at IS NULL`). **Born-safe: a draft or paused token cannot authenticate.**
3. **Grant gate** — `McpStaffController::toolAllowed():1848-1911`, **re-read from the live DB on every call** (`tools/list` is only a cache). Every sensitive family requires `allowedTools !== null && allows($tool)` — the `!== null` clause **denies the legacy token**.
4. **Mode gate** (`:476-484`) — **runs only `if ($stageable)`**. Non-stageable tools skip it entirely.
5. **Dispatch** → executor. **The kill switch is enforced inside executors, never at dispatch.**

- **The legacy token is a near-dead break-glass and its docblock oversells it.** `McpConfig.php:11-13` calls it *"the legacy full-surface token"*. It reaches **0 of 122 sensitive tools** and **54 of 205** overall. Its surviving writes are defensible — `send_reply` never auto-sends, `propose_close` is held — **except `add_ticket_note`**, a real write callable with no grant and no kill switch.
- **Only 33 of 205 tools are stageable** — counts independently re-derived from the registry by the adversarial pass, reproducing exactly. The non-stageable ones — where **a grant means immediate execution and no approval is even possible** — include all 16 `psa_records` (incl. `delete_client`, `delete_contact`), 9/11 `psa_action` (incl. `set_ticket_status`, `create_ticket`, `move_ticket_to_client`), 3/3 `wiki_write`, 38/40 `tactical_admin`, 6/15 `tactical_action`, and `cipp_reset_user_password`. For the **172 non-stageable tools the mode gate is skipped entirely** (`McpStaffController.php:476`, `if ($stageable)`). And for the 33 that *are* stageable, **a bare grant means immediate** (`McpToolModes::parseGrantEntry():116-119`, *"Bare canonical = legacy grant of the immediate variant"*); held-only requires explicitly writing `name:staged`.
  > **Honest qualifier, so the example is not oversold:** `delete_client` is a **weaker** doomsday case than it reads. It does carry downstream guards — `guardDirectAction()` (the kill switch, `StaffPsaActionToolExecutor.php:825`), a typed-confirm (`:840-842`), and `ClientService::deleteClient` blocks on open tickets / active contracts / unpaid invoices, then **soft**-deletes. But **the confirm string is supplied by the agent itself** — that is a wrong-target guard, not a human approval. The claim *"no approval is even possible"* stands; *"the AI can nuke a client"* would not.
- **Path B reachability:** `set_ticket_status` is reachable **only** via a scoped token carrying an explicit grant — never the legacy token — and is **not stageable**, so no held step exists. *(Whether Chet's prod token carries that grant is **UNVERIFIED** — see §7.)*
- **`mcp_tool_custom_instructions` is GLOBAL but edited PER-TOKEN.** One setting keyed by tool name with **no token dimension**; `replaceAll()` overwrites the whole map; applied to every token's `tools/list`. **Editing token A's instructions silently rewrites token B's.** It is also an operator-controlled text channel into the tool descriptions the model reads.
- **CLI and UI mint tokens with different safety.** `McpConfig::rotateStaffToken():147-150` sets `activated_at` → **born ACTIVE**, bypassing the draft flow.
- **Portal MCP** is dormant until `mcp_portal_token` is minted, and **fail-closed twice** (resolver returns null on any failure; controller refuses `tools/call` without a person). Best-guarded lane in the cluster, alongside the portal chatbot.

### 3.7 Cluster: Assistant / Briefing / Portal chatbot / Reply drafts

10 keys. **The Assistant is the only cluster that defaults ON — and it writes.**

- **`assistant_enabled` is COSMETIC.** `AssistantConfig::isEnabled()` requires no explicit toggle: it keys off `AiConfig::isConfigured()` ("is a key present") + provider `anthropic` (itself the default), and unset ⇒ **true**. Pasting a key activates it. **And unticking the box does not turn it off:** every reader is a Blade `@if` or the settings page; `AssistantController` has **zero** gate; `AssistantService::sendMessage:39-41` re-checks only key+provider. The bubble disappears; the endpoint and both write tools stay live. → **psa-uw2o**
- **Two write tools, no held step:** `create_ticket` (`AssistantToolDefinitions.php:225`) and `add_ticket_note` (`:248`), both straight to `TicketService`. Neither `technician_kill_switch` nor `ai_enabled` reaches them. **Cascade:** an Assistant-created ticket carries the *staff* user as `created_by`, so `TicketObserver.php:44-49` does **not** treat it as system-created — it fires `RunTriagePipeline` and pages staff.
- **The docblock and the system prompt both claim read-only.** `AssistantToolDefinitions.php:8` — *"Read-only tools only (v1)"* — 217 lines above two writers. `AssistantService.php:177`, **the system prompt sent to the model** — *"You have access to read-only tools…"* — while handing it those writers. Anyone reviewing the prompt to ask *"can the staff Assistant write?"* is told **no** by the prompt itself.
- **Briefing: 5 keys, NO UI at all** (`briefing_enabled` default **false**; `briefing_ai_suggestions` default **true**). Its docblock (`BriefingConfig.php:12`) says *"An operator turns it on in Settings"* — there is no Settings UI. (INSTALL.md:871 is honest here; the docblock is the wrong one.) Emails **internal staff only**, Haiku, 400 output tokens — **no cost trap**. Latent: the hardcoded Haiku id is sent regardless of provider, so an OpenAI deployment silently loses briefing suggestions.
- **Portal chatbot** — read-only and client-locked, exactly as claimed. Scope is **constructor-bound, never from tool input**; executor throws if `clientId <= 0`; invoices restricted to Posted/Synced/Paid. **Best-guarded sub-feature in the cluster.**

### 3.8 Cluster: Teams bridge + Signals/Alerts relay

13 `teams_*` keys. **Signals has ZERO settings keys of its own** — it is entirely DB-modeled (`signal_routes`, `signal_destinations`, `signal_event_type_settings`).

- **5 of 13 Teams keys have NO UI**, including **both Chet-lane keys** (`teams_chet_conversation_id`, `teams_chet_routing_enabled`). *The two that decide whether the AI can hear and speak at all are the least reachable.*
- **The ambient dials are downstream of a toggle the code says is deliberately off.** `OperatorDelivery.php:66-74` states the PSA-native bot is superseded — *"Do NOT 'fix' this by flipping teams_bot_enabled back on."* At that configuration the four ambient settings have a live, inviting Settings UI that **cannot change behaviour**.
- **9 of 18 catalog event types have NO PRODUCER anywhere in `app/`**: `ticket.sla_breached`, `ticket.sla_approaching`, `operator.message`, `agent.proposal_held`, `agent.proposal_auto_closed`, `agent.run_failed`, `integration.sync_failed`, `tactical.alert_created`, `digest.daily`. They are relayable, matrix-toggleable and master-toggleable — **and can never fire.** An operator can tick a cell and reasonably conclude they are covered for SLA breach or a failed integration sync. **This is a false-assurance surface, and it is the CLAUDE.md rule-3 class ("a confident clear is worse than an exception") expressed in the UI rather than in a tool response.**
- **Chet's wake lane is unseeded.** No code path puts any type into a managed relay route; the only seeded route is disabled + operator-owned. Out of the box, the lane is empty.
- **psa-lunj's fix is INCOMPLETE — the destination door is unguarded.** → **psa-qddf**. Routes are partitioned by `managed_token_label`; **destinations are not** (no such column; `index()` lists them unfiltered; `update()`/`toggle()` unguarded). `SignalRelayMatrix.php:100` computes `$delivers` from **`$route->enabled` only**, never the destination's — so disabling a matrix-owned *destination* stops the relay while the matrix still renders the cell relayed. The same lie psa-lunj was written to kill, one layer down. `SignalRouter::wouldReachMcpDestination():131` *does* check destination enabled-ness — **so the detector and the matrix UI actively disagree about the same config.**

---

## 4. THE GAPS

Corrections and additions to the companion spec's §8. **Its gap table is a snapshot; two rows have already moved.**

### 4.1 The emergency stop: reachable now — but its card promises coverage it does not have
**CORRECTION — the companion spec §8 is STALE.** It says *"zero writers outside tests… hand-editing prod MariaDB."* psa-2wwh shipped a UI **40 minutes after that spec merged** (spec `baaf2cc` 14:29:53 → UI `0e16d26` 15:09:24; both ancestors of HEAD). Anyone quoting §8 to Charlie will tell him the brake is unreachable when it is a toggle in Settings.

**What remains true, and is the real finding:** coverage. `technician_kill_switch` does **not** reach —
- the **Triage** lane (Paths C and D) — psa-0d0t, confirmed: zero hits in `app/Services/Triage/`
- the **Wiki** lane — zero hits in `app/Services/Wiki/`; **Chet can write the wiki with the emergency stop engaged** (`wiki_add_fact`/`wiki_create_page`/`wiki_update_page` are live MCP tools gated only by `wiki_enabled`)
- the **Assistant**, **Briefing**, and **Portal** lanes — zero hits in each
- **4 staff MCP writes** (`add_ticket_note` + the 3 wiki writes) that fall to the else-branch → `AssistantToolExecutor`, which has no gate. Traced end-to-end: `routes/api.php:52` (auth + throttle only) → `toolAllowed()` (grant check only) → mode gate **skipped** (not stageable) → `McpStaffController.php:878-881` → `AssistantToolExecutor:92` (zero `TechnicianActionGate|killSwitchEngaged` hits) → `TicketService::addNote()` (zero hits) → `TicketNote::create()`. **No guard anywhere on the path.**

> **The sharpest form of this, found by the adversarial pass and stronger than the gap itself: the brake's own card promises the coverage it does not have.** `integrations.blade.php:3529-3532` tells the operator it *"Pauses the AI's **write** actions in the agent/MCP lane… **within this lane you do not need to work out which tool is responsible first.** It does **not** cover AI triage."* **AI triage is the only carve-out named** — so the operator is explicitly instructed *not* to reason about which tool is responsible, while four MCP write tools in that very lane keep writing. **A false safety guarantee during an incident is worse than an undocumented gap.**
>
> **In fairness — the honest blast radius is narrower than it sounds.** None of the four are client-facing: `AssistantToolExecutor.php:626` hard-codes `isPrivate: true`, and the wiki tools write an internal KB. The real exposure is *"the AI keeps writing internal notes and KB during an emergency stop"*, **not** *"it keeps emailing clients"* — the kill switch's own refusal string is scoped to *"direct client-facing action refused"* (`StaffPsaActionToolExecutor.php:2310`). That mitigation does not survive the card's copy, but it should be stated plainly rather than left for someone to discover as a rebuttal.

*(A claim was **dropped here** rather than shipped: an earlier draft added "and it fails OPEN when unset". Literally true — but the implicature is unsupported. "Not engaged" is the **correct** default for an emergency stop, and a seeded row would hold `'0'`, which casts to false identically. Nothing would change. It added no risk and weakened an otherwise solid finding, so it is gone.)*

Enforcement is **per-tool opt-in across five independent choke points with no central barrier** — coverage is convention, not construction. **psa-0d0t should be widened well beyond Triage.**

### 4.2 Token ceilings that do not ceil
`technician_max_tokens_per_run` — **DEAD**, while `TechnicianAgent.php:132` hardcodes **200,000**, double its 100k default. `wiki_max_tokens_per_run` — **DEAD but rendered in the UI and validated**, so an operator capping spend gets nothing. `triage_max_tokens_per_run` — **live but narrower than it says**: its docblock claims *"across all AI calls"*; it reaches only the Stage-3 tool loop (`TechnicalTriager.php:89`), leaving four AI-calling stages unbudgeted. `technician_daily_token_limit` — **live at one site**; the agent loop has no daily ceiling.

### 4.3 Nothing is admin-gated — psa-qbr6 CONFIRMED, and it understates the exposure
`User::isAdmin()` (`app/Models/User.php:90`) has **zero production call sites** (declaration + 3 test refs). `routes/web.php:122` is a plain `auth` group containing every settings route, the MCP token routes (`:295-309`) and the kill-switch route (`:472`), with no nested group. No `app/Policies`, no admin alias in `bootstrap/app.php`, no constructor middleware on `McpTokensController`.
**The bead says "flip every AI toggle". The real exposure:** any authenticated staff user — Tech, Billing, **Contractor** — can mint an MCP token (`:296`), grant it any tool including `delete_client` and `set_ticket_status` (`updateTools`, zero authz), activate it (`:303`), and **release the emergency stop mid-incident**. The born-safe draft lifecycle protects against *accident*, not against an authenticated actor. **psa-2wwh's UI made the brake reachable — and reachable by everyone.** This is live, not theoretical: roles are assignable today (`StaffController.php:54`, `settings/staff/_form.blade.php`).

> **This is a known, deliberately-deferred gap — not an oversight — and that changes the ask.** `app/Enums/UserRole.php:8-12` says so in its own words: *"It only models the role itself — policies and gates that read it are added in follow-up work."* So the question for Charlie is not *"did we miss authorization?"* but **"the follow-up work was deferred; is now the time, given the AI surface it currently leaves ungated?"**

### 4.4 The cost trap, and a provider that is not interchangeable
**Cost — confirmed, and the docs are worse than "wrong".** `agent_model` → **`claude-opus-4-8`** (`AgentConfig.php:105-110` → `AiConfig.php:13,56-60`), `agent_significance_model` → **`claude-haiku-4-5`** (`:93-97` → `AiConfig.php:12,51-54`), both bypassing `AiConfig::model()` — the only reader of the operator's `ai_model` — while INSTALL.md:732 promises *"defaults: `claude-sonnet-4-6` for Anthropic, `gpt-4o` for OpenAI"*. **`grep -n "agent_model|agent_significance|opus|haiku" docs/INSTALL.md` → zero hits: the docs never disclose that the agent uses a different model at all.** Each model key prices **two** consumers, and the second in each pair is **not** `agent_enabled`-gated (`TeamsReplyService.php:36` → Opus; `ChimeInGate.php:30` → Haiku), so *"the agent ships dormant"* does not bound the Opus default.

**Provider — the structure is exact; two details in an earlier draft were wrong and are corrected here, because the corrected version is both truer and more useful.**
- `AiClient` throws *"Tool loop is only supported with Anthropic provider"* (`:160-162`). Exactly five tool-loop callers; only `AssistantService` (`:39-41`) and `PortalChatbotService` (`:43-44`) pre-check the provider. **`TechnicianAgent` and `TeamsReplyService` have zero `provider()` references**; `TechnicalTriager`'s single reference is the `$hasImages` multimodal branch (`:56`), not a loop guard. The dropdown offers OpenAI as a co-equal peer (`integrations.blade.php:2803-2804`) with **no warning**.
- **CORRECTED — "breaks" overstated it. All three unguarded surfaces fail SOFT.** Every throw is swallowed: `TechnicianAgent.php:140` (`catch (\Throwable)` → `notAssessed()`; its docblock `:22` says *"run() NEVER throws … fail-soft"*), `TriagePipeline::runStage:235` (records a stage error on the `TriageRun`), `TeamsReplyService.php:92` (log + return; docblock `:23-24` *"reply() never throws"*). So **all five degrade without crashing.** The real distinction is not crash-vs-degrade — it is that two return a **clear user-facing message** while the other three become **invisible no-ops**, leaving only a log line. On an OpenAI deployment the agent simply never assesses anything and nobody is told. *Silent* is the accurate word; *broken* was not.
- **CORRECTED — Opus can never reach OpenAI; Haiku can, by a path the earlier draft never cited.** For `agent_model`, both consumers feed the **tool loop**, and `AiClient.php:160-162` throws **before** `$model` is read at `:165` — so `claude-opus-4-8` is never posted to OpenAI. **But `agent_significance_model` reaches OpenAI for real:** `SignificanceGate.php:33` and `ChimeInGate.php:30` build `new AiClient(AgentConfig::significanceModel())` and call **`complete()`**, not the tool loop. `AiClient::complete()` routes by provider at call time and uses the override verbatim (`:35` → `:48` `callOpenAi(...)` → `:397` posts to `https://api.openai.com/v1/chat/completions`). Neither gate has a provider guard. So on an OpenAI deployment, **`claude-haiku-4-5` is posted to the OpenAI endpoint.** Same for the Briefing (`BriefingAssembler.php:333`, also `complete()`) — which is why briefing suggestions would silently never appear.

### 4.5 The disclosure asymmetry — sharper than filed. **Charlie's call; flagged, not pre-decided.**
- **Cockpit staged reply → dual credit, fail-closed.** `TechnicianApprovalService.php:77-78` calls `withDualDisclosure(...)` then `assertPresent(...)`, with `MissingDisclosureException` on omission.
- **Ticket-UI Draft button → no disclosure at all.** `TechnicianDisclosure` appears **nowhere** in `TicketController.php` or `ReplyDraftService.php`. The draft lands in the client-facing editor and sends under the tech's name.
- **It is not merely omission — it is instructed impersonation.** `ReplyDraftPrompts.php:32`, Rule 11: *"**Write as the technician.** … Use 'I' for your own actions"*, plus `ReplyDraftService.php:88-90` injecting *"YOU ARE: {$techName}"*.
- **The obvious defence does not survive:** "a human pressed send" cannot separate them — the cockpit path *also* has a human approver and still discloses. And `ai_reply_guidelines` feeds **both** drafters. Same operator guidelines, same client, opposite disclosure.

### 4.6 Docblocks that assert the opposite of their own code
The `SendReplyTool.php:42-45` rot class is **systemic**, not incidental. Confirmed instances: `AssistantToolDefinitions.php:8` and `AssistantService.php:177` (read-only vs two writers); `McpConfig.php:11-13` ("full-surface" vs 0/122 sensitive); `TechnicianConfig`'s `technician_operator_covering` ("Authoritative" vs zero callers); `ContactResolver.php:77` ("off by default" vs returns true); `AiActorResolver.php:11-13` (*"so the guard cannot drift between them"* — while `TechnicianConfig::requiredAiActorUserId():90-104` is a **third** reader re-implementing the check independently); `TeamsMessagesController.php:166` (claims it *"mirrors"* a check that is its **inversion** — fail-open vs fail-closed on the same key); `BriefingConfig.php:12` ("turns it on in Settings" — there is none).

### 4.7 A latent fail-open in the closing direction → **psa-su3y**
`TriageConfig::reviewAutoCloseThreshold()` (`:129-134`) uses `$value !== null ? (int) $value : 80` while its siblings use `?: 60` / `?: 20`. `Setting::getValue` returns `''` — not null — for a row holding an empty string ⇒ `(int) '' = 0` ⇒ `meetsThreshold(0)` is `>= 0` ⇒ **always true**: auto-close every `resolved|junk` assessment at any confidence. Unreachable via the current UI and inert while Path C is inert. **The sting:** the sibling four methods up carries a psa-lqlu docblock adopting exactly this defence — *"The Settings UI already validates min:5; this guards a future unvalidated write path"* — so the codebase already ruled that "the UI validates it" is insufficient, and **the one reader whose failure mode closes client tickets is the one that did not get the lesson.**

### 4.8 A safety control any unrelated click deletes → **psa-xjiz**
`technician_action_tiers` is **rebuilt from scratch** from two checkboxes on every Technician-form save (`IntegrationsController.php:1977-1984`). `TechnicianTierClassifier.php:32` honours `propose_close: block` — **the operator's only kill for the auto-close band** — and it has **no UI** (zero `propose_close` hits in the Blade). So a hand-set block is silently wiped by ticking an unrelated box. **The precedent cuts against us:** `routes/web.php:470-471` gave the kill switch its own route *specifically* so *"an unrelated settings save"* could not disarm it. Same hazard, same controller, one function below, unguarded. **Bounded today** (the band is dormant, so the loss degrades "don't propose" into "propose for approval") — **but its severity rises the moment psa-y4ft ships a threshold.**

### 4.9 Doc rot: two entire subsystems are absent from both living docs
- **Client Wiki AI** — `grep -ic "wiki" CLAUDE.md` → **0**; `docs/INSTALL.md` → **0**. Not "9 undocumented settings": the module, 5 artisan commands, 5 DB tables, 22 service files and 2 staff UI route-blocks. And the omission is against a **maintained** table — INSTALL.md:328-367 documents 38 scheduled commands including the AI siblings `triage:review-open` and `briefing:send-daily`. `wiki:maintain` is simply missing.
- **Signals / Alerts Hub** — `alerts hub`, `relay matrix`, `managed_token_label`, `signal_routes`, `SignalRoute`, `SignalHub`: **0 hits across all 12 combinations** of term × doc. The engine, both settings pages, 3 sinks and the MCP relay feeding the AI's wake lane are all absent.
- `technician:digest` — scheduled, defaults **true**, in no doc.
- **The pattern:** the living-documentation rule is not being applied to anything that lands as a settings **page** rather than as a settings **key on an existing card**.

### 4.10 INSTALL.md now contradicts itself about Path C
psa-2wwh's doc pass added, at **INSTALL.md:534**: *"It does NOT stop AI triage. The junk filter's auto-close (on by default whenever triage is enabled) and **the hourly conversation-review auto-close** run in the triage lane, which this switch does not reach."* — asserting the path exists. **INSTALL.md:796** still says: *"Optionally enable **Auto-review open tickets** for hourly conversation analysis **(recommend-only, no auto-close)**."* Same file, 262 lines apart. **CLAUDE.md:203** also still denies it.
Ground truth — `ConversationReviewer.php:99`:
```php
if (! TriageConfig::reviewAutoCloseEnabled() || \App\Support\AgentConfig::enabled()) {
    return null;
}
```
Path C needs the box ticked (**real default false**) **AND** the agent **off**. So **neither line is correct**: `:796`/CLAUDE.md:203 are flatly false; `:534` is true-but-imprecise (asserts it *runs*, omitting both preconditions). `:534` errs **safe**, so this is not an alarm — but the operator reading the *triage setup* section, the natural place to look when enabling auto-review, gets the **wrong** answer while the truth sits in the kill-switch section they have no reason to read.
**Consequence for psa-3xbv:** its premise ("both docs say no auto-close; do not touch until Charlie rules") is **half-overtaken** — psa-2wwh already touched the doc, in the safe direction. Restated: *the docs now disagree with each other, and both versions are wrong in opposite directions.* Still gated on Charlie's D1 ruling: **kill Path C's auto-close and `:796` + CLAUDE.md:203 become true with no edit** — only `:534` needs a trim.

---

## 5. PROPOSED IA — what this map changes about the companion spec's §9

The companion spec's §9 proposed a first-class **AI** section ordered by operator intent (Stop/Panic → What the AI may do on its own → What it costs → What the client sees → Per-integration plumbing). **This map does not replace that; it supplies the evidence and adds three rules it could not have known.**

1. **§9's ordering is validated, and Stop/Panic is now half-done.** psa-2wwh put the brake on its own route with explicit intent — exactly §9.1. **Finish the thought:** the brake's card must *state its own coverage gaps* (it already does, honestly, at blade `:3549-3551`) **and** the gaps must shrink (§4.1). A brake whose card lists five lanes it cannot stop is honest but not a brake.
2. **NEW — a control must not lie about its own authority.** This is the §1 pattern and it is the single highest-value rule the IA can encode. Concretely: `assistant_enabled` must gate the endpoint or lose the word "Enable"; `wiki_max_tokens_per_run` must cap something or leave the page; a relay cell for an event type with no producer must not be tickable (§3.8). **Test: can an operator take an action in this UI that they will reasonably believe did something it did not?**
3. **NEW — the intake card is the reference implementation.** `IntegrationsController.php:1779-1781` + blade `:3500-3502` ship the two channel toggles, deliberately withhold the auto-act thresholds, and *say so in plain words*. It is the only surface in the AI cluster that gets shipped-dormant right. **Copy it, don't reinvent it.**
4. **NEW — the `technician_*` prefix must be split before the IA is drawn.** One namespace currently spans a subsystem operators are told to leave **off** and the live controls for Chet's MCP writes and escalation routing (§3.4). No layout can be clear while the naming says the brake belongs to the retired thing.
5. **Where the safety-critical controls actually are, today:** 13 of 15 agent-lane keys, both per-client "never let the AI act here" gates, `propose_close:block`, both Chet escalation-routing keys, and all 5 briefing keys are **DB-edit-only**. §9's "the most safety-critical ones have no UI at all" is confirmed and quantified.

## 6. Verification record

Per this bead's own rule — **no author verifies their own claim**. The companion spec is the cautionary tale: it shipped an overstatement three sections after describing that exact failure mode, and only its reviewer caught it. So every load-bearing claim here was handed to an independent party instructed to **REFUTE**, defaulting to REFUTED where unproven. Three adversarial passes: the MCP authorization model; the claims this document's own author wrote (staleness / doc-contradiction / psa-lunj); and the Assistant + cost-trap claims.

**A record that lists only survivors is worthless. Here is what did not survive.**

| Claim | Verdict | What the refutation changed |
|---|---|---|
| Kill switch has no UI (companion spec §8) | **CONFIRMED stale** | **Strengthened, in the spec's favour** — `git grep "setValue('technician_kill_switch'" baaf2cc` → **zero hits**. The spec was **accurate when it merged** and was invalidated 40 min later by its own bead. Not "wrong": **rotted**. |
| INSTALL.md self-contradicts on Path C | **CONFIRMED** | Sharpened: `triage_review_auto_close` **appears nowhere in INSTALL.md** — `:796`'s denial is the file's only word on it, and it denies a capability **the same settings card offers one indent below** (`integrations.blade.php:3049`). |
| Neither doc line is correct | **CONFIRMED** | Fairness correction to me: `:534`'s *"on by default"* attaches **only to the junk filter**, so it does **not** claim the review auto-close is on by default — it just never says it is off. |
| psa-lunj's destination door unguarded | **CONFIRMED** | **My proposed fix was partly refuted** — `mcp_token_label` **cannot** be the discriminator (operator-authored MCP destinations carry it too), so the backfill I sketched would have swept operator rows into matrix ownership. See psa-qddf. |
| Nothing is admin-gated | **CONFIRMED** | Reframed: it is **known and deferred** (`UserRole.php:8-12`), not missed. |
| 33/205 stageable; grant = immediate | **CONFIRMED** | Counts re-derived from the registry, reproduce exactly. But **`delete_client` is a weaker example than it reads** — it has downstream guards; only the *approval* claim survives. |
| Legacy token "full-surface" oversells | **CONFIRMED** | Verified **empirically** — `toolAllowed()` executed by reflection over all 205 tools: legacy reaches **54**, denied **151**, and **0 of 122** sensitive. |
| Kill switch misses 4 MCP writes | **CONFIRMED — understated** | The brake's **own card promises the coverage it lacks** (§4.1). But blast radius is internal-only (`isPrivate: true`), which the claim omitted. |
| …"and it fails open when unset" | **REFUTED → DROPPED** | Literally true, unsupported implicature. A seeded row would hold `'0'` and cast to false identically. **Removed from §4.1.** |
| Assistant toggle is cosmetic | **CONFIRMED** | Attacked hard (routes, middleware, aliases, policies, providers, composers, `route:list -v`): genuinely no gate. Damning: `bootstrap/app.php` ships `portal.enabled` — **the codebase knows how to build a feature-gate middleware; the Assistant has none.** |
| "**Two** docblocks claim read-only" | **PARTLY-TRUE → CORRECTED** | There is **one** docblock (`AssistantToolDefinitions.php:8`) + the system prompt. `AssistantToolExecutor`'s docblock makes no such claim. Substance intact, count was wrong. |
| Opus posted to OpenAI | **REFUTED → REPLACED** | The tool loop throws **before** the model is read, so Opus **never** reaches OpenAI. **But Haiku does** — via `complete()` in `SignificanceGate`/`ChimeInGate`, a path I never cited. The corrected finding is **stronger**. |
| OpenAI "breaks" 3 tool loops | **PARTLY-TRUE → CORRECTED** | All three throws are **swallowed**; all five callers degrade without crashing. The real defect is **invisible no-ops**, not breakage. *Silent* was right; *broken* was not. |

**Four of my own claims went in; three came back altered.** That is the entire argument for the rule — and the reason §7's UNVERIFIED list should be read as seriously as the findings.

## 7. UNVERIFIED — and deliberately not probed

**The tree proves code paths, defaults and guards. It cannot prove prod.** Everything below needs Charlie or gus; none of it was probed.

1. **Every prod setting value.** Most load-bearing: is `agent_enabled` on? Was "Auto-close resolved/junk" ever ticked? Is `technician_kill_switch` engaged? Is `wiki_enabled` on (the single fact deciding whether §3.5 is live or structural)? Are any of the DEAD settings *present* in the prod table — which would mean **an operator once set them believing they worked**, changing §4.2 from "dead code" to "an operator was misled"?
2. **Which tools Chet's prod token grants.** The tree proves what *can* be granted, never what *is*. **Path B's liveness rests entirely on this** — it is the single highest-value check in this document.
3. **The deployed SHA.** If prod is behind `0e16d26`, psa-2wwh's "DB-edit-only" is **still true in prod** and the remedy is a **deploy**, not a build. §4.1's correction is a claim about the tree only.
4. **Whether any relay cell is configured in prod** — decides whether psa-qddf (§3.8) is live or latent. Only Charlie or gus can answer.
5. **Which provider prod runs** — decides whether §4.4's tool-loop breakage and the briefing's Haiku bug bite.
6. **Prod token spend.** The Opus-vs-Sonnet delta is a real pricing question not answerable from the tree.
7. **Code comments asserting prod facts are not evidence about prod.** `OperatorDelivery.php:66-83` and `TeamsMessagesController.php:74-77` assert prod's Teams configuration. That is evidence about their author's intent. It decides whether `agent_model`→Opus prices a live `TeamsReplyService` or a dead lane, and **it should not be inherited from those comments.**

---

> **The rule this document is bound by.** A doc that overstates the code is worse than no doc. Its predecessor found CLAUDE.md asserting a safety property the code lacks, a docblock asserting the opposite of its own class, and a retracted-by-reality bead note that nearly reached the owner as fact — **and then the review caught that document doing the same thing.**
>
> So: every claim here carries `file:line` **and a quoted fragment**, because this pass proved that a bare line number rots — the companion spec's own `INSTALL.md:719` and `:783` citations no longer resolve (the real lines are **:732** and **:796**), after the file grew under them in a single afternoon.
>
> **A doc, a docblock, a bead comment and this map are all snapshots. The code is the record.** Verified at `6ccc660`. Re-verify before quoting.
