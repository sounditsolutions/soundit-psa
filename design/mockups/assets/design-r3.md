# Asset Sources r3: telemetry-first source panels (design only)

Scope: the r3 design ruling (2026-10-21 14:06 PT), items 1-6. Code read at main **6c2ef8d3** only. Facts come from the r3 telemetry inventory (cited); r2 facts from the r2 inventory. The inventories, the r2 design and the mockup generator are design-round working files and are not in the tree; re-verify any cited `path:line` at your own base.
- Gaps I filled are marked **[FORGE CHOICE]**.
- Questions are **Q-n (A/B)**: for Jeeves unless marked *for Charlie*.
- Changes against the accepted r2 design (design.md) are marked **⚑ r3 CHANGE**.

Mockups (this directory, synthetic data):
- `mockup-a-sources-linked.html/.png`: a1 Tactical healthy, a2 AutoElevate in audit mode
- `mockup-c-429.html/.png`: c1 AutoElevate 429, c2 Tactical agent unreachable
- `mockup-d-failing-check-reboot.html/.png`: one failing check and a pending reboot, opened from a ticket
- `mockup-b-source-off` is re-rendered in the r3 template (⚑ r3b, Jeeves 22:1xZ fix 2; see §9 (b)).

---

## 1. Q-1 = A, kept
One **Sources (n)** tab with the rail (Charlie: "A is fine"). Rail order, visibility and the `(n)` = linked-only count are unchanged from r2.

**⚑ r3 CHANGE [FORGE CHOICE], rail attention marker.** Each rail entry keeps its link-state badge. A linked source whose *stored* telemetry has something a tech should look at also shows a small count, e.g. `Tactical · LINKED · 3`. The count is computed from stored columns only (no vendor call on render), per source:

| Source | What counts toward the marker (all stored, inventory-r3 §2) |
|---|---|
| Tactical | checks_failing > 0 or null-with-total; needs_reboot; has_patches_pending; open Tactical alerts; agent offline/overdue (agent status offline or overdue) ⚑ r3b (Jeeves 22:1xZ fix 1) |
| AutoElevate | elevation_mode = audit or unrecognised; link state ≠ linked |
| Comet | open Comet alerts (the live posture is not known at render; see §2.4) |
| Control D / Zorus | agent not active; filtering off (Zorus) |
| M365 | is_compliant = false; Defender status not "clean"-equivalent (shown as raw value) |
| LITSRMM / Ninja / Level | needs_reboot; rmm_online = false. **Inferred provenance (Q-4)**: the marker tooltip says "most likely written by the newest sync" |
| ScreenConnect | none (online/offline is not an issue on its own) |

**⚑ r3 CHANGE [FORGE CHOICE], default selection.** The rail opens on the first source in rail order that has an attention count. If none has one, it opens on the most recently synced source, as in r2. A deep link `#tab-sources/<source>` always wins.

## 2. Item 2: each panel leads with telemetry

### 2.1 One template, new order ⚑ r3 CHANGE
r2's template was: header → link block → freshness block → stored fields → sync block. r3 is:

1. **Header** (navy bar): vendor name, state badge (unchanged D-15 vocabulary), and on the right **Open in <vendor>**. The link appears only where a builder exists today (inventory-r3 §0 table), with no new URL builders ([FORGE CHOICE] carried from r2):
   - Ninja `ninja_url`, Level `level_url`, ScreenConnect `sessionUrl()`: per device.
   - **Tactical `TacticalConfig::webUrl()`: dashboard root, not the agent.** The label says so: "Open Tactical dashboard". A per-agent link is Q-7.
   - None for AutoElevate, Control D, Zorus, M365, Comet (per-asset), Servosity; LITSRMM by design. The header shows nothing there: no greyed button, no "N/A".
2. **Health strip.** One line of chips, each a colour plus a text label (D-15), in a fixed order: state · last contact · last user · then the source's own key signals (checks, reboot, updates, mode, compliance, last backup). Every chip shows its own "as of" age when it comes from a snapshot. The psa-0pb9m rules carry over verbatim for Tactical checks: unknown is never green, zero checks reads "unmonitored", and a stale clean snapshot never reads "all passing".
3. **Needs attention** (only when something does). One row per item, with the evidence: failing check name + retcode + clipped redacted stdout; "Reboot needed (reported by Tactical, as of 2 min ago)"; open alerts from this source (title, severity, fired, ticket link). This is the section a tech uses on a ticket.
4. **Recent activity** (source-specific, from stored or already-read data): Tactical actions with ticket links; Comet backup jobs (posture + last success/failure); Control D DNS activity (on click); ScreenConnect session events.
5. **Device details** (collapsed by default): the r2 key/value table. Human label + raw column name in muted mono, "Not reported by <vendor>" for null, `Unrecognised: <value>` for drift. The vendor id and "mapped through" sit here now.
6. **Footer strip** (compact, one line, muted, top hairline). See §2.3.

### 2.2 Per source: what v1 shows (stored or already read only)
"Already read" means a read the asset page makes today: on render, on tab open, or on click. Anything else is a Q with its cost.

| Source | Health strip | Needs attention | Recent activity | New reads in v1 |
|---|---|---|---|---|
| **Tactical** | status, last seen, last user, checks (snapshot rule), reboot, updates pending y/n, open alerts | failing checks with names + stdout; reboot; updates pending; open Tactical alerts | Recent Tactical actions (10, ticket-linked) | **none new**. The failing-check list reuses the existing `GET /assets/{a}/device-data/checks?source=tactical` (TacticalPanelData::checks, 3 s, degrades) **[FORGE CHOICE]**: fired when the Tactical panel is opened, never on page render |
| **AutoElevate** | mode, last check-in, link state | AUDIT callout (existing wording); not linked + reason; unrecognised mode | none stored (Q-9) | none |
| **LITSRMM** | online, last seen, last user, OS/build, last boot | reboot needed; offline | none | none |
| **Ninja** | online, last contact, last user, last boot | reboot; open Ninja alerts | backup jobs + integrity checks (read on page view today) | none |
| **Level** | online, last seen, last user | offline | none | none |
| **Comet** | last backup posture (vocabulary of CometJobService::derivePosture, never "ok"), last success, last failure | last backup failed; open Comet alerts | 7-day job list: **link** to the Backup tab table (§4) | none (the page-view read exists) |
| **Control D** | device status, agent status, last fetch, profile | agent inactive | DNS activity, **on click** (existing route, 1 h default, blocked rows first) | none |
| **Zorus** | agent state, filtering on/off, CyberSight on/off, last seen, group | filtering off; agent not online | none (block log not read; Q-9) | none |
| **M365 / CIPP** | compliance, Defender AV status, last scan, Intune last sync | not compliant; Defender status raw | none | none |
| **ScreenConnect** | online/offline + last event time (psa-wedk: never one without the other), version | no events in 7 days (existing rule) | last 10 session events | none |
| **Servosity** | backup enabled | none | none ("job outcome is not available from Servosity's API", existing rule) | none |

### 2.3 Sync status becomes a compact footer strip ⚑ r3 CHANGE
r2 gave sync status two blocks: Freshness (last synced, last outcome, previous) and the Sync block (button + description). r3 folds both into **one footer line** at the bottom of the panel: muted ink at AA, a 1px top hairline, no fill.

`Synced 4 min ago · 9:16 AM PDT · scheduled · OK          [Sync now]`

- **Content**: `<x>_synced_at` (relative + absolute in the app timezone, C-14), how it ran (scheduled / Sync now by <user>), and the outcome from `asset_source_syncs` (Q-3), with its fixed reason sentence. The button is an outline, small, right-aligned.
- **Variants** (the closed reason vocabulary from r2 is unchanged):
  - **refused/failed**: the outcome reads as a text badge (`RATE LIMITED`, `FAILED`) + the sentence. The button becomes "Try again in 42 s", disabled, with a countdown (mockup c1).
  - **degraded read** (Tactical agent unreachable): "Could not reach the agent at 9:20 AM PDT; showing the last sync" (mockup c2). Snapshot chips keep their own "as of".
  - **OFF**: "Switched off in Settings; not being updated". No button (mockup b, re-rendered in the r3 template ⚑ r3b).
  - **no single-asset read** (Zorus, M365, Servosity): "Updated by the daily sync at 05:59 PDT; this source cannot be read for one asset". No button.
  - **ScreenConnect**: "Updated by ScreenConnect events; last event 3 min ago". Never a button.
- **History**: the "Previous" row moves behind a "History" disclosure in the footer, listing the last 5 `asset_source_syncs` rows. **[FORGE CHOICE]**
- **What the button reads**: the one-line description ("Reads this computer's record from AutoElevate. Changes nothing at AutoElevate.") becomes the button's `title` and its accessible description, not body text. **[FORGE CHOICE]**

### 2.4 Freshness rule for telemetry (C-56) [FORGE CHOICE]
Every chip and attention row says which kind of fact it is:
- **snapshot**: "as of <age>", from the source's synced_at or the column's own time;
- **live**: "just now", from a read made while the panel was open;
- **vendor's own claim**: "AutoElevate last check-in", never "online".
A live read that fails never blanks a snapshot. It adds "could not refresh" beside the snapshot value, which is the Tactical precedent (AssetController.php:1150-1160 docblock; TacticalInsightService::read).

---

## 3. Item 3: inventory
Done: `inventory-r3.md`. Headline (its §1): v1 needs **no new vendor read for any source**.
- Tactical is the richest (status, checks, reboot, updates, alerts, actions, all stored), plus the existing on-demand failing-check list.
- Comet and Control D add the reads they already make (jobs on page view, DNS activity on click).
- AutoElevate has only mode + check-in + link state.

Anything new is a Q (§7: Q-7 per-agent Tactical link, Q-9 new vendor reads), each with its read cost.

## 4. Item 4: topic tabs, reconcile and don't duplicate ⚑ r3 CHANGE
r2's rule was "Sources shows a key/value table, not the topic cards' widgets, so nothing is duplicated". It no longer holds, because r3 panels show the same telemetry the topic cards show. Below is each existing card at 6c2ef8d3, what the panel does with it, and what that means for its tab.

| Existing card (tab · show.blade.php line) | Source panel | Option A (recommended) | Option B |
|---|---|---|---|
| AutoElevate (Security · :1525-1532) | AutoElevate | moves to Sources (already ruled r2); Security keeps a one-line summary | same (already ruled) |
| M365 / Intune (Security · :1310-1385) | M365 | **moves**; Security summary row "Intune: Not compliant · Defender: … · View source" | stays; panel shows it too |
| DNS Security, Control D, read part + DNS Activity (Security · :1387-1486) | Control D | **moves**, DNS Activity included; Security keeps the summary row **and the link/unlink forms** (unlink :1450, link dropdown :1488-1523) | stays |
| DNS Security, Zorus, read part (Security · :1534-1631) | Zorus | **moves**; Security keeps the summary row + link/unlink forms (unlink :1589, link dropdown :1599-1631) | stays |
| ScreenConnect (Security · :1633-1719) | ScreenConnect | **moves**; Overview's RMM row keeps its "ScreenConnect" link (:445-456) | stays |
| MFA Status (Security · :1721-1750) | none (person, not a source) | stays | stays |
| Tactical RMM card (Overview · :505-780) | Tactical | card **keeps** its health line (it is the summary row), refresh, maintenance toggle and "Open Tactical dashboard"; the details table (:660-760) moves to the panel's Device details | unchanged |
| Recent Tactical actions (Overview · :782-852) | Tactical | moves to the panel's Recent activity; the Overview card gets "Recent actions (N) · View in Sources" | stays; panel shows the newest 3 + link |
| Run Script / power / recover / MeshCentral / reboot modal (Overview · :854-1200) | none | stays on Overview: write actions, item 5 | stays |
| **Checks tab** (Tactical-only · :165-172, :1300-1304) | Tactical | **tab retires**: the failing-check list (same read, TacticalPanelData::checks) leads the Tactical panel | stays; panel shows count + first failing check + link |
| Patches tab (:157-162) | Tactical / Ninja | stays (merged list, Ninja or Tactical); the panel's "updates pending" chip links to it | same |
| Network / Storage / Software tabs | Tactical / Ninja / Level | stay (merged inventory) | same |
| Alerts & Tickets (:1754-) | each source | stays; each panel shows only **its own** open alerts (top 3) with "all N alerts" linking to this tab, filtered by source **[FORGE CHOICE]** | same |
| Backup: Comet storage, Recent Backup Jobs, Servosity, Ninja jobs, toggles (:2141-2600) | Comet / Servosity / Ninja | stays (cross-vendor backup view + toggles); the Comet panel shows posture + last success/failure and links to the job table | same |
| Overview "Refresh from RMM" (:493-500) | Ninja / Level | removed (already ruled r2) | same |

**What becomes redundant under A:**
- the **Checks tab** goes;
- the **Security tab** shrinks to: one summary row per security source, MFA, and the Control D/Zorus link forms.

Measured point in favour of A: on an asset linked to Tactical **and** Ninja or Level, the Checks tab is not rendered at all (show.blade.php:165 `tacticalAsset && !ninja_id && !level_id`). The Tactical card still tells the tech to "open Checks for the live per-check result" (show.blade.php:580-585). So on dual-linked assets the failing-check names appear nowhere on the page today, and the Sources Tactical panel would be their first home.

**Q-8 (for Charlie, A/B):** this removes or moves things techs use today, so it is Charlie's.
- **A** (recommended): Sources becomes the home of each single-vendor card. Topic tabs keep one-line summaries, the merged views (Patches, Network, Storage, Software, Alerts, Backup) and every write action. The Checks tab retires.
- **B**: topic tabs stay exactly as they are. Sources panels repeat the telemetry in compact form and link to the topic card for depth. That is accepted duplication.

## 5. Item 5: read-only stays
- **Panels host no vendor write action.** Excluded:
  - Tactical reboot / shutdown / run-script / command / recover / maintenance toggle / MeshCentral session (web.php:655-663);
  - Comet and Servosity backup toggles (:653-654);
  - Control D / Zorus link and unlink (:648-652; these are PSA-side writes, but they stay on Security, as r2 ruled).
- **What a panel may do**:
  - navigate ("Open in <vendor>", links to topic tabs);
  - run the existing reads: Tactical checks on panel open, Control D DNS activity on click;
  - Sync now. That is a POST to the PSA whose vendor call is a read (GET agents/{id}, GET v1/devices/{id}, GET /api/v1/computers, GET Control D devices), writing only this asset's snapshot columns plus the audit row.
- **Never-POST rules are unchanged.** No source with a POST-shaped vendor read gets a button: Zorus `api/endpoints/search` is a POST (ZorusClient.php:88-97), and it stays without a button as in r2.
- The Overview Tactical card's maintenance toggle and refresh stay where they are (§4).

### 5.1 Mockup (d): seen from a ticket [FORGE CHOICE]
The ticket page is **not changed** by this card. The tech clicks the asset badge on the ticket (tickets/show.blade.php:840, `x-asset-badge`; its quick-look already shows a reboot icon, components/asset-badge.blade.php:154) and lands on the asset page. Because of the attention-first default (§1), the Sources tab's rail is already on Tactical:
- the failing check, with its output;
- "Reboot needed";
- this source's open alert, with its ticket.

The back link reads "Back to ticket". d shows that state.

---

## 6. Do Q-2..Q-6 or the r2 FORGE CHOICE rulings change?

| Ruling (r2, design.md + Jeeves 10/20) | r3 effect |
|---|---|
| Q-1 A | unchanged (Charlie confirmed) |
| Q-2 B (6/min per user; 30 s asset+source; Retry-After fallback 60 s cap 300 s; AE `run_in_progress` while the hourly lock is held) | **unchanged.** Clarification, not a change: the Tactical failing-check list read on panel open is the *existing* device-data route. It is not Sync now and is not counted against the Q-2 limiter, the same as today's Checks tab. ⚑ flagged so Jeeves can say otherwise |
| Q-3 A (`asset_source_syncs`) | **unchanged.** The footer reads it; "History" lists its last 5 rows. No new columns |
| Q-4 A (inferred newest-writer marker, M4.3) | **⚑ extended, not changed.** The "most likely written by the newest sync" wording now also covers health chips and attention rows built from **shared** columns (rmm_online, last_seen_at, last_user, needs_reboot, last_boot_at) on the LITSRMM / Ninja / Level panels. M4.3 widens to those chips (§8 S5/S6) |
| Q-5 A (`autoelevate_enabled` default '1') | unchanged |
| Q-6 B (linked assets only) | unchanged |
| FC: route shape, closed reason vocabulary, audit content | unchanged |
| FC: "panels show stored fields as a key/value table, not the topic cards' rich widgets, so there is no second copy" | **⚑ replaced.** Panels now show telemetry; duplication is settled by §4 / **Q-8 (Charlie)** |
| FC: card moves (AutoElevate → Sources; Security summary row; Overview "Refresh from RMM" removed; Tactical refresh re-pointed) | unchanged |
| FC: "M365, Control D, Zorus, ScreenConnect stay on Security with a 'Source: X · View source' footer" | **⚑ changes under Q-8 A** (they move; Security keeps summary rows). Unchanged under Q-8 B |
| FC: panel template order (link → freshness → fields → sync block) | **⚑ replaced** by §2.1 (header + Open in → health → attention → activity → details → footer) |
| FC: rail opens on the most recently synced source | **⚑ changed** to attention-first, falling back to most recently synced (§1) |
| FC: no write action in Sources | unchanged (item 5) |
| FC: no new console URL builders | unchanged; **Q-7** asks whether the per-agent Tactical shape already coded in Alert.php may be reused |
| FC: S0 → S6 order with per-slice mutants | **⚑ reordered** (§8): Tactical moves first |

## 7. Questions

- **Q-7 (Jeeves, A/B): per-agent "Open in Tactical".**
  - A (recommended for v1): keep the dashboard-root link `TacticalConfig::webUrl()`, labelled "Open Tactical dashboard". No new builder; the r2 ruling stands.
  - B: build `webUrl() + '/#/agents/{agent_id}'`, the shape `Alert::tacticalUrl` already codes (app/Models/Alert.php:98-113). Cost: no vendor read. However, the route shape is the code's claim, not checked against the Tactical producer, so C-56 means a producer read before building.
  - Related separate finding: `Alert::tacticalUrl` reads `tactical_console_url`, which nothing writes, while Settings writes `tactical_web_url` (inventory-r3 §0). Alert links are null unless the setting is set by hand. Not widened into this card.
- **Q-8 (Charlie, A/B): topic-tab reconciliation.** See §4.
  - A (recommended): Sources is home to the single-vendor cards; Security shrinks to summary rows + MFA + link forms; the **Checks tab retires** into the Tactical panel.
  - B: no topic tab changes; panels repeat telemetry compactly and link out.
- **Q-9 (Jeeves, A/B): new vendor reads for telemetry nobody reads today.**
  - A (recommended): none in v1; file each as a later issue.
  - B: name the ones to add in this series.

  Candidates with their read cost:

| Telemetry | Endpoint as coded | Scope | Limit as coded |
|---|---|---|---|
| AutoElevate elevation-request history | **not coded** (no method; ARCHITECTURE.md:167 "no elevation approve/deny") | unknown; producer read needed | vendor 20/min/route (ReadService.php:24-30), shared with the hourly run |
| Zorus block/activity log | **not coded** (ZorusClient has 2 methods) | unknown | not coded |
| Tactical agent history (recent script/command runs) | GET agents/{id}/history/ (TacticalClient.php:893-896) | per agent | not coded; self-hosted |
| Tactical failing-check names **stored** (not only live) | GET agents/{id}/checks/ (TacticalClient.php:1210-1213) | per agent; would be 1 call per agent per sync | not coded; self-hosted |
| LITSRMM agentVersion / enrollmentState / firstSeen | already read (LitsrmmDevice.php:45-66); **storing** them needs a migration, no new read | per client run | not coded |
| LITSRMM alerts on the asset | no read; RmmAlertController would need to resolve hostname → asset | n/a (inbound) | n/a |

## 8. Slice order ⚑ r3 CHANGE, with kill/mutant lists
Telemetry changes the value order. Tactical carries most of what a tech needs on a ticket, all of it already stored or read, so it moves to S1. The security-source panels are pure re-presentation of stored columns, so they move up. LITSRMM's value was a new Sync now path, and its telemetry sits in shared columns, so it moves down.

**S0 frame → S1 Tactical → S2 AutoElevate → S3 security panels (M365, Control D, Zorus, ScreenConnect) → S4 backup panels (Comet, Servosity) → S5 LITSRMM → S6 Level + Ninja.**

Every slice is one PR through review-pr-v2, cites its mockup (DESIGN.md §7), and is done only when each listed mutant is killed by a **named** test. r2 mutant ids are kept where the mutant survives the reorder, so ids follow r2 numbering, not slice numbers.

### S0: frame, telemetry template, footer, shared pipeline (no vendor reads)
r2 M0.1-M0.7 are kept, plus:
- M0.8: render the footer above the telemetry, or drop the health strip from the template → view test "panel order is header, health, attention, activity, details, footer" must fail.
- M0.9: a chip from a snapshot without its "as of" age → view test "every snapshot chip carries its age" must fail.
- M0.10: attention marker computed by calling a vendor on render → test "rendering Sources with every source linked makes zero HTTP calls" (Http::preventStrayRequests) must fail.
- M0.11: "Open in" rendered for a source with no builder (greyed or N/A) → view test "no Open-in element for AutoElevate/Control D/Zorus/M365" must fail.

### S1: Tactical panel (was S3)
- M3.1, M3.2, M3.3 kept (route throttle/audit; degraded read keeps synced_at; no write form in the panel).
- M1T.1: checks chip "all passing" from failing==0 on a snapshot → existing psa-0pb9m assertions re-run against the panel partial must fail.
- M1T.2: zero checks shown as clean → "checks_total=0 renders 'unmonitored'" must fail.
- M1T.3: the failing-check read fires on page render rather than on panel open → "asset page render makes no agents/{id}/checks/ request" must fail.
- M1T.4: stdout rendered unredacted or unclipped → "a failing check's stdout passes WikiRedactor and STDOUT_CLIP" (reuse the TacticalPanelData test) must fail.
- M1T.5: reboot chip from `assets.needs_reboot` instead of `tactical_assets.needs_reboot` → "Tactical panel reboot chip follows tactical_assets only" must fail (shared-column bleed).
- M1T.6: panel shows other sources' alerts → "Tactical panel lists only source=tactical open alerts" must fail.
- M1T.7 (if Q-8 A): Checks tab still rendered → view test must fail; and "dual-linked (Tactical+Ninja) asset shows the failing-check list in the Tactical panel" must fail on the old code (the measured gap in §4).
- M1T.8: "Open Tactical dashboard" falls back to the API URL when web_url is unset → "link hidden when tactical_web_url blank" must fail (psa-6h5r).
- M1T.9 ⚑ r3b (Jeeves 22:1xZ fix 1): agent offline/overdue left out of the Tactical rail marker → view test "offline agent with no other issue renders marker 1" must fail.

### S2: AutoElevate panel + Sync now + switch (was S1)
r2 M1.1-M1.10 kept, plus:
- M2A.1: AUDIT callout missing from the health strip when mode=audit → view test must fail.
- M2A.2: last check-in labelled "online" → test asserting "AutoElevate last check-in" wording must fail.

### S3: security panels (M365, Control D, Zorus, ScreenConnect) (was S5/S6 in part)
r2 M5.1, M5.2, M6.1, M6.2 kept, plus:
- M3S.1: Control D DNS activity loaded on panel render → "no v2/activity-log request until the button is clicked" must fail.
- M3S.2: Zorus gains a Sync button → M6.1 shape; the never-POST rule (`api/endpoints/search` is POST) must fail.
- M3S.3 (if Q-8 A): link/unlink forms moved into the panel → "Sources contains no controld/zorus link or unlink form" must fail.
- M3S.4: M365 compliance chip green when m365_is_compliant is null → "null compliance renders unknown" must fail.

### S4: backup panels (Comet, Servosity)
- M5.3 kept (Comet lookup failure never reads as "no jobs").
- M4B.1: Comet posture chip says "OK" for a successful read → "posture vocabulary never 'ok' (derivePosture)" must fail.
- M4B.2: a second Comet job read for the panel (two getJobsForUser calls per page view) → "one AdminGetJobsForUser per render" must fail.
- M4B.3: Servosity panel claims a job outcome → "Servosity panel shows no run outcome" must fail.

### S5: LITSRMM panel + single-asset read (was S2)
r2 M2.1-M2.6 kept, plus:
- M5L.1: health chips from shared columns without the inferred wording → M4.3 extended to the LITSRMM chips must fail.

### S6: Level + Ninja (was S4)
r2 M4.1-M4.4 kept; M4.3 is extended to the chips as in S5.

## 9. Mockups and what each one shows
- **(a)** `mockup-a-sources-linked.png`, re-rendered.
  - a1: Tactical panel on a healthy device.
  - a2: AutoElevate panel in audit mode (rail marker 1).
- **(c)** `mockup-c-429.png`, re-rendered.
  - c1: AutoElevate 429. The footer strip shows "Rate limited" and a disabled "Try again in 42 s". Telemetry stays on its snapshot, with its age.
  - c2: Tactical agent unreachable. The footer shows "Failed"; the checks chip reads "coverage unknown", never "all passing".
  - The page header still says ONLINE, from the merged asset status, while Tactical says Offline. That disagreement between sources is what the Sources tab exists to show. The header badge is not changed by this card.
- **(d)** `mockup-d-failing-check-reboot.png`, new. The tech has come from ticket #1001; the rail opens on Tactical (marker 4). It shows:
  - the failing check with retcode and output;
  - reboot needed;
  - updates pending, linking to the Patches tab;
  - the open alert, linking to the ticket;
  - the recent actions;
  - the footer.
- **(b)** `mockup-b-source-off.png`, **re-rendered in the r3 template** (⚑ r3b, Jeeves 22:1xZ fix 2). Ninja selected and switched off in Settings: last-known chips (each labelled "last known, not updated"), a Needs attention block that shows no attention count and uses the inferred-writer wording for shared columns, "None while the integration is off" under Recent activity, collapsed Device details, and the footer "Switched off in Settings; not being updated". No Sync now button and no vendor console link; turning the source back on is an administrator action in Settings. No vendor call is made to render. Synthetic data only.
- **Generator:** a design-round script (not in the tree) on top of `../_tokens.css`. All data is synthetic (DESK-EXAMPLE-01, Example Dental Co., 111111synthAA, ticket #1001).
