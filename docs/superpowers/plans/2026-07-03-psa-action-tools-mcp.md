# PSA Action Tools over Staff MCP

> **Work bead:** `psa-sx99`
> **Executor:** `developer`
> **Mode:** plan-first, then strict TDD implementation after Mayor review.

## Goal

Expose the next PSA action tools through `/api/mcp/staff`:

- `send_email`
- `stage_email`
- `write_public_note`
- `stage_public_note`
- `propose_merge`

The direct client-facing tools (`send_email`, `write_public_note`) must exist as separately grantable tools but ship ungranted by default. The staged tools create cockpit-held `TechnicianRun` drafts/proposals for human approval. `propose_merge` is also held: the MCP call records the proposal, and cockpit approval executes the merge.

Recipient and visibility decisions stay server-side. Callers provide ticket IDs, reasons, and body text where needed; they do not provide trusted recipient addresses, note visibility, author identity, or merge execution authority.

## Authenticated Source Context

- `psa-sx99`: "PSA action tools: send_email + stage_email + write_public_note (+staged) + propose_merge."
- `psa-sx99`: staged variants should reuse the held-run machinery from `psa-b5jk`: `executeHeld`, `drafted_by` attribution, and `body_length` audit instead of body retention in MCP audit.
- `psa-sx99`: direct client-facing tools ship ungranted by default.
- `psa-sx99`: recipient/visibility derivation stays server-side.
- Parent epic `psa-fhjb`: per-token grants, dangerous tools ungranted, and public OSS platform behavior with no tenant-specific baked policy.

There are no bead comments on `psa-sx99` at plan time; this plan is based on the bead description and current source.

## Current Shape

- `McpStaffController` is the staff MCP boundary. It builds the runtime `tools/list`, injects `client_id` for scoped tools, enforces token grants, dispatches `tools/call`, and writes `McpAuditLog`.
- `McpToolRegistry` feeds both runtime definitions and the MCP token settings UI. Sensitive groups (`wiki_write`, `bridge`) require explicit token scopes; most legacy/general tools still allow legacy full-surface tokens.
- `send_reply` is the closest pattern:
  - `McpToolRegistry::sendReplyTool()` defines the schema.
  - `McpStaffController::sendReply()` validates ticket/reason/body and calls `SendReplyTool::executeHeld()`.
  - `SendReplyTool::executeHeld()` writes an `AwaitingApproval` `TechnicianRun` with `proposed_content`, `proposed_meta['drafted_by']`, idempotent hash, and an awaiting-approval `TechnicianActionLog`.
  - `McpStaffController::auditSendReplyArguments()` stores `body_length`, never body text.
  - `TechnicianApprovalService::approveAndSend()` performs the human-approved send with a CAS latch and a signed approval grant.
- `propose_close` is held over MCP via `ProposeCloseTool::executeHeld()` and approves through `TechnicianApprovalService::approveClose()`.
- `TicketService::addNote()` already creates public/private notes and handles ticket touch/notifications.
- `EmailService::sendTicketReplyNote()` sends ticket-threaded client email to a ticket contact, records the outbound `Email`, and can link the email back to the note.
- `TicketService::mergeTickets()` is the existing merge executor. It validates same-client/self/already-merged cases, moves notes/calls/emails/assets, closes the secondary ticket, and writes merge audit notes.
- The cockpit UI already renders any `AwaitingApproval` run, but approval dispatch is currently limited to `propose_close`, `send_reply`, and `propose_resolution`.

## Assumptions For Review

- The staged public-note tool name will be `stage_public_note`.
- `send_email` means "send a ticket reply email to the ticket contact" and will also write the client-visible reply note into the ticket timeline, linked to the outbound email when Graph send succeeds.
- `write_public_note` means "write a public, portal-visible ticket note" and does not email the contact.
- All client-visible AI-authored text will use the existing `TechnicianDisclosure` before it is stored/sent.
- `propose_merge` records `ticket_id = primary_ticket_id` on the `TechnicianRun`; `proposed_meta` carries both ticket IDs/display IDs so the cockpit can render the pair.
- Cockpit-approved merges should call `TicketService::mergeTickets($primary, $secondary, $approverId)` so the existing merge notes name the human approver, while `TechnicianActionLog` still records the AI action plus `approver_user_id`.

## Design

### 1. Add explicit-grant PSA action tool definitions

Files:

- `app/Support/McpToolRegistry.php`
- `app/Http/Controllers/Api/McpStaffController.php`
- `tests/Feature/Mcp/McpToolRegistryTest.php`

Add a new sensitive registry group, likely:

```php
'psa_action' => ['label' => 'PSA actions (sensitive)', 'sensitive' => true, 'tools' => ...]
```

Tools:

- `send_email`
  - Required: `ticket_id`, `body`
  - Optional: `reason`
  - No `to`, `cc`, `subject`, `visibility`, or author fields.
- `stage_email`
  - Required: `ticket_id`, `body`, `reason`
  - Holds the body verbatim for cockpit review.
- `write_public_note`
  - Required: `ticket_id`, `body`
  - Optional: `reason`
  - No `is_private` or `note_type`.
- `stage_public_note`
  - Required: `ticket_id`, `body`, `reason`
  - Holds the body verbatim for cockpit review.
- `propose_merge`
  - Required: `primary_ticket_id`, `secondary_ticket_id`, `reason`
  - Server validates both tickets and derives client.

The boundary should inject and require `client_id` for all five tools. On call, the server re-validates the referenced ticket(s) belong to that client. This follows the current Chet-held-action pattern and avoids trusting a bare ticket ID for client-facing writes.

Add an `EXPLICIT_GRANT_ONLY_TOOLS` list in `McpStaffController` for these five names. `toolAllowed()` should require `allowedTools !== null && token->allows($tool)` for them, so legacy full-surface/null-tool tokens do not list or call the new direct action surface.

### 2. Add a PSA action execution service

New file:

- `app/Services/Mcp/StaffPsaActionToolExecutor.php`

Responsibilities:

- Parse positive integer IDs and non-empty body/reason values.
- Validate client scope:
  - one-ticket actions: ticket exists and `ticket.client_id === client_id`
  - merge actions: both tickets exist, both match `client_id`, and the pair passes the same same-client/self/already-merged guard expected by `TicketService::mergeTickets`
- Resolve actor identity using `TechnicianConfig::requiredAiActorUserId()` and `TechnicianConfig::aiActorName()`.
- For direct `send_email`:
  - derive recipient from `$ticket->contact?->email`
  - fail cleanly if no contact email
  - apply disclosure to the body
  - create a public `Reply` note with `ai_authored=true`
  - call `EmailService::sendTicketReplyNote($ticket, $note, $ticket->contact->email, [])`
  - link `email_id` on the note when an `Email` is returned
- For direct `write_public_note`:
  - apply disclosure to the body
  - create a public `Note` with `ai_authored=true`
  - do not call `EmailService`
- For staged `stage_email` and `stage_public_note`:
  - create or revive an `AwaitingApproval` `TechnicianRun`
  - `action_type` is the tool name
  - `proposed_content` is the body
  - `proposed_meta` includes `reasons`, `drafted_by`, and server-derived destination metadata such as contact email/display name for cockpit display only
  - `content_hash` is stable: `<action_type>:<ticket_id>:<body>`
  - supersede older active staged drafts of the same action type on the same ticket, mirroring latest-held-reply-wins for `send_reply`
  - dispatch through `TechnicianActionGate` with a throwing/no-op executor so the gate writes exactly one awaiting-approval action log and can never auto-execute
- For `propose_merge`:
  - create or revive an `AwaitingApproval` `TechnicianRun`
  - `ticket_id` and `client_id` point to the primary ticket
  - `proposed_content` is the reason
  - `proposed_meta` includes `primary_ticket_id`, `secondary_ticket_id`, display IDs, subjects, and `drafted_by`
  - `content_hash` is stable: `propose_merge:<primary>:<secondary>:<reason>`
  - dispatch through the gate as awaiting approval

### 3. Extend MCP dispatch and audit redaction

File:

- `app/Http/Controllers/Api/McpStaffController.php`

Changes:

- Add the five tool definitions to runtime `tools/list`.
- Require `client_id` for the five action tools for all tokens.
- Route calls to the new action executor instead of `AssistantToolExecutor`.
- Extend audit redaction:
  - `send_email`, `stage_email`, `write_public_note`, and `stage_public_note` store `body_length`, never `body`.
  - Unknown/malicious recipient or visibility inputs are not retained in audit.
  - `propose_merge` stores ticket IDs plus `reason_length`, not raw reason text.
- Keep `McpAuditLog` at the boundary as the only MCP audit path.

### 4. Extend cockpit approval execution

Files:

- `app/Services/Technician/TechnicianApprovalService.php`
- `app/Http/Controllers/Web/TechnicianCockpitController.php`
- `app/Services/Technician/Cockpit/CockpitQuery.php`
- `resources/views/cockpit/index.blade.php`
- `tests/Feature/Technician/Cockpit/*`

Changes:

- Add approval methods:
  - `approveStagedEmail(TechnicianRun $run, string $body, int $approverId)`
  - `approveStagedPublicNote(TechnicianRun $run, string $body, int $approverId)`
  - `approveMerge(TechnicianRun $run, int $approverId)`
- Each method uses the existing CAS latch (`claimForExecution()`), `TechnicianApprovalGrant`, `TechnicianActionGate`, release-on-decline pattern, and `approver_user_id` attribution.
- `approveStagedEmail`:
  - uses the edited cockpit body
  - applies disclosure
  - creates a public `Reply` note
  - sends through `EmailService::sendTicketReplyNote()` after the gate transaction
  - links `email_id` when available
- `approveStagedPublicNote`:
  - uses the edited cockpit body
  - applies disclosure
  - creates a public `Note`
  - does not email
- `approveMerge`:
  - re-fetches both tickets from `proposed_meta`
  - revalidates before execution
  - runs `TicketService::mergeTickets($primary, $secondary, $approverId)` inside the gate executor
  - advances the run to `Done`
  - does not send client email
- `TechnicianCockpitController::approve()` dispatches by action type and fail-closes unknown types.
- Cockpit view adds distinct labels/buttons:
  - `stage_email`: editable message, "Send email"
  - `stage_public_note`: editable message, "Publish public note"
  - `propose_merge`: read-only proposal summary, "Approve merge"
- Sorting keeps client-facing approvals before close/merge proposals. `stage_email` and `stage_public_note` should sort with `send_reply`.

### 5. Tier classifier defense-in-depth

File:

- `app/Services/Technician/TechnicianTierClassifier.php`

Hard-code these held action types to `Approve`, regardless of tier-map misconfiguration:

- `stage_email`
- `stage_public_note`
- `propose_merge`

Direct tools do not use the Technician gate because their grant is the MCP token scope plus direct service-side validation; they are kept out of legacy full-surface tokens by `EXPLICIT_GRANT_ONLY_TOOLS`.

### 6. Documentation

File:

- `docs/INSTALL.md`

Update the staff MCP section:

- new PSA action tools and their held/direct posture
- direct tools require explicit token grants
- staged tools appear in cockpit and require human approval
- recipient/visibility are derived server-side
- MCP audit records lengths for body-bearing tools, not bodies

## TDD Sequence

### Task 1: Registry and grant posture

Red tests:

- `McpToolRegistryTest` includes the new `psa_action` sensitive group and all five tools.
- A legacy full-surface token does not list or call `send_email` / `write_public_note`.
- A scoped token sees only the explicitly granted PSA action tools.
- Runtime `tools/list` schemas require `client_id` and do not expose `to`, `cc`, `subject`, `is_private`, or `note_type`.

Green:

- Add registry group/definitions.
- Add explicit-grant-only enforcement and client-id injection.

### Task 2: Direct client-facing actions

Red tests:

- `send_email` with a granted token writes an AI-authored public reply note, sends through `EmailService::sendTicketReplyNote()` to the ticket contact, ignores an attacker-supplied recipient field, links the email, and audits `body_length` without body text.
- `send_email` fails cleanly when the ticket has no contact email and creates no note/email.
- `write_public_note` writes an AI-authored public note, does not call `EmailService`, ignores malicious `is_private` / `note_type`, and audits `body_length` without body text.
- Both tools reject missing/malformed `client_id` and cross-client ticket IDs without side effects.

Green:

- Add the PSA action execution service and MCP dispatch.

### Task 3: Staged email/public note

Red tests:

- `stage_email` creates an `AwaitingApproval` run with verbatim `proposed_content`, `drafted_by = mcp-staff:<label>`, `reasons`, server-derived contact metadata, awaiting-approval action log, no note/email, and MCP audit `body_length`.
- `stage_public_note` does the same with action type `stage_public_note`.
- Repeating identical staged calls is idempotent; a newer staged draft supersedes the older active draft for the same ticket/action type.
- A token without the staged tool scope is denied.
- Cross-client/malformed client IDs create no run.

Green:

- Implement held-run staging for both action types.

### Task 4: Cockpit approval for staged actions

Red tests:

- Approving `stage_email` sends the edited body to the derived contact, writes a public reply note with disclosure, advances the run to `Done`, writes an executed action log with `approver_user_id`, and double-approve is single-use.
- If the gate declines after claim, the run returns to `AwaitingApproval`.
- Approving `stage_public_note` writes a public note with disclosure, does not email, advances the run to `Done`, and is single-use.
- Cockpit route does not require a body for merge/close actions, but does require a body for staged email/public note actions.
- Cockpit view labels staged tools distinctly and never falls through unknown action types into a send path.

Green:

- Extend `TechnicianApprovalService`, controller dispatch, and cockpit view.

### Task 5: Propose merge

Red tests:

- `propose_merge` stages an `AwaitingApproval` run and does not mutate either ticket before approval.
- Cross-client, self-merge, already-merged, and missing-ticket inputs produce MCP errors and no run.
- Approving the run calls the existing merge behavior: secondary gets `parent_ticket_id`, secondary closes, related notes/emails/calls move, primary gets merge audit note, run becomes `Done`, and action log records `executed`.
- Approval revalidates; if a human merged/closed the secondary between staging and approval, the cockpit returns a clear non-executed result and the run is retryable or marked handled according to the stale state.

Green:

- Implement held merge proposal and approval executor.

### Task 6: Docs and final verification

Focused tests:

```bash
php artisan test \
  tests/Feature/Mcp/McpToolRegistryTest.php \
  tests/Feature/Mcp/McpStaffTokenGateTest.php \
  tests/Feature/Mcp/PsaActionToolsTest.php \
  tests/Feature/Technician/Cockpit/TechnicianApprovalServiceTest.php \
  tests/Feature/Technician/Cockpit/CockpitControllerTest.php \
  tests/Feature/Technician/Cockpit/CockpitQueryTest.php
```

Broader regression tests:

```bash
php artisan test tests/Feature/Mcp tests/Feature/Chet tests/Feature/Technician/Cockpit tests/Feature/Agent tests/Feature/Tickets
```

Final gates:

```bash
vendor/bin/pint --test
php artisan test
```

After production code changes, restart the local Laravel services:

```bash
sudo systemctl restart soundit-psa-queue.service soundit-psa-dev.service
systemctl is-active soundit-psa-queue.service soundit-psa-dev.service
```

For the PR-ready gate, use the Mayor-confirmed convention for this bead. If no bead comment changes it during plan review, default to the `gc prime` gate: Pint clean, relevant tests green, PR pushed, and `/soundpsa-review-pr <branch>` clean before reporting ready.
