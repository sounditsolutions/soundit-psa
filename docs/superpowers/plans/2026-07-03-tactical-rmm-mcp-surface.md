# Tactical RMM Staff MCP Surface

> **Work bead:** `psa-kdn8`
> **Executor:** `developer`
> **Mode:** audit-first plan for Mayor review; no implementation until approved.

## Goal

Expose the Tactical RMM control surface as grantable staff MCP tools. The surface should include
direct API-analogy reads, composed operations, endpoint actions, and admin/write controls. Every
Tactical MCP tool ships ungranted by default: legacy null-tool/full-surface tokens must not list or
call any new Tactical tool, including normal read tools.

This plan is source-only. I did not touch live Tactical RMM, and implementation must not run live
admin/write calls without explicit authorization.

## Authenticated Source Context

- `psa-kdn8`: full Tactical RMM MCP tool surface, all ungranted-by-default.
- `psa-kdn8`: audit first, enumerate candidates and tiers, then push this plan for Mayor review
  before implementation.
- `psa-kdn8`: reads belong in the normal integration tier; composed ops are tiered by sensitivity;
  admin/write controls are sensitive.
- `psa-kdn8`: use the PSA-action-tool safety pattern from `psa-sx99`: kill-switch fail-closed,
  append-only action audit, reason required, dedup/cooldown on anything mutating/sending, and gate
  enforcement before the upstream call.
- `psa-kdn8`: server-derive client/agent/target scope. Do not let the caller free-select upstream
  Tactical targets.

## Source Audit Findings

### Current MCP/Tactical Exposure

The staff MCP server already exposes six Tactical read-only tools through
`TacticalReadOnlyToolset` and `ChetDataSurfaceTools`:

- `tactical_get_device`
- `tactical_get_device_checks`
- `tactical_get_device_network`
- `tactical_get_device_software`
- `tactical_get_device_services`
- `tactical_get_device_disks`

These tools are listed in the existing `integration` group, but runtime `toolAllowed()` already
requires an explicit scoped token for `ChetDataSurfaceTools::handles()`. A legacy full-surface token
does not receive them. They require `client_id` and resolve `hostname` through local
`TacticalAsset` rows whose linked PSA `Asset` belongs to that client.

`tactical_run_diagnostic` exists in the triage loop. It maps a fixed diagnostic name to hard-coded
Tactical script IDs and dispatches `RunScriptAction` through `TacticalActionService` as
`actor_label=ai-triage`. It is deliberately not part of the read-only MCP surface, and Chet tokens
are explicitly denied that tool today.

### Existing Tactical Client Surface

`TacticalClient` wraps these upstream endpoints and helpers:

- Reads: `getAgents`, `getAgent`, `getClients`, `getPolicies`, `getScripts`, `getSoftware`,
  `getPatches`, `getAgentChecks`, `getMeshCentralLinks`, `getAgentTasks`, `getUrlActions`,
  `getAlertTemplates`, `getCoreSettings`, `isHealthy`.
- Endpoint actions: `runScript`, `runScriptAsync`, `reboot`, `cmd`, `shutdown`, `recover`,
  `setMaintenance`, `setAgentCustomField`.
- Provisioning/admin helpers: `createClient`, `createUrlAction`, `updateUrlAction`,
  `createAlertTemplate`, `updateAlertTemplate`, `setDefaultAlertTemplate`, `getInstallerInfo`.

The client already has important transport hardening: configured `X-API-KEY`, redirects disabled,
request-time SSRF validation and curl DNS pinning, and no raw response-body logging for many failure
paths.

### Existing Action Bus

Endpoint-affecting actions already have a single audited bus:

- `tactical.run_script` via `RunScriptAction`
- `tactical.reboot` via `RebootAction`
- `tactical.run_command` via `RunCommandAction`
- `tactical.shutdown` via `ShutdownAction`
- `tactical.recover` via `RecoverAction`
- `tactical.set_maintenance` via `SetMaintenanceAction`

`TacticalActionService` resolves the upstream agent from a PSA `Asset`, validates action params,
requires confirm tokens for destructive actions, classifies offline vs HTTP errors, writes exactly
one immutable `TacticalActionLog` row for every dispatch path, and returns a normalized result.

The web UI already has CSRF-protected endpoints for script execution, reboot, command, shutdown,
recover, maintenance, refresh-now, and MeshCentral remote-control links. The command and shutdown
paths use typed-hostname friction plus payload-bound confirm tokens before dispatch. MeshCentral
link minting is separately audited in `TacticalActionLog` and never stores the returned token URL.

### Existing Admin/Provisioning Surface

`TacticalProvisioningService` already performs idempotent, no-clobber alert-to-ticket provisioning:

- ensure/store webhook key;
- create/update URL action;
- create/update alert template;
- read core settings;
- set default alert template only when empty or already ours;
- write a secret-free provisioning audit blob.

Client onboarding uses `createClient` and maps `clients.tactical_site_id` as `ClientName|SiteName`.
Portal installer downloads use `getInstallerInfo`, which resolves numeric upstream client/site IDs
from that server-side mapping and returns a pre-signed installer URL.

### API Catalog Caveat

`tests/Fixtures/tactical/api_schema.json` is a small pinned fixture for selected agent/list/detail
fields. It is not a full Tactical RMM API catalog and must not be treated as authoritative for
unwrapped admin operations. Any tool below marked "needs schema/source confirmation" requires a
fresh upstream schema/source check before implementation.

## Safety Model

### Grant Posture

- Add Tactical-specific explicit-grant checks in `McpStaffController::toolAllowed()`.
- Legacy null-tool/full-surface tokens must not list or call any `tactical_*` MCP tool, including
  normal read tools.
- Reads remain in the normal `integration` tier for UI grouping, but still require explicit grants.
- Endpoint actions and admin writes go into sensitive groups, likely `tactical_action` and
  `tactical_admin`.

### Server-Derived Scope

The MCP caller may provide PSA-facing selectors only:

- `client_id` injected/required by the MCP boundary;
- `hostname` or PSA `asset_id`;
- optional `ticket_id` for incident attribution;
- local `tactical_script_id` or script name resolved through `TacticalScript`;
- local PSA `client_id` for provisioning.

The caller must not provide trusted upstream `agent_id`, Tactical numeric client/site IDs,
Tactical client/site names, arbitrary policy IDs, arbitrary alert-template IDs, or arbitrary custom
field IDs. When an upstream integer is unavoidable, the executor must resolve or verify it from a
server-side catalog/read call before the write.

Ticket-scoped tools must prove both the ticket and asset belong to `client_id`. If an asset is
provided for a ticket operation, the asset must already be attached to that ticket.

### Auditing

Keep three distinct ledgers:

- `McpAuditLog`: every MCP `tools/list` and `tools/call`, with arguments redacted.
- `TechnicianActionLog`: every mutating/admin Tactical MCP attempt or held proposal, following the
  `psa-sx99` direct-action pattern with actor label, client/ticket where known, content hash, and
  `executed`, `awaiting_approval`, `blocked`, `rejected`, or `error` status.
- `TacticalActionLog`: every endpoint action that reaches the Tactical action bus, plus MeshCentral
  link outcomes. This remains the endpoint-specific incident ledger with `agent_id`/`asset_id`.

For endpoint actions, write both a `TechnicianActionLog` MCP-control row and the existing
`TacticalActionLog` bus row. For admin writes that do not target an endpoint, write
`McpAuditLog` plus `TechnicianActionLog`; use a new Tactical admin ledger only if the
implementation finds `TechnicianActionLog` cannot represent the action cleanly.

### Mutating Gate

Every mutating/admin tool must fail before any upstream call when:

- `TechnicianConfig::killSwitchEngaged()` is true;
- the token lacks the explicit tool grant;
- `reason` is missing or blank;
- target scope cannot be derived server-side;
- dedup finds an identical recent executed action;
- cooldown finds any recent mutating action of that type on the same target;
- destructive/RCE confirmation friction fails.

Dedup/cooldown should use a stable content hash:

`tactical:<tool>:<client>:<asset-or-admin-target>:<canonical-params>`

Suggested default cooldowns:

- command, reboot, shutdown: 300 seconds per asset;
- run script: 120 seconds per asset/script/args;
- maintenance, recover, remote-control link: 60 seconds per asset;
- provisioning/admin writes: 300 seconds per admin target.

Direct destructive/RCE tools should keep the web UI's typed-hostname friction as
`confirm_hostname`, even though the MCP token grant is the primary control. Ship staged variants too
so Charlie can grant the safer cockpit-held dial to some tokens and the direct dial to others.

### Redaction and Retention

- Reuse `ActionRedactor` for command strings, argv, output, and audit params.
- Store body/output lengths or redacted snippets, not raw command bodies when a safer summary works.
- Never store MeshCentral URLs or installer URLs in `McpAuditLog`, `TechnicianActionLog`, or
  `TacticalActionLog`.
- Remote-control and installer responses must be `Cache-Control: no-store` if exposed through any
  HTTP callback path.

## Candidate Tool Inventory

### Normal Integration Reads

These are grantable read tools in the `integration` tier, but explicit-grant-only:

| Tool | Source | Notes |
| --- | --- | --- |
| `tactical_list_devices` | local `TacticalAsset`/`Asset` snapshot | Bounded list for one PSA client; no live call by default. |
| `tactical_get_device` | existing read-only tool | Keep current bounded/sanitized live summary. |
| `tactical_get_device_checks` | existing read-only tool | Keep output sanitization and cap. |
| `tactical_get_device_network` | existing read-only tool | Reuse `TacticalFieldMap::mapNetwork`. |
| `tactical_get_device_software` | existing read-only tool | Keep sorted/capped/sanitized list. |
| `tactical_get_device_services` | existing read-only tool | Read only; service control is separate sensitive tooling. |
| `tactical_get_device_disks` | existing read-only tool | Reuse disk mappers. |
| `tactical_get_device_patches` | `TacticalClient::getPatches` | Bounded patch/update list for a derived asset. |
| `tactical_get_device_tasks` | `TacticalClient::getAgentTasks` | Bounded task history/status. |
| `tactical_get_endpoint_insight` | `TacticalInsightService` | Composed read of snapshot, live bounded signal, alerts, and recent action logs. |
| `tactical_list_scripts` | local `TacticalScript` | Hide deprecated/hidden scripts unless a future admin view explicitly asks. |
| `tactical_get_script` | local `TacticalScript` | Metadata only; no script bodies unless source confirms safe fields. |
| `tactical_list_recent_actions` | local `TacticalActionLog` | Client/asset/ticket-scoped audit read. |
| `tactical_list_clients_sites` | local client mapping plus `getClients` if needed | Prefer local mappings; live read must be bounded. |
| `tactical_list_policies` | `cachedPolicies`/`getPolicies` | Policy names/IDs for operator selection; IDs verified server-side before writes. |
| `tactical_list_url_actions` | `getUrlActions` | Admin read, but still normal read tier unless review moves it sensitive. |
| `tactical_list_alert_templates` | `getAlertTemplates` | Admin read, bounded. |
| `tactical_get_core_settings` | `getCoreSettings` | Admin read; redact sensitive values if upstream includes any. |
| `tactical_health_check` | `isHealthy` | Returns configured/reachable status only. |

### Sensitive Endpoint Actions

These affect an endpoint or mint endpoint session tokens. All are sensitive, explicit-grant-only,
reason-required when they mutate or mint a token, and server-derived from PSA client/asset/ticket.

| Tool | Direct or held | Source | Required gates |
| --- | --- | --- | --- |
| `tactical_run_script` | direct sensitive | `RunScriptAction` | reason, script from local catalog, dedup/cooldown, bus audit. |
| `tactical_stage_script` | held | `TechnicianRun` plus later bus dispatch | reason, cockpit approval, script from local catalog. |
| `tactical_run_command` | direct sensitive | `RunCommandAction` | reason, `confirm_hostname`, content hash, cooldown, kill-switch, bus audit. |
| `tactical_stage_command` | held | `TechnicianRun` plus later bus dispatch | reason, cockpit approval, command redaction, no upstream call at MCP time. |
| `tactical_reboot_device` | direct sensitive | `RebootAction` | reason, `confirm_hostname`, cooldown, kill-switch, bus audit. |
| `tactical_stage_reboot` | held | `TechnicianRun` plus later bus dispatch | reason, cockpit approval. |
| `tactical_shutdown_device` | direct sensitive | `ShutdownAction` | reason, `confirm_hostname`, stronger consequence copy, cooldown, kill-switch, bus audit. |
| `tactical_stage_shutdown` | held | `TechnicianRun` plus later bus dispatch | reason, cockpit approval. |
| `tactical_recover_mesh` | direct sensitive | `RecoverAction` | reason, mode fixed to `mesh`, cooldown, kill-switch. |
| `tactical_set_maintenance` | direct sensitive | `SetMaintenanceAction` | reason, explicit boolean, cooldown, kill-switch. |
| `tactical_open_remote_control` | direct sensitive | `getMeshCentralLinks` | reason, link type allowlist, no URL audit/cache/log, safe URL validation. |
| `tactical_refresh_device_snapshot` | direct sensitive or normal-read-plus-local-write | `TacticalDeviceSyncService::syncDeviceDetail` | no upstream mutation, but writes local snapshot; require explicit grant and cooldown. |

### Composed Operations

These combine reads and/or actions. Tier by actual side effect.

| Tool | Tier | Composition |
| --- | --- | --- |
| `tactical_diagnose_device` | normal integration if read-only | Insight + checks + patches + tasks + recent actions. No script run. |
| `tactical_run_diagnostic` | sensitive | Convert existing triage diagnostic to MCP with local script catalog or reviewed diagnostic map; use bus and reason. |
| `tactical_prepare_support_session` | sensitive | Insight plus `tactical_open_remote_control`; no URL retention. |
| `tactical_sync_devices_now` | sensitive admin | Run device sync for one client/all mapped clients; load/cooldown guarded. |
| `tactical_sync_scripts_now` | sensitive admin | Refresh script catalog; load/cooldown guarded. |
| `tactical_provision_client_site` | sensitive admin | `createClient`, then store `clients.tactical_site_id`; idempotent no-clobber. |
| `tactical_generate_installer` | sensitive admin | `getInstallerInfo`; signed URL returned once, never audited. |
| `tactical_provision_alert_ticketing` | sensitive admin | Existing `TacticalProvisioningService`; no-clobber default-template behavior. |

### Sensitive Admin/Write Controls Already Wrapped

These can be implemented from in-repo wrappers after plan approval:

| Tool | Source | Gates |
| --- | --- | --- |
| `tactical_create_client_site` | `createClient` | client_id only, site name normalized, policy IDs verified from `getPolicies`, no-clobber mapping. |
| `tactical_set_agent_custom_field` | `setAgentCustomField` | expose only allowlisted PSA-owned fields; do not accept arbitrary field IDs. |
| `tactical_upsert_url_action` | `createUrlAction`/`updateUrlAction` | no-clobber unless target is PSA-owned; redact headers/body. |
| `tactical_upsert_alert_template` | `createAlertTemplate`/`updateAlertTemplate` | no-clobber unless target is PSA-owned. |
| `tactical_set_default_alert_template` | `setDefaultAlertTemplate` | sensitive; prefer held unless setting ours or prior default is empty. |
| `tactical_get_or_create_installer` | `getInstallerInfo` | signed URL, no audit retention, cooldown. |

### Candidate Admin/Write Controls Needing Schema/Source Confirmation

These are plausible Tactical RMM controls but are not wrapped by the current client. Do not implement
them from guesses. Before implementation, refresh upstream schema/source and add client methods with
tests for the exact endpoint, verb, body, and response shape.

| Tool | Tier | Notes |
| --- | --- | --- |
| `tactical_start_service` | sensitive | Service control was called out in the bead; source shape must be verified. |
| `tactical_stop_service` | sensitive | Must require service identity from live service list, not arbitrary free text alone. |
| `tactical_restart_service` | sensitive | Same as service start/stop; cooldown per asset/service. |
| `tactical_install_patches` | sensitive | Patch write shape and reboot behavior need source confirmation. |
| `tactical_create_script` | sensitive admin held | Script content is RCE supply chain; cockpit-held by default. |
| `tactical_update_script` | sensitive admin held | No raw script bodies in MCP audit; require change reason. |
| `tactical_hide_script` | sensitive admin | Safer than delete; verify upstream semantics. |
| `tactical_delete_script` | sensitive admin held | Prefer not to expose until Mayor explicitly approves deletion semantics. |
| `tactical_create_policy` | sensitive admin held | Automation policy writes can affect many endpoints. |
| `tactical_update_policy` | sensitive admin held | Must show diff/summary and verify target policy server-side. |
| `tactical_assign_policy` | sensitive admin held | Scope by PSA client/site/asset; verify upstream IDs server-side. |
| `tactical_schedule_task` | sensitive admin held | Needs upstream task schema, execution semantics, and cancellation story. |

## Implementation Design After Approval

### 1. Registry and Token Gating

Add a Tactical MCP registry helper rather than scattering definitions through
`McpStaffController`:

- `App\Support\TacticalMcpToolRegistry` or Tactical sections on `McpToolRegistry`;
- normal read definitions merged into the existing `integration` group;
- sensitive endpoint action definitions in `tactical_action`;
- sensitive admin definitions in `tactical_admin`;
- `McpToolRegistry::allToolNames()` includes all active Tactical definitions.

`McpStaffController::toolAllowed()` must require:

```php
$token->allowedTools !== null && $token->allows($toolName)
```

for every Tactical MCP tool. Runtime `tools/list` must inject required `client_id` for every
client-scoped Tactical tool.

### 2. Executors

Add focused services:

- `TacticalMcpReadToolExecutor`
- `TacticalMcpActionToolExecutor`
- `TacticalMcpAdminToolExecutor`

The read executor can extend `TacticalReadOnlyToolset` or reuse its resolver/sanitizer. Avoid
duplicating hostname/client scoping logic.

The action executor should:

- validate `client_id`, target, reason, and canonical params;
- resolve PSA `Asset` and optional `Ticket`;
- write/dispatch the `TechnicianActionLog` control row;
- call `TacticalActionService` for endpoint actions;
- translate normalized bus result into MCP JSON;
- never accept raw `agent_id`.

The admin executor should:

- fail closed on kill-switch;
- write `TechnicianActionLog`;
- perform no-clobber/idempotent checks;
- call only source-confirmed `TacticalClient` methods;
- redact secrets and token URLs before return/audit.

### 3. Held Variants

Use `TechnicianRun` plus `TechnicianActionGate` for staged command/script/reboot/shutdown and broad
admin writes. The MCP call records the proposal and returns `run_id`; the cockpit approval path
revalidates scope, target, and current grant before dispatching the upstream call.

Held proposed metadata should include:

- target PSA asset/ticket/client IDs;
- redacted command/script/admin summary;
- reason;
- actor label;
- content hash;
- consequence copy for destructive actions.

### 4. Description Hygiene

Tactical descriptions should be operational and concise. Avoid teaching the model to bypass policy
or call out hidden internal controls. For dangerous tools, state concrete consequences and required
reason/confirmation fields.

## Test Plan

All implementation tasks must be strict test-first.

Registry/grant tests:

- scoped token without a Tactical grant does not list or call any Tactical tool;
- legacy null-tool token does not list or call any Tactical tool;
- granted read/action/admin tools appear in the expected groups;
- token settings accept all Tactical tool names from the registry;
- descriptions pass the existing MCP description hygiene tests.

Client scoping tests:

- every Tactical MCP call requiring `client_id` rejects a missing or malformed value;
- caller-supplied `agent_id`, Tactical client/site IDs, and arbitrary upstream IDs are ignored or
  rejected;
- duplicate hostnames resolve within the requested PSA client only;
- ticket-scoped actions require ticket/client/asset membership.

Read tests:

- existing six read tools remain sanitized and bounded;
- new read tools cap lists, redact unsafe text, and degrade gracefully on Tactical failures;
- `tactical_refresh_device_snapshot` writes only the local snapshot and does not create action-log
  rows that imply endpoint mutation.

Action tests:

- direct mutators require reason and explicit grant before any upstream call;
- kill-switch blocks before any upstream call;
- dedup returns an idempotent result without a second upstream call;
- cooldown blocks distinct rapid repeats;
- `confirm_hostname` is required for command/reboot/shutdown direct tools;
- command params are canonicalized once, hashed once, redacted in all audits, and passed unchanged
  to `RunCommandAction`;
- endpoint tools write `McpAuditLog`, `TechnicianActionLog`, and `TacticalActionLog` as designed;
- staged tools write an awaiting-approval run/log and make no Tactical upstream call.

Remote URL tests:

- MeshCentral and installer tools never store returned URLs in MCP/action/audit logs;
- unsafe/non-HTTPS remote-control URLs fail closed;
- success responses are no-store where applicable.

Admin tests:

- provisioning is idempotent and no-clobber;
- URL action and alert template upserts redact headers/body and do not store webhook keys in logs;
- custom-field writes reject non-allowlisted field IDs;
- unwrapped schema-dependent candidates are not callable until source-confirmed wrappers exist.

Regression tests:

- existing `tests/Feature/Mcp`, `tests/Feature/Chet`, `tests/Feature/Tactical`, and
  `tests/Unit/Tactical` remain green.
- existing AI Technician fences still exclude `tactical_run_diagnostic` unless this plan's MCP-only
  sensitive diagnostic tool is explicitly implemented and granted.

## Verification Gate After Implementation

Before any implementation PR is marked ready:

- `vendor/bin/pint`
- relevant MCP/Chet/Tactical unit and feature tests
- any new cockpit approval tests for held variants
- no live Tactical admin/write smoke unless Mayor authorizes it
- after code changes on the dev box, restart `soundit-psa-queue.service` and `soundit-psa-dev.service`
  before empirical checks
- push branch, wait for CI green, comment on `psa-kdn8`, and nudge Mayor for review

This PR is plan-only. Implementation waits for Mayor plan review.
