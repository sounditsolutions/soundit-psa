# Decisions and retained development obligations

These are retained rulings, not new authorization for any action. Forge maintains the record; Jeeves adjudicates development changes; Charlie owns product/design and production decisions.

<a id="pricing"></a>
## 2026-07-13 — Pricing

Source: `863bbcd60fc7721a1891cf3ac22b753672e22e39:CLAUDE.md`, Contracts & Billing architecture (owner ruling dated in the original). Full wording retained:

- **Graduated vs volume tiers — two rate cards, one seam, line overrides SKU default** — The billing engine carries two *different, both legitimate* tier pricing models with deceptively similar shapes (each is an ascending `up_to` + `unit_price` list with an unbounded final entry). **Volume** (per-SKU `backup_storage_tiers`, backup-storage lines only): picks the one tier covering the measured quantity and bills the *whole* quantity at that single rate. **Graduated** (per-profile-line `pricing_tiers`, any quantity type): splits the quantity into bands and bills *each band* at its own rate. Same numbers, different money — 300 GB over 1.00/0.80/0.60 is **$240 volume** but **$260 graduated**. They are deliberately kept distinct: same shape with opposite semantics is exactly how you mis-bill a client, so do not "unify" them without also unifying the semantics.
  - **The SKU's pricing method is a DEFAULT, not a constraint.** A line that carries its own graduated bands overrides the SKU's volume rate card — allowed at every door (profile line create/edit, bulk `set_quantity_type`, and the SKU tiers form gaining a card under an already-graduated line), never refused, no confirm dialog. `BillingService::priceLineSegments()` — the single seam where a (line, quantity) becomes money — encodes **graduated (line) > volume (SKU) > flat**: a line-level setting beats one inherited from the product, the same precedence as `unit_cost_override ?? sku->unit_cost`. (Owner ruling 2026-07-13: SoundPSA targets small/single-member MSPs where the SKU-creator is usually the invoice-creator and per-client pricing varies; a mandatory SKU method is friction without benefit.)
  - **The override is never silent.** Real money must not hang on a code-precedence rule the operator never sees, so which card applies is stated everywhere the billing decision is made or reviewed: an inline note beside the graduated toggle in the profile line editors (driven by `data-has-volume-tiers` on the SKU options), a per-line rate-card badge + explicit override notice on the profile show page, a note on the SKU tiers editor naming the profile lines that override its card, the applied card in invoice preview (`quantity_source`), and an info log at generation. `App\Support\PricingModelOverride` is the single predicate all of them consult — an override needs all three of: graduated bands on the line, a volume rate card on its SKU, **and** `quantity_type = PerBackupStorageGb` (the only type that reads the card; a graduated per-user line on the same SKU overrides nothing, and no surface may claim it does).
  - **Audit record.** Where a *non-flat* rate card priced a line, the invoice line's `quantity_source` names it — `[graduated: N bands]` or `[volume tier rate $X/GB]` — so the record can never claim a rate that was not applied. It is appended to the quantity description, or stands alone when there is none: a **Fixed** line records no quantity source (the operator typed the number; nothing to audit about *how much*), but Fixed + graduated still records `[graduated: N bands]`. Only a plain **flat Fixed** line has a null `quantity_source`.

<a id="authority"></a>
## 2026-09-13 — Freeze, release and production authority

Source: card `6aa664b8`, reaffirmed by card `6i4cN8BC` (Charlie: “Lift it. Yes, you should make these calls in the future.”). Jeeves is Charlie's standing development delegate and makes PSA freeze/release calls, including ordering reviewed, green, adjudicated changes to land through the normal gate. A specific PR hold remains until its owner lifts it. A watchdog alarm, stall gauge or drained queue is not release authority.

Production settings, grants, credentials, live-route activation, schema-destructive operations, client-data migrations and irreversible production actions require Charlie's explicit go for the specific action. Development approval is not production authorization. Escalate ambiguous product/scope judgments, security beyond a mechanical fix, schema/data-loss risks, money, customer impact and irreversibility to Jeeves; they cannot spend Charlie's production authority.

<a id="release-conduct"></a>
### 2026-09-25 — Merging is not releasing (card `PNxPmLMO`)

A seat that merges to `main` owes the release in the same sitting. Where Jeeves's release go covers the merge, it deploys through the normal gate and verifies at the consumer boundary; without that go, or when it deliberately does not ship, it records the no-deploy as below and names on the owning card who ships it. This entry grants no release or production authority; the authority entry above governs who may deploy. Ending silently between those two is forbidden.

Observed 2026-09-23 on card `57SuhqPY`: a seat merged, ran `psa-deploy-gate.sh`, saw it PASS, and stopped. For about fifteen minutes the ledger, CI and the deploy gate all read green while production ran the old code. **A GATE PASS IS NEITHER RELEASE AUTHORITY NOR A DEPLOY.** It was caught by a later session re-deriving at source, not by any alarm.

A deliberate no-deploy is legitimate and is recorded, not merely intended: the sha goes in the no-ship acknowledgement list with a reason and the owning card names who ships it, so correct inaction is distinguishable from neglect. Detection is a drift gauge comparing `origin/main` against the deployed HEAD; it reports what it measures (two shas, a clock, whether a deploy gate has passed on the tip) and does not assert why, because a gauge may fire correctly and still accuse the wrong cause.

<a id="review"></a>
## Review contract retained on 2026-09-15

Source: gate SPEC (2026-07-28; review-brief amendment 2026-09-12), recorded by parent card `CKFuKp9m`, leg-1 ruling `6aa9df4732eaa04b2e8a65bd`. This repository copy retains the obligations for contributors; it does not modify the harness or rubric.

FIND → ADJUDICATE → REWORK → VERIFY is structural, not an approve/reject loop. FIND uses independent diff/context/contract slices (not persona selection or reviewers calling reviewers), plus a deterministic seat for syntax, changed-module smoke, invariants and secrets. Report every finding with evidence and a concrete failure scenario, not a verdict. Each timed-out model seat gets one replacement, then forfeits; quorum is any two of three model seats plus the deterministic seat.

The arbiter classifies existing findings, not new ones, with three samples and per-finding majority. Classes are must-fix, ticket, discard, with duplicates identified. Two of three win; without a majority choose the most severe (must-fix > ticket > discard). A security finding majority-discarded is still filed as a ticket flagged for Charlie, never silently discarded.

**RUBRIC:** must-fix means incorrect results on valid input; crash/hang on admitted input; introduced security vulnerability (injection, authorization bypass, secret exposure, unsafe deserialization, externally triggerable resource exhaustion); data loss/corruption; or broken documented API contract/invariant relied on by callers. Ticket means real non-blocking degradation outside the evident contract, maintainability/naming/structure, missing tests/docs, hardening beyond the contract, performance without a demonstrated pathological case, or valid findings outside the rework delta. Discard means factually wrong, non-behavioral style preference, speculation without a concrete scenario, or duplicate (mark duplicate_of). Standards prose does not itself amend those classes.

The author fixes exactly must-fix findings; tickets are filed rather than fixed in that cycle. No change against standing must-fix findings goes to terminal verdict. VERIFY reviews only the rework delta: confirm each fix with evidence; new findings on the delta are fully valid and adjudicated by the same majority, outside it advisory. No new must-fix permits a merge recommendation; otherwise repeat rework/verify up to three cycles total. Terminal vocabulary: merge, merge-with-final-edit, reject, escalate. Overridden objections are filed as tickets. A recommendation is not release authority.

<a id="held"></a>
## Held review and landing

Source: development protocol dated 2026-07-29, parent `CKFuKp9m` §4, retained by leg-1 ruling `6aa9df4732eaa04b2e8a65bd`.

`hold: true` requests human-grade review. Verify branch state in git, not a payload; assess every finding on its merits, not panel authority. Run guards and the full suite on the landing tree, red-check new guards against unfixed code, and require CI green at the exact SHA. Every added commit, even test-only, goes back through review before merge. Compare PR head with the ledger's `completed_payload->>'sha'`, and merge that recorded SHA, not an assumed branch head. After release authorization, merge `--no-ff` with the verdict in the message, deploy through the normal gate, then verify production HEAD and `/` → 302 → `/login` → 200. Report on the owning card. None of this authorizes a deployment on its own.

<a id="ledger"></a>
## Held-verdict ledger

Source: card `Y9XACeDi` (2026-09-15), parent `CKFuKp9m` §4. The holder records DECISION/PIN and the residual MANIFEST before filer/landing consumption; narration is not a ledger act. Use the installed ledger client and binding contract, not hand-invented markers. Missing holder-appended DECISION/PIN refuses filing. Preserve distinct UNPINNED and UNMANIFESTED dispositions; neither is acceptance. Ledger binding does not waive unresolved findings or grant release authority.

<a id="development"></a>
## Issue-first and vendor isolation

Sources: cards `6aa86bf7` (2026-09-14) and `6aa9bf6f` (2026-09-15). Open the issue before implementation, use an isolated owned branch, and scope a change to its brief. Tests use `Http::fake` plus `Http::preventStrayRequests`; dev/tests/CI must not make live vendor calls. Vendor credentials are read only through their Config path. Present one exact-tip held review, not competing launches. Jeeves owns release; Charlie owns the live click. A source fixture does not grant permission to call a vendor.

<a id="evidence"></a>
## Mutation and red controls

Sources: card `6aa9bf6f` handback 2026-09-15 and card `6aa69606`. Handback identifies each meaningful mutant, its intended behavioral defect and the assertion that killed it. Run new guards against unfixed code; a missing symbol/import or setup failure is not a behavioral red. Include a positive control reaching the behavior on both sides, restore the original source, and rerun guards and the full suite green. Prove the loader binds to the tree tested; a linked dependency tree can silently test a sibling. State exact SHA, commands, results, denominator, failed/skipped checks and limitations. Documentation controls may mutate documentation, not runtime behavior; do not claim those controls validate application logic.

<a id="brief"></a>
## Driver brief admission

Source: SPEC amendment 2026-09-12 and card `6aa39605`. Keep the descriptor in `domain`, terminate its sentence, add a blank line and `Review brief:`, and preserve the full brief. `notes` is rebase-only (`rebase.notes`); top-level review notes are dropped. The installed `preflight.cjs` must run before any separately permitted launch: exit 2 (dropped notes) or 3 (unverified) refuses clearance; exit 0 is narrow static clearance, not launch/release authority. More than 2,000 characters is advisory, not a model limit. Do not truncate or rewrite historical payloads.

<a id="push"></a>
## Durable work before review

Source: development ruling 2026-07-31, parent `CKFuKp9m` §4. Work carrying a real fix must be committed and pushed to origin as soon as it exists, before review and before ending the session. Verify the remote ref and clean local state; resolve failed pushes rather than leaving work only in a worktree. Push carries a branch ref, not merge, deployment or release authorization. File remaining work, run gates, update status and hand back context; do not erase another author's residue while cleaning up.

<a id="hygiene"></a>
## Public-source hygiene

Source: parent `CKFuKp9m` §4 and corrected leg-1 ruling 2026-09-15 on `6aa9df4732eaa04b2e8a65bd`. The public tracker credential files under Charlie's own account (`Wldc4rd`): each filing is a technical claim in that name. No client material, secrets, internal hosts, IPs or absolute infrastructure paths in public issues, PRs or source citations. Cite commits, tests, PR/issue numbers, card IDs or repository documents. Issue bodies are create-only in this workflow: correct with a comment; PR titles/bodies are editable. A green secret regex is not proof of privacy. Newly discovered unfixed vulnerabilities require private escalation, not public disclosure.

Source: card `zLXEBPec`, Charlie's 2026-09-22 ruling (“integration mechanics are ours to document”), recorded by Jeeves the same day.

Vendor integration mechanics (endpoints, parameter/query shapes, redirect chains, auth flow, product names) may be documented in public source, tests, issues and PRs; credentials, client-derived identifiers and vendor security weaknesses may not (G-9; private escalation).

This retires Jeeves's 2026-09-04 bar on putting integration detail in the public tracker, so code and issues follow one rule again. The existing prohibition on client material (including real press IDs from real client tickets), secrets, internal hosts, IPs and infrastructure paths remains binding; vendor security weaknesses stay under private escalation.

<a id="modes"></a>
## Fail-closed MCP modes

Source: card `6aa986d5`, retained 2026-09-15. Unknown is refusal, never a pass. A bare grant runs immediate; held-only verbs never offer immediate execution. Do not infer mode authority from a permissive fallback or missing grant data. Preserve the distinction between a capability's catalog presence, caller grant and live availability.

<a id="runtime-mechanism-strings"></a>
## 2026-09-23 — Runtime mechanism strings

Source: adopted development ruling on runtime mechanism strings, coordination card `Ujy42wr5`, retained by the sweep in issue #3265 and PR #3266. The adoption record and its round-two clarification are held in private coordination; this public record carries the rule without private thread content.

An operator-facing runtime string that asserts why something happened, how a function behaves, or what is true of data is output and must be true on every emitting path. Pure event labels name an event without asserting a mechanism and are outside this rule. Work one site at a time: first delete an explanatory clause when structured keys already carry the fact or it is false or unverifiable on any emitting path; otherwise assert the claim on every emitting arm, killing a swap/merge-arms mutant or a predicate-weakening mutant for a single arm. Logging controls cover every exposed level and generic log calls, not just the expected record's level. Prose with an executable citation is the last resort. Verify truth by isolated execution before pinning text; do not bulk-assert strings or cite suite totals as truth evidence. Cite methods, not unstable line numbers. This governs new mechanism strings too; comments and names keep their separate delete-or-rename remedy. [STANDARDS G-14](../STANDARDS.md#later-development-obligations) indexes this adopted rule.

<a id="runtime-mechanism-strings-addendum"></a>
### 2026-09-25 addendum — logging controls (issue #3695, card `K5qwIx3B`)

Source: issue #3695 and card `K5qwIx3B`, landed as PR #3696; corrected by issues #3697 and #3703. This addendum was recorded after the 2026-09-23 ruling and is not part of the record adopted under `Ujy42wr5`. It adds no obligation and amends no adopted wording. It is not a checklist that controls are admitted or refused against. Whether a control covers "every exposed level and generic log calls, not just the expected record's level" is judged under the adopted clause alone. This addendum records, for the framework pinned below, the ways a log record can reach a handler without meeting a facade expectation, so that control authors and reviewers can see them. [STANDARDS G-14](../STANDARDS.md#later-development-obligations) indexes the adopted clause and points here.

Laravel's logger has two level-first generic entries: `Log::log($level, $message, $context)` and `Log::write($level, $message, $context)`. A record also escapes a facade expectation when the code under test writes it through an object that a facade method returned. That record goes through the returned object and not through the facade. The set of such methods is open, and the `Log` facade docblock does not bound it. `LogManager::__call` forwards any method `LogManager` does not declare to the default driver. `Illuminate\Log\Logger::__call` then forwards any method `Logger` does not declare to the underlying Monolog logger. Examples at `38ff0c83`:

- Methods that return a logger: `channel()` (whose body is `return $this->driver($channel)`), `driver()`, `stack()`, `build()`, `withContext()`, `when()`/`unless()` and `getLogger()` (the underlying Monolog logger).
- `getChannels()`, which returns the resolved loggers.
- `shareContext()`, `withoutContext()`, `flushSharedContext()`, `extend()` and `setApplication()`, which return the manager. The manager logs like the facade.
- Monolog methods reached only through the `__call` chain and absent from the docblock: `withName()` (which returns a renamed clone) and `pushHandler()`, `setHandlers()` and `pushProcessor()` (which return the Monolog logger itself).

None of these methods is a log call in itself. A record escapes only when the code under test logs through the value one of them returns, and only that call is a log call under the adopted clause. No control refusing all three of `write`, `channel` and `stack` remains; it retired with the portal connection test.

A `MessageLogged` listener (`Log::listen`) receives what `Logger::writeLog` writes. That covers the facade's level methods, `log`, `write`, and records from the Illuminate loggers or the manager returned by the methods above. It does not cover a record written through a Monolog logger reached from the facade. That includes `getLogger()`, and also `withName()`, `pushHandler()`, `setHandlers()`, `pushProcessor()` or any other Monolog method that returns a logger through the `__call` chain. Such a record bypasses `Logger::writeLog` and fires no event, so no listener sees it. A refusal on a spied facade has to name each method, so it cannot cover this open set. The listener has two further limits. First, `Logger::writeLog` returns before `fireLogEvent` when the channel's handler does not handle the level (`isHandling`). A record below a channel's minimum level therefore reaches no listener and no handler, for example on the `slack` channel in `config/logging.php`, which defaults to `critical`, or on any channel under a raised `LOG_LEVEL`. A listener control sees only the levels the test environment's channels handle. Second, the event carries the level string exactly as the caller passed it, so `Log::log('WARNING', ...)` arrives as `'WARNING'`, and a listener that compares against `'warning'` misses it.

A run on 2026-09-25 under `Log::spy()` recorded no commit, no package versions and no probe. Under G-12 it is not a measurement this record can cite, and nothing below is pinned as measured truth under G-14. The results below match what that run reported, but they have not been re-executed. They are inferred from source at `38ff0c83`, with laravel/framework v12.69.2, mockery/mockery 1.6.12 and the monolog/monolog version pinned in that commit's `composer.lock`. To measure one of them:

1. Write a PHPUnit test extending `Tests\TestCase` at the commit under test.
2. Call `Log::spy()`, make the one call under test and run the one expectation.
3. Record the outcome (pass, or the exception class) together with the commit SHA, the three package versions from `composer.lock`, and the probe's path or full text.

Inferred results: `Log::write('warning', ...)` passes `shouldNotHaveReceived` for all eight levels and `log`, so an exclusion control that omits `write` reads green. `Log::channel(...)->warning(...)` makes the spied `channel()` return null, and the production call then errors. A generic `Log::log('warning', ...)` does not satisfy a per-level `warning` expectation (Mockery raises `InvalidCountException`), because the spy records it as a call named `log`. A per-level closure registered on `log` and expecting `($message, $context)` binds positionally, so `$message = 'warning'` and `$context` = the sentence. What that does depends on the closure. If it filters on `$message`, it drops every generic record: an absence control then passes green, and a presence control expecting one record fails red. If it indexes `$context` by key, PHP raises a `TypeError`. The dangerous case is the green absence control.

Source basis, re-derived on 2026-09-26 without re-execution, at the same commit and versions. `LogManager` declares no `write`, so the facade's `write` reaches the spy as a call named `write`, and no `shouldNotHaveReceived` over the eight levels and `log` inspects that call. A spy returns null for any call it has no expectation for unless the method declares a return type (`Mockery\Mock::__call`). None of the methods listed above declares one on `LogManager`, and several exist only through its `__call`. So on a spied facade each of them returns null, and a log call chained onto it errors. The listener limits above follow from `Logger::writeLog` and `Logger::fireLogEvent`. The Monolog bypass follows from `LogManager::__call` and `Logger::__call`. These facts depend on the installed versions. After any laravel/framework, mockery or monolog/monolog upgrade, run the probe described above at the new commit before relying on them.

This sweep records one instance of that binding hole: PR #3614's round-one `diff:1`, in the fix for issues #3586 and #3599. A separate hole is a control that accepts or captures other levels without asserting which level the record arrived at. Assert the level, not only the record count: a control that captures every level but asserts none cannot see a demotion. This sweep records these instances of it: issues #3586 (info/debug/error stubbed and never read, so moving the cause onto another level survived) and #3599 (an any-level capture letting a demotion survive), both folded into PR #3614; PR #3614's round-one `contract:1` (a warning-to-debug demotion still produced the single record `assertCount(1)` expected); and a pre-commit draft of the #3675 reply-threading control, a `Log::listen` capture of every level in which mutant m5's warning-to-debug demotion survived.

<a id="reporting"></a>
## Measured reporting

Source: measured-reporting protocol (2026-08-03), retained by parent `CKFuKp9m` §4 and leg-1 ruling. Distinguish measured, inferred, attempted, skipped, unknown and failed. Anchor citations to an immutable commit and measurement timestamp (or symbol/step when no SHA exists); enumerate the complete set and name the denominator. Every assertion of absence needs proof the instrument ran and a positive control. Unknown or unable-to-assess is refusal, never pass. Read what the instrument knows and defaults to, verify the load/consumer boundary, and report environment limits as findings. Correct a cited artifact by addendum with a visible pointer, not silent rewriting of its cited identity.

