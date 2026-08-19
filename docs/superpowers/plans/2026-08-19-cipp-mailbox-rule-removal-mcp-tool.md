# CIPP staged mailbox inbox-rule removal MCP tool

Staged, **held-only** removal of ONE inbox rule from ONE server-derived user's
mailbox — compromise remediation and mailbox hygiene (strip a malicious
forwarding/delete-mail rule after an account takeover). Mirrors the
directory-role removal pattern (2026-07-12 plan, bead psa-5qrd) end-to-end: one
capability (`cipp_remove_mailbox_rule`) with a staged twin
(`cipp_stage_remove_mailbox_rule`), and the capability is **structurally
held-only** — `mailboxRuleParams()` throws on every non-held path, so no grant
mode can execute the upstream removal without a cockpit approval.

## Source-verified upstream shape

Verified against `KelvinTegelaar/CIPP-API` @ master (fetched 2026-08-19):

- **Write — `POST api/ExecRemoveMailboxRule`**
  (`Modules/CIPPHTTP/Public/Entrypoints/HTTP Functions/Email-Exchange/Administration/Invoke-ExecRemoveMailboxRule.ps1`,
  `.ROLE Exchange.Mailbox.ReadWrite`). Reads `TenantFilter`, `ruleName`,
  `ruleId`, `userPrincipalName` from query or body; computes
  `MailboxObjectId = ruleId.Split('\')[0]`; calls `Remove-CIPPMailboxRule`
  **WITHOUT** `-RemoveAllRules`, so the API path deletes exactly one rule.
  Returns HTTP 200 `{Results: <message>}` on success or HTTP 500
  `{Results: <error message>}` on failure — so `send()`'s status check throws
  `CippClientException` on failure and no `guardReportedFailure()`
  200-with-error-in-body wrap is needed (that guard exists for the spam-filter
  endpoints, whose failures ride HTTP 200).

- **`Modules/CIPPCore/Public/Remove-CIPPMailboxRule.ps1` (single-rule arm)**:
  `New-ExoRequest -cmdlet 'Remove-InboxRule' -Anchor $Username -cmdParams
  @{Identity = $RuleId}` with a fallback retry anchored to `$MailboxObjectId`.
  The `-RemoveAllRules` arm (not reachable through this endpoint) is where the
  protected-rule filter lives upstream: `Name -ne 'Junk E-Mail Rule'` and
  `Name -notlike 'Microsoft.Exchange.OOF.*'`. We deliberately do **not** mirror
  it: that filter guards a bulk delete where the operator named no rule, while
  here the approver names exactly one — and a name filter would be keyed on a
  string the ATTACKER picks, making a rule called "Junk E-Mail Rule"
  un-removable through this verb *and* reported to the approver as not
  existing (a false all-clear on the remediation path). The single-rule arm
  imposes no such restriction, and neither do we.

- **Read — `GET api/ListUserMailboxRules` with `TenantFilter` + `UserID`**:
  a LIVE Exchange call (`Get-InboxRule -Mailbox $UserID`), scoped to one
  mailbox server-side — already documented in this repo at
  `CippMcpToolRelay.php:34-57` against its cache/queue-backed tenant-wide
  sibling `ListMailboxRules`. Because it is live it should never answer with a
  queue marker, but `listUserMailboxRules()` runs
  `CippQueueGuard::assertNotQueueBacked()` anyway (the `listMailQuarantine`
  guard-at-the-source precedent, psa-lmex): a "still loading" reply must never
  read as "this mailbox has no such rule".

## Why rule_name, and the approve-time live resolution

The per-mailbox listing projection
(`CippToolContract::DEFAULT_FIELDS['cipp_list_mailbox_rules']`) exposes only
rule names — no `Identity` — and the house invariant bans caller-supplied
upstream identifiers (`ruleId`/`RuleId`/`ruleName`/`RuleName`/`InboxRuleId` are
added to `UPSTREAM_IDENTIFIER_KEYS`; `Identity`/`Identities` were already
present). So the agent-facing schema identifies the rule by **`rule_name`
only** (bounded string, ≤ 256), a safe local scalar stored in the held payload.
At approval, `executeMailboxRuleRemoval()`:

1. `listUserMailboxRules(tenant, upn)` — fresh LIVE listing, server-derived
   tenant and mailbox owner.
2. Drops rows whose own mailbox marker (`MailboxOwnerId`, else the
   `<mailbox>\<ruleId>` Identity prefix) PROVES another mailbox — the read
   path's guard (psa-7lgo.1), applied here because upstream anchors the delete
   to that prefix, not to the `userPrincipalName` we pass.
3. Matches the stored `rule_name` case-insensitively after trim, against the
   raw upstream `Name` **or** the fenced form the per-mailbox read shows the
   agent (the projection fences rule names as untrusted free text, so the
   read→write round trip breaks on attacker-authored names otherwise —
   psa-4k6m.8). Zero matches → declined ("no inbox rule named … exists on this
   mailbox; nothing was removed"). Two or more → declined naming the
   ambiguity; nothing removed. No name is un-removable.
4. On the unique match, refuses unless the row is provably on the approved
   mailbox or the listing carried exactly one mailbox, then sends
   `removeMailboxRule()` with the matched rule's own upstream `Identity` as
   `ruleId` and its actual upstream name as `ruleName`.

Every guard throws `CippClientException` ending "nothing was removed." → audit
`error` row, claim released, approval declined, nothing changed upstream.

## Held-only invariants

- Server-derived client scope: tenant from `clients.cipp_tenant_domain`, user
  from PSA `person_id` → `cipp_user_id`/`cipp_upn`. No caller-supplied
  upstream identity, no cross-client. `confirm_upn` must match the resolved
  mailbox owner.
- Held payload stores only safe scalars (`rule_name`, `person_id`); the rule's
  upstream `Identity` never exists at stage time and is resolved fresh at
  approval. `rule_name` rides `safeMailboxParams()` (the approver reviews the
  exact rule to be deleted); no new sensitive_inputs / re-type field for v1.
- HELD-ONLY, no auto-act ever: `mailboxRuleParams()` throws unless the call is
  a staged proposal or the held-approval replay — even an `:immediate` grant's
  `staged=false` call is refused with guidance (message carries the literal
  `held-only` and `staged=true`).
- Legacy full-surface tokens cannot call it (cipp-write class is explicit
  per-grant only); `:staged` grants auto-downgrade `staged=false` calls.
- Kill-switch, 300s per-target cooldown for both names, 24h executed dedup,
  live-run idempotency, `TechnicianActionLog` audit by PSA person id (the
  cockpit display alone carries the UPN + rule name).
- Name-collision check: `CippMcpCatalogSyncService::localNameFor()` maps the
  actual upstream endpoint `ExecRemoveMailboxRule` to
  `cipp_exec_remove_mailbox_rule`, which does not collide with
  `cipp_remove_mailbox_rule`; and now that the curated name exists,
  `CippMcpToolPolicy::refusalReason()`'s collision branch refuses any future
  dynamic row that normalises to it (the same guard that already covers
  `cipp_list_mailbox_rules`, see `CippMcpToolPolicy.php`).

## Files

- `app/Services/Cipp/CippRestWriteClient.php` — `listUserMailboxRules()`
  (curated GET, queue-guarded at the source) and `removeMailboxRule()`
  (source-pinned body, non-empty inputs validated before any HTTP).
- `app/Services/Mcp/StaffCippWriteToolExecutor.php` — staged map + cooldowns,
  `RULE_NAME_MAX`, identifier-blocklist additions, `mailboxRuleParams()`
  (held-only gate + bounded name), `executeMailboxRuleRemoval()` (approve-time
  live resolution + foreign-mailbox guard + fenced-name match + unique-match
  gate),
  `mailboxRuleDisplay()`, `safeMailboxParams()` allowlist, definitions.
- `app/Support/StagedActionLabels.php` — `'cipp_stage_remove_mailbox_rule' =>
  'CIPP mailbox rule removal'`.
- `app/Http/Controllers/Web/TechnicianCockpitController.php` — approve
  dispatch arm.
- `resources/views/cockpit/index.blade.php` — approval-queue badge
  (byte-identical label, `bi-envelope-x`).
- Tests: `tests/Unit/Cipp/CippRestWriteClientTest.php` (curated-method
  allowlist, source-pinned bodies, empty-args-before-HTTP, queue-guard),
  `tests/Feature/Mcp/CippWriteMailboxRuleTest.php` (schema safety, grant
  gating, structural held-only refusal, staged→approve→execute with live
  resolution, zero/ambiguous/foreign-mailbox declines, protected-name and
  fenced-name removals, input/identifier
  rejection, idempotency).

Ships DORMANT: the verb arrives in nobody's `allowed_tools` — it appears only
once an operator grants a token `cipp_remove_mailbox_rule` (in practice
`:staged`); execution is held-only regardless of mode.
