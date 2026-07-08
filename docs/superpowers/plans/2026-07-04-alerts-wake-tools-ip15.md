# Alerts-Wake See+Manage Tools (psa-ip15 W2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use `- [ ]` checkboxes.

**Goal:** The see+manage MCP tool half of psa-ip15 (spec §2): 4 READ tools (email-item + phone-call) and 5 MANAGE tools (the intake front-door verbs), so a woken Chet can read and act on the `Email`/`PhoneCall` rows a reference-only signal points at. Emissions (W1) ship separately (blocked on a Mayor payload ruling).

**Architecture:** READ tools ride the existing `AssistantToolExecutor` in the grant-gated `psa_read` group (generalized from contracts-only). MANAGE tools ride `StaffPsaActionToolExecutor` in a new grant-gated `intake_manage` group; each is a thin reuse of a native service path (no bespoke reimplementation). Everything is **ungranted-by-default (dormant)** and audited like the existing surface.

**Tech Stack:** PHP 8.3, Laravel 12, PHPUnit (sqlite `:memory:`). No schema changes, no new deps.

## Global Constraints (from the spec + bead invariants)

- **Ungranted-by-default / dormant.** All 9 tools are grant-gated; no token gains them implicitly. Staff-class (chet-token), never client-portal.
- **Cross-client staff-class reads.** `McpStaffToken` scopes by tool, not client (identical to the shipped `get_ticket` read surface). `client_id` is an OPTIONAL filter on the list tools, NOT required — a woken Chet must be able to list UNLINKED/unresolved items that have no client yet. Do not invent per-token client scoping (parked platform item).
- **Token economy on lists.** `list_email_items` returns metadata + `body_preview` ONLY (the real `emails.body_preview` column — never `body_text`). `list_phone_calls` returns metadata only — never `transcription`. Full `body_text` / `transcription` only via the by-id `get_` tools. `limit` capped `max(1, min($limit, 50))`.
- **Reuse native paths — no bespoke parallel implementations** (spec §2). Manage verbs call the existing services; side-effects (note creation, observer signal emissions, notifications, auto-reopen) come for free.
- **Redaction discipline (h186).** MANAGE audit rows (`technician_action_logs.summary`) record ids + `reason` only — NEVER email body or call transcript. No MANAGE tool accepts a body/transcript argument (so `mcp_audit_logs` arguments stay clean under the generic `ActionRedactor`). Each MANAGE verb requires a `reason`. Test-lock the redaction.
- **Kill-switch.** MANAGE verbs go through `guardDirectAction()` (kill-switch) like every other `StaffPsaActionToolExecutor` mutation.
- `vendor/bin/pint` clean; the MCP test suites + `McpToolRegistryTest` green.

---

### Task 1: READ surface — generalize `psa_read`, add the 4 read tools

**Files:**
- Modify: `app/Support/McpToolRegistry.php` (rename `psaContractReadTools`→`psaReadTools`, generalize label + `sensitiveMap`; add 4 read-tool schemas)
- Modify: `app/Http/Controllers/Api/McpStaffController.php` (add 4 names to `PSA_READ_TOOLS` :174-177)
- Modify: `app/Services/Assistant/AssistantToolExecutor.php` (4 match arms + 4 handlers)
- Test: `tests/Feature/Mcp/IntakeReadToolsTest.php` (new)

**Interfaces (produced):**
- `list_email_items(direction?, unlinked?, client_id?, since?, limit?)` → `{count, email_items:[{id, direction, from_address, subject, received_at, client_id, ticket_id, dismissed_at, body_preview}]}`
- `get_email_item(email_id)` → full email incl. `body_text`, `client_id`, `ticket_id`, linkage.
- `list_phone_calls(direction?, unlinked?, client_id?, transcription_status?, since?, limit?)` → `{count, phone_calls:[{id, direction, from_number, to_number, status, started_at, duration, client_id, ticket_id, transcription_status}]}` (no transcript).
- `get_phone_call(phone_call_id)` → full call incl. `transcription`, linkage.

- [ ] **Step 1: Failing test** — create `tests/Feature/Mcp/IntakeReadToolsTest.php`. Mirror the harness in `tests/Feature/Mcp/PsaRecordsToolsTest.php` (the `token(array $tools, string $label)`, `callTool`, `decodedResult`, `listTools` helpers — copy them). Tests:
  - `test_read_tools_are_grant_gated_and_absent_from_legacy_token`: assert `list_email_items`/`get_email_item`/`list_phone_calls`/`get_phone_call` are in `McpToolRegistry::groups()['psa_read']['tools']` names AND in `allToolNames()`; assert a legacy full-surface token (`McpConfig::rotateStaffToken()`) does NOT list them.
  - `test_list_email_items_returns_preview_not_body_and_respects_filters`: create an inbound `Email` with `body_text='SECRET FULL BODY'`, `body_preview='short preview'`, one linked (ticket_id set) + one unlinked; grant a token `['list_email_items']`; call with `{unlinked: true}`; assert the unlinked one is returned, its payload has `body_preview` and NOT `body_text`, and the response JSON does not contain `'SECRET FULL BODY'`.
  - `test_get_email_item_returns_full_body`: grant `['get_email_item']`; call `{email_id}`; assert `body_text` present.
  - `test_list_phone_calls_excludes_transcript`: create a `PhoneCall` with `transcription='SECRET TRANSCRIPT'`; grant `['list_phone_calls']`; assert list payload has no transcript and the response doesn't contain `'SECRET TRANSCRIPT'`; `transcription_status` filter works.
  - `test_get_phone_call_returns_transcript`: grant `['get_phone_call']`; assert `transcription` present.
  - `test_read_limit_capped_at_50`: pass `limit: 999`, assert at most 50 (create a couple rows; assert the SQL cap by checking the handler doesn't error and returns ≤ created — or assert via a smaller cap test with 3 rows + limit 2 → 2).

  (Build `Email`/`PhoneCall` rows with `::create([...])` — no factories exist; mirror `CoreEmissionsTest.php`'s `PhoneCall::create` and `ForwardAttributionTest.php`'s `Email::create`.)

- [ ] **Step 2: Run — expect FAIL** (`php artisan test tests/Feature/Mcp/IntakeReadToolsTest.php`): tools unregistered.

- [ ] **Step 3: Generalize the `psa_read` group** in `app/Support/McpToolRegistry.php`:
  - Rename `psaContractReadTools()` (:875) → `psaReadTools()`, and append the 4 new tool schemas to its return array (keep `listClientContractsTool()`, `getContractTool()`).
  - Update its call site (:65) `$psaRead = self::shape(self::psaReadTools());`.
  - Change the group label (:77) to `'psa_read' => ['label' => 'PSA reads (sensitive)', 'sensitive' => true, 'tools' => $psaRead],`.
  - Update `sensitiveMap` (:122) tier label from `['psa', 'contracts', 'Contracts (read)', 3]` to `['psa', 'read', 'Reads', 3]` (keep group key `psa_read` — do NOT change the key, tokens reference it).
  - Add 4 schema builder methods (hand-declare filters; NO `client_id` in `required` — optional filter). Example for the list tool (mirror `listClientContractsTool()` :890 shape):
    ```php
    private static function listEmailItemsTool(): array
    {
        return [
            'name' => 'list_email_items',
            'description' => 'List inbound/outbound email items (metadata + short body_preview only; never full bodies). Staff-class, cross-client. Filter by direction, unlinked (no ticket), client_id, since.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'direction' => ['type' => 'string', 'enum' => ['inbound', 'outbound'], 'description' => 'Filter by direction.'],
                    'unlinked' => ['type' => 'boolean', 'description' => 'Only items not yet linked to a ticket.'],
                    'client_id' => ['type' => 'integer', 'description' => 'Optional: scope to one client. Omit for cross-client triage of unresolved items.'],
                    'since' => ['type' => 'string', 'description' => 'ISO-8601; only items received at/after this time.'],
                    'limit' => ['type' => 'integer', 'description' => 'Max rows (default 25, cap 50).'],
                ],
                'required' => [],
            ],
        ];
    }
    ```
    `get_email_item`: required `['email_id' => integer]`. `list_phone_calls`: optional `direction, unlinked, client_id, transcription_status (enum pending|processing|completed|failed), since, limit`. `get_phone_call`: required `['phone_call_id' => integer]`.

- [ ] **Step 4: Gate the names** — in `app/Http/Controllers/Api/McpStaffController.php` `PSA_READ_TOOLS` (:174-177), add `'list_email_items', 'get_email_item', 'list_phone_calls', 'get_phone_call'`.

- [ ] **Step 5: Handlers** — in `app/Services/Assistant/AssistantToolExecutor.php`, add 4 match arms in `execute()` and 4 private methods. These are cross-client (do NOT gate on `$this->clientId`; use it only as an optional filter from the argument). Example:
    ```php
    private function listEmailItems(array $input): array
    {
        $limit = max(1, min((int) ($input['limit'] ?? 25), 50));
        $query = \App\Models\Email::query()->orderByDesc('received_at');
        if (($d = trim((string) ($input['direction'] ?? ''))) !== '' && ($case = \App\Enums\EmailDirection::tryFrom($d)) !== null) {
            $query->where('direction', $case);
        }
        if (! empty($input['unlinked'])) {
            $query->whereNull('ticket_id');
        }
        if (! empty($input['client_id'])) {
            $query->where('client_id', (int) $input['client_id']);
        }
        if (($since = trim((string) ($input['since'] ?? ''))) !== '') {
            try { $query->where('received_at', '>=', \Carbon\Carbon::parse($since)); } catch (\Throwable) { return ['error' => 'since must be a valid ISO-8601 timestamp']; }
        }
        $items = $query->limit($limit)->get();
        return [
            'count' => $items->count(),
            'email_items' => $items->map(fn (\App\Models\Email $e) => [
                'id' => $e->id, 'direction' => $e->direction->value, 'from_address' => $e->from_address,
                'subject' => $e->subject, 'received_at' => $e->received_at?->toIso8601String(),
                'client_id' => $e->client_id, 'ticket_id' => $e->ticket_id, 'dismissed_at' => $e->dismissed_at?->toIso8601String(),
                'body_preview' => $e->body_preview,
            ])->toArray(),
        ];
    }

    private function getEmailItem(array $input): array
    {
        $id = (int) ($input['email_id'] ?? 0);
        $email = $id > 0 ? \App\Models\Email::find($id) : null;
        if (! $email) { return ['error' => 'Email item not found']; }
        return ['email_item' => [
            'id' => $email->id, 'direction' => $email->direction->value, 'from_address' => $email->from_address, 'from_name' => $email->from_name,
            'subject' => $email->subject, 'received_at' => $email->received_at?->toIso8601String(),
            'client_id' => $email->client_id, 'person_id' => $email->person_id, 'ticket_id' => $email->ticket_id,
            'dismissed_at' => $email->dismissed_at?->toIso8601String(), 'body_text' => $email->body_text,
        ]];
    }
    ```
    `listPhoneCalls`/`getPhoneCall` mirror these against `\App\Models\PhoneCall` (order by `started_at`; filters `direction` via `CallDirection`, `unlinked`→`whereNull('ticket_id')`, `client_id`, `transcription_status` via `TranscriptionStatus::tryFrom`, `since`→`started_at`); list omits `transcription`, get includes it.

- [ ] **Step 6: Run — expect PASS** (the file's suite). Then `php artisan test tests/Feature/Mcp/PsaRecordsToolsTest.php` (the existing psa_read tools `list_client_contracts`/`get_contract` still work under the renamed method).

- [ ] **Step 7: Update `McpToolRegistryTest`** if it asserts the `psa_read` label/tool-set (grep it; update the expected label/tools). Run it.

- [ ] **Step 8: Pint + commit**
  ```bash
  vendor/bin/pint app/Support/McpToolRegistry.php app/Http/Controllers/Api/McpStaffController.php app/Services/Assistant/AssistantToolExecutor.php tests/Feature/Mcp/IntakeReadToolsTest.php
  git add -A && git commit -m "psa-ip15 (W2 1/3): email-item + phone-call read tools (psa_read, cross-client, dormant)"
  ```

---

### Task 2: MANAGE infrastructure — DI, native-path promotion, new group + dispatch

**Files:**
- Modify: `app/Services/EmailService.php` (promote `autoCreateTicketFromEmail` private→public `:Ticket`)
- Modify: `app/Services/Mcp/StaffPsaActionToolExecutor.php` (add `PhoneCallService` to constructor)
- Modify: `app/Support/McpToolRegistry.php` (new `intake_manage` group + 5 schemas + `sensitiveMap`)
- Modify: `app/Http/Controllers/Api/McpStaffController.php` (predicate + dispatch branch + list exposure)
- Test: covered by Task 3's suite; add a focused infra assertion here if useful.

- [ ] **Step 1: Promote the reuse target.** In `app/Services/EmailService.php`, change `private function autoCreateTicketFromEmail(Email $email): void` (:790) to `public function autoCreateTicketFromEmail(Email $email): Ticket`, and add `return $ticket;` as the last line (after the `Log::info`, :899). Its two `routeInboundEmail` callers ignore the return — source-compatible. (Verify by reading :705-751 that neither call site breaks.)

- [ ] **Step 2: Inject `PhoneCallService`.** In `StaffPsaActionToolExecutor`'s constructor (:38-47), add `private readonly \App\Services\PhoneCallService $phoneCallService,`.

- [ ] **Step 3: New MANAGE group.** In `app/Support/McpToolRegistry.php`:
  - Add a `intakeManageTools()` method returning the 5 schemas (below).
  - Register a group: `'intake_manage' => ['label' => 'Intake email/call manage (sensitive)', 'sensitive' => true, 'tools' => self::shape(self::intakeManageTools())],` (build `$intakeManage` alongside the other `self::shape(...)` calls near :58-65).
  - `sensitiveMap` entry: `'intake_manage' => ['psa', 'write', 'Write & act', 2]` (or a dedicated tier — mirror how `psa_records` maps).
  - Each schema requires `reason`. `link_email_to_ticket`: `{email_id:int, ticket_id:int, reason:str}` required all three. `create_ticket_from_email`: `{email_id:int, reason:str, priority?:str}`. `dismiss_email_item`: `{email_id:int, reason:str}`. `link_call_to_ticket`: `{phone_call_id:int, ticket_id:int, reason:str}`. `create_ticket_from_call`: `{phone_call_id:int, reason:str, priority?:str}`. (Descriptions note these are consequential intake front-door actions, audited.)

- [ ] **Step 4: Controller wiring.** In `app/Http/Controllers/Api/McpStaffController.php`:
  - Add `INTAKE_MANAGE_TOOLS` const listing the 5 names.
  - Add `isIntakeManageTool(string $name): bool`.
  - In `toolAllowed()`, add a branch (mirror `isPsaRecordsTool`): `if ($this->isIntakeManageTool($toolName)) { return $token->allowedTools !== null && $token->allows($toolName); }`.
  - In `callTool()`, add a dispatch branch (mirror the `isPsaRecordsTool` branch :781-789) routing to `app(StaffPsaActionToolExecutor::class)->execute($name, $arguments, (int) $clientId, $this->actorLabel($request))`.
  - In `listTools()`, ensure the group's tools are exposed for granted tokens (mirror how `psa_records` is merged).

- [ ] **Step 5: Run** the existing MCP suites to confirm no regression from the DI/registry changes (`php artisan test tests/Feature/Mcp`). Pint. Commit:
  ```bash
  git add -A && git commit -m "psa-ip15 (W2 2/3): intake_manage group + DI + autoCreateTicketFromEmail public reuse"
  ```

---

### Task 3: the 5 MANAGE verbs + audit + redaction lock

**Files:**
- Modify: `app/Services/Mcp/StaffPsaActionToolExecutor.php` (5 handlers + dispatch match arms in `execute()`)
- Test: `tests/Feature/Mcp/IntakeManageToolsTest.php` (new)

**Interfaces (produced), each returns `success` + ids + `message`:**
- `link_email_to_ticket` → `$this->email->linkEmailToTicket($email, $ticket)`.
- `create_ticket_from_email` → guard `$email->client_id !== null`; `$ticket = $this->email->autoCreateTicketFromEmail($email)`.
- `dismiss_email_item` → `$this->email->dismissEmail($email, TechnicianConfig::requiredAiActorUserId())`.
- `link_call_to_ticket` → `$this->phoneCallService->linkCallToTicketWithNote($call, $ticket->id, "Linked via MCP: {$reason}")`.
- `create_ticket_from_call` → guard `$call->client_id !== null`; `$ticket = $this->phoneCallService->createTicketFromCall($call)`.

- [ ] **Step 1: Failing test** — `tests/Feature/Mcp/IntakeManageToolsTest.php` (mirror `PsaRecordsToolsTest` harness + `configureAiActor`). Tests:
  - grant-gated/absent-from-legacy (all 5).
  - `test_link_email_to_ticket_reuses_native_path_and_audits_ids_only`: create inbound `Email` (`body_text='SECRET BODY'`) + a `Ticket`; grant `['link_email_to_ticket']`; call `{email_id, ticket_id, reason:'triage'}`; assert `$email->fresh()->ticket_id === $ticket->id` AND a `TicketNote` (Reply) was created (native side-effect); assert a `technician_action_logs` row `action_type=link_email_to_ticket` whose `summary` contains the ids + 'triage' but NOT 'SECRET BODY'; assert `json_encode(McpAuditLog::all())` does not contain 'SECRET BODY'.
  - `test_create_ticket_from_email_requires_client`: email with `client_id=null` → error "client"; with client set → creates a ticket, links it, returns ticket_id.
  - `test_dismiss_email_item_sets_dismissed_and_audits_reason`: assert `dismissed_at` + `dismissed_by` set; audit has reason, no body.
  - `test_link_call_to_ticket_reuses_service`: create `PhoneCall` (`transcription='SECRET TRANSCRIPT'`) + ticket; assert `ticket_id` set; audit has ids+reason, NOT the transcript; McpAuditLog clean of transcript.
  - `test_create_ticket_from_call_requires_client`: `client_id` null → error; set → creates ticket linked to the call.
  - `test_manage_verbs_require_reason`: each without `reason` → error.
  - `test_kill_switch_blocks_manage_verbs`: `Setting::setValue('technician_kill_switch','1')` → blocked.

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Handlers** in `StaffPsaActionToolExecutor` — add match arms in `execute()` and 5 private methods. Template (link_email_to_ticket):
    ```php
    private function linkEmailToTicket(array $arguments, string $actorLabel): array
    {
        if ($error = $this->guardDirectAction()) { return $error; }
        $reason = $this->requiredString($arguments, 'reason');
        if ($reason === null) { return ['error' => 'reason is required']; }
        $email = \App\Models\Email::find((int) ($arguments['email_id'] ?? 0));
        if (! $email) { return ['error' => 'Email item not found']; }
        $ticket = Ticket::find((int) ($arguments['ticket_id'] ?? 0));
        if (! $ticket) { return ['error' => 'Ticket not found']; }
        $this->email->linkEmailToTicket($email, $ticket);
        $this->auditEntityExecution('link_email_to_ticket', 'email', (int) $email->id, (int) $ticket->client_id,
            $actorLabel, $this->mutationContentHash('link_email_to_ticket', (int) $email->id, ['ticket_id' => $ticket->id], $reason),
            'Email #'.$email->id.' linked to ticket #'.$ticket->id.': '.$reason, TechnicianConfig::requiredAiActorUserId());
        return ['success' => true, 'email_id' => $email->id, 'ticket_id' => $ticket->id, 'message' => 'Email linked to ticket.'];
    }
    ```
    The summary is composed from ids + `$reason` ONLY — never `$email->body_text`/`$call->transcription`. `create_ticket_from_email`/`create_ticket_from_call` guard `client_id !== null` and audit the resulting ticket id. `create_ticket_from_call` uses `linkCallToTicketWithNote` internally already (via `createTicketFromCall`).

- [ ] **Step 4: Run — expect PASS** (the file), then full MCP regression `php artisan test tests/Feature/Mcp`.

- [ ] **Step 5: Pint + commit**
  ```bash
  vendor/bin/pint app/Services/Mcp/StaffPsaActionToolExecutor.php tests/Feature/Mcp/IntakeManageToolsTest.php
  git add -A && git commit -m "psa-ip15 (W2 3/3): 5 intake manage verbs (native-path reuse, ids+reason audit lock)"
  ```

---

## Final Verification

- [ ] `vendor/bin/pint --test` clean; `php artisan test tests/Feature/Mcp` green; then full `php artisan test`.
- [ ] `/soundpsa-review-pr` on the branch; address findings (esp. redaction locks + grant gates).
- [ ] PR + comment on psa-ip15 + notify Mayor; hold merge. Flag in the PR body: (a) `psa_read` group generalized from contracts-only; (b) `autoCreateTicketFromEmail` promoted private→public; (c) `PhoneCallService` added to the executor constructor; (d) reads are cross-client staff-class (client_id optional) per the spec's authz posture; (e) this is W2 only — emissions (W1) follow after the Mayor's payload ruling.
