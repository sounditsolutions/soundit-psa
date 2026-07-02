# Chet Spike-3: Held `send_reply` via `mcp/staff`

> **Work bead:** `psa-b5jk`
> **Executor:** `developer` on Codex, after Mayor plan review.
> **Mode:** TDD. Write each failing test first, watch it fail, then implement the smallest code to pass.

## Goal

Expose the existing held client-reply action to a Chet-labeled `mcp/staff` token as a scoped `send_reply` tool. The MCP call should create a `TechnicianRun(action_type=send_reply, state=awaiting_approval)` that appears in the existing cockpit approval lane. It must never create a client reply note, send email, or auto-execute from the MCP call.

The implementation should reuse the current held reply machinery:

- `App\Services\Agent\SendReplyTool` records the held run, supersedes older held reply drafts, and dispatches `TechnicianActionGate` with a throwing tripwire executor.
- When Chet supplies `body`, that body is held verbatim and no native drafter runs. When `body` is absent, `TechnicianReplyDrafter` remains the fallback.
- `TechnicianTierClassifier` hard-codes `send_reply` to `Approve`.
- `TechnicianApprovalService` and the cockpit already own the only real send path.

## Source Context

- `psa-b5jk` requires `send_reply` to move from `CHET_DENIED_WRITE_TOOLS` into `CHET_CLIENT_SCOPED_WRITE_TOOLS`.
- Mayor plan review 2026-07-02 23:35Z superseded the server-drafted-only assumption: Chet is now the primary reply brain, so Chet-authored `body` is first-class and zero native LLM tokens are spent when provided.
- Prior internal bridge spec: `docs/superpowers/specs/2026-07-01-gascity-chet-teams-bridge-design.md`.
- Prior Chet MCP plan, already implemented: `docs/superpowers/plans/2026-07-01-chet-teams-psa-mcp-tools.md`.
- Cockpit approval invariant: `docs/superpowers/plans/2026-06-24-ai-technician-phase-1b-cockpit.md`.

## Invariants

- **Held only:** MCP `send_reply` creates or revives a held run only. No `TicketNote` with `ai_authored=true` and no `technician_action_logs.result_status=executed` may appear from the MCP call.
- **Chet-authored body is first-class:** the MCP schema accepts optional `body`. If present, the exact body becomes `TechnicianRun.proposed_content`. If absent, the server drafts a fallback body through `TechnicianReplyDrafter`.
- **Recipient remains server-derived:** the MCP schema still has no `to`; approval-time send derives the recipient from the ticket contact.
- **Audit never stores body:** `McpAuditLog.arguments` stores `body_length`, never the body text, on success and failure paths.
- **Chet client scope:** Chet tokens must supply a positive `client_id`; the ticket must belong to that client. This mirrors Chet `propose_close`.
- **Token scope:** tools are listed/callable only when the token allows `send_reply`.
- **No generic assistant widening:** do not add `send_reply` to `AssistantToolDefinitions` or the normal assistant executor surface. Route it explicitly at `McpStaffController`, like a controlled action tool.
- **Cockpit path unchanged:** approval, disclosure, recipient derivation, note creation, and email send remain solely in `TechnicianApprovalService`.
- **Attribution:** `TechnicianRun.proposed_meta.drafted_by` records `mcp-staff:<label>` for supplied MCP bodies and `technician-drafter` for fallback drafts.

## Planned Changes

### 1. Registry and MCP Listing

Files:
- `app/Support/McpToolRegistry.php`
- `app/Http/Controllers/Api/McpStaffController.php`
- `tests/Feature/Mcp/McpToolRegistryTest.php`
- `tests/Feature/Settings/McpTokensPageTest.php`
- New/updated Chet list-schema assertions in `tests/Feature/Chet/ChetSendReplyTest.php`

Changes:
- Add `McpToolRegistry::sendReplyTool()` with schema:
  - `ticket_id` integer, required.
  - `reason` string, required.
  - `body` string, optional.
  - No `to`, no confidence.
- Include it in the registry next to `propose_close` so token admins can grant it deliberately.
- Include it in `McpStaffController::listTools()`.
- When the request token is Chet and the tool is in `CHET_CLIENT_SCOPED_WRITE_TOOLS`, inject `client_id` into the returned schema and mark it required. This will make both `propose_close` and `send_reply` honest to Chet at tool-list time.

Red tests:
- Registry contains `send_reply` and no duplicate tool names.
- MCP token page renders `send_reply`.
- A Chet token scoped to `send_reply` sees it in `tools/list` with required `client_id`, `ticket_id`, and `reason`, optional `body`, and no `to`.

### 2. Chet Boundary Rules

Files:
- `app/Http/Controllers/Api/McpStaffController.php`
- `tests/Feature/Chet/ChetSendReplyTest.php`
- Update `tests/Feature/Chet/ChetProposeCloseTest.php`
- Update `tests/Feature/Chet/AddTicketNoteToolTest.php`

Changes:
- Remove `send_reply` from `CHET_DENIED_WRITE_TOOLS`.
- Add `send_reply` to `CHET_CLIENT_SCOPED_WRITE_TOOLS`.
- Refactor the current Chet `propose_close` ticket/client check into a helper that applies to `propose_close` and `send_reply`.
- Keep `create_ticket`, `close_ticket`, and `tactical_run_diagnostic` hard-denied for Chet even if scoped.

Red tests:
- Chet `send_reply` without `client_id` is rejected at the MCP boundary and creates no run.
- Malformed `client_id` is rejected.
- Cross-client `ticket_id` is rejected and creates no run.
- Token without `send_reply` scope cannot list or call it.
- Chet-denied tools still exclude `create_ticket`, `close_ticket`, and `tactical_run_diagnostic`; update old tests that currently expect `send_reply` to be denied.

### 3. Held Reply Execution Seam

Files:
- `app/Services/Agent/SendReplyTool.php`
- `app/Http/Controllers/Api/McpStaffController.php`
- `tests/Feature/Chet/ChetSendReplyTest.php`
- `tests/Feature/Agent/SendReplyToolTest.php`
- `tests/Feature/Agent/McpStaffProposeCloseTest.php`

Changes:
- Add `SendReplyTool::executeHeld(Ticket $ticket, array $input, string $draftedBy): string` as the MCP-facing seam. If `body` is present and non-empty, use it verbatim as the held draft and do not call `TechnicianReplyDrafter`. If `body` is absent, call the same fallback drafter path as the native `execute()` with `drafted_by=technician-drafter`.
- Route MCP `send_reply` explicitly in `McpStaffController` before the generic `AssistantToolExecutor`.
- Validate `ticket_id` and non-empty `reason`, load the ticket, and call `executeHeld`.
- For non-Chet scoped tokens, mirror existing `propose_close` behavior: derive the client from the ticket and keep the tool held-only. For Chet tokens, keep the stricter explicit `client_id` boundary.

Red tests:
- Chet token with `send_reply` scope and `body` creates exactly one `TechnicianRun` in `AwaitingApproval`.
- The run stores the supplied body verbatim, `proposed_meta.reasons`, and `proposed_meta.drafted_by=mcp-staff:chet`.
- Bodyless Chet call invokes the mocked `TechnicianReplyDrafter`, stores the fallback body, `proposed_meta.to`, `proposed_meta.reasons`, and `proposed_meta.drafted_by=technician-drafter`.
- A tier-map misconfiguration of `{"send_reply":"auto"}` still does not execute.
- No `TicketNote` reply is created by the MCP call.
- Generic scoped token without `send_reply` remains denied; generic scoped token with `send_reply` can create a held draft by `ticket_id`.

### 4. Audit Hygiene

Files:
- `app/Http/Controllers/Api/McpStaffController.php`
- `tests/Feature/Chet/ChetSendReplyTest.php`

Changes:
- Add a `send_reply` audit argument sanitizer that preserves only safe routing/judgment fields:
  - `ticket_id`
  - `client_id` if present
  - `reason`
- `body_length` when a body argument is present.
- If `body`, `Body`, `to`, or similar fields are supplied, do not persist those raw values in `McpAuditLog.arguments`.
- Ensure failure-path audit rows also use the sanitizer.

Red tests:
- A supplied `body` does not appear in MCP audit arguments; `body_length` does.
- Boundary failure with a supplied body does not persist that body in audit arguments or error text.

### 5. Documentation

Files:
- `docs/INSTALL.md`

Changes:
- Update the MCP staff tools section to mention `send_reply` as a sensitive, token-scoped, held-only AI Technician action.
- State that Chet must pass `client_id`, `ticket_id`, and `reason`, may pass `body`, and that any supplied body is held for cockpit approval. Bodyless calls fall back to the server drafter.

## Test Plan

Focused during implementation:

```bash
php artisan test \
  tests/Feature/Chet/ChetSendReplyTest.php \
  tests/Feature/Chet/ChetProposeCloseTest.php \
  tests/Feature/Chet/AddTicketNoteToolTest.php \
  tests/Feature/Agent/McpStaffProposeCloseTest.php \
  tests/Feature/Agent/SendReplyToolTest.php \
  tests/Feature/Technician/Cockpit/TechnicianApprovalServiceTest.php \
  tests/Feature/Technician/Cockpit/CockpitQueryTest.php \
  tests/Feature/Mcp/McpToolRegistryTest.php \
  tests/Feature/Settings/McpTokensPageTest.php
```

Broader slice before PR:

```bash
php artisan test tests/Feature/Chet tests/Feature/Mcp tests/Feature/Agent tests/Feature/Technician/Cockpit
vendor/bin/pint --dirty
vendor/bin/pint --test
```

## Review Focus

- Verify `send_reply` never flows through `AssistantToolExecutor` or any direct note/email path.
- Verify Chet `client_id` is enforced before `SendReplyTool` is called.
- Verify supplied `body` is held verbatim and attributed to `mcp-staff:<label>`.
- Verify bodyless calls use the native drafter fallback and are attributed to `technician-drafter`.
- Verify the only executable send remains cockpit approval.
- Verify audit logs cannot retain MCP-supplied body text and store only `body_length`.
- Verify the registry/UI can grant `send_reply` intentionally, while unscoped tokens still cannot call it.
