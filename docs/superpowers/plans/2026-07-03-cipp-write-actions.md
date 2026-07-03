# CIPP Write Actions Staff MCP Surface

> **Work bead:** `psa-iurj`
> **Executor:** `developer`
> **Mode:** audit-first plan for Mayor review; no implementation until approved.

## Goal

Expose a curated set of CIPP REST write actions as grantable staff MCP tools. The first build
should cover high-value M365 operator actions without opening CIPP's broad admin surface:
account sign-in state, password reset, MFA reset/state, session revocation, license assignment and
removal, and selected mailbox actions.

This plan is source-only. I did not call a live CIPP tenant, did not execute any write endpoint,
and did not implement code. Implementation waits for Mayor plan review.

## Authenticated Source Context

- `psa-iurj`: CIPP's MCP relay remains read-only. CIPP write actions must wrap CIPP REST endpoints
  ourselves rather than enabling write-class dynamic MCP catalog tools.
- `psa-iurj`: tenant scope is server-derived from `clients.cipp_tenant_domain`; callers cannot
  free-select tenant domains, tenant IDs, `TenantFilter`, `tenantFilter`, or `tenantID`.
- `psa-iurj`: all new tools are sensitive and ungranted by default. Legacy null-tool/full-surface
  tokens inherit none.
- `psa-iurj`: use the `psa-sx99` action-tool pattern: kill-switch fail-closed, append-only
  `TechnicianActionLog`, reason required, dedup/cooldown, and gate enforcement before upstream.
- `psa-iurj`: destructive writes need cockpit-held variants and confirm friction. Truly dangerous
  or irreversible CIPP writes go into a Mayor+Charlie sign-off quarantine tier.

## Current PSA Audit

### CIPP Client And Read Surface

`CippClient` is currently a read wrapper. It exposes `get()` plus typed list helpers such as
`listUsers`, `listLicenses`, `listMailboxes`, `listMFAUsers`, `listDevices`,
`listConditionalAccessPolicies`, and security posture reads. Its private request method supports
HTTP verbs internally, but there is no public `post()` or `patch()` write surface and no current
CIPP REST write wrapper in the PSA.

`CippMcpClient` is separate from `CippClient`. It calls CIPP `api/ExecMCP` for official MCP
`tools/list` and read-only `tools/call`, with CIPP API URL SSRF validation, redirects disabled, and
curl DNS pinning. Any REST write implementation should carry that transport hardening into
`CippClient` or a new CIPP REST write client before sending sensitive requests.

### MCP Catalog Fence

The dynamic CIPP MCP catalog is intentionally read-only at runtime:

- `CippMcpCatalogSyncService` classifies non-read-only upstream MCP entries as sensitive.
- `McpToolRegistry` already has a sensitive `cipp_write` group for write-class dynamic catalog rows.
- `CippMcpDynamicToolExecutor` refuses any dynamic CIPP tool where `read_only` is false or
  `sensitive` is true, even when the token has a grant.
- `CippMcpTool::publicInputSchema()` strips tenant selector fields from dynamic schemas.

This plan keeps that fence intact. The new write tools should be hand-written curated REST wrappers,
not generic dynamic CIPP write execution.

### Tenant, User, And License Scope

The PSA already has the server-side mapping needed for a safe write boundary:

- `Client.cipp_tenant_domain` is the tenant authority. The CIPP tenant mapping UI stores CIPP
  `defaultDomainName` on the client.
- `Person.cipp_user_id` stores the Entra object ID returned by CIPP `ListUsers`.
- `Person.cipp_upn` stores the CIPP user principal name.
- `LicenseType.vendor = cipp_m365` and `LicenseType.vendor_sku_id` are upserted from CIPP
  `ListLicenses` `skuId` or `skuPartNumber`.
- `License.vendor_ref` mirrors the same CIPP license identifier for a PSA client.

The write tools should accept local PSA IDs such as `client_id`, `person_id`, and
`license_type_id`. They should reject raw upstream user IDs, UPN target fields, tenant filters, and
raw SKU IDs. `confirm_upn` can be allowed as friction only; it must not select the target.

### Existing Action Gate Shape

The PSA already has the required audit primitives:

- `McpAuditLog` records every staff MCP list/call at the boundary.
- `TechnicianActionLog` is append-only and supports nullable `ticket_id`, `client_id`, content
  hashes, status, actor label, and correlation ID.
- `TechnicianRun` is the cockpit-held proposal record, but its `ticket_id` is not nullable. The
  first CIPP held variants should therefore require a PSA `ticket_id`, or the implementation must
  include a reviewed schema change before supporting ticketless held admin proposals.

The Tactical action/admin MCP work already added useful patterns for direct integration writes:
explicit-grant sensitive groups, reason requirement, kill-switch checks, content-hash dedup,
cooldown, server-derived upstream target IDs, redacted summaries, and URL/secret non-retention.

## Upstream CIPP Source Audit

I audited public `KelvinTegelaar/CIPP-API` at commit
`3d835b5dab4c1749da50cdbd15e37d0750d29634` (`2026-07-02`, version bump 10.5.7):

- `Config/openapi.json`
- `Modules/CIPPHTTP/Public/Entrypoints/HTTP Functions/Identity/Administration/Users/*.ps1`
- `Modules/CIPPHTTP/Public/Entrypoints/HTTP Functions/Email-Exchange/Administration/*.ps1`
- `Modules/CIPPCore/Public/Set-CIPPSignInState.ps1`
- `Modules/CIPPCore/Public/Set-CIPPResetPassword.ps1`
- `Modules/CIPPCore/Public/Revoke-CIPPSessions.ps1`
- `Modules/CIPPCore/Public/Remove-CIPPUserMFA.ps1`
- `Modules/CIPPCore/Public/Set-CIPPUserLicense.ps1`
- `Modules/CIPPCore/Public/Set-CIPPMailboxType.ps1`
- `Modules/CIPPCore/Public/Set-CIPPForwarding.ps1`
- `Modules/CIPPCore/Public/Set-CIPPHideFromGAL.ps1`
- `Modules/CIPPCore/Public/Set-CIPPOutOfoffice.ps1`

Reference root:
https://github.com/KelvinTegelaar/CIPP-API/tree/3d835b5dab4c1749da50cdbd15e37d0750d29634

### Source-Confirmed User Actions

| CIPP endpoint | Role | Source behavior | PSA wrapper note |
| --- | --- | --- | --- |
| `POST api/ExecDisableUser` | `Identity.User.ReadWrite` | Reads `tenantFilter`, `ID`, and `Enable`; calls `Set-CIPPSignInState`, which PATCHes Graph `accountEnabled`. It warns/fails for AD-synced users. | Build as separate enable and disable tools so grants can differ. Disable is destructive and should have held and direct variants. |
| `POST api/ExecResetPass` | `Identity.User.ReadWrite` | Reads `tenantFilter`, `ID`, `displayName`, `MustChange`; generates a password, PATCHes Graph password profile, and returns a password or PwPush link in `copyField`. | Build held-first. Direct reset needs a separate secret-delivery decision because the upstream response contains a credential. |
| `POST api/ExecResetMFA` | `Identity.User.ReadWrite` | Reads `tenantFilter`, `ID`; calls `Remove-CIPPUserMFA`, which deletes all non-password authentication methods and requires the user to supply MFA again at next logon. | Build held and direct variants with strong description and `confirm_upn`. |
| `POST api/ExecPerUserMFA` | `Identity.User.ReadWrite` | Reads `tenantFilter`, `userPrincipalName` or guest `userId`, and `State`; calls CIPP's legacy per-user MFA setter. | Build only if the description states this is legacy per-user MFA, not Conditional Access or Security Defaults. |
| `POST api/ExecRevokeSessions` | `Identity.User.ReadWrite` | Reads `tenantFilter`, `id`, and `Username`; calls Graph `invalidateAllRefreshTokens`. | Build direct and held variants. Direct is useful for incident response but disruptive. |
| `GET api/ExecBulkLicense` in OpenAPI, body-driven in source | `Identity.User.ReadWrite` | Source reads `$Request.Body` as an array grouped by `tenantFilter`; supports `LicenseOperation` Add/Remove/Replace, `userIds`, license arrays, and remove-all/replace-all flags; calls `Set-CIPPUserLicense`. | Build one-user assign/remove wrappers only after tests pin the actual request method/body. Do not expose replace-all or remove-all in v1. |

OpenAPI/source mismatch: `ExecBulkLicense` is generated as `GET` with no request schema, but the
PowerShell function reads a body and calls a bulk license helper. Treat this as verified source
behavior with schema drift. The implementation must pin the exact request shape in tests before
calling upstream.

### Source-Confirmed Mailbox Actions

| CIPP endpoint | Role | Source behavior | PSA wrapper note |
| --- | --- | --- | --- |
| `POST api/ExecConvertMailbox` | `Exchange.Mailbox.ReadWrite` | Reads `tenantFilter`, `ID`, `MailboxType`; calls `Set-CIPPMailboxType` with allowed types `Shared`, `Regular`, `Room`, `Equipment`. | Build held and direct variants. Converting to shared can affect licensing and mailbox limits. |
| `POST api/ExecEmailForward` | `Exchange.Mailbox.ReadWrite` | Reads `tenantFilter`, `userID`, `forwardOption`, internal or external forward target, and `KeepCopy`; can set internal forwarding, external SMTP forwarding, or disable forwarding. | Build disable/internal direct; external forwarding should be held by default and explicitly describe BEC/data-exfiltration risk. |
| `POST api/ExecHideFromGAL` | `Exchange.Mailbox.ReadWrite` | Reads `ID`, `tenantFilter` or `TenantFilter`, and `HideFromGAL`; calls Exchange `Set-Mailbox HiddenFromAddressListsEnabled`. | Build held and direct variants. |
| `POST api/ExecSetOoO` | `Exchange.Mailbox.ReadWrite` | Reads `tenantFilter`, `userId`, `AutoReplyState`, internal/external messages, optional schedule times, timezone, and calendar options; calls Exchange auto-reply configuration. | Build a constrained direct tool for Disabled/Enabled/Scheduled with bounded messages; calendar-decline options stay out of v1. |
| `POST api/AddSharedMailbox` | `Exchange.Mailbox.ReadWrite` | Reads `tenantID`, display name, username, domain, and aliases; creates a shared mailbox and blocks sign-in. | Hold for v1 unless the implementation first adds a server-derived accepted-domain catalog. Do not accept caller-supplied `tenantID` or arbitrary domain. |
| `POST api/ExecEditMailboxPermissions` | `Exchange.Mailbox.ReadWrite` | Broadly adds/removes FullAccess, SendAs, and SendOnBehalf. Source comment says it is not called by the frontend and is manual/API/scheduler only. | Defer from v1. It needs typed delegate/target resolution and careful grant tiers. |

### Broad Or Dangerous Source-Confirmed Write Families

The current upstream OpenAPI also contains write endpoints for Conditional Access, named locations,
GDAP relationships, SAM/CIPP app settings, service principals, tenant onboarding/offboarding,
standards deployment, transport rules, Safe Links/Safe Attachments/spam policies, Intune policies,
applications, device actions, mailbox restore, quarantine management, JIT admin, OAuth/app
credentials, user deletion, and bundled BEC/offboarding actions.

These are real source-confirmed surfaces, but they are outside the first buildable set.

## Proposed Tool Tiers

### Tier 1: Buildable V1, Held Default For Destructive Actions

All tools in this tier are sensitive and explicit-grant-only. Held variants should be the safer
default grants; direct variants are separate tool names for narrow operator tokens.

| Tool | Upstream endpoint | Direct/held | Target selectors | Notes |
| --- | --- | --- | --- | --- |
| `cipp_disable_user_sign_in` | `ExecDisableUser` with `Enable=false` | direct high-risk plus `cipp_stage_disable_user_sign_in` | `client_id`, `person_id`, `ticket_id` for stage, `confirm_upn` | Prevents user sign-in; AD-synced users may need local AD changes. |
| `cipp_enable_user_sign_in` | `ExecDisableUser` with `Enable=true` | direct plus held | `client_id`, `person_id`, optional/stage `ticket_id`, `confirm_upn` | Restores account access; still sensitive. |
| `cipp_revoke_user_sessions` | `ExecRevokeSessions` | direct plus held | `client_id`, `person_id`, optional/stage `ticket_id`, `confirm_upn` | Invalidates refresh tokens and forces re-authentication. |
| `cipp_remove_user_mfa_methods` | `ExecResetMFA` | held default plus direct high-risk | `client_id`, `person_id`, `ticket_id` for stage, `confirm_upn` | Deletes registered MFA methods except password methods. |
| `cipp_set_legacy_per_user_mfa` | `ExecPerUserMFA` | held default plus direct | `client_id`, `person_id`, `state`, `ticket_id` for stage, `confirm_upn` | State enum should be pinned from CIPP/source tests. Description must warn this is legacy per-user MFA. |
| `cipp_assign_user_license` | `ExecBulkLicense` source body | direct plus held | `client_id`, `person_id`, `license_type_id`, optional/stage `ticket_id` | Only one user and one or more server-resolved local license types. No replace-all. |
| `cipp_remove_user_license` | `ExecBulkLicense` source body | held default plus direct high-risk | `client_id`, `person_id`, `license_type_id`, `ticket_id` for stage, `confirm_upn` | Can remove mailbox/app access; no remove-all in v1. |
| `cipp_convert_mailbox` | `ExecConvertMailbox` | held default plus direct high-risk | `client_id`, `person_id`, `mailbox_type`, `ticket_id` for stage, `confirm_upn` | Allowed mailbox types only; describe shared-mailbox/license consequences. |
| `cipp_set_mailbox_forwarding` | `ExecEmailForward` | internal/disable direct; external held default plus direct high-risk | `client_id`, `person_id`, `mode`, `target_person_id` or `external_smtp`, `keep_copy`, `ticket_id` for stage | External forwarding is a data-exfiltration risk; require confirm for external target. |
| `cipp_set_mailbox_gal_visibility` | `ExecHideFromGAL` | direct plus held | `client_id`, `person_id`, `hidden`, optional/stage `ticket_id`, `confirm_upn` | Changes discoverability in the address list. |
| `cipp_set_mailbox_out_of_office` | `ExecSetOoO` | direct plus held | `client_id`, `person_id`, `state`, bounded messages, optional schedule, optional/stage `ticket_id` | Keep v1 to reply state/messages/schedule; omit calendar-decline options. |

### Tier 2: Buildable Only After A Narrow Follow-Up Decision

These are useful but have a schema, secret, or scope issue that deserves explicit review before a
first implementation.

| Candidate | Source | Reason to hold |
| --- | --- | --- |
| `cipp_stage_reset_user_password` | `ExecResetPass` | Build staged/cockpit first. The upstream response contains a password or PwPush link; direct MCP exposure would put a credential into model-visible output unless a separate secret-delivery design exists. |
| `cipp_reset_user_password` | `ExecResetPass` | Direct variant should not ship until Mayor approves how the temp credential is delivered, redacted, and retained. |
| `cipp_create_shared_mailbox` | `AddSharedMailbox` | Requires server-derived accepted mailbox domain, not caller-supplied `tenantID`/domain. |
| `cipp_manage_mailbox_permissions` | `ExecEditMailboxPermissions` or `ExecModifyMBPerms` | Broad delegation writes. Needs typed mailbox/delegate resolution and a separate permission model. |
| `cipp_offboard_user_lite` | `ExecOffboardUser` with a restricted flag set | Upstream offboarding is a bundle that can remove licenses, groups, MFA, forwarding, reset password, disable sign-in, and delete users. A safe composed wrapper needs its own Mayor-approved mini-plan. |
| `cipp_create_user` | `AddUser` | Creates credentials, licenses, groups, and defaults; better as a provisioning plan after password delivery and billing implications are reviewed. |

### Tier 3: Quarantine, Mayor+Charlie Sign-Off Required

Do not put these on a v1 dial:

- Deletes and irreversible object removal: `RemoveUser`, `RemoveDeletedObject`, group/contact/app
  deletes, tenant removal, policy/template deletion, mailbox or device deletion.
- Tenant-wide or multi-tenant policy writes: Conditional Access policies, named locations,
  standards deploy/run/update, transport rules, Safe Links/Safe Attachments/spam/malware filters,
  DLP/retention/compliance policies, auth method policies, tenant allow/block lists.
- Privileged relationship/app/security plumbing: GDAP changes, SAM/app permissions, service
  principals, app credentials, SSO setup, CPV permission repair, backend URL/CIPP settings.
- Endpoint and device destructive actions: Intune policy assignment, app deployment, device
  wipe/delete/passcode actions, recovery key/LAPS retrieval, Autopilot delete/assign/sync.
- Bundled remediations: `ExecBECRemediate`, `ExecOffboardUser`, JIT admin, TAP creation, org-wide
  messages, mailbox restore, quarantine management/release.
- Generic Graph or proxy-style write operations, even if CIPP exposes them through an `Exec*`
  endpoint.

## Safety Model

### Grant Posture

- Add static curated CIPP write definitions to the existing sensitive `cipp_write` group.
- All static CIPP write tools require `allowedTools !== null && token->allows($tool)`.
- Legacy null-tool/full-surface tokens must not list or call any new CIPP write tool.
- Dynamic CIPP write-class catalog tools remain non-executable.
- Held and direct variants are separate tool names so Charlie can grant the safer held dial without
  granting the direct one.

### Server-Derived Scope

Allowed caller selectors:

- `client_id`, injected/required by the staff MCP boundary.
- `person_id` for user/mailbox targets. The server verifies `people.client_id == client_id` and
  requires `cipp_user_id` and/or `cipp_upn`.
- `license_type_id` for license writes. The server verifies `vendor = cipp_m365` and that the
  client has a synced `License` row for that license type.
- `target_person_id` for internal forwarding or future mailbox delegation. The server verifies the
  same client and resolves the UPN/object ID.
- `ticket_id` for held variants and optional direct attribution. For ticket-scoped calls, verify
  `tickets.client_id == client_id`.
- Confirmation fields such as `confirm_upn` or `confirm_external_forward_to`; these are friction
  only and never select the upstream target.

Rejected caller inputs:

- `tenantFilter`, `TenantFilter`, `tenantID`, tenant GUIDs/domains, customer IDs, or CIPP tenant
  selectors.
- Raw upstream `ID`, `id`, `userId`, `userPrincipalName`, `Username`, object IDs, UPN target
  selectors, mailbox identity strings, or SKU GUIDs.
- Raw CIPP endpoint names, Graph URLs, cmdlets, role names, policy IDs, group IDs, mailbox
  permission trustees, or arbitrary request bodies.

The executor constructs the upstream CIPP body from local PSA records, injects
`tenantFilter = Client.cipp_tenant_domain` or endpoint-specific `tenantID` from that same mapping,
and fails before upstream when scope cannot be derived.

### Mutating Gate

Every CIPP write tool must fail before any upstream call when:

- the MCP token lacks the explicit tool grant;
- `TechnicianConfig::killSwitchEngaged()` is true;
- `reason` is missing or blank;
- `client_id`, target person, ticket, or license scope fails server-side validation;
- caller supplied a forbidden upstream selector;
- required confirmation friction does not match the server-derived target;
- an identical content hash was recently executed or staged;
- cooldown is active for the same action target.

Suggested content hash:

`cipp:<tool>:<client_id>:<person_or_mailbox_or_license_target>:<canonical_safe_params>`

Suggested default cooldowns:

- sign-in enable/disable, MFA reset/state, password reset: 300 seconds per user;
- revoke sessions: 120 seconds per user;
- license assign/remove: 300 seconds per user/license;
- mailbox convert/forwarding/GAL/OOO: 300 seconds per mailbox;
- shared mailbox creation if later approved: 600 seconds per alias/domain.

### Auditing And Redaction

Keep two ledgers:

- `McpAuditLog`: boundary list/call record with arguments redacted. No CIPP response bodies.
- `TechnicianActionLog`: every direct execution, staged proposal, rejected attempt, blocked
  cooldown, kill-switch hold, or upstream error. Include actor label, client/ticket where known,
  content hash, result status, and a redacted bounded summary.

Credential and secret handling:

- Never store password values, PwPush links, access tokens, raw CIPP response bodies, mailbox
  message bodies, or external forwarding targets in `McpAuditLog` or `TechnicianActionLog`.
- For password reset, do not return upstream `copyField` to MCP until a reviewed secret-delivery
  path exists. A staged cockpit execution can show the secret once to a human in a no-store response
  if Mayor approves that implementation detail.
- For OOO and forwarding, audit lengths and target type/domain, not raw message body or full
  external address unless review approves specific retention.

### Held Variants

First implementation path:

- Stage tools require `ticket_id` because `TechnicianRun.ticket_id` is currently non-null.
- Stage calls do not call CIPP. They write `TechnicianRun` with `state=awaiting_approval`, encrypted
  execution payload in `proposed_meta`, redacted display summary, and an awaiting-approval
  `TechnicianActionLog`.
- Cockpit approval re-fetches all local records, revalidates scope, re-checks kill-switch and
  cooldown, re-derives tenant/user/license upstream fields, and only then calls CIPP.
- Approval should record the executed `TechnicianActionLog` with `approver_user_id`.

If Mayor wants held CIPP proposals that are not tied to a PSA ticket, add a reviewed schema change
or a separate admin hold ledger before implementation. Do not work around the current
`TechnicianRun.ticket_id` constraint with fake tickets.

## Implementation Design After Approval

### 1. Transport And Client Wrappers

Add tested CIPP REST write methods:

- either extend `CippClient` with `post()`/`patch()` and safe request options, or introduce a
  focused `CippRestWriteClient`;
- port the CIPP API URL SSRF validation, redirect refusal, and DNS pinning from `CippMcpClient`;
- keep token caching keyed by tenant/client to avoid cross-config cache collisions;
- allow only curated endpoint methods from the write executor, not arbitrary endpoint strings;
- normalize CIPP `Results` envelopes and return bounded redacted status summaries.

### 2. Static Registry And Staff Dispatch

Add a focused registry/executor pair:

- `App\Services\Mcp\StaffCippWriteToolExecutor`
- static definitions merged into `McpToolRegistry` `cipp_write`
- staff MCP `tools/list` injects required `client_id`
- `McpStaffController::toolAllowed()` treats static CIPP writes like dynamic CIPP catalog tools:
  explicit grant only
- dispatch happens before generic assistant/Chet paths

### 3. Scope Resolvers

Implement small server-side resolvers:

- `resolveCippTenant(Client $client): string`
- `resolveCippPerson(int $clientId, int $personId): ResolvedCippUser`
- `resolveCippLicense(int $clientId, int $licenseTypeId): string`
- `resolveTicketForHeldAction(int $clientId, int $ticketId): Ticket`

These resolvers are the only code allowed to produce upstream CIPP `tenantFilter`, `tenantID`,
`ID`, `id`, `Username`, `userPrincipalName`, license SKU, or mailbox identity values.

### 4. Direct Executors

For each direct tool:

- validate grant/reason/scope/confirm fields;
- compute content hash;
- check dedup/cooldown before upstream;
- write `TechnicianActionLog` for rejected/blocked paths;
- call CIPP only after all gates pass;
- write executed/error `TechnicianActionLog`;
- return a small status object with no raw CIPP body.

### 5. Staged Executors And Cockpit Approval

For each staged tool:

- require `ticket_id`;
- create or reuse an awaiting `TechnicianRun`;
- supersede older active staged CIPP runs of the same action type on that ticket/target;
- encrypt the revalidatable payload in `proposed_meta`;
- add a cockpit approval path such as `approveStagedCippAction`;
- revalidate and execute with the same direct executor code path on approval.

### 6. Description Hygiene

Descriptions should state consequences plainly:

- disable sign-in prevents the user from accessing M365 until re-enabled;
- reset MFA removes registered MFA methods and can lock users out until re-registration;
- reset password creates a temporary credential and must use an approved secret-delivery path;
- revoke sessions signs the user out of active sessions;
- remove license can remove mailbox/app access;
- external mailbox forwarding can exfiltrate mail outside the tenant;
- convert mailbox changes Exchange mailbox type and may affect licensing/limits.

Do not describe hidden internal controls as bypassable policy. Confirmation fields are
defense-in-depth only; grant, kill-switch, held/default posture, and cooldown are the real gates.

## Test Plan For Implementation

All implementation should be strict test-first.

Registry/grant tests:

- legacy null-tool/full-surface tokens list and call zero new CIPP write tools;
- scoped token without a specific CIPP write grant cannot list or call it;
- granted held/direct tools appear in `cipp_write`;
- dynamic write-class catalog tools still fail closed even when granted;
- description hygiene tests include the new definitions.

Scope tests:

- missing `client_id`, unmapped CIPP tenant, missing `person_id`, missing `cipp_user_id`, or wrong
  client target fails before upstream;
- caller-supplied tenant/user/SKU/upstream ID fields are rejected before upstream;
- `confirm_upn` mismatch fails before upstream;
- `ticket_id` for stage tools must belong to `client_id`;
- license writes resolve only from local `LicenseType`/`License` rows for that client.

Gate tests:

- every mutating tool requires `reason`;
- kill-switch blocks before upstream and writes a held/blocked audit row;
- dedup returns an idempotent result without a second upstream call;
- cooldown blocks rapid distinct repeats before upstream;
- rejected validation paths write a bounded redacted `TechnicianActionLog`.

Endpoint shape tests:

- CIPP client sends exact source-confirmed endpoint, verb, and JSON body for each wrapper;
- `ExecBulkLicense` has explicit tests for the source body shape and the OpenAPI method/schema
  drift;
- password reset tests prove upstream `copyField`/password text is not stored in MCP or
  Technician audits and is not returned by direct MCP unless a secret-delivery decision is approved;
- mailbox OOO/forwarding tests prove message/address redaction.

Held approval tests:

- staged tools create awaiting `TechnicianRun` and make no CIPP call at MCP time;
- cockpit approval revalidates scope, kill-switch, confirm target, and cooldown before upstream;
- double approval cannot execute twice;
- denial never calls CIPP;
- executed approval rows include `approver_user_id`.

Regression tests:

- existing curated CIPP read tools and dynamic CIPP read catalog remain green;
- existing `CippMcpRelayTest`, `DynamicCippMcpToolsTest`, staff MCP token tests, and Tactical/PSA
  action tests remain green.

Manual verification after implementation:

- no live CIPP write smoke unless Mayor explicitly authorizes it;
- if live smoke is authorized, use a test tenant/test user and report raw endpoint status/counts
  only, not passwords, tokens, or response bodies.

## Open Review Decisions

- Should password reset be held-only for v1, with direct reset held behind a separate
  secret-delivery design?
- Should held CIPP admin/user actions require a PSA ticket in v1, or should implementation include
  a nullable-ticket/admin-hold schema change first?
- Should `ExecBulkLicense` be accepted from source despite OpenAPI drift, or should license
  assignment/removal wait until current installed CIPP behavior is smoke-verified by a human?
- Should mailbox forwarding external targets be allowed as a direct high-risk dial, or held-only in
  v1?
