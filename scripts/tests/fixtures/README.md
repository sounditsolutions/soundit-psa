# Fixtures for `detect-starved-review-gate.test.sh`

## Provenance — projected from the real producer, not typed from what the code expects

Every board fixture here started as rows from a live capture of the detector's
actual data source, taken on the rig the incident happened on:

```bash
gc bd --rig psa list --all --include-gates --limit 0 --json   # captured 2026-07-26T02:4xZ, 1894 beads
```

This follows the repo rule (CLAUDE.md, "Vendor response shapes"): fixtures come
from the real payload so they encode the producer's actual shape. The captured
rows were then **projected and genericized** (psa-qqaka R1 must-fix 3 — a
public repo does not need retained live board notes/operational history to test
a parser):

1. **Projection to the consumed-field allowlist.** Each row keeps ONLY the
   fields the detector reads: `id`, `title`, `status`, `assignee`,
   `updated_at`, `notes`, and `metadata` restricted to `review_gate` /
   `review_gate_head` / `review_gate_reviews`. Everything else (descriptions,
   owners, close reasons, dependencies, timestamps other than `updated_at`,
   `gc.*` operational metadata, …) is dropped wholesale. Rows whose metadata
   held only unconsumed keys project to metadata-absent — itself a real
   producer shape (511 of 1936 live rows have no metadata at all).
2. **Value genericization, property-preserving.** Retained values that carry
   internal narrative are replaced by generic text that keeps EXACTLY the
   properties the detector consumes:
   - review-leg **titles** keep the real pattern
     (`<PERSPECTIVE> [re-]review [RN][ (...)]: <target-id> @ <sha> …`) with
     fake shas; non-leg titles keep their structural traps (em-dash multibyte
     text, `review`-without-perspective, `review-lead` as an agent name);
   - **notes** keep present/absent, empty/non-empty, and
     terminal-`VERDICT`-line presence (with the captured decision word) — a
     row that really had `VERDICT: REVISE — …` gets
     `"Fixture-genericized review body.\nVERDICT: REVISE — genericized."`;
     a row that really had gate-style notes keeps a `GATE: PROCEED` line
     (deliberately NOT verdict evidence); a row captured with no notes stays
     notes-absent;
   - `updated_at` values are kept verbatim (they drive the clocks, and at
     least one row keeps the producer's nanosecond precision);
   - `assignee` keeps the load-bearing `psa/review-lead` value and the
     genericized `<role>-session-x` forms.

Test **T16** enforces this permanently: every `board-*.json` row must stay
inside the allowlist, `metadata` inside the three gate keys, `notes` ≤ 120
chars, and no internal pattern (paths, session ids, owner addresses) may
appear — so a later live capture cannot silently reintroduce board history.

## Producer-shape facts the projection preserves

Measured from the producer (all 1936 live rows, 2026-07-26) and kept exercised
here — including the facts a guessed fixture would have gotten wrong:

- gate fields live in `metadata` and an **unset gate is an empty string**
  (`"review_gate": ""`), not an absent key — but on beads that never entered
  the pipeline the keys (or the whole `metadata` object) ARE absent; both
  spellings of "unset" appear in real rows and both are exercised here;
- `metadata`, `notes`, and `assignee` are ABSENT (not null) when they have no
  value; `notes`, when present, is a string;
- `metadata.review_gate_reviews` is a comma-separated id list;
- timestamps carry nanosecond precision (`2026-07-26T02:43:16.574319819Z`);
- observed statuses are `open`, `in_progress`, `blocked`, `deferred`,
  `closed` (the row contract also admits `escalated`, reachable per the
  polecat escalation protocol);
- titles contain multibyte characters (em-dashes), which is why the matcher is
  pure regex — jq `indices()` returns byte offsets while slicing is
  codepoint-based, and mixing them corrupts boundary checks on exactly these
  titles;
- real closed review legs end with a terminal `VERDICT: <DECISION>` line in
  their notes (`APPROVE` / `REVISE — …` / `PROCEED` all observed); synthesis
  beads use `GATE: <decision>` instead, which is NOT review-leg evidence.

## Per-fixture behavior (everything not listed is the projected capture)

### `board-incident.json` — the documented 2026-07-25 incident, reconstructed
The starved state was observed live by the manager at ~20:2xZ (bead psa-qqaka);
park times are from bead psa-bi7zp ("psa-z30dv 19:56Z, psa-0pb9m 20:08Z").
By capture time the incident had been manually fixed, so the two rows are
reverted to their documented incident state:

| Row | Edit | Why |
|---|---|---|
| `psa-z30dv` | `review_gate_reviews` → `""`; `updated_at` → `2026-07-25T19:56:30Z` | zero reviews existed; parked 19:56Z |
| `psa-0pb9m` | `review_gate` → `""` (was `revise`); `review_gate_reviews` → `""`; `updated_at` → `2026-07-25T20:08:30Z` | pre-fix state; keeps its captured polecat assignee → exercises the `gate_head` OR-arm |
| `psa-trap1` | invented id; title is a discussion-bead shape | contains a target id + the word "review" but **no perspective keyword** — must not suppress the alarm and must not enter the population |
| `psa-enpew` + `.13`–`.16`, `psa-v8l0`, `psa-gy7a`, `psa-xty1.1` | projected capture | bystanders: gate already decided / closed — must stay out of the population. `.16` is the REAL closed-with-no-notes leg (see board-closed-empty) |

### `board-working.json` — reviewers actively working → healthy
`psa-z30dv` + all 16 children + `psa-enpew`, `psa-0pb9m`, `psa-eu5la`. R4
re-reviews `.13`–`.16` were open/in_progress at capture (notes absent); closed
legs `.1`–`.12` all carry VERDICT-bearing notes: 4 working + 12 evidenced
valid inputs — the detector must read this as healthy (the alarm is for ZERO
inputs, not slow ones).

### `board-boundary.json` — parent/child id word boundary + evidence rule
Rows `psa-717bn`, `psa-717bn.6` and the four captured standalone review beads
whose titles say `… review: psa-717bn.6 @ dddd001` (`psa-5c7pd`, `psa-lps0u`,
`psa-sr134`, `psa-wvmsf`). Three of the four were CAPTURED with no notes —
the real closed-empty shape — so `psa-717bn.6` is **UNKNOWN** (1 evidenced +
3 invalid), and the parent's alarm proves the `.6` reviews neither leak upward
nor read as evidence. (The R1 suite asserted this board healthy; that
assertion was itself the false green named in the review.)

| Row | Edit | Why |
|---|---|---|
| `psa-717bn` | `assignee` → `psa/review-lead`; `status` → `open`; `review_gate` → `""`; `review_gate_head` → `beefca7`; `review_gate_reviews` → `""`; `updated_at` → `2026-07-26T09:00:00Z` | synthetic-but-shape-real starved parent |
| `psa-717bn.6` | `assignee` → `psa/review-lead`; `status` → `open`; `review_gate` → `""`; `review_gate_head` → `dddd001`; `review_gate_reviews` → `""`; `updated_at` → `2026-07-26T08:00:00Z` | its four closed reviews reach it via the title path alone (no pointers) |

### `board-closed-empty.json` — closed legs are NOT evidence without a VERDICT
The real `psa-enpew` leg family (from the incident capture) with the parent
gate re-armed (`review_gate` → `""`, pointers at all four legs):

| Row | Edit | Property |
|---|---|---|
| `psa-enpew.16` | none | REAL closed-with-no-notes capture — the exact incident shape |
| `psa-enpew.15` | notes → whitespace-only | whitespace ≡ no durable notes |
| `psa-enpew.13` | notes → text without a VERDICT line | closed_no_verdict |
| `psa-enpew.14` | none (genericized) | properly evidenced (`VERDICT: PROCEED`) |

Expected: UNKNOWN (never healthy, never silently starved-aging), exit 1.

### `board-mispointed.json` — pointers at existing beads that are NOT reviews of the target
`psa-z30dv` (gate armed) points `review_gate_reviews` at `psa-enpew` (not a
review at all) and `psa-lps0u` (a review of a DIFFERENT target, psa-717bn.6),
while one genuinely open leg (`psa-z30dv.13`) exists → UNKNOWN even alongside
a valid input. `psa-0pb9m` points at `psa-enpew` with zero legs → UNKNOWN,
not an aging zero-input watch. Existing-id pointers must never silently
suppress (or satisfy) the alarm.

### `board-dangling.json`
`psa-z30dv` incident row, but `review_gate_reviews` → `"psa-z30dv.13, psa-z30dv.14"`
(note the space — exercises trimming) with **no such beads on the board**, plus
the projected `psa-enpew`. Pointers to nonexistent beads are dangling:
reported, and not inputs — the bead is genuinely starved (ALARM).

### `board-chatter-a.json` / `board-chatter-b.json`
Single-row boards: the incident `psa-z30dv` row with `updated_at` →
`2026-07-26T10:00:00Z` (a) / `2026-07-26T10:49:00Z` (b). Simulates a bead that
keeps getting touched while starved — the state file's observed-starved clock,
not `updated_at`, must carry the alarm.

### `board-malformed-row.json` / `board-missing-field.json`
`[{}]` (the exact adversarial repro from review R1) and a well-formed row plus
a row missing `updated_at`. A nonempty malformed board must fail the row
contract for the WHOLE read (exit 2) — never be normalized into an empty
population and an all-clear.

### `board-empty.json` / `board-invalid.json`
`[]` and malformed JSON. A degraded read must exit 2 and SCREAM (this rig's
board is never legitimately empty — an empty result is the so-2ck1 silent
false-negative signature), never report all-clear.
