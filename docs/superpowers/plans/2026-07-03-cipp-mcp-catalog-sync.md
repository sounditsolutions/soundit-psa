# CIPP MCP Catalog Sync

> **Work bead:** `psa-es22`
> **Executor:** `developer`
> **Mode:** audit-first plan for Mayor review; no implementation until approved.

## Goal

Surface the CIPP MCP catalog as grantable PSA staff MCP tools without hand-writing 200+
definitions. Preserve the existing curated `cipp_*` tools, add the missing relayed CIPP
catalog dynamically, keep per-token grants as the control plane, and keep dynamic CIPP
responses reference-only rather than returning raw upstream payloads.

## Authenticated Source Context

- `psa-es22`: investigate what the current PSA CIPP relay exposes today versus the full
  CIPP MCP catalog, then design catalog sync/import before building.
- `psa-es22`: prefer dynamic catalog import over hand-written entries because the CIPP catalog
  will drift.
- `psa-es22`: per-token grants apply; reference-only payload canon from `psa-fn58` applies to
  returned payloads; CIPP writes belong in a sensitive grant-gated tier.
- Parent epic `psa-fhjb`: populate the MCP tool surface, with dangerous tools ungranted and
  no MSP-specific or Chet-specific policy baked into public platform code.

There are no bead comments on `psa-es22` at plan time.

## Audit Findings

### Current PSA Exposure

The PSA currently exposes **17** CIPP tool names:

- `cipp_list_users`
- `cipp_list_mailboxes`
- `cipp_list_licenses`
- `cipp_list_devices`
- `cipp_list_sign_ins`
- `cipp_list_groups`
- `cipp_list_user_groups`
- `cipp_list_mailbox_permissions`
- `cipp_list_mailbox_rules`
- `cipp_list_defender_state`
- `cipp_list_conditional_access_policies`
- `cipp_list_user_conditional_access`
- `cipp_list_audit_logs`
- `cipp_list_message_trace`
- `cipp_list_mail_quarantine`
- `cipp_list_user_mfa_methods`
- `cipp_list_oauth_apps`

Where they come from:

- `TriageToolDefinitions::cippTools()` is the static definition source.
- `AssistantToolDefinitions::getTools(hasClient: true)` includes those definitions when
  `CippConfig::isConfigured()`.
- `/api/mcp/staff` lists them as client-scoped tools and injects `client_id`.
- `McpToolRegistry::groups()` always includes the same 17 in the `integration` grant UI group.
- `AssistantToolExecutor` and `TriageToolExecutor` both switch on the same 17 names.
- `CippMcpToolRelay` is hand-mapped to upstream `ExecMCP?tools=<name>` only when
  `cipp_mcp_enabled` is on; otherwise the curated tools keep their legacy `CippClient::get()`
  path.

The relay has good safety behavior for those 17: tenant mapping is server-derived from the PSA
client, unadvertised query-shaping arguments are rejected, returned rows are bounded/projected,
and projected free text is fenced/sanitized. It does **not** discover or import CIPP `tools/list`.

### Upstream CIPP MCP Catalog

I cloned upstream `KelvinTegelaar/CIPP-API` at commit
`3d835b5dab4c1749da50cdbd15e37d0750d29634` (`2026-07-02`, version bump 10.5.7) and applied
CIPP's own `Get-CippMcpToolList` predicate to `Config/openapi.json`.

Source facts:

- `Invoke-ExecMcp.ps1` implements JSON-RPC `initialize`, `tools/list`, and `tools/call`, and
  describes the MCP endpoint as read-only OpenAPI projection through `openapi.json`:
  https://raw.githubusercontent.com/KelvinTegelaar/CIPP-API/3d835b5dab4c1749da50cdbd15e37d0750d29634/Modules/CIPPHTTP/Public/Entrypoints/HTTP%20Functions/CIPP/MCP/Invoke-ExecMcp.ps1
- `Get-CippMcpToolList.ps1` builds tools from operations whose `x-cipp-role` ends in `.Read`,
  excludes mutation-looking endpoint names, inlines query/body JSON schema, marks tools with
  `annotations.readOnlyHint = true`, and supports `tags=`, `tools=`, and `first=/limit=`
  filters:
  https://raw.githubusercontent.com/KelvinTegelaar/CIPP-API/3d835b5dab4c1749da50cdbd15e37d0750d29634/Modules/CIPPCore/Public/MCP/Get-CippMcpToolList.ps1
- `Get-CippMcpToolResult.ps1` validates requested tool names against `Get-CippMcpToolList`,
  then re-dispatches to the CIPP API router with `X-CIPP-Origin: mcp`:
  https://raw.githubusercontent.com/KelvinTegelaar/CIPP-API/3d835b5dab4c1749da50cdbd15e37d0750d29634/Modules/CIPPCore/Public/MCP/Get-CippMcpToolResult.ps1

Computed catalog count from that source: **231 read-only MCP tools**.

Top-level category counts:

| Category | Count |
| --- | ---: |
| Uncategorized | 42 |
| Email-Exchange | 39 |
| Tenant | 38 |
| CIPP | 33 |
| Identity | 26 |
| Endpoint | 23 |
| Security | 16 |
| Teams-Sharepoint | 9 |
| Tools | 5 |

Examples currently missing from the PSA grantable surface include `ListDBCache`,
`ExecUniversalSearchV2`, `ListAuditLogSearches`, `ListCalendarPermissions`,
`ListMailboxForwarding`, `ListNamedLocations`, `ListAppConsentRequests`, `ListCAtemplates`,
`ListDefenderTVM`, and many more.

### Gap

The current PSA exposes 17 local CIPP tools. The upstream catalog currently contains 231
read-only tools. Preserving the 17 curated names and importing the remaining upstream tools means
roughly **214 missing CIPP catalog entries** need to become first-class grantable staff MCP tools.

`cipp_list_sign_ins` is the only current oddity: the local tool calls upstream `ListSignIns` by
default and `ListUserSigninLogs` when `user_id` is supplied. The dynamic catalog should still
import `ListUserSigninLogs` as its own deterministic local tool name so the catalog remains
one-to-one with upstream where possible.

I did not query Charlie's live/office CIPP instance. The public upstream audit is enough to
design the import path; the implementation should include a mocked catalog test and, if Mayor
wants live installed-version confirmation, a post-build smoke against the real CIPP endpoint.

## Design

### 1. Add a Persistent CIPP MCP Catalog

New model/table:

- `CippMcpTool`

Suggested columns:

- `local_name` unique, e.g. `cipp_list_db_cache`
- `upstream_name`, e.g. `ListDBCache`
- `category`
- `description`
- `input_schema_json` as `longText`
- `annotations_json` as `longText`
- `read_only` boolean
- `sensitive` boolean
- `active` boolean
- `last_seen_at`
- timestamps

Do not store this in `settings.value`; full schemas for 200+ tools are too large for the current
`text` setting column and harder to query/test.

Name conversion:

- Upstream CIPP endpoint names become `cipp_` + deterministic snake case.
- Existing curated local names are reserved and keep their current definitions:
  `ListUsers -> cipp_list_users`, `ListMailboxes -> cipp_list_mailboxes`, etc.
- Dynamic sync skips upstream entries whose upstream name is already represented by a curated
  static tool, except `ListUserSigninLogs`, which should import as `cipp_list_user_signin_logs`
  because the current compatibility path only reaches it indirectly.
- Sync fails closed on any local-name collision instead of suffixing silently.

### 2. Add Catalog Sync from Live CIPP `tools/list`

Extend `CippMcpClient` with `listTools(array $query = [])`.

Wire shape:

- Same OAuth/client-credentials path as `callTool()`.
- Same configured CIPP URL, SSRF inspection, DNS pinning, and request timeout rules.
- POST JSON-RPC `{method: "tools/list"}` to `/api/ExecMCP`.
- Accept current CIPP JSON response mode; keep the existing JSON/SSE decoder tolerant because
  the current PSA client already supports both.

Add a `CippMcpCatalogSyncService` plus an operator-triggered path:

- Artisan command: `php artisan cipp:mcp-sync-tools`.
- Settings UI button on the CIPP integration card can call the same service.
- Sync upserts seen tools as active and marks previously-seen missing tools inactive.
- Sync stores `last_seen_at` and returns raw counts: seen, active, created, updated, deactivated.
- It should be safe to run repeatedly and not require code deploy when upstream adds/removes tools.

The registry and runtime should use the persisted catalog, not call upstream live during every
settings-page render or `tools/list`.

### 3. Feed the Staff MCP Registry, Not the Native Assistant Surface

Do not add the 200+ dynamic tools to `AssistantToolDefinitions::getTools()`. That method feeds
the native assistant service and Teams read-only wrapping too. The bead is about the staff MCP
grantable surface, so dynamic CIPP catalog entries should be composed at the staff MCP boundary.

Changes:

- `McpToolRegistry::groups()` adds dynamic active CIPP read tools to the integration group.
- If future CIPP catalogs expose non-read-only or mutation-classified tools, put them in a new
  `cipp_write` sensitive group.
- `McpToolRegistry::allToolNames()` includes active dynamic names so token validation and
  `McpToolInstructions` accept per-tool custom instructions.
- `McpStaffController::listTools()` adds active dynamic CIPP tools to `clientScopedTools`.
- `McpStaffController::toolAllowed()` requires `allowedTools !== null && token->allows($tool)`
  for dynamic CIPP catalog tools. Legacy null-scope/full-surface tokens must not automatically
  gain 200+ new CIPP tools.

Existing 17 curated tools keep their current behavior for compatibility.

### 4. Normalize Dynamic Schemas at the PSA Boundary

For every imported dynamic tool:

- Remove `tenantFilter`, `TenantFilter`, and equivalent tenant selector arguments from the public
  MCP schema. The PSA derives tenant scope from `Client::cipp_tenant_domain`.
- Remove those fields from `required`; if CIPP requires a tenant filter, the executor injects it.
- Keep upstream schema for all other arguments, including enums and descriptions.
- `McpStaffController` injects required `client_id` like other client-scoped tools.
- On call, reject caller-supplied tenant selector arguments even if a malicious client bypasses
  `tools/list`.
- Reject unknown arguments unless the imported schema explicitly allows them.

This keeps CIPP's per-tool argument surface dynamic while preserving SoundPSA's client isolation.

### 5. Add a Dynamic CIPP Tool Executor

New service:

- `CippMcpDynamicToolExecutor`

Responsibilities:

- Resolve `local_name -> CippMcpTool`.
- Require active catalog row.
- Require `CippConfig::isMcpRelayEnabled()`.
- Require a positive `client_id` and a mapped `client.cipp_tenant_domain`.
- Validate arguments against the stored schema.
- Inject `tenantFilter = client.cipp_tenant_domain`.
- Call upstream by `upstream_name` through `CippMcpClient::callTool()`.
- Return a bounded reference-only result, not raw upstream JSON.

Reference-only response shape:

```json
{
  "tool": "cipp_list_db_cache",
  "upstream_tool": "ListDBCache",
  "client_id": 123,
  "reference": "cippmcp_01...",
  "summary": {
    "result_type": "array",
    "count": 42,
    "truncated": true
  },
  "items": [
    {
      "index": 0,
      "id": "detected-id-or-null",
      "display": "sanitized short label",
      "keys": ["id", "displayName", "userPrincipalName"]
    }
  ]
}
```

The generic dynamic path should not try to return arbitrary CIPP rows. It can include only:

- counts and result type;
- stable local reference id for the call;
- a short bounded list of item references;
- detected IDs/display labels after sanitizer/redactor processing;
- top-level key names, capped.

The existing curated 17 tools keep their richer typed projections. If a dynamic CIPP endpoint
needs richer model-readable data later, add a typed projector for that endpoint instead of
weakening the generic reference-only rule.

### 6. Audit and Retention

MCP boundary audit stays in `McpAuditLog`.

- Arguments continue through `ActionRedactor`.
- Tenant selector attempts are audited as errors after redaction.
- Dynamic CIPP audit rows should include local tool name, status, duration, and actor label.
- Do not store upstream response bodies in `McpAuditLog`.

If implementation stores call references, keep them short-lived and avoid storing raw response
bodies unless a later reviewed design explicitly approves encrypted retention. The first cut can
mint reference IDs for traceability without persisting raw payloads.

### 7. CIPP Writes / Sensitive Tier

Current upstream CIPP MCP projection is read-only by design, but the local importer should be
defensive:

- Tools with `annotations.readOnlyHint === true` and non-mutation names go to the normal
  integration group.
- Anything missing `readOnlyHint`, marked non-read-only, or matching mutation prefixes goes to
  `cipp_write`.
- `cipp_write` is `sensitive: true`, explicit-grant-only, and invisible to legacy full-surface
  tokens.
- Until a separate write-specific plan exists, write-class dynamic CIPP calls should fail closed
  with a controlled error even if grantable in the UI.

That satisfies the tiering requirement without silently enabling future upstream write tools.

## Test Plan

Audit/count tests:

- Fixture a representative CIPP `tools/list` response and assert sync creates active catalog rows.
- Assert current curated CIPP names remain available and are not duplicated by sync.
- Assert a `ListUserSigninLogs` fixture imports as `cipp_list_user_signin_logs`.
- Assert sync deactivates tools missing from a later catalog.
- Assert local-name collision fails the sync and does not partially corrupt active rows.

Registry/UI tests:

- `McpToolRegistry::groups()` includes active dynamic CIPP read tools in `integration`.
- Fake non-read-only CIPP entries land in `cipp_write`, and that group is sensitive.
- Token creation/update accepts dynamic CIPP tool names.
- MSP per-tool custom instructions save for dynamic CIPP names and append at `tools/list` time.
- Forbidden-phrase description sweep includes dynamic registry and `tools/list` descriptions.

Staff MCP tests:

- A scoped token granted `cipp_list_db_cache` sees it in `tools/list`; an ungranted scoped token
  does not.
- A legacy null-scope/full-surface token does not gain dynamic CIPP tools.
- Dynamic tool schema has `client_id` required and no tenant selector argument.
- A dynamic call requires `client_id`, requires a CIPP tenant mapping, injects tenant filter, and
  calls upstream by `upstream_name`.
- Caller-supplied `tenantFilter`/`TenantFilter` fails closed before upstream call.
- Unknown/unadvertised arguments fail closed before upstream call.
- Returned dynamic payload is reference-only: no raw CIPP row bodies, no raw prompt-injection
  strings, no secrets.
- MCP audit stores redacted arguments and never upstream response text.

Regression tests:

- Existing `CippMcpRelayTest` suite remains green for the 17 curated tools.
- Existing `DataSurfaceToolsTest` CIPP scoped-token behavior remains green.
- Existing MCP token settings and description hygiene tests remain green.

Manual / empirical checks after implementation:

- Run `php artisan cipp:mcp-sync-tools` against mocked/local test config in CI-style tests.
- If Mayor provides or approves use of live CIPP credentials, run one dev smoke:
  `tools/list` count, grant one dynamic read tool to a throwaway scoped token, call it for a
  mapped test client, and record only raw counts/status in the bead.

## Open Questions

- Does Mayor want the first implementation to include the settings-page "Sync CIPP MCP catalog"
  button, or is the artisan command plus automatic registry use sufficient for this child bead?
- For live confirmation, should I smoke against the current production/office CIPP instance after
  the implementation, or is the upstream-source count plus mocked tool-list contract enough for
  PR review?
