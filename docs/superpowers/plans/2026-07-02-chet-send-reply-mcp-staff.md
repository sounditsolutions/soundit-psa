# Chet Spike-3: Held `send_reply` via `mcp/staff`

> **Work bead:** `psa-b5jk`
> **Executor:** `developer` on Codex, after Mayor plan review.
> **Mode:** TDD. Write each failing test first, watch it fail, then implement the smallest code to pass.

## Goal

Expose the existing held client-reply action to a Chet-labeled `mcp/staff` token as a scoped `send_reply` tool. The MCP call should create a `TechnicianRun(action_type=send_reply, state=awaiting_approval)` that appears in the existing cockpit approval lane. It must never create a client reply note, send email, or auto-execute from the MCP call.

The implementation should reuse the current held reply machinery:

- `App\Services\Agent\SendReplyTool` drafts through `TechnicianReplyDrafter`, records the held run, supersedes older held reply drafts, and dispatches `TechnicianActionGate` with a throwing tripwire executor.
- `TechnicianTierClassifier` hard-codes `send_reply` to `Approve`.
- `TechnicianApprovalService` and the cockpit already own the only real send path.

## Source Context

- `psa-b5jk` requires `send_reply` to move from `CHET_DENIED_WRITE_TOOLS` into `CHET_CLIENT_SCOPED_WRITE_TOOLS`.
- Prior internal bridge spec: `docs/superpowers/specs/2026-07-01-gascity-chet-teams-bridge-design.md`.
- Prior Chet MCP plan, already implemented: `docs/superpowers/plans/2026-07-01-chet-teams-psa-mcp-tools.md`.
- Cockpit approval invariant: `docs/superpowers/plans/2026-06-24-ai-technician-phase-1b-cockpit.md`.

## Invariants

- **Held only:** MCP `send_reply` creates or revives a held run only. No `TicketNote` with `ai_authored=true` and no `technician_action_logs.result_status=executed` may appear from the MCP call.
- **Server-written body:** the MCP schema accepts `ticket_id` and `reason`; it does not accept a reply body or recipient. Any smuggled `body`, `to`, or similar input is ignored and must not land in immutable audit rows.
- **Chet client scope:** Chet tokens must supply a positive `client_id`; the ticket must belong to that client. This mirrors Chet `propose_close`.
- **Token scope:** tools are listed/callable only when the token allows `send_reply`.
- **No generic assistant widening:** do not add `send_reply` to `AssistantToolDefinitions` or the normal assistant executor surface. Route it explicitly at `McpStaffController`, like a controlled action tool.
- **Cockpit path unchanged:** approval, disclosure, recipient derivation, note creation, and email send remain solely in `TechnicianApprovalService`.

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
  - No `body`, no `to`, no confidence.
- Include it in the registry next to `propose_close` so token admins can grant it deliberately.
- Include it in `McpStaffController::listTools()`.
- When the request token is Chet and the tool is in `CHET_CLIENT_SCOPED_WRITE_TOOLS`, inject `client_id` into the returned schema and mark it required. This will make both `propose_close` and `send_reply` honest to Chet at tool-list time.

Red tests:
- Registry contains `send_reply` and no duplicate tool names.
- MCP token page renders `send_reply`.
- A Chet token scoped to `send_reply` sees it in `tools/list` with required `client_id`, `ticket_id`, and `reason`, and without `body`/`to`.

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
- Add `SendReplyTool::executeHeld(Ticket $ticket, array $input): string` as the MCP-facing seam. It should call the same held implementation as `execute()` with no correction provenance.
- Route MCP `send_reply` explicitly in `McpStaffController` before the generic `AssistantToolExecutor`.
- Validate `ticket_id` and non-empty `reason`, load the ticket, and call `executeHeld`.
- For non-Chet scoped tokens, mirror existing `propose_close` behavior: derive the client from the ticket and keep the tool held-only. For Chet tokens, keep the stricter explicit `client_id` boundary.

Red tests:
- Chet token with `send_reply` scope creates exactly one `TechnicianRun` in `AwaitingApproval`.
- The run stores the body returned by a mocked `TechnicianReplyDrafter`, `proposed_meta.to`, and `proposed_meta.reasons`.
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
- If `body`, `Body`, `to`, or similar fields are supplied, do not persist them in `McpAuditLog.arguments`.
- Ensure failure-path audit rows also use the sanitizer.

Red tests:
- A smuggled `body` and `to` do not appear in MCP audit arguments.
- Boundary failure with a smuggled body does not persist that body in audit arguments or error text.

### 5. Documentation

Files:
- `docs/INSTALL.md`

Changes:
- Update the MCP staff tools section to mention `send_reply` as a sensitive, token-scoped, held-only AI Technician action.
- State that Chet must pass `client_id`, `ticket_id`, and `reason`, and that the reply body is server-drafted and cockpit-approved.

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
- Verify the only executable send remains cockpit approval.
- Verify audit logs cannot retain attempted MCP-supplied body text.
- Verify the registry/UI can grant `send_reply` intentionally, while unscoped tokens still cannot call it.
