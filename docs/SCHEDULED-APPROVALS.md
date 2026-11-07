# Scheduled approvals (issue #1724)

## The `execute_at` parameter

Scheduling is an **optional tool parameter, not a settings switch.** There is no
global "enable scheduled execution" control: the former `scheduled_approvals_enabled`
setting and its Settings > Integrations > AI Technician checkbox are removed and are
read nowhere (a stale settings row of any value changes nothing). The feature is simply
present; the **per-tool token grant** on the MCP token page (with-approval `:staged` /
without-approval `:immediate`) is the only permission gate, and the existing
`technician_kill_switch` remains the emergency stop exactly as before.

An MCP call to one of the thirteen capabilities with a scheduled adapter may pass
`execute_at`: an ISO-8601 instant **with an explicit offset** (for example
`2026-09-18T17:00:00-07:00` or `...Z`), strictly in the future and at most seven days
ahead — the same bounds `ApprovalWindow` enforces. `tools/list` advertises the
parameter only on those thirteen canonical tools:

- Mailbox (CIPP): `cipp_set_mailbox_forwarding`, `cipp_set_mailbox_out_of_office`,
  `cipp_set_mailbox_delegate`, `cipp_set_mailbox_gal_visibility`,
  `cipp_convert_mailbox`.
- Tactical: `tactical_run_command`, `tactical_reboot_device`,
  `tactical_shutdown_device`, `tactical_recover_mesh`, `tactical_set_maintenance`,
  `tactical_start_service`, `tactical_stop_service`, `tactical_restart_service`.

For `cipp_set_mailbox_delegate`, the alternative `target_mailbox_upn` shape supports only SharedMailbox, RoomMailbox and EquipmentMailbox verified live at staging and approval, requires `staged: true`, and refuses `execute_at`; scheduling remains available on the `person_id` shape.

Every refusal is named and nothing runs now in its place: `execute_at` on any other
tool — including `tactical_run_script` and `tactical_install_approved_patches`, which
await an immutable/conditional vendor execution primitive (#1911; see
[SCHEDULED-TACTICAL-POLICY](SCHEDULED-TACTICAL-POLICY.md)) — is refused as
`unsupported_scheduling_type:<staged type>`; a malformed, past or too-distant value is
refused as `execute_at_invalid`, `execute_at_not_in_future` or
`execute_at_too_far_ahead`. A second call for the same content naming a different
instant (or none) while the first is still awaiting approval is refused as
`execute_at_conflicts_with_pending_proposal`, never reported as "already staged".

### Staged mode (token grant `:staged`, or auto-downgrade)

The call is staged exactly as today (`McpStaffController` tools/call → the executor's
`stageAction`) with the instant recorded in the proposal's existing
`scheduled_provenance` (`execute_at` normalised to UTC, `execute_at_offset` as the
caller wrote it). The cockpit card shows **Runs at <local time>** in the deployment's
display time zone, with the caller's instant beside it. The ordinary **Approve** button
(`TechnicianCockpitController::approve` → `ScheduledApproval`) admits it through
`ScheduledAdmission` with the window **[execute_at, execute_at + 60 min]** instead of
executing now; the response and flash say "Approved to run at …; it has not executed".
**Hold it** (deny) and expiry are unchanged. The run time is a property of the proposal:
an approve request that itself carries `execute_at`, `schedule`, `start`, `end` or
`timezone` is refused with 422 and does not fall through to immediate execution.

The approver's click is the human factor. The AI's own confirmation inputs are the
sealed `human_inputs`: for Tactical, `confirm_hostname` / `confirm_service_name` are
captured from the call at staging (`scheduled_human_inputs`) and verified again at
admission exactly as `assertScheduledTacticalConfirmation` does for an immediate call
(a wrong hostname refuses at Approve and the proposal stays awaiting). For mailbox
actions the sensitive inputs a staged proposal never stores (`external_smtp`, the
out-of-office bodies) are still typed by the approver on the card, with the same
validation rules as the immediate approval, and sealed into the envelope.

In this release an `execute_at` call on a token that holds the `:immediate` grant is
**also staged for the cockpit**, and the response says so; the no-cockpit lane
(admission straight into `scheduled_authorizations` under token lineage) is a separate
increment. `ScheduledPolicy::lineage` accepts a token that still holds the capability
in either mode (`:immediate` implies `:staged` in the grant grammar), so a
human-approved row from such a token is not refused at fire time.

The former **Schedule approval instead** link, the `cockpit.schedule` form and
`ScheduledMailboxController::create/store` are removed; the cockpit's
**Scheduled approvals — latest 100** result table and the recorded approver's
**Cancel schedule** control for waiting/claimed rows remain. Cancellation can lose the
intent race: inspect the recorded result rather than assuming prevention. Submitted
means an observed send, not completed execution; uncertain means the effect is unknown.
Reconcile with the relevant vendor (CIPP or Tactical), never auto-retry.

### Fire time

Unchanged: the every-minute `technician:scheduled-sweep` (no longer filtered by a
setting; it is a no-op on an empty table) dispatches rows whose window has opened after
re-checking the kill switch, clock health, target identity, ticket binding and the
originating token's grant lineage. Old proposals without recorded staging lineage are
not grandfathered. Scheduling is not immediate approval or a generic delayed task.

### Operational checks

`php artisan technician:scheduled-preflight` is read-only: it certifies DB UTC through
`ScheduledClock` (MySQL and MariaDB) and emits exhaustive state counts, including
unknown states, with no row IDs, nonces, payloads or client/ticket data
(`clock`, `inventory`, `total`, `reconciliation_required`; there is no `enabled` or
`activation_authorized` field any more). Exit 0 means clock certified and no
pending/unresolved inventory; exit 1 means clock unverified, reconciliation required
or unavailable inventory. Counts are a point-in-time observation, not a quiescence
guarantee. Verify `php artisan schedule:list` includes `technician:scheduled-sweep`
every minute and inspect recent scheduler completion and private-note delivery; an
entry in the list alone is not proof of execution.

Clock health requires absolute skew at both read edges <=2 seconds and a positive
`timedatectl show --property=NTPSynchronized --value` within its one-second timeout
from the application runtime. SQLite is not clock certification.

### Container clock diagnostics (not certification)

The stock Docker runtime is **unsupported for operational clock certification**:
`healthy()` requires an in-runtime positive `timedatectl` result as well as the
DB UTC comparison. Do not rely on scheduled execution in that runtime merely
because the host is synchronized: admission and dispatch refuse while `healthy()` is false. On the host, `timedatectl show
--property=NTPSynchronized --value` and `date -u` are diagnostics. Inside the
application container, run `date -u` and
`php artisan technician:scheduled-preflight` (using your normal container exec
command). The preflight queries DB `UTC_TIMESTAMP(6)` via `ScheduledClock` on
MySQL/MariaDB; missing timedatectl/systemd or unknown NTP remains unverified,
exit 1. A host `yes` alone cannot make `healthy()` pass. SQLite/fallback drivers
are explicitly unverified. No privileged container, host socket mount, fake
binary or alternative trust primitive is prescribed here. Supporting that runtime
requires a separately reviewed clock-certification design.

### Stop and drain safely

There is no scheduling switch to turn off. To stop new admissions and pre-intent
dispatch, engage the **technician kill switch** (its own route, deliberately separate
from the AI Technician form) and/or revoke or narrow the originating token's grant;
the read-only preflight then reports the remaining inventory. Neither undoes a
persisted dispatch intent or an already sent operation: in-flight work may finish,
and a dead intent can become uncertain. Do not promise that the kill switch retracts
work past the intent boundary.

Under an explicitly approved operational procedure, run
`php artisan technician:scheduled-drain`. This MUTATES a live persisted DB marker
`scheduled_approvals.quiesced_at` and later delivers private notes; it is not the
read-only preflight and requires production-setting authorization in production.
Admission refuses while the marker exists. A live sweep re-reads the marker inside
the claim transaction and again inside the intent transaction after its live evidence
revalidation, releasing a claim it already holds back to `waiting` rather than
carrying it to the send fence, so a row that is still waiting or claimed when the
marker lands is not terminalized by quiescence. Only a dispatch intent that committed
before the marker can still reach the fence and become `abandoned_no_send`. The marker
lives in a different row from the approval, so those in-transaction re-reads take it as
a LOCKING read: a plain consistent read would be answered from the snapshot the
transaction's first ordinary read opened under the default REPEATABLE READ and could
miss a marker committed after that point, and the lock is held until the transaction
commits. After a drain, still inventory `abandoned_no_send` rows rather than assuming
none can exist. Both vendor send paths re-read row
state, nonce and marker after preparation (including CIPP token acquisition),
immediately before transport. A matching unsent intent becomes `abandoned_no_send`;
a stale holder never overwrites another nonce or terminal evidence. There remains
an irreducible read-to-HTTP window: quiescence cannot retract an already sent call.

The first invocation persists the marker and exits 1 with `quiesced_wait` and
`wait_seconds`; it never sleeps while holding a lock. Repeat after that wait.
Drain waits the shared maximum transport bound (610 seconds) from the ORIGINAL
marker, then acquires the same `scheduled-approvals:sweep-drain` cache lock as the
sweep. `overlap_busy` exits 1; preserve the live holder and retry. All processes
must share a functioning cross-process cache lock store; process-local array cache
is only a test fixture, never deployment certification. Both holders take that lock
with an explicit 3600-second lease, so a killed holder self-expires within an hour
on every store class (file, redis, memcached, database) instead of stalling recovery
and the drain forever; the database store's own 24h default never applies while this
explicit lease is passed. The lease alone is NOT a claim that a run always finishes
first: 100 rows each bounded at 610 transport seconds can far outlast an hour. So both
holders also stop starting new work 2320 elapsed seconds into the run and finish only
the unit already in flight inside the reserved 1280 seconds. That work budget, not the
size of the lease, is what keeps a live holder inside its own lock; do not force-release
a live holder. The sweep splits that budget rather than sharing it: it stops starting
dispatch work at 1380 elapsed seconds, reserving 940 seconds: one bounded in-flight
unit plus 300 seconds of note delivery,
which is local database work only, so a slow vendor backlog can never starve the very
notes it generates. A sweep that stops on either budget just resumes on the next minute
and reports the rows it left in `deferred`, so a truncated sweep is never read as an
empty one; `deferred` is not an error and does not by itself fail the command. A drain
scan cut short by its budget counts an error and exits 1 — re-run it, and never read that
as a clean quiesce.
Drain scans all recovery candidates and pending notes in chunks, never dispatches,
and reports aggregate counts only. Recovery still requires intent age 610 + 30
seconds, so `grace_pending_intents` may require another invocation after the extra
grace. Pending notes/errors also cause exit 1; investigate rather than calling
zero deliveries a clean drain. Exit 0 certifies only that recovery/notes pass, not
activation, absence of uncertainty, or cancellation of waiting approvals.

Late matching receipts are append-only `scheduled_late_receipts` evidence with
nonce, vendor, nullable vendor operation ID, intent and receipt timestamps, and
normalized outcome. These synchronous endpoints do not expose stable operation IDs;
null is explicit, not a manufactured ID or raw response body. Uncertainty and its
fences never reverse; no replay or success audit follows a refused settlement.
The additive evidence migration refuses destructive down. There is deliberately
no automatic marker clear or resume command: retain the marker across restarts;
clearing it requires separate authorization and complete inventory reconciliation.
Cancel waiting/claimed approvals through the recorded approver's stop-only control
where appropriate; the kill switch does not itself cancel them, so account for them
before releasing it. Keep intent/submitted/uncertain evidence for reconciliation, preserve
uncertain fences, and never revive a Scheduled proposal, drop populated tables or
replay an unknown effect. An inverse vendor operation needs separate approval.

Sources: `ExecuteAt`, `ScheduledApproval`, `McpStaffController` (tools/call),
`McpToolModes::unifyDefinition`, `TechnicianCockpitController::approve`,
`ScheduledMailboxController::cancel`, cockpit views, `ActionRegistry`, `MailboxPlan`,
`TacticalPlan`, `ScheduledAdmission`, `ScheduledPolicy`, `ScheduledCoordinator`,
`ScheduledClock`, `ScheduledSweep` and `routes/console.php` at this repository tip.

## Historical PR1 substrate notes

The following describes the original PR1 increment, **not current adapter/UI
availability**. The sections above describe the shipped surface.

This increment is **not an executable scheduled-action feature**. It adds a dormant
server-side authorization ledger, not an adapter or a new bearer credential. There
is no schedule route/UI, production evidence provider, vendor client or action-bus
call. The original substrate flag was false by default; the registry has exactly
30 future action contracts but `adapterAvailable()` always returns false. Even a
locally enabled flag cannot send anything. Immediate approvals and offline queue
queries remain unchanged. Scheduled runs cannot pass the existing immediate CAS.

## Boundaries

- `ApprovalWindow` rejects IANA gaps and folds, including non-hour transitions.
  Start is strictly future and at most seven days out; explicit end is after start
  and no more than 24 hours later. Saved UTC and offsets never move with rendering.
- `ApprovalEnvelope` encrypts the whole canonical binding and authenticates its
  digest with the application key. Full messages/addresses, not length/domain
  summaries, are bound. Key rotation/unreadable ciphertext refuses; it does not
  silently re-authorize. The envelope includes run/content revision, action/tool,
  exact client/ticket, approver, source token, provenance and window.
- `ScheduledAdmission` is an internal API requiring a read-only `ScheduledEvidence`
  implementation. **None is registered here.** Future adapters must implement
  exact action policy, full payload validators, tenant/object/mapping provenance
  and client access checks. A plain domain/UPN is not immutable identity evidence.
  Legacy staged rows lack instrumented provenance and refuse. The future staging
  integration must write real source token identity; no label-based grandfathering.
- Active Admin/Tech only; Billing/Contractor denied. Token must remain active and
  explicitly hold the capability (`:staged` or `:immediate`; immediate implies
  staged). Broad/null full-surface grants and bare legacy names are intentionally
  insufficient. Native-human provenance must identify an active
  authorized human. Future token client-scope extensions must be checked too.
- Admission locks the proposal, creates the sealed row, reserves run and target,
  changes its state to `scheduled`, and inserts the initial note in one transaction.
  Repeated identical admission returns the same row; changed confirmation refuses.
  DB unique reservations serialize all effects on a pinned tenant/object within a
  client, **conservatively including nonoverlapping windows** while a reservation
  is live. This stricter substrate restriction may be narrowed only with overlap
  controls; it never silently coalesces separate approvals.
- Claims are fenced by UUID and attempt count. Time is DB UTC on MariaDB. Clock
  health requires positive `timedatectl NTPSynchronized=yes`, bounded subprocess
  duration, and absolute DB/system skew **<=2 seconds at both read edges**. Missing
  tooling/unknown NTP/slow clock reads fail closed. This is not physical-time proof
  when both clocks are wrong. SQLite is not operational clock certification.
- Final intent checks exact bindings, provenance and permissions again, half-open
  window and nonce/state. In PR1 it terminates as `adapter_unavailable`. Future
  adapters must not bypass this fence or reuse generic immediate approval routes.
  No external network call may run inside the transaction.
- Pre-intent defer supports only offline/read-unavailable/cooldown/kill-switch/
  clock-unhealthy. Backoff is 1/2/4/8/15 minutes, respects a later cooldown, caps at
  100 claims and original expiry. Changed wait reasons produce one note. A crash
  before intent can be reclaimed only after invalidating the old nonce.
- A persisted intent is never automatically reissued. Abandoned intent becomes
  terminal `uncertain`, including death before an unobservable send. Submitted is
  not completed, but its send was observed, so only uncertain target fences remain
  for later read-only reconciliation; this PR deliberately has no fence-clear or
  reconcile endpoint. A failure proven to precede the send settles `failed`.
  Cancel/expiry cannot report prevention after intent. Terminal rows never become
  awaiting approval or legacy offline queue rows. Replacement requires a new
  proposal/fresh confirmation, not mutation or revival of the old row.
- Result state and outbox event commit together. Delivery inserts a private System
  note and acknowledges it under the outbox row lock in the same local transaction.
  No public reply/email API is called. Notes contain only fixed event/reason codes,
  run/approver identifiers and schedule bounds. Missing tickets retain orphan audit
  with `ticket_missing`. Submitted/uncertain notes require reconciliation explicitly.
- Ciphertext remains through terminal state plus 30 days, then is nulled, digest
  retained. Submitted is nonterminal pending reconciliation. No schema cascade
  deletes evidence. Migration rollback refuses when any authorization exists.

## Tests and limitations

`ScheduledApprovalPrimitivesTest` covers exact allowlist count/exclusions, no
adapters, canonical full-input sealing and strict window/DST validation.
`ScheduledApprovalTest` covers service admission/idempotence, clock/permissions,
lineage revocation, binding mutations, target conflict, fencing, cancel/expiry,
pre-intent retry and terminal uncertainty, note dedup and retention.

`ScheduledApprovalMariaDbTest` repeats the service controls on isolated MariaDB
10.11 and adds independent PHP-process admission/claim/cancel races, SIGKILL after
claim and synthetic intent, SIGKILL between note insert/ack, and fault triggers at
admission/settlement outbox boundaries. It requires an explicitly supplied
`SCHEDULED_TEST_SOCKET` ending in `/test.sock`, a sibling `db-launch.pid`, database
`scheduled_synthetic_test`, root with no password on that isolated socket and
`@@skip_networking=1`. It **destroys only that synthetic database**. Set
`SCHEDULED_TEST_EXTENSION_DIR` if the test PHP needs isolated mysqlnd/pdo_mysql
extensions. Without the socket the MariaDB class skips, not passes certification.
CI's SQLite run cannot establish concurrent locking/crash behavior.

Synthetic intent rows are deliberately seeded to test future dispatch-boundary
recovery. No actual provider request, provider acceptance, upstream idempotency or
adapter correctness is certified by PR1. Later PRs still owe all exact per-action
argument/mutation controls, live read-only evidence contracts, staging provenance
instrumentation, UI and integration/kill-switch gates. #1399 is Mesh-specific and
is not fixed or a gate for these scheduled-only notes.

## Rollback and release

Before merge, abandon/revert the unmerged branch. After an authorized deployment,
disable new admissions/dispatch; retain additive schema and immutable evidence.
Cancel waiting/claimed authorizations transactionally and drain private-note
outbox; leave intent/submitted/uncertain rows for controlled reconciliation. Do
not drop populated tables or reinterpret Scheduled as an old executable state.
An external operation is never undone by code rollback; inverse effects need a
separate approval. No production setting, grant, integration or adapter activation
is authorized by this PR. Full exact-tip gate, MariaDB controls, CI and held review
with independent adjudication remain release prerequisites.
