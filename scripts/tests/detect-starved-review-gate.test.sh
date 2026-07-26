#!/usr/bin/env bash
#
# Tests for scripts/detect-starved-review-gate.sh — run offline against fixtures
# captured from the real `gc bd --rig psa list --all --include-gates --limit 0 --json`
# payload (see fixtures/README.md for provenance and the documented per-row edits).
#
# Run from anywhere:
#   scripts/tests/detect-starved-review-gate.test.sh
#
# Exits non-zero if any assertion fails.
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
DETECTOR="$HERE/../detect-starved-review-gate.sh"
FIX="$HERE/fixtures"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PASS=0
FAIL=0

# run_detector <name> <expected_exit> [args...] — captures stdout to $TMP/<name>.out
# without a pipe, so the detector's real exit code is observed.
run_detector() {
    local name="$1" expected="$2"
    shift 2
    local rc=0
    "$DETECTOR" "$@" >"$TMP/$name.out" 2>"$TMP/$name.err" || rc=$?
    if [ "$rc" -eq "$expected" ]; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        echo "FAIL [$name] exit code: expected $expected, got $rc"
        sed 's/^/    out: /' "$TMP/$name.out"
        sed 's/^/    err: /' "$TMP/$name.err"
    fi
}

# assert_json <name> <label> <jq-boolean-expr> — probe the captured JSON report.
assert_json() {
    local name="$1" label="$2" expr="$3"
    if jq -e "$expr" "$TMP/$name.out" >/dev/null 2>&1; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        echo "FAIL [$name] $label — jq probe failed: $expr"
        jq -c '{alarms, watch, newly_alarmed, population: [.population[]? | {id, verdict, input_count, population_reason}]}' \
            "$TMP/$name.out" 2>/dev/null | sed 's/^/    report: /' || sed 's/^/    out: /' "$TMP/$name.out"
    fi
}

# assert_text <name> <label> <grep-pattern> [invert] — probe human-mode output.
assert_text() {
    local name="$1" label="$2" pattern="$3" invert="${4:-}"
    if [ "$invert" = "absent" ]; then
        if ! grep -q "$pattern" "$TMP/$name.out"; then PASS=$((PASS + 1)); else
            FAIL=$((FAIL + 1)); echo "FAIL [$name] $label — pattern unexpectedly present: $pattern"
        fi
    else
        if grep -q "$pattern" "$TMP/$name.out"; then PASS=$((PASS + 1)); else
            FAIL=$((FAIL + 1)); echo "FAIL [$name] $label — pattern missing: $pattern"
            sed 's/^/    out: /' "$TMP/$name.out"
        fi
    fi
}

# ---- T1: the reconstructed 2026-07-25 incident, past threshold → ALARM --------
run_detector t1 1 --input "$FIX/board-incident.json" --now 2026-07-25T20:56:00Z --no-state --json
assert_json t1 "both starved beads alarm" '(.alarms | sort) == ["psa-0pb9m", "psa-z30dv"]'
assert_json t1 "no watch items" '.watch == []'
assert_json t1 "z30dv reason is assignee+gate_head" \
    '.population[] | select(.id == "psa-z30dv") | .population_reason == "assignee+gate_head"'
assert_json t1 "0pb9m reason is gate_head (OR-arm: parked on a polecat, not review-lead)" \
    '.population[] | select(.id == "psa-0pb9m") | .population_reason == "gate_head"'
assert_json t1 "all four perspectives reported missing" \
    '[.population[] | select(.verdict == "ALARM") | .missing_perspectives | length] == [4, 4]'
assert_json t1 "z30dv parked ~59m from updated_at clock" \
    '.population[] | select(.id == "psa-z30dv") | .parked_minutes == 59'
assert_json t1 "trap bead (id + 'review' in title, no perspective keyword) not counted as input" \
    '[.population[] | select(.id == "psa-z30dv") | .input_count] == [0]'
assert_json t1 "trap/bystanders not in population (enpew gate set, xty1.1 closed, trap1 no head)" \
    '([.population[].id] | sort) == ["psa-0pb9m", "psa-z30dv"]'
assert_json t1 "report carries the read-only remedy" '.remedy | test("does not generate reviews")'

# ---- T2: same incident board, before threshold → WATCH, exit 0 ----------------
run_detector t2 0 --input "$FIX/board-incident.json" --now 2026-07-25T20:26:00Z --no-state --json
assert_json t2 "no alarms yet" '.alarms == []'
assert_json t2 "both beads on watch" '(.watch | sort) == ["psa-0pb9m", "psa-z30dv"]'

# ---- T3: live capture with reviewers actively working → healthy, exit 0 -------
run_detector t3 0 --input "$FIX/board-working.json" --now 2026-07-26T02:50:00Z --no-state --json
assert_json t3 "population is exactly z30dv (others gate-set)" '[.population[].id] == ["psa-z30dv"]'
assert_json t3 "verdict OK" '.population[0].verdict == "OK"'
assert_json t3 "16 inputs found (pointer + title union)" '.population[0].input_count == 16'
assert_json t3 "4 inputs open/in_progress = reviewer working, not a stall" '.population[0].inputs_open == 4'
assert_json t3 "all four perspectives covered" '(.population[0].covered_perspectives | length) == 4'

# ---- T4: parent/child id word boundary -----------------------------------------
# psa-717bn.6 has four closed standalone reviews (titles say "psa-717bn.6");
# parent psa-717bn is starved. The .6 reviews must satisfy .6 and must NOT
# leak upward to suppress the parent's alarm.
run_detector t4 1 --input "$FIX/board-boundary.json" --now 2026-07-26T10:00:00Z --no-state --json
assert_json t4 "parent alarms" '.alarms == ["psa-717bn"]'
assert_json t4 "child .6 healthy on its four closed reviews" \
    '.population[] | select(.id == "psa-717bn.6") | .verdict == "OK" and .input_count == 4 and .inputs_closed == 4'
assert_json t4 "parent has zero inputs (child reviews did not leak upward)" \
    '.population[] | select(.id == "psa-717bn") | .input_count == 0'

# ---- T5: dangling pointers — review_gate_reviews names beads that don't exist --
run_detector t5 1 --input "$FIX/board-dangling.json" --now 2026-07-25T20:56:00Z --no-state --json
assert_json t5 "still starved (pointers to nonexistent beads are not inputs)" '.alarms == ["psa-z30dv"]'
assert_json t5 "dangling pointers reported" \
    '.population[0].dangling_pointers == ["psa-z30dv.13", "psa-z30dv.14"]'
assert_json t5 "no pointer hits" '.population[0].pointer_hits == []'

# ---- T6: state file — the observed-starved clock survives bead chatter --------
ST="$TMP/state.json"
run_detector t6a 0 --input "$FIX/board-chatter-a.json" --now 2026-07-26T10:00:30Z --state-file "$ST" --json
assert_json t6a "fresh sighting is WATCH not ALARM" '.watch == ["psa-z30dv"] and .alarms == []'
if jq -e '."psa-z30dv".first_seen == "2026-07-26T10:00:30Z"' "$ST" >/dev/null 2>&1; then PASS=$((PASS+1)); else
    FAIL=$((FAIL+1)); echo "FAIL [t6a] state file did not record first_seen"; cat "$ST" 2>/dev/null; fi

run_detector t6b 1 --input "$FIX/board-chatter-b.json" --now 2026-07-26T10:50:30Z --state-file "$ST" --json
assert_json t6b "alarms via observed clock despite fresh updated_at" '.alarms == ["psa-z30dv"]'
assert_json t6b "updated_at clock alone would not have alarmed" '.population[0].minutes_untouched == 1'
assert_json t6b "observed clock carried the duration" '.population[0].minutes_observed_starved == 50'
assert_json t6b "first alarm crossing is newly_alarmed (dedupe hook for mail bridges)" \
    '.newly_alarmed == ["psa-z30dv"]'

run_detector t6c 1 --input "$FIX/board-chatter-b.json" --now 2026-07-26T10:52:00Z --state-file "$ST" --json
assert_json t6c "alarm persists" '.alarms == ["psa-z30dv"]'
assert_json t6c "but newly_alarmed is empty on re-fire (alarmed_at persisted)" '.newly_alarmed == []'

run_detector t6d 0 --input "$FIX/board-working.json" --now 2026-07-26T10:53:00Z --state-file "$ST" --json
if jq -e '. == {}' "$ST" >/dev/null 2>&1; then PASS=$((PASS+1)); else
    FAIL=$((FAIL+1)); echo "FAIL [t6d] recovered bead not dropped from state"; cat "$ST" 2>/dev/null; fi

# ---- T7: degraded reads SCREAM (exit 2), never a clean all-clear ---------------
run_detector t7a 2 --input "$FIX/board-empty.json" --no-state
assert_text t7a "empty board is a detector error" "DETECTOR-ERROR"
assert_text t7a "empty board names the false-negative signature" "so-2ck1"
run_detector t7b 2 --input "$FIX/board-invalid.json" --no-state
assert_text t7b "invalid JSON is a detector error" "DETECTOR-ERROR"
run_detector t7c 2 --input "$FIX/does-not-exist.json" --no-state
assert_text t7c "missing input file is a detector error" "DETECTOR-ERROR"
run_detector t7d 2 --input "$FIX/board-empty.json" --no-state --json
assert_json t7d "json mode error object" '.ok == false and (.error | test("DETECTOR-ERROR"))'

# ---- T8: human-readable report content -----------------------------------------
run_detector t8a 1 --input "$FIX/board-incident.json" --now 2026-07-25T20:56:00Z --no-state
assert_text t8a "alarm block present" "ALARM psa-z30dv"
assert_text t8a "missing inputs named" "missing: PRODUCT, UX/A11Y, ARCHITECTURE/CORRECTNESS, SECURITY/DATA-SAFETY"
assert_text t8a "how-long-parked stated" "parked ~59m (threshold 45m"
assert_text t8a "generation owner named" "psa-lead"
run_detector t8b 1 --input "$FIX/board-dangling.json" --now 2026-07-25T20:56:00Z --no-state
assert_text t8b "dangling pointers called out" "DANGLING: review_gate_reviews names"
run_detector t8c 0 --input "$FIX/board-working.json" --now 2026-07-26T02:50:00Z --no-state
assert_text t8c "explicit all-clear (silence is not a report)" "all clear"
run_detector t8d 0 --input "$FIX/board-working.json" --now 2026-07-26T02:50:00Z --no-state --verbose
assert_text t8d "verbose input accounting" "16 review input(s) exist (4 open/in_progress = reviewer working"

# ---- T9: argument validation ----------------------------------------------------
run_detector t9a 2 --threshold-mins nope --input "$FIX/board-working.json" --no-state
run_detector t9b 2 --now "yesterday-ish" --input "$FIX/board-working.json" --no-state
run_detector t9c 2 --definitely-not-a-flag
run_detector t9d 0 --help

# ---- T10: --no-state never touches a state file ----------------------------------
NS="$TMP/never-created.json"
run_detector t10 1 --input "$FIX/board-incident.json" --now 2026-07-25T20:56:00Z --no-state --state-file "$NS" --json
if [ ! -e "$NS" ]; then PASS=$((PASS+1)); else
    FAIL=$((FAIL+1)); echo "FAIL [t10] --no-state wrote a state file anyway"; fi

# ---- T11: threshold is tunable (the default is a starting point, not a law) -----
run_detector t11 1 --input "$FIX/board-incident.json" --now 2026-07-25T20:26:00Z --no-state --threshold-mins 15 --json
assert_json t11 "15-min threshold alarms at 20:26 where the 45-min default only watched" \
    '(.alarms | sort) == ["psa-0pb9m", "psa-z30dv"]'

echo
echo "detect-starved-review-gate tests: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
