# CIPP staged Entra directory-role removal MCP tool (bead psa-5qrd)

Staged, **held-only** removal of one Microsoft Entra directory (admin) role from
one server-derived user — strip a stale admin role WITHOUT touching licensing
(offboarding + least-privilege hygiene). Follows the psa-hqk9 mailbox-delegate
staged-write pattern (PR #264, commit 95dd593) with one deliberate difference:
the capability is **structurally held-only** (external-forwarding precedent) —
`directoryRoleParams()` throws on every non-held path, so no grant mode can
execute the upstream removal without a cockpit approval.

## Source-verified upstream shape

Verified against `KelvinTegelaar/CIPP-API` @ master (tree
`fd23ff20016d97f87b2a262f0d08ec4e07501399`, fetched 2026-07-12):

- **Write — `POST api/ExecRemoveAdminRole`**
  (`Modules/CIPPHTTP/Public/Entrypoints/HTTP Functions/Identity/Administration/Users/Invoke-ExecRemoveAdminRole.ps1`,
  `.ROLE Identity.Role.ReadWrite`). JSON body:
  - `tenantFilter` — tenant default domain (plain string; `{value}` also accepted upstream).
  - `RoleId` — the tenant's **activated `directoryRole` OBJECT id** (NOT the
    role template id). Used verbatim in the Graph call.
  - `RoleName` — display label, used only for CIPP's own log line.
  - `Users` — array; each entry `{value: <entra user object id>, label: <upn>}`
    (CIPP autocomplete shape) or a plain id string. We always send exactly one.
  - Per user, CIPP runs Graph
    `DELETE /v1.0/directoryRoles/{RoleId}/members/{UserId}/$ref`.
  - Returns HTTP 200 only when every removal succeeded; 500 when any failed;
    400 when required fields are missing — `send()` throws `CippClientException`
    on any non-success, so failures are fail-closed.

- **Read — `GET api/ListRoles?tenantFilter=X`**
  (`Modules/CIPPHTTP/Public/Entrypoints/HTTP Functions/Invoke-ListRoles.ps1`,
  `.ROLE Identity.Role.Read`). Returns a bare array of
  `{Id, roleTemplateId, DisplayName, Description, Members: [{displayName,
  userPrincipalName, id}], SID}` for the tenant's **activated** roles.

## Why role_template_id, and the approve-time re-resolution

There is no local PSA table of Entra directory roles to derive from (unlike
licenses), and the staged MCP call deliberately makes **no upstream calls**. So
the tool accepts the **universal Microsoft role TEMPLATE GUID**
(`role_template_id`, surfaced by the existing CIPP role reads) plus a typed
`role_name` confirmation — both safe local scalars stored in the held payload
(template GUID canonicalized to lowercase so casing cannot fork the idempotency
hash). At approval, `executeDirectoryRoleRemoval()`:

1. `listDirectoryRoles(tenant)` — fresh resolution, server-derived tenant.
2. Matches `roleTemplateId` case-insensitively → the activated role object.
   No match → declined (role not active in tenant / bad template id).
3. Cross-checks the resolved `DisplayName` against the typed `role_name`
   (case-insensitive) → mismatch → declined.
4. Verifies the target user's **current membership** by Entra object id
   (`Members[].id` vs the person's `cipp_user_id`) → not a member → declined
   (role already gone; nothing to do).
5. Sends the single-member `ExecRemoveAdminRole` with the **resolved** object
   id and the server-derived user identity.

Every guard throws `CippClientException` → audit `error` row, claim released,
approval declined, nothing changed upstream. The caller-supplied
`role_template_id` is never forwarded upstream; `RoleId`/`RoleName`/`Users`
(and case variants) are added to the caller-supplied-identifier blocklist.

## Held-only invariants (build brief psa-5qrd)

- Server-derived client scope: tenant from `clients.cipp_tenant_domain`, user
  from PSA `person_id` → `cipp_user_id`/`cipp_upn`. No caller-supplied
  upstream identity, no cross-client.
- Held payload stores only safe scalars (`role_template_id`, `role_name`,
  `person_id`); the tenant role OBJECT id never exists at stage time and is
  re-resolved at approval.
- HELD-ONLY, no auto-act ever: `directoryRoleParams()` throws unless the call
  is a staged proposal or the held-approval replay — even an `:immediate`
  grant's `staged=false` call is refused with guidance to re-call staged.
- Legacy full-surface tokens cannot call it (cipp-write class is explicit
  per-grant only); `:staged` grants auto-downgrade `staged=false` calls.
- Kill-switch, 300s per-target cooldown, 24h executed dedup, live-run
  idempotency, `TechnicianActionLog` audit by PSA person id (no tenant/UPN in
  stored summaries; the cockpit display alone carries UPN + role name so the
  approver can verify who loses which role).

## Files

- `app/Services/Cipp/CippRestWriteClient.php` — `listDirectoryRoles()` (curated
  GET with the same URL-safety/DNS pinning as `send()`), `removeDirectoryRoleMember()`.
- `app/Services/Mcp/StaffCippWriteToolExecutor.php` — staged map + cooldowns,
  `directoryRoleParams()` (held-only gate + GUID/name validation),
  `executeDirectoryRoleRemoval()` (approve-time re-resolution), display,
  definitions, identifier-blocklist additions.
- `app/Http/Controllers/Web/TechnicianCockpitController.php` — approve dispatch arm.
- `resources/views/cockpit/index.blade.php` — approval-queue badge.
- Tests: `tests/Unit/Cipp/CippRestWriteClientTest.php` (source-pinned bodies),
  `tests/Feature/Mcp/CippWriteDirectoryRoleTest.php` (schema safety, grant
  gating, structural held-only refusal, staged→approve→execute, approve-time
  decline guards, input/identifier rejection, idempotency).

Ships DORMANT: appears only once an operator grants a token
`cipp_remove_directory_role` (in practice `:staged`); execution is held-only
regardless of mode.
