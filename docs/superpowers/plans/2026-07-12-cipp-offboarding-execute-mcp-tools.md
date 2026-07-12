# CIPP offboarding EXECUTE MCP tools — device wipe + OneDrive handover (bead psa-zjpd)

The destructive execute half of offboarding, as two staged, **held-only**
capabilities following the psa-5qrd directory-role pattern (PR #267) on the
psa-hqk9 staged-write chassis (PR #264): the params gate throws on every
non-held path, so no grant mode — including `:immediate` — can reach the
upstream call without a cockpit approval.

- **`cipp_wipe_device`** (+ staged twin `cipp_stage_wipe_device`) — Intune
  device **wipe** (factory reset, destroys local data) or **retire** (removes
  company data + unenrolls) for one server-derived managed device.
- **`cipp_reassign_onedrive`** (+ `cipp_stage_reassign_onedrive`) — grant one
  server-derived successor owner/site-admin access to the offboarded user's
  OneDrive (data handover).

Forwarding/mailbox-convert were already covered by existing stage tools; these
two complete triage req 102.

## Source-verified upstream shapes

Verified against `KelvinTegelaar/CIPP-API` @ master (tree
`fd23ff20016d97f87b2a262f0d08ec4e07501399`, fetched 2026-07-12) and the CIPP
frontend (`KelvinTegelaar/CIPP` @ HEAD) for exact request bodies:

- **Wipe/retire — `POST api/ExecDeviceAction`**
  (`Modules/CIPPHTTP/.../Endpoint/MEM/Invoke-ExecDeviceAction.ps1` →
  `New-CIPPDeviceAction.ps1`, `.ROLE Endpoint.MEM.ReadWrite`). JSON body:
  `tenantFilter`, `GUID` (the Intune **managedDevice** id), `Action`. The
  default arm forwards the WHOLE body to Graph
  `POST /beta/deviceManagement/managedDevices('{GUID}')/{Action}` — which is
  why the wrapper's action arms are a **closed allowlist** (`wipe`, `retire`):
  an uncontrolled action string would be an arbitrary Graph device call. For
  `wipe` the data-destroying options are pinned explicitly
  (`keepUserData: false, keepEnrollmentData: false`) so Graph defaults can
  never soften an approved wipe; `retire` takes no options (matches the CIPP
  frontend's own Retire device action). Endpoint 500s on failure → `send()`
  throws `CippClientException`, fail-closed.

- **OneDrive handover — `POST api/ExecSharePointPerms`**
  (`Modules/CIPPHTTP/.../Teams-Sharepoint/Invoke-ExecSharePointPerms.ps1` →
  `Set-CIPPSharePointPerms.ps1`, `.ROLE Sharepoint.Site.ReadWrite`; the same
  core function the CIPP offboarding wizard's `OnedriveAccess` option uses).
  JSON body: `tenantFilter`, `UPN` (the OneDrive **owner**),
  `onedriveAccessUser: {value, label}` (the successor, CIPP autocomplete
  shape), `RemovePermission: false`. `URL` is deliberately omitted — CIPP
  resolves the OneDrive site URL from Graph (`/users/{UPN}/Drives`)
  server-side, so no caller-supplied URL exists anywhere in this flow. CIPP
  then runs SharePoint admin CSOM `SetSiteAdmin(url, successor, true)`.
  **Gotcha:** per-user CSOM failures are collected into `Results` and still
  return HTTP **200** — so `reassignOneDriveOwnership()` verifies the
  `Results` text for the success marker (`Successfully …`, no `Failed …`) and
  throws otherwise. The Results line (successor + OneDrive URL) is then
  discarded; callers only ever see success/status.

## Strictest gate (destructive — build brief psa-zjpd)

- **Held-only, no auto-act ever:** `deviceWipeParams()` /
  `oneDriveReassignParams()` throw unless the call is a staged proposal or the
  held-approval replay. Even an `:immediate` grant's `staged=false` call is
  refused with guidance to re-call staged. Legacy full-surface tokens cannot
  call either tool (cipp-write class is explicit per-grant only); `:staged`
  grants auto-downgrade `staged=false` calls to staged proposals.
- **Unmistakable blast radius:** the wipe readout names the exact device —
  hostname + Intune device id + PSA asset id — the action, what it destroys,
  and the device user; the OneDrive readout names both parties by UPN + PSA
  id. Only the display carries hostname/UPNs; stored payloads and audit
  summaries stay id-only.
- **Typed confirmations, both ends:** at call time the agent types
  `confirm_upn` (person) and `confirm_hostname` (device, verified against the
  resolved asset). At approval the operator must **re-type the exact Intune
  device id** (`confirm_device_id`, a cockpit `sensitive_inputs` field —
  stricter than the hqk9 confirm_upn pattern, per brief).
- **Approve-time re-resolution:** the held payload stores only safe local
  scalars (`asset_id`, `wipe_action`, lowercase `staged_device_id` snapshot /
  `successor_person_id`). `executeDeviceWipe()` re-resolves the asset fresh
  and declines on: lost client scope, inactive asset, lost/malformed Intune
  mapping, device-identity drift vs the snapshot, or a typed-id mismatch —
  each fails closed as an audited `error` decline, nothing changed upstream.
  The OneDrive replay re-resolves owner and successor fresh
  (`resolveCippPerson`), so a deactivated/unmapped party declines the same way.
- **Double-wipe rail (idempotency):** a completed wipe/retire is never
  re-issued. Same ticket + identical content → the stage call itself is
  idempotent (24h executed dedup). A duplicate staged from a **different
  ticket** (different content hash) is caught at approval by
  `deviceWipeAlreadyExecuted()` — keyed on the **device identity + action**
  embedded in the executed audit summary (`device <guid> (<action>)`), not the
  hash — and the re-fired approval becomes a **logged no-op**: audited
  `blocked` row naming the device, run advanced to Done (terminal — checked
  before the cooldown so it can't bounce back into the queue), result
  `already_handled`. A *different* action on the same device (e.g. retire
  after wipe) is not suppressed.
- **Kill-switch + cooldowns honored:** stage- and approve-time kill-switch
  refusal; 300s per-target proposal cooldown; server-derived client scope
  (tenant from `clients.cipp_tenant_domain`, user from `person_id`, device
  from `asset_id`, successor from `successor_person_id`); the upstream body
  keys (`GUID`/`Action`/`UPN`/`onedriveAccessUser`/`RemovePermission`/`URL`
  and variants) are on the caller-supplied-identifier blocklist.

## Files

- `app/Services/Cipp/CippRestWriteClient.php` — `wipeDevice()` (closed action
  allowlist, pinned wipe options), `reassignOneDriveOwnership()` (Results
  verification, fail-closed).
- `app/Services/Cipp/CippWriteScopeResolver.php` — `resolveIntuneAsset()`
  (client scope, active, GUID-validated Intune mapping, hostname required);
  `app/Services/Cipp/ResolvedIntuneDevice.php` value object.
- `app/Services/Mcp/StaffCippWriteToolExecutor.php` — staged map + cooldowns,
  held-only params gates, approve-time device re-verification
  (`executeDeviceWipe`), double-wipe no-op rail (`deviceWipeAlreadyExecuted` +
  `executedAuditSuffix`), displays, definitions, identifier-blocklist
  additions, `confirm_device_id` sensitive input.
- `app/Http/Controllers/Web/TechnicianCockpitController.php` — approve
  dispatch arms + `confirm_device_id` validation rule.
- `resources/views/cockpit/index.blade.php` — approval-queue badges + the
  typed device-id input on the approve form.
- Tests: `tests/Unit/Cipp/CippRestWriteClientTest.php` (source-pinned bodies,
  closed allowlist, Results fail-closed),
  `tests/Feature/Mcp/CippWriteOffboardingExecuteTest.php` (schema safety,
  grant gating, structural held-only refusal, staged→typed-id-approve→execute,
  decline guards incl. identity drift + kill-switch, the re-fired-approval
  no-op, successor/input/identifier rejection, idempotency).

Ships DORMANT: the tools appear only once an operator grants a token
`cipp_wipe_device` / `cipp_reassign_onedrive` (in practice `:staged`);
execution is held-only regardless of mode. Enabling/granting to Chet remains
operator+Charlie-gated.
