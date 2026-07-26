#!/usr/bin/env bash
#
# Tests for scripts/detect-starved-review-gate.sh — run offline against fixtures
# projected from the real `gc bd --rig psa list --all --include-gates --limit 0 --json`
# payload (see fixtures/README.md for provenance, the consumed-field projection,
# and the documented per-row edits).
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
# and stderr to $TMP/<name>.err without a pipe, so the real exit code is observed.
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
        jq -c '{alarms, watch, unknown, newly_alarmed, newly_unknown, population: [.population[]? | {id, verdict, input_count, valid_input_count, invalid_input_count}]}' \
            "$TMP/$name.out" 2>/dev/null | sed 's/^/    report: /' || sed 's/^/    out: /' "$TMP/$name.out"
    fi
}

# assert_text <name> <label> <grep-pattern> [invert] — probe human-mode stdout.
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

# assert_err <name> <label> <grep-pattern> — probe captured stderr.
assert_err() {
    local name="$1" label="$2" pattern="$3"
    if grep -q "$pattern" "$TMP/$name.err"; then PASS=$((PASS + 1)); else
        FAIL=$((FAIL + 1)); echo "FAIL [$name] $label — stderr pattern missing: $pattern"
        sed 's/^/    err: /' "$TMP/$name.err"
    fi
}

# ---- T1: the reconstructed 2026-07-25 incident, past threshold → ALARM --------
run_detector t1 1 --input "$FIX/board-incident.json" --now 2026-07-25T20:56:00Z --no-state --json
assert_json t1 "both starved beads alarm" '(.alarms | sort) == ["psa-0pb9m", "psa-z30dv"]'
assert_json t1 "no watch items" '.watch == []'
assert_json t1 "no unknown items (evidence rules add no noise here)" '.unknown == []'
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
assert_json t3 "16 identified legs (pointer targets ⊆ title path)" '.population[0].input_count == 16'
assert_json t3 "4 legs working = reviewer active, not a stall" '.population[0].inputs_working == 4'
assert_json t3 "12 closed legs all carry VERDICT evidence" '.population[0].inputs_evidenced == 12'
assert_json t3 "all 16 are valid inputs, zero invalid" \
    '.population[0] | .valid_input_count == 16 and .invalid_input_count == 0'
assert_json t3 "all four perspectives covered by VALID inputs" '(.population[0].covered_perspectives | length) == 4'
assert_json t3 "all four pointers validated as legs of this target" \
    '(.population[0].pointer_hits | sort) == ["psa-z30dv.13", "psa-z30dv.14", "psa-z30dv.15", "psa-z30dv.16"]'

# ---- T4: parent/child id word boundary + closed-legs-need-evidence -------------
# psa-717bn.6 has four closed standalone reviews (titles say "psa-717bn.6");
# parent psa-717bn is starved. The .6 reviews must satisfy neither the parent
# (no upward leak) NOR .6 itself as evidence: three of the four are the REAL
# closed-with-no-notes shape, so .6 is UNKNOWN, not healthy. (R1 must-fix 1:
# the previous suite asserted this exact board healthy — that assertion was
# itself the false green.)
run_detector t4 1 --input "$FIX/board-boundary.json" --now 2026-07-26T10:00:00Z --no-state --json
assert_json t4 "parent alarms" '.alarms == ["psa-717bn"]'
assert_json t4 "parent has zero inputs (child reviews did not leak upward)" \
    '.population[] | select(.id == "psa-717bn") | .input_count == 0'
assert_json t4 "child .6 is UNKNOWN, not healthy: 3 of 4 closed legs carry no evidence" \
    '.population[] | select(.id == "psa-717bn.6") | .verdict == "UNKNOWN" and .invalid_input_count == 3 and .inputs_evidenced == 1'
assert_json t4 ".6 in unknown, not watch/alarms" \
    '(.unknown == ["psa-717bn.6"]) and ((.watch + .alarms) | index("psa-717bn.6") == null)'
assert_json t4 "the three evidence-less closed legs are named" \
    '(.population[] | select(.id == "psa-717bn.6") | [.invalid_inputs[].id] | sort) == ["psa-5c7pd", "psa-sr134", "psa-wvmsf"]'

# ---- T5: dangling pointers — review_gate_reviews names beads that don't exist --
run_detector t5 1 --input "$FIX/board-dangling.json" --now 2026-07-25T20:56:00Z --no-state --json
assert_json t5 "still starved (pointers to nonexistent beads are not inputs)" '.alarms == ["psa-z30dv"]'
assert_json t5 "dangling pointers reported" \
    '.population[0].dangling_pointers == ["psa-z30dv.13", "psa-z30dv.14"]'
assert_json t5 "no pointer hits, nothing mispointed (they exist nowhere)" \
    '.population[0] | .pointer_hits == [] and .mispointed_pointers == []'

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

# UNKNOWN observations persist and dedupe the same way (unknown_at clock).
run_detector t6e 1 --input "$FIX/board-closed-empty.json" --now 2026-07-26T11:00:00Z --state-file "$ST" --json
assert_json t6e "first UNKNOWN sighting is newly_unknown" '.newly_unknown == ["psa-enpew"]'
if jq -e '."psa-enpew".unknown_at == "2026-07-26T11:00:00Z" and ."psa-enpew".first_seen == null' "$ST" >/dev/null 2>&1; then PASS=$((PASS+1)); else
    FAIL=$((FAIL+1)); echo "FAIL [t6e] state did not record unknown_at (with null first_seen)"; cat "$ST" 2>/dev/null; fi
run_detector t6f 1 --input "$FIX/board-closed-empty.json" --now 2026-07-26T11:05:00Z --state-file "$ST" --json
assert_json t6f "UNKNOWN persists but newly_unknown is empty on re-fire" \
    '.unknown == ["psa-enpew"] and .newly_unknown == []'

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

# Row contract: a nonempty malformed board must fail LOUDLY before filtering
# (R1 must-fix 2 — [{}] previously normalized into an empty population + all-clear).
run_detector t7e 2 --input "$FIX/board-malformed-row.json" --no-state
assert_text t7e "[{}] board violates the row contract" "row contract violated"
run_detector t7f 2 --input "$FIX/board-missing-field.json" --no-state
assert_text t7f "missing updated_at names the row and field" "psa-z30dv.*updated_at"
run_detector t7g 2 --input "$FIX/board-malformed-row.json" --no-state --json
assert_json t7g "row contract violation is machine-readable non-green" \
    '.ok == false and (.error | test("row contract"))'

# Row-contract micro-cases crafted from a healthy projected row.
jq '.[0].status = "reopened"'            "$FIX/board-chatter-a.json" >"$TMP/bad-status.json"
jq '.[0].metadata = "nope"'              "$FIX/board-chatter-a.json" >"$TMP/bad-meta.json"
jq '.[0].notes = 42'                     "$FIX/board-chatter-a.json" >"$TMP/bad-notes.json"
jq '.[0].metadata.review_gate_head = 7'  "$FIX/board-chatter-a.json" >"$TMP/bad-gate.json"
jq '.[0].id = ""'                        "$FIX/board-chatter-a.json" >"$TMP/bad-id.json"
jq 'del(.[0].title)'                     "$FIX/board-chatter-a.json" >"$TMP/bad-title.json"
jq '. + ["not-a-row"]'                   "$FIX/board-chatter-a.json" >"$TMP/bad-row.json"
run_detector t7h 2 --input "$TMP/bad-status.json" --no-state
assert_text t7h "unrecognised status screams" 'status not a recognised value'
run_detector t7i 2 --input "$TMP/bad-meta.json" --no-state
assert_text t7i "string metadata screams" "metadata is neither object nor null"
run_detector t7j 2 --input "$TMP/bad-notes.json" --no-state
assert_text t7j "numeric notes scream" "notes is neither string nor null"
run_detector t7k 2 --input "$TMP/bad-gate.json" --no-state
assert_text t7k "non-string gate metadata screams" "review_gate.* metadata key is present but not a string"
run_detector t7l 2 --input "$TMP/bad-id.json" --no-state
assert_text t7l "empty id screams" "id missing/empty/non-string"
run_detector t7m 2 --input "$TMP/bad-title.json" --no-state
assert_text t7m "missing title screams" "title missing/empty/non-string"
run_detector t7n 2 --input "$TMP/bad-row.json" --no-state
assert_text t7n "non-object row screams" "not an object"

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
assert_text t8d "verbose input accounting distinguishes working from evidenced" \
    "16 valid review input(s) (4 working = reviewer active, 12 evidenced-closed)"
run_detector t8e 1 --input "$FIX/board-closed-empty.json" --now 2026-07-26T03:00:00Z --no-state
assert_text t8e "UNKNOWN section present" "UNKNOWN psa-enpew"
assert_text t8e "closed-empty reason stated" "closed with no durable notes"
assert_text t8e "no-verdict reason stated" "closed notes lack a terminal VERDICT line"
assert_text t8e "action line tells the responder what to verify" "verify whether these reviews actually happened"
assert_text t8e "no all-clear alongside UNKNOWN" "all clear" absent
run_detector t8f 1 --input "$FIX/board-mispointed.json" --now 2026-07-26T03:00:00Z --no-state
assert_text t8f "mispointed reason names the seam" "not a review of this target"

# ---- T9: argument validation ----------------------------------------------------
run_detector t9a 2 --threshold-mins nope --input "$FIX/board-working.json" --no-state
run_detector t9b 2 --now "yesterday-ish" --input "$FIX/board-working.json" --no-state
run_detector t9c 2 --definitely-not-a-flag
run_detector t9d 0 --help

# ---- T10: --no-state never touches (or trips over) a state file ------------------
NS="$TMP/never-created.json"
run_detector t10a 1 --input "$FIX/board-incident.json" --now 2026-07-25T20:56:00Z --no-state --state-file "$NS" --json
if [ ! -e "$NS" ]; then PASS=$((PASS+1)); else
    FAIL=$((FAIL+1)); echo "FAIL [t10a] --no-state wrote a state file anyway"; fi
echo 'not json {' >"$TMP/corrupt-ignored.json"
run_detector t10b 0 --input "$FIX/board-working.json" --now 2026-07-26T02:50:00Z --no-state --state-file "$TMP/corrupt-ignored.json" --json
assert_json t10b "--no-state is the explicit stateless mode: corrupt file ignored" '.ok == true'

# ---- T11: threshold is tunable (the default is a starting point, not a law) -----
run_detector t11 1 --input "$FIX/board-incident.json" --now 2026-07-25T20:26:00Z --no-state --threshold-mins 15 --json
assert_json t11 "15-min threshold alarms at 20:26 where the 45-min default only watched" \
    '(.alarms | sort) == ["psa-0pb9m", "psa-z30dv"]'

# ---- T12: closed legs are evidence ONLY with durable notes + VERDICT line -------
# board-closed-empty is the real psa-enpew leg family: .16 closed with notes
# ABSENT (verbatim capture — the exact shape from the incident), .15 whitespace
# notes, .13 notes without a VERDICT line, .14 properly evidenced.
run_detector t12 1 --input "$FIX/board-closed-empty.json" --now 2026-07-26T03:00:00Z --no-state --json
assert_json t12 "verdict UNKNOWN, exit attention — never healthy" \
    '.unknown == ["psa-enpew"] and .alarms == [] and .watch == []'
assert_json t12 "one evidenced leg (.14), three invalid" \
    '.population[0] | .inputs_evidenced == 1 and .invalid_input_count == 3 and .valid_input_count == 1'
assert_json t12 "absent-notes and whitespace-notes legs both read closed_no_notes" \
    '[.population[0].inputs[] | select(.id == "psa-enpew.15" or .id == "psa-enpew.16") | .disposition] == ["closed_no_notes", "closed_no_notes"]'
assert_json t12 "verdict-less notes leg reads closed_no_verdict" \
    '.population[0].inputs[] | select(.id == "psa-enpew.13") | .disposition == "closed_no_verdict"'
assert_json t12 "covered perspectives come from VALID legs only (arch via .14)" \
    '.population[0].covered_perspectives == ["ARCHITECTURE/CORRECTNESS"]'
assert_json t12 "pointers all resolve to real legs (the failure is evidence, not pointing)" \
    '(.population[0].pointer_hits | length) == 4 and .population[0].mispointed_pointers == []'

# ---- T13: mispointed pointers — existing beads that are NOT reviews of the target
# psa-z30dv points at psa-enpew (not a review at all) and psa-lps0u (a review of
# a DIFFERENT target) while one genuinely open leg exists → invalid inputs must
# go loud even alongside valid ones. psa-0pb9m points at psa-enpew with zero
# legs → UNKNOWN, not silently suppressed and not an aging zero-input watch.
run_detector t13 1 --input "$FIX/board-mispointed.json" --now 2026-07-26T03:00:00Z --no-state --json
assert_json t13 "both beads UNKNOWN" '(.unknown | sort) == ["psa-0pb9m", "psa-z30dv"]'
assert_json t13 "nothing alarmed/watched (mispointed is not starvation)" '.alarms == [] and .watch == []'
assert_json t13 "z30dv: valid working leg AND two mispointed pointers — still loud" \
    '.population[] | select(.id == "psa-z30dv") | .inputs_working == 1 and (.mispointed_pointers | sort) == ["psa-enpew", "psa-lps0u"]'
assert_json t13 "wrong-target review leg (psa-lps0u reviews psa-717bn.6) is mispointed for z30dv" \
    '.population[] | select(.id == "psa-z30dv") | .mispointed_pointers | index("psa-lps0u") != null'
assert_json t13 "mispointed invalid_inputs carry the pointed bead's title for the responder" \
    '.population[] | select(.id == "psa-z30dv") | [.invalid_inputs[] | select(.id == "psa-lps0u")] | length == 1 and .[0].title != null'
assert_json t13 "0pb9m: zero legs + mispointed pointer is UNKNOWN, not starved" \
    '.population[] | select(.id == "psa-0pb9m") | .verdict == "UNKNOWN" and .starved == false and .input_count == 0'

# ---- T14: state persistence is strict — corruption is exit 2, never a reset -----
echo 'not json {' >"$TMP/state-corrupt.json"
run_detector t14a 2 --input "$FIX/board-chatter-a.json" --now 2026-07-26T10:00:30Z --state-file "$TMP/state-corrupt.json"
assert_text t14a "corrupt state is a detector error" "DETECTOR-ERROR.*state file"
echo '{"psa-x": {"first_seen": 12345}}' >"$TMP/state-badts.json"
run_detector t14b 2 --input "$FIX/board-chatter-a.json" --now 2026-07-26T10:00:30Z --state-file "$TMP/state-badts.json"
assert_text t14b "non-string timestamp is a detector error" "DETECTOR-ERROR.*state file"
echo '{"psa-x": []}' >"$TMP/state-badentry.json"
run_detector t14c 2 --input "$FIX/board-chatter-a.json" --now 2026-07-26T10:00:30Z --state-file "$TMP/state-badentry.json"
assert_text t14c "non-object entry is a detector error" "DETECTOR-ERROR.*state file"
echo '{"psa-x": {"first_seen": null, "surprise": "key"}}' >"$TMP/state-extrakey.json"
run_detector t14d 2 --input "$FIX/board-chatter-a.json" --now 2026-07-26T10:00:30Z --state-file "$TMP/state-extrakey.json"
assert_text t14d "unexpected entry key is a detector error" "DETECTOR-ERROR.*state file"
echo '{"psa-x": {"first_seen": "2026-13-99T99:99:99Z"}}' >"$TMP/state-badiso.json"
run_detector t14e 2 --input "$FIX/board-chatter-a.json" --now 2026-07-26T10:00:30Z --state-file "$TMP/state-badiso.json"
assert_text t14e "unparseable timestamp is a detector error" "DETECTOR-ERROR.*state file"
run_detector t14f 2 --input "$FIX/board-chatter-a.json" --now 2026-07-26T10:00:30Z --state-file "$TMP" --json
assert_json t14f "state path that is a directory is machine-readable non-green" \
    '.ok == false and (.error | test("not a regular file"))'
# v1 state files ({first_seen, alarmed_at} only, no unknown_at) stay readable.
echo '{"psa-z30dv": {"first_seen": "2026-07-26T09:30:00Z", "alarmed_at": null}}' >"$TMP/state-v1.json"
run_detector t14g 1 --input "$FIX/board-chatter-b.json" --now 2026-07-26T10:50:30Z --state-file "$TMP/state-v1.json" --json
assert_json t14g "v1-schema state accepted; observed clock carries from it" \
    '.alarms == ["psa-z30dv"] and .population[0].minutes_observed_starved == 80'
if [ "$(id -u)" -ne 0 ]; then
    # Unreadable state file.
    echo '{}' >"$TMP/state-unreadable.json"; chmod 000 "$TMP/state-unreadable.json"
    run_detector t14h 2 --input "$FIX/board-chatter-a.json" --now 2026-07-26T10:00:30Z --state-file "$TMP/state-unreadable.json"
    assert_text t14h "unreadable state is a detector error" "DETECTOR-ERROR.*not readable"
    chmod 644 "$TMP/state-unreadable.json"
    # Unwritable state dir on a HEALTHY board: the write failure must be exit 2,
    # not a warn + exit 0 (the R1 security repro direction — a silent clock
    # reset would postpone alarms indefinitely under bead chatter).
    mkdir -p "$TMP/state-ro"; echo '{}' >"$TMP/state-ro/state.json"; chmod 500 "$TMP/state-ro"
    run_detector t14i 2 --input "$FIX/board-working.json" --now 2026-07-26T02:50:00Z --state-file "$TMP/state-ro/state.json" --json
    assert_json t14i "unwritable state is machine-readable non-green even on a healthy board" \
        '.ok == false and (.error | test("could not be written"))'
    chmod 755 "$TMP/state-ro"
else
    echo "SKIP [t14h/t14i] running as root — permission-based cases not meaningful"
fi

# ---- T15: live acquisition seam (fake gc on PATH) — completeness must be proven -
FAKE_GC_DIR="$TMP/fake-gc"
mkdir -p "$FAKE_GC_DIR/bin"
export FAKE_GC_DIR
cat >"$FAKE_GC_DIR/bin/gc" <<'FAKE'
#!/usr/bin/env bash
# fake gc: serves a canned board + a scripted COUNT sequence for seam tests.
case "$*" in
    *"sql --json"*)
        [ -e "$FAKE_GC_DIR/sql-fail" ] && exit 1
        [ -e "$FAKE_GC_DIR/sql-garbage" ] && { echo '{"oops": 1}'; exit 0; }
        if [ "$(wc -l <"$FAKE_GC_DIR/counts")" -gt 1 ]; then
            n="$(head -n1 "$FAKE_GC_DIR/counts")"
            tail -n +2 "$FAKE_GC_DIR/counts" >"$FAKE_GC_DIR/counts.tmp" && mv "$FAKE_GC_DIR/counts.tmp" "$FAKE_GC_DIR/counts"
        else
            n="$(head -n1 "$FAKE_GC_DIR/counts")"
        fi
        echo "[{\"n\": $n}]"
        ;;
    *"list --all"*)
        [ -e "$FAKE_GC_DIR/list-fail" ] && exit 1
        cat "$FAKE_GC_DIR/board.json"
        ;;
    *)
        echo "fake-gc: unexpected invocation: $*" >&2
        exit 9
        ;;
esac
FAKE
chmod +x "$FAKE_GC_DIR/bin/gc"

run_fake_gc() { # <name> <expected_exit> [detector args...]
    local name="$1" expected="$2"
    shift 2
    local rc=0
    PATH="$FAKE_GC_DIR/bin:$PATH" "$DETECTOR" "$@" >"$TMP/$name.out" 2>"$TMP/$name.err" || rc=$?
    if [ "$rc" -eq "$expected" ]; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        echo "FAIL [$name] exit code: expected $expected, got $rc"
        sed 's/^/    out: /' "$TMP/$name.out"
        sed 's/^/    err: /' "$TMP/$name.err"
    fi
}

cp "$FIX/board-working.json" "$FAKE_GC_DIR/board.json"
N="$(jq 'length' "$FAKE_GC_DIR/board.json")"

echo "$N" >"$FAKE_GC_DIR/counts"
run_fake_gc t15a 0 --now 2026-07-26T02:50:00Z --no-state --json
assert_json t15a "live path healthy when count == rows == count" \
    ".ok == true and .board_beads == $N"

printf '%s\n' "$((N + 5))" "$N" "$N" >"$FAKE_GC_DIR/counts"
run_fake_gc t15b 0 --now 2026-07-26T02:50:00Z --no-state --json
assert_json t15b "transient count race retries and recovers" '.ok == true'
assert_err t15b "the retry is visible on stderr" "completeness not yet proven"

printf '%s\n' "$((N + 5))" >"$FAKE_GC_DIR/counts"
run_fake_gc t15c 2 --now 2026-07-26T02:50:00Z --no-state --json
assert_json t15c "persistent count mismatch is machine-readable non-green" \
    '.ok == false and (.error | test("completeness could not be proven"))'

: >"$FAKE_GC_DIR/sql-fail"
run_fake_gc t15d 2 --now 2026-07-26T02:50:00Z --no-state
assert_text t15d "COUNT path failure is a detector error" "count corroboration failed"
rm -f "$FAKE_GC_DIR/sql-fail"

: >"$FAKE_GC_DIR/sql-garbage"
run_fake_gc t15e 2 --now 2026-07-26T02:50:00Z --no-state
assert_text t15e "unrecognisable COUNT payload is a detector error" "unrecognised payload"
rm -f "$FAKE_GC_DIR/sql-garbage"

echo '[]' >"$FAKE_GC_DIR/board.json"
echo '0' >"$FAKE_GC_DIR/counts"
run_fake_gc t15f 2 --now 2026-07-26T02:50:00Z --no-state
assert_text t15f "a corroborated EMPTY board is still the so-2ck1 signature, exit 2" "so-2ck1"

cp "$FIX/board-working.json" "$FAKE_GC_DIR/board.json"
echo "$N" >"$FAKE_GC_DIR/counts"
: >"$FAKE_GC_DIR/list-fail"
run_fake_gc t15g 2 --now 2026-07-26T02:50:00Z --no-state
assert_text t15g "list path failure is a detector error" "board scan failed"
rm -f "$FAKE_GC_DIR/list-fail"

# ---- T16: fixture minimization guard — the corpus stays projected + genericized -
# (R1 must-fix 3: a later live capture must not silently reintroduce notes,
# owners, paths, session ids, or any unconsumed board history.)
ROW_ALLOW='["id", "title", "status", "assignee", "updated_at", "notes", "metadata"]'
META_ALLOW='["review_gate", "review_gate_head", "review_gate_reviews"]'
for f in "$FIX"/board-*.json; do
    base="$(basename "$f")"
    if ! jq -e . "$f" >/dev/null 2>&1; then
        if [ "$base" = "board-invalid.json" ]; then PASS=$((PASS+1)); else
            FAIL=$((FAIL+1)); echo "FAIL [t16] $base is unparseable but is not the designated invalid fixture"
        fi
        continue
    fi
    if jq -e --argjson row_allow "$ROW_ALLOW" --argjson meta_allow "$META_ALLOW" '
            (type == "array") and
            ([ .[] | select(type == "object") ] | all(
                ((keys - $row_allow) == [])
                and (if (has("metadata") and (.metadata | type) == "object")
                     then ((.metadata | keys) - $meta_allow) == [] else true end)
                and (if (has("notes") and (.notes | type) == "string")
                     then (.notes | length) <= 120 else true end)
            ))
        ' "$f" >/dev/null 2>&1; then PASS=$((PASS+1)); else
        FAIL=$((FAIL+1)); echo "FAIL [t16] $base violates the fixture allowlist (keys/metadata/notes-length)"
    fi
    if grep -qiE 'soundit|so-wisp|/home/|gus@|charlie|work_dir|session_name|close_reason|created_by|description"' "$f"; then
        FAIL=$((FAIL+1)); echo "FAIL [t16] $base contains a forbidden internal pattern"
    else
        PASS=$((PASS+1))
    fi
done

echo
echo "detect-starved-review-gate tests: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
