# Fixtures for `detect-starved-review-gate.test.sh`

## Provenance — copied from the real producer, not from what the code expects

Every board fixture here was extracted from a live capture of the detector's
actual data source, taken on the rig the incident happened on:

```bash
gc bd --rig psa list --all --include-gates --limit 0 --json   # captured 2026-07-26T02:4xZ, 1894 beads
```

Rows were copied **verbatim** and then minimally edited per the tables below.
This follows the repo rule (CLAUDE.md, "Vendor response shapes"): fixtures come
from the real payload so they encode the producer's actual shape — including the
facts a guessed fixture would have gotten wrong:

- gate fields live in `metadata` and an **unset gate is an empty string** (`"review_gate": ""`),
  not an absent key — but on beads that never entered the pipeline the keys ARE absent;
  both spellings of "unset" appear in real rows and both are exercised here;
- `assignee` can be `null` (real row `psa-717bn`);
- `metadata.review_gate_reviews` is a comma-separated id list;
- timestamps carry nanosecond precision (`2026-07-26T02:43:16.574319819Z`);
- titles contain multibyte characters (em-dashes), which is why the matcher is
  pure regex — jq `indices()` returns byte offsets while slicing is
  codepoint-based, and mixing them corrupts boundary checks on exactly these titles.

## Sanitization applied to every row (`clean`)

The detector never reads these fields/values; they are genericized only to keep
environment internals out of a public repo:

| Field | Edit |
|---|---|
| `description` | truncated to 100 chars + marker |
| `metadata.work_dir`, `metadata."gc.work_dir"` | → `/home/operator/town/worktrees/x` |
| `metadata."gc.session_name"` | → `polecat-session-x` |
| `assignee`, `created_by` with session suffixes | `-so-wisp-<id>` → `-session-x` (preserves the read property "is/isn't the review-lead address") |

## Per-fixture edits (everything not listed is verbatim capture)

### `board-incident.json` — the documented 2026-07-25 incident, reconstructed
The starved state was observed live by the manager at ~20:2xZ (bead psa-qqaka);
park times are from bead psa-bi7zp ("psa-z30dv 19:56Z, psa-0pb9m 20:08Z").
By capture time the incident had been manually fixed, so the two rows are
reverted to their documented incident state:

| Row | Edit | Why |
|---|---|---|
| `psa-z30dv` | `review_gate_reviews` → `""`; `updated_at` → `2026-07-25T19:56:30Z` | zero reviews existed; parked 19:56Z |
| `psa-0pb9m` | `review_gate` → `""` (was `revise`); `review_gate_reviews` → `""`; `updated_at` → `2026-07-25T20:08:30Z` | pre-fix state; keeps its captured polecat assignee → exercises the `gate_head` OR-arm |
| `psa-trap1` | copy of the real `psa-qqaka` row; id renamed; title → `"INCIDENT NOTE: psa-z30dv review starvation postmortem — review-lead idle window analysis"` | a discussion bead whose title contains a target id + the word "review" but **no perspective keyword** — must not suppress the alarm and must not enter the population |
| `psa-enpew` + `.13`–`.16`, `psa-v8l0`, `psa-gy7a`, `psa-xty1.1` | verbatim (clean only) | bystanders: gate already decided / closed — must stay out of the population |

### `board-working.json` — verbatim live capture, reviewers actively working
`psa-z30dv` + all 16 children + `psa-enpew`, `psa-0pb9m`, `psa-eu5la`, clean
only, no state edits. R4 re-reviews `.13`–`.16` were open/in_progress at
capture: review legs exist and are being worked — the detector must read this
as healthy (the alarm is for ZERO inputs, not slow ones).

### `board-boundary.json` — parent/child id word boundary + closed-inputs case
Real rows `psa-717bn`, `psa-717bn.6` and the four real standalone review beads
whose titles say `… review: psa-717bn.6 @ 41d35c2` (`psa-5c7pd`, `psa-lps0u`,
`psa-sr134`, `psa-wvmsf`, verbatim).

| Row | Edit | Why |
|---|---|---|
| `psa-717bn` | `assignee` → `psa/review-lead`; `status` → `open`; `review_gate` → `""`; `review_gate_head` → `beefca7`; `review_gate_reviews` → `""`; `updated_at` → `2026-07-26T09:00:00Z` | synthetic-but-shape-real starved parent: the `.6` reviews must NOT leak upward and suppress it |
| `psa-717bn.6` | `assignee` → `psa/review-lead`; `status` → `open`; `review_gate` → `""`; `review_gate_head` → `41d35c2`; `review_gate_reviews` → `""`; `updated_at` → `2026-07-26T08:00:00Z` | its four closed reviews satisfy it via the title path alone (no pointers) |

### `board-dangling.json`
`psa-z30dv` incident row, but `review_gate_reviews` → `"psa-z30dv.13, psa-z30dv.14"`
(note the space — exercises trimming) with **no such beads on the board**, plus
verbatim `psa-enpew`. Pointers to nonexistent beads are dangling: reported, and
not inputs.

### `board-chatter-a.json` / `board-chatter-b.json`
Single-row boards: the incident `psa-z30dv` row with `updated_at` →
`2026-07-26T10:00:00Z` (a) / `2026-07-26T10:49:00Z` (b). Simulates a bead that
keeps getting touched while starved — the state file's observed-starved clock,
not `updated_at`, must carry the alarm.

### `board-empty.json` / `board-invalid.json`
`[]` and malformed JSON. A degraded read must exit 2 and SCREAM (this rig's
board is never legitimately empty — an empty result is the so-2ck1 silent
false-negative signature), never report all-clear.
