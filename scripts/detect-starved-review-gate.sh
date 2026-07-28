#!/usr/bin/env bash
#
# detect-starved-review-gate.sh — alarm when a review gate is STARVED OF ITS INPUTS.
#
# THE GAP THIS CLOSES (bead psa-qqaka, observed live 2026-07-25): a work bead can sit
# parked at the review synthesiser (psa/review-lead) with its review gate armed
# (review_gate_head set, review_gate undecided) while ZERO perspective-review beads
# exist for it. The synthesiser cannot synthesise what does not exist, so it idles —
# correctly. Every ordinary surface reads healthy (assignee set, PR open, CI green,
# no errors), so nothing alarms, and the bead stalls indefinitely. Absences are what
# monitoring is worst at: nothing alarms on a thing that is not there unless
# something explicitly counts it. This script is the thing that counts it.
#
# Companion to the refinery tourniquet (bead psa-bi7zp), which only covers beads
# sitting on the refinery hook (assignee == psa/gastown.refinery). This detector
# covers the review-lead / gate-armed shape the tourniquet deliberately cannot see.
# Root-cause family: so-jgyc (dispatch emitter routes un-reviewed builds).
#
# WHAT IT DETECTS
#   Population — beads plausibly waiting on the review pipeline:
#       status is open or in_progress
#       AND review_gate is unset (metadata.review_gate absent or "")
#       AND (assignee == <review-lead> OR metadata.review_gate_head is non-empty)
#   Identified review leg — a bead in ANY status whose title looks like a
#   perspective review of a population bead: contains the word "review" (not as
#   part of "review-lead"), at least one perspective keyword (product / ux / a11y
#   / arch / correctness / security / safety), and either the target id
#   word-bounded in the title, or a child id of the target (<target>.<n>) whose
#   title does NOT name a different target IN TARGET POSITION. Hierarchy alone
#   must not override contradictory title evidence (review R2 rule): a child
#   bead TITLED AS A REVIEW OF another id-shaped target is not a leg of this
#   target — if pointed at via review_gate_reviews it becomes MISPOINTED
#   (invalid input → UNKNOWN), and unpointed it simply does not feed this gate.
#   But an id merely MENTIONED in a review title is incidental prose, not a
#   target (review R3 rule — live example: "Architecture re-review r6:
#   psa-uw2o @ 69449b9 — … psa-0lvh3 closable? …" reviews psa-uw2o and only
#   references psa-0lvh3): it must not turn a legitimate child leg into a
#   contradiction. "Target position" is the review-title grammar measured from
#   every review-shaped title on the live board (2026-07-26, three forms, no
#   others observed):
#       colon form:  "… review[ Rn][ (annotation)]: <id> …"   (optionally
#                    "PR #NNN / " between the colon and the id)
#       direct form: "[RE-]REVIEW <id> [/ …] — <PERSPECTIVE>"
#       paren form:  "… review (<id>) [round n] — <lens>"
#   ("review of <id>" was checked and is NOT a leg-title target form on this
#   board — both live occurrences are prose in non-leg titles.) "Id-shaped" is
#   measured from the board itself: a token whose <prefix>- matches a prefix
#   observed in the board's own ids (so ordinary hyphenated words never trip
#   it). A title that names the target id anywhere still always counts:
#   positive title evidence beats hierarchy and beats incidental foreign ids.
#   Input VALIDITY (review R1 + R2 rules — apparent inputs are not evidence):
#       * a leg that is open/in_progress (or blocked/deferred) is a reviewer
#         WORKING — valid, suppresses starvation; the alarm is for ZERO inputs,
#         not slow ones;
#       * a CLOSED leg is valid ONLY as completed evidence: it must carry durable
#         non-empty notes containing a terminal VERDICT DECISION line — a line
#         "VERDICT: <decision>" where <decision> is one of the rig's real
#         terminal decisions APPROVE | REVISE | PROCEED | PASS (measured from
#         every VERDICT line on the live board 2026-07-26: 136× REVISE,
#         101× APPROVE, 40× PROCEED, 9× PASS; the observed non-decision shapes
#         "VERDICTS…" and "VERDICT INPUTS…" are exactly what must NOT count).
#         A closed leg with no notes, whitespace notes, notes without a VERDICT
#         line, or a VERDICT marker without a recognised decision is an INVALID
#         input. An unrecognised future decision word therefore reads UNKNOWN
#         (loud, a human looks) — the safe failure direction for a watchdog;
#       * a pointer in metadata.review_gate_reviews only counts if the named bead
#         exists AND qualifies as an identified review leg of THIS target. A
#         pointer at a bead that does not exist is DANGLING (reported, not an
#         input). A pointer at an existing bead that is NOT a review of this
#         target is MISPOINTED — an INVALID input, never silent suppression.
#   Verdicts per population bead:
#       OK      — at least one valid input (working or evidenced), zero invalid.
#       UNKNOWN — any INVALID input exists (closed-without-evidence or mispointed
#                 pointer), regardless of valid ones. The gate's input record
#                 cannot be trusted as either fed or starved — a human must look.
#                 Reported immediately (no threshold wait), exit 1.
#       WATCH / ALARM — zero inputs of any kind (starved; dangling pointers may
#                 exist — they point at nothing). ALARM when parked duration
#                 reaches the threshold; WATCH below it. Parked duration is the
#                 MAX of two lower-bound clocks (both reported):
#                     untouched-for:    now - updated_at  (survives detector restarts)
#                     observed-starved: now - first_seen  (state file; survives
#                                       bead chatter that keeps touching updated_at)
#
# THE SCAN PATH (do not "simplify" this)
#   gc bd --rig <rig> list --all --include-gates --limit 0 --json
#   plus CLIENT-SIDE jq filtering. The metadata query forms (--metadata-field,
#   `bd query metadata.*`) have returned 0 rows with exit 0 on this rig — a silent
#   false negative (so-2ck1 / so-ufmx7 / so-zq5eh). A detector on that path would
#   report all-clear forever and be worse than nothing. For the same reason an
#   EMPTY board result is treated as a DETECTOR ERROR (exit 2), never as all-clear:
#   this rig's board is never legitimately empty.
#
# COMPLETENESS PROOF (live path; review R1 + R2 + R3 rules — a partial read
# must never become green; COUNT EQUALITY IS NOT A COMPLETENESS PROOF (a list
# that duplicates one valid row while omitting the gate-armed row passes any
# cardinality check); and ID-SET EQUALITY IS NOT A SEMANTIC PROOF (a list that
# serves the right id on a row whose gate/population fields were elided or
# substituted — a serializer silently dropping metadata/assignee — passes any
# id check while the armed bead silently leaves the population). Board
# acquisition is therefore corroborated at the CONSUMED-ROW level over TWO
# independent read paths (the rig's own so-2ck1 discipline):
#       rows-1: gc bd --rig <rig> sql --json
#                 'SELECT id, status, assignee, title, metadata FROM issues'
#       list:   the scan path above
#       rows-2: the same SELECT again
#   Each side is projected to the DECISION-CONSUMED fields — id, status,
#   assignee, title, and the three consumed metadata keys review_gate /
#   review_gate_head / review_gate_reviews (absent/null normalised to "";
#   the SQL metadata column is the raw JSON object string, "{}" when empty —
#   measured from the producer, all 2005 live rows 2026-07-26, where both
#   paths agreed exactly on every projected field). The read is accepted only
#   when sorted(rows-1) == sorted(list rows) == sorted(rows-2) — membership,
#   duplicates, count, AND the consumed payload of every row, all at once (id
#   is the primary key, so the SQL side is duplicate-free by construction),
#   and the board did not move during the read. Deliberately NOT in the
#   projection, because their loss is loud rather than green: notes (elision
#   turns a closed leg into closed_no_notes → UNKNOWN, a dropping serializer
#   cannot INVENT a VERDICT line, and notes are unbounded-size + append-hot —
#   bracketing them would triple the read and thrash the retry loop) and
#   updated_at (elision fails the row contract → exit 2; substitution only
#   skews a clock whose backstop is the state file's observed-starved clock,
#   able to delay a WATCH→ALARM crossing but never to manufacture OK — and it
#   churns on every bead write, which would make brackets spuriously fail).
#   A mismatch is retried up to 3 times (a live board legitimately moves);
#   persistent mismatch, a failed/unrecognisable SELECT, or a zero-row set is
#   a DETECTOR ERROR (exit 2) — completeness could not be proven, and the
#   error names the duplicated / missing / unexpected ids AND the per-field
#   divergence of mismatched rows so the responder does not re-derive them.
#   --input files bypass corroboration (a static file cannot truncate in flight;
#   it IS the board by definition) but get the full row contract below,
#   INCLUDING duplicate-id rejection.
#
# ROW CONTRACT (validated BEFORE any filtering; review R1 rule — malformed rows
# must scream, not be normalised into an empty population). Measured from the
# producer across all 1936 live rows on 2026-07-26, not guessed:
#       every row is an object;
#       .id      non-empty string;
#       .title   non-empty string;
#       .status  one of open|in_progress|blocked|deferred|closed (all five
#                observed live) or escalated (reachable per the polecat
#                escalation protocol, not observed);
#       .updated_at  string, parseable ISO-8601 UTC (nanosecond precision ok);
#       .metadata    absent, null, or object; the consumed keys review_gate /
#                    review_gate_head / review_gate_reviews, when present, strings;
#       .notes       absent, null, or string;
#       .assignee    absent, null, or string;
#       .id          UNIQUE across the board (review R2 rule — a duplicated row
#                    is the signature of a substituted/omitted row, never valid).
#   Any violation fails the ENTIRE run (exit 2, naming the first bad row) —
#   partial normalisation is unsafe for an absence detector.
#
# THRESHOLD PROVENANCE (default 45 minutes — a grounded default, NOT a tuned constant)
#   Real data, one incident night (2026-07-25), three points, all resolved by one
#   manual bulk-generation event after a human-ish agent noticed:
#       psa-z30dv parked 19:56Z -> first reviews 20:32:58Z  (~37 min starved)
#       psa-0pb9m parked 20:08Z -> first reviews 20:33:08Z  (~25 min starved)
#       psa-enpew parked 20:25Z -> first reviews 20:41:23Z  (~16 min starved)
#   45 sits above the worst observed lag, so it only fires on state that has
#   outlived everything seen so far. Three samples from one night is not a tuning —
#   re-derive from accumulated WATCH/ALARM reports and adjust --threshold-mins.
#
# STATE FILE (strict; review R1 + R2 rules — persistence corruption must be
# machine-visible, never a silent clock reset, and a corrupt state must never
# be overwritten as if healthy). Unless --no-state, the script persists
# {<bead-id>: {first_seen, alarmed_at, unknown_at}} (ISO timestamps or null).
# If state is ENABLED and the file exists but is unreadable, not a regular
# file, a symlink, not valid JSON, not shaped as above, or cannot be WRITTEN
# back, that is a DETECTOR ERROR (exit 2) — the observed-starved clock and the
# alarm/unknown dedup cannot be trusted, so no report is emitted and the bad
# state file is LEFT INTACT as evidence (validation happens before any write).
# Shape alone is not enough (review R2): entries the detector could never have
# persisted are rejected as corrupt —
#       * key sets other than exactly {first_seen, alarmed_at} (v1) or
#         {first_seen, alarmed_at, unknown_at} (v2) — so {} and
#         {"alarmed_at": …} with no first_seen cannot masquerade as fresh;
#       * alarmed_at set while first_seen is null (an alarm without the
#         starved clock that produced it);
#       * first_seen and unknown_at both set (the states are exclusive) or
#         both null (nothing to persist means no entry at all);
#       * any timestamp in the FUTURE of the current clock (a future
#         first_seen postpones an alarm indefinitely);
#       * alarmed_at earlier than first_seen.
# Recover by inspecting/removing the state file, or run --no-state (the
# explicit stateless mode: no read, no write, clocks degrade to updated_at
# only, by choice). Writes go through mktemp (O_EXCL, random name) in the
# state directory plus an atomic rename — rename(2) never follows a destination
# symlink, and a planted predictable "<state>.tmp" symlink is never opened.
#
# WHAT IT DELIBERATELY DOES NOT DO (bead psa-qqaka, ask #5)
#   No review generation, no re-routing, no gate writes, no merges, no bead writes
#   of any kind, no mail. Detect and report only. Generation is a judgement call
#   that stays with the PR owner. The only thing this script writes is its own
#   state file. (The COUNT corroboration is a read-only SELECT.)
#
# USAGE
#   scripts/detect-starved-review-gate.sh [options]         # run inside the town tree
#     --rig <name>            rig to scan (default: psa)
#     --review-lead <addr>    synthesiser address (default: <rig>/review-lead)
#     --owner-hint <name>     who owes generation, for the report (default: <rig>-lead)
#     --threshold-mins <n>    alarm threshold in minutes (default: 45)
#     --input <file>          read the board from a JSON file instead of gc (tests;
#                             skips live-path completeness corroboration only)
#     --state-file <path>     persistence file (default:
#                             ${XDG_STATE_HOME:-$HOME/.local/state}/gc-detectors/
#                             starved-review-gate-<rig>.json)
#     --no-state              do not read or write the state file
#     --now <iso8601Z>        clock override for deterministic tests
#     --json                  emit the machine-readable report instead of text
#     --verbose               also print healthy population beads (input accounting)
#
# EXIT CODES
#   0  healthy (population may include WATCH items — printed)
#   1  ATTENTION: at least one ALARM (starved at/over threshold) and/or UNKNOWN
#      (invalid gate inputs) bead — the report says which and why
#   2  detector error (scan failed, completeness unproven, empty/invalid board,
#      row contract violation, state corrupt/unwritable, bad args) — NEVER all-clear
#
# WIRING EXAMPLES (delivery is the operator's choice; the contract is exit codes +
# report; .newly_alarmed / .newly_unknown in --json output make an alarm bridge
# naturally deduped — they list only beads whose ALARM/UNKNOWN began this run)
#   cron, every 10 min, log-only:
#     */10 * * * * cd /path/to/town && repo/scripts/detect-starved-review-gate.sh >> /var/log/starved-review-gate.log 2>&1
#   witness patrol, mail only on NEW attention states:
#     R="$(repo/scripts/detect-starved-review-gate.sh --json)"; rc=$?
#     [ "$rc" -eq 1 ] && [ "$(printf '%s' "$R" | jq '(.newly_alarmed + .newly_unknown) | length')" -gt 0 ] \
#       && gc mail send psa/gastown.witness -s "ALARM: review gate starved/unknown [HIGH]" -m "$R"
#
set -euo pipefail

RIG="psa"
REVIEW_LEAD=""
OWNER_HINT=""
THRESHOLD_MINS=45
INPUT_FILE=""
STATE_FILE=""
NO_STATE=0
JSON_OUT=0
VERBOSE=0
NOW_ISO=""

usage() {
    sed -n '2,/^set -euo pipefail$/p' "$0" | sed 's/^#//; s/^ //' | sed '$d'
}

# Detector failure is LOUD and never reads as all-clear: exit 2, both streams.
die() {
    if [ "$JSON_OUT" -eq 1 ]; then
        jq -n --arg msg "$*" --arg rig "$RIG" \
            '{detector: "starved-review-gate", rig: $rig, ok: false, error: ("DETECTOR-ERROR: " + $msg)}'
    else
        echo "DETECTOR-ERROR: $*"
    fi
    echo "DETECTOR-ERROR: $*" >&2
    exit 2
}

while [ $# -gt 0 ]; do
    case "$1" in
        --rig)            RIG="${2:?}"; shift 2 ;;
        --review-lead)    REVIEW_LEAD="${2:?}"; shift 2 ;;
        --owner-hint)     OWNER_HINT="${2:?}"; shift 2 ;;
        --threshold-mins) THRESHOLD_MINS="${2:?}"; shift 2 ;;
        --input)          INPUT_FILE="${2:?}"; shift 2 ;;
        --state-file)     STATE_FILE="${2:?}"; shift 2 ;;
        --no-state)       NO_STATE=1; shift ;;
        --json)           JSON_OUT=1; shift ;;
        --verbose)        VERBOSE=1; shift ;;
        --now)            NOW_ISO="${2:?}"; shift 2 ;;
        -h|--help)        usage; exit 0 ;;
        *)                die "unknown argument: $1 (see --help)" ;;
    esac
done

[ -n "$REVIEW_LEAD" ] || REVIEW_LEAD="$RIG/review-lead"
[ -n "$OWNER_HINT" ] || OWNER_HINT="$RIG-lead"
[ -n "$NOW_ISO" ] || NOW_ISO="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
[ -n "$STATE_FILE" ] || STATE_FILE="${XDG_STATE_HOME:-$HOME/.local/state}/gc-detectors/starved-review-gate-${RIG}.json"

case "$THRESHOLD_MINS" in
    ''|*[!0-9]*) die "--threshold-mins must be a non-negative integer, got: $THRESHOLD_MINS" ;;
esac

# Strict parse: fromdateiso8601 leniently rolls over out-of-range components
# (month 13, minute 99), so require the epoch to round-trip back to the input.
if ! jq -en --arg t "$NOW_ISO" '
        ($t | sub("\\.[0-9]+Z$"; "Z")) as $c
        | (($c | fromdateiso8601)? // null) as $e
        | ($e != null) and (($e | todate) == $c)
    ' >/dev/null 2>&1; then
    die "--now is not parseable ISO-8601 UTC (want YYYY-MM-DDTHH:MM:SSZ): $NOW_ISO"
fi

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT
BOARD_FILE="$WORK_DIR/board.json"

# ---- acquire the board -------------------------------------------------------
# Second read path for completeness corroboration: the full CONSUMED-ROW
# projection, not a count (review R2 — count equality is defeated by a
# duplicated row substituting an omitted one) and not the id set alone (review
# R3 — id equality is defeated by a row whose gate/population fields were
# elided or substituted while its id survived). Writes the SORTED projected-row
# array (JSON) to $1 (a file, not stdout — die() inside $(...) would be
# swallowed by the subshell). id is the primary key, so this path is
# duplicate-free by construction. The SELECT reads the same issues table,
# still read-only; the metadata column arrives as the raw JSON object string
# ("{}" when empty — measured, all 2005 live rows 2026-07-26, zero
# unparseable), so a non-object value is producer drift, not a race: die, do
# not retry.
consumed_projection() {
    # jq filter: one board row (metadata already an OBJECT) → consumed fields.
    printf '%s' '{id, st: (.status? // ""), as: (.assignee? // ""), ti: (.title? // ""),
                  rg: (.m.review_gate? // ""), rgh: (.m.review_gate_head? // ""), rgr: (.m.review_gate_reviews? // "")}'
}
sql_rows_into() {
    local out_file="$1" payload bad_meta
    if ! payload="$(gc bd --rig "$RIG" sql --json 'SELECT id, status, assignee, title, metadata FROM issues' 2>"$WORK_DIR/sql.err")"; then
        sed 's/^/  gc-sql: /' "$WORK_DIR/sql.err" >&2 || true
        die "board row corroboration failed: gc bd --rig $RIG sql exited non-zero — completeness cannot be proven"
    fi
    if ! jq -e '(type == "array") and all(.[];
                (type == "object") and ((.id | type) == "string") and (.id != "")
                and has("status") and has("assignee") and has("title") and has("metadata"))' \
            >/dev/null 2>&1 <<<"$payload"; then
        die "board row corroboration returned an unrecognised payload (want [{id, status, assignee, title, metadata}, …] with non-empty string ids) — completeness cannot be proven"
    fi
    # fromjson? yields an EMPTY STREAM on parse failure (not null) — a bare
    # fromjson?|type would drop the bad row from this check instead of
    # catching it, so normalise the failure to null first.
    bad_meta="$(jq -r '[.[] | select((((.metadata // "{}") | (fromjson? // null)) | type) != "object") | .id] | first // ""' <<<"$payload")"
    [ -z "$bad_meta" ] \
        || die "board row corroboration: sql metadata column is not a JSON object for id $bad_meta — the consumed gate fields cannot be corroborated"
    jq "[.[] | .m = ((.metadata // \"{}\") | fromjson) | $(consumed_projection)] | sort" <<<"$payload" >"$out_file"
}

if [ -n "$INPUT_FILE" ]; then
    [ -r "$INPUT_FILE" ] || die "board input file not readable: $INPUT_FILE"
    cp -- "$INPUT_FILE" "$BOARD_FILE"
    jq -e 'type == "array"' "$BOARD_FILE" >/dev/null 2>&1 \
        || die "board payload is not a JSON array — refusing to evaluate (never all-clear on a bad read)"
else
    # THE RELIABLE SCAN PATH — verbatim; see header before changing anything here.
    # Bracketed by two independent consumed-row reads: accept only when the
    # sorted projected-row multiset of the list equals BOTH bracketing SQL
    # projections exactly — ids AND the consumed payload of every row.
    PROVEN=0
    for ATTEMPT in 1 2 3; do
        sql_rows_into "$WORK_DIR/rows-pre.json"
        if ! gc bd --rig "$RIG" list --all --include-gates --limit 0 --json \
                >"$BOARD_FILE" 2>"$WORK_DIR/gc.err"; then
            sed 's/^/  gc: /' "$WORK_DIR/gc.err" >&2 || true
            die "board scan failed: gc bd --rig $RIG list --all --include-gates --limit 0 --json exited non-zero"
        fi
        jq -e 'type == "array"' "$BOARD_FILE" >/dev/null 2>&1 \
            || die "board payload is not a JSON array — refusing to evaluate (never all-clear on a bad read)"
        # Tolerant projection (rows are contract-validated after acquisition):
        # a non-object row projects from {}, so its null id can never match a
        # SQL id, and non-object metadata projects to a sentinel gate value no
        # SQL row can carry — a malformed live read still fails corroboration.
        jq "[.[] | (if type == \"object\" then . else {} end)
                 | .m = ((.metadata? // {}) | if type == \"object\" then . else {review_gate: \"\\u0000non-object-metadata\"} end)
                 | $(consumed_projection)] | sort" "$BOARD_FILE" >"$WORK_DIR/rows-list.json"
        sql_rows_into "$WORK_DIR/rows-post.json"
        if jq -en --slurpfile pre "$WORK_DIR/rows-pre.json" --slurpfile lst "$WORK_DIR/rows-list.json" --slurpfile post "$WORK_DIR/rows-post.json" \
                '($pre[0] == $lst[0]) and ($post[0] == $lst[0])' >/dev/null 2>&1; then
            PROVEN=1
            break
        fi
        echo "WARN: board completeness not yet proven (sql-pre=$(jq 'length' "$WORK_DIR/rows-pre.json") list-rows=$(jq 'length' "$WORK_DIR/rows-list.json") sql-post=$(jq 'length' "$WORK_DIR/rows-post.json") — consumed-row projections differ), attempt $ATTEMPT/3" >&2
    done
    if [ "$PROVEN" -ne 1 ]; then
        # Name the divergence so the responder does not re-derive it: id-level
        # drift (duplicated / missing / unexpected) AND field-level drift on
        # rows whose id matched but whose consumed payload did not (review R3
        # — the id-preserving elided/substituted row).
        ID_DIVERGENCE="$(jq -rn --slurpfile pre "$WORK_DIR/rows-pre.json" --slurpfile lst "$WORK_DIR/rows-list.json" '
            ($pre[0]) as $S | ($lst[0]) as $L
            | ($S | map(.id)) as $Sids | ($L | map(.id)) as $Lids
            | { sql_rows: ($S | length),
                list_rows: ($L | length),
                duplicated_in_list: ([$Lids | group_by(.) | .[] | select(length > 1) | .[0]] | .[0:5]),
                missing_from_list: (($Sids - $Lids) | .[0:5]),
                unexpected_in_list: ((($Lids | unique) - $Sids) | .[0:5]),
                rows_mismatched: ([ ($S | map({key: .id, value: .}) | from_entries) as $SM
                                    | ($L | map({key: (.id // "\u0000null-id"), value: .}) | from_entries) as $LM
                                    | ($SM | keys[]) | select(($LM[.] != null) and ($SM[.] != $LM[.]))
                                    | . as $id
                                    | { id: $id,
                                        fields: [ ($SM[$id] | keys[]) | select($SM[$id][.] != $LM[$id][.]) ],
                                        sql: $SM[$id], list: $LM[$id] } ] | .[0:3]) }
            | tojson' 2>/dev/null || echo '{}')"
        die "board completeness could not be proven after 3 attempts — the list rows never matched the SQL consumed-row projection: $ID_DIVERGENCE — a partial, duplicated, substituted, or field-elided read must never become green"
    fi
fi

jq -e 'length > 0' "$BOARD_FILE" >/dev/null 2>&1 \
    || die "board scan returned ZERO beads — impossible on a live rig; this is the silent-false-negative signature (so-2ck1), not an all-clear"

# ---- row contract (validated BEFORE filtering; see header for provenance) -----
ROW_VIOLATION="$(jq -r '
    def ok_ts:
        (type == "string") and
        ((sub("\\.[0-9]+Z$"; "Z")) as $c
         | ((($c | fromdateiso8601)? // null) as $e
            | ($e != null) and (($e | todate) == $c)));
    def opt_string($v): ($v == null) or (($v | type) == "string");
    [ to_entries[]
      | .key as $i | .value as $r
      | (if ($r | type) != "object" then "row \($i): not an object"
         elif (($r.id? | type) != "string") or ($r.id == "") then "row \($i): id missing/empty/non-string"
         elif (($r.title? | type) != "string") or ($r.title == "") then "row \($i) (\($r.id)): title missing/empty/non-string"
         elif (["open","in_progress","blocked","deferred","closed","escalated"] | index($r.status?)) == null
             then "row \($i) (\($r.id)): status not a recognised value: \($r.status? | tojson)"
         elif ($r.updated_at? | ok_ts | not) then "row \($i) (\($r.id)): updated_at missing/unparseable: \($r.updated_at? | tojson)"
         elif ($r | has("metadata")) and ($r.metadata != null) and (($r.metadata | type) != "object")
             then "row \($i) (\($r.id)): metadata is neither object nor null"
         elif ($r.metadata? != null) and ($r.metadata? | type == "object") and
              ([($r.metadata.review_gate?), ($r.metadata.review_gate_head?), ($r.metadata.review_gate_reviews?)]
               | map(select(. != null)) | map(type) | any(. != "string"))
             then "row \($i) (\($r.id)): a consumed review_gate* metadata key is present but not a string"
         elif ($r | has("notes")) and (opt_string($r.notes) | not) then "row \($i) (\($r.id)): notes is neither string nor null"
         elif ($r | has("assignee")) and (opt_string($r.assignee) | not) then "row \($i) (\($r.id)): assignee is neither string nor null"
         else empty end)
    ] | first // ""
' "$BOARD_FILE" 2>/dev/null || echo "row contract check itself failed")"
[ -z "$ROW_VIOLATION" ] \
    || die "board row contract violated — $ROW_VIOLATION; a malformed board must scream, not normalise into an empty population"

# Duplicate ids are the signature of a substituted/omitted row (review R2): a
# board that lists one bead twice can be hiding the bead it dropped, so it is
# never trustworthy — for --input files too, where corroboration cannot run.
DUP_ID="$(jq -r '[.[].id] | group_by(.) | map(select(length > 1) | .[0]) | first // ""' "$BOARD_FILE")"
[ -z "$DUP_ID" ] \
    || die "board row contract violated — duplicate id appears more than once: $DUP_ID; a duplicated row can substitute for an omitted bead, so the board cannot be trusted"

# ---- load detector state (first-seen / alarmed-at / unknown-at clocks) --------
# Strict: enabled + present but untrustworthy state is a detector error, never a
# silent clock reset (a reset can postpone an alarm indefinitely under chatter).
# Validation happens BEFORE any evaluation or persist, so a corrupt state file
# is always LEFT INTACT as evidence — never overwritten as if healthy (R2).
STATE_JSON="{}"
if [ "$NO_STATE" -eq 0 ] && { [ -e "$STATE_FILE" ] || [ -L "$STATE_FILE" ]; }; then
    [ ! -L "$STATE_FILE" ] \
        || die "state path is a symlink: $STATE_FILE — refusing to read or write through it (point --state-file at a regular file, or run --no-state)"
    [ -f "$STATE_FILE" ] \
        || die "state path exists but is not a regular file: $STATE_FILE (inspect/remove it, or run --no-state)"
    [ -r "$STATE_FILE" ] \
        || die "state file exists but is not readable: $STATE_FILE (fix permissions, remove it, or run --no-state)"
    # Shape AND possibility: reject any entry this detector could never have
    # persisted (see header). Missing keys must not evaluate as fresh nulls —
    # the exact R2 repro was {"alarmed_at": …} with no first_seen reading as a
    # brand-new WATCH and then overwriting the alarm evidence.
    if ! jq -e --arg now_iso "$NOW_ISO" '
            def ok_ts:
                (type == "string") and
                ((sub("\\.[0-9]+Z$"; "Z")) as $c
                 | ((($c | fromdateiso8601)? // null) as $e
                    | ($e != null) and (($e | todate) == $c)));
            def ep: if . == null then null else (sub("\\.[0-9]+Z$"; "Z") | fromdateiso8601) end;
            ($now_iso | ep) as $now
            | (type == "object")
            and (keys | all(. != ""))
            and ([ to_entries[].value ] | all(
                (type == "object")
                and (((keys | sort) == ["alarmed_at", "first_seen"])
                     or ((keys | sort) == ["alarmed_at", "first_seen", "unknown_at"]))
                and ([.first_seen, .alarmed_at, (.unknown_at // null)] | all(. == null or ok_ts))
                # a persisted entry always carries exactly one clock:
                and ((.first_seen != null) or ((.unknown_at // null) != null))
                and ((.first_seen == null) or ((.unknown_at // null) == null))
                # an alarm cannot exist without the starved clock that produced it:
                and ((.alarmed_at == null) or (.first_seen != null))
                # no clock may sit in the future (a future first_seen postpones
                # an alarm indefinitely — the R2 minutes_observed:-38097430 repro):
                and ([.first_seen, .alarmed_at, (.unknown_at // null)]
                     | all(. == null or ((ep) <= $now)))
                # and an alarm cannot predate its own first sighting:
                and ((.alarmed_at == null) or ((.alarmed_at | ep) >= (.first_seen | ep)))
            ))
        ' "$STATE_FILE" >/dev/null 2>&1; then
        die "state file is corrupt, mis-shaped, or impossible (an entry this detector could never have persisted — missing/extra keys, missing clocks, both clocks, a future timestamp, or an alarm without/before its first sighting): $STATE_FILE — the observed clocks cannot be trusted; file left intact as evidence (inspect/remove it, or run --no-state)"
    fi
    STATE_JSON="$(cat "$STATE_FILE")"
fi

# ---- decision core (pure jq: board + state + clock in, report out) ------------
JQ_CORE='
def ts2e:
    (if type == "string" then (sub("\\.[0-9]+Z$"; "Z") | fromdateiso8601) else null end)? // null;

def re_escape: gsub("(?<c>[\\\\^$.|?*+()\\[\\]{}])"; "\\\(.c)");

# Word-bounded id occurrence: the id must not extend an id-ish token on either
# side, so reviews of psa-x.6 never satisfy a scan for psa-x (and vice versa).
# Pure regex on purpose: jq indices() returns BYTE offsets while slicing is
# codepoint-based, so index arithmetic silently breaks on em-dash titles.
def mentioned($tid; $title):
    ($tid | re_escape) as $e
    | ($title // "") | test("(^|[^A-Za-z0-9._-])" + $e + "($|[^A-Za-z0-9._-])");

def canonical_perspectives: ["PRODUCT", "UX/A11Y", "ARCHITECTURE/CORRECTNESS", "SECURITY/DATA-SAFETY"];

# Perspective keywords, from every observed real review title on this board:
# "PRODUCT review:", "UX/A11Y re-review R4", "ARCH re-review", "SECURITY/DATA-SAFETY
# review:", "R2 DATA-SAFETY/SECURITY review:", "PR #279 review lane — SECURITY/ARCHITECTURE".
def perspectives_of($title):
    ($title // "") as $t
    | [ (if $t | test("\\bproduct\\b"; "i") then "PRODUCT" else empty end),
        (if $t | test("\\bux\\b|a11y"; "i") then "UX/A11Y" else empty end),
        (if $t | test("\\barch(itecture)?\\b|correctness"; "i") then "ARCHITECTURE/CORRECTNESS" else empty end),
        (if $t | test("security|data-safety|\\bsafety\\b"; "i") then "SECURITY/DATA-SAFETY" else empty end) ];

# "review" but not solely the agent name "review-lead" (a title like
# "route psa-x to review-lead" is not a review of psa-x).
def looks_like_review($title): ($title // "") | test("review(?!-lead)"; "i");

# Completed-review evidence: durable non-empty notes carrying a terminal VERDICT
# DECISION line. has_verdict_line detects the marker (a line starting, after
# optional whitespace, with the word VERDICT); has_verdict_decision requires
# the real terminal decision of this rig after it — VERDICT: APPROVE | REVISE |
# PROCEED | PASS, the complete vocabulary measured from every VERDICT line on
# the live board 2026-07-26 (136× REVISE, 101× APPROVE, 40× PROCEED, 9× PASS).
# A marker without a recognised decision ("VERDICT", "VERDICT:", the observed
# live shape "VERDICT INPUTS …") is NOT evidence (review R2) — and an
# unrecognised future decision word reads UNKNOWN, the safe direction.
# jq/Oniguruma "^" anchors the whole string, not lines, so the line start is
# spelled (^|\n) explicitly.
def has_verdict_line: (. // "") | test("(^|\\n)[ \\t]*VERDICT\\b");
def has_verdict_decision:
    (. // "") | test("(^|\\n)[ \\t]*VERDICT[ \\t]*:[ \\t]*(APPROVE|REVISE|PROCEED|PASS)\\b");
def notes_text: (.notes // "");

# Ids a review title names AS ITS TARGET — not ids it merely mentions. The
# grammar is measured from every review-shaped title on the live board
# (2026-07-26; see header): the target id sits immediately after the review
# keyword phrase in one of three forms — "review[ stuff]: [PR #N / ]<id>"
# (colon form; the annotation segment never crosses a colon or an em-dash),
# "REVIEW <id>" (direct form), "review (<id>)" (paren form). An id anywhere
# else is incidental prose (review R3 rule — a live child leg said "…
# incorporates context from sibling <id>" and must not read as a contradictory
# target). \breview keeps "preview…"/"reviewer…" from anchoring the grammar;
# (?!-lead) keeps the agent name "review-lead" out of it, as everywhere else.
def id_regex: "[A-Za-z][A-Za-z0-9]*-[A-Za-z0-9]+(?:\\.[0-9]+)*";
def titled_target_ids($title):
    ($title // "") as $t
    | ([ $t | match("\\breview(?!-lead)[^:—]{0,40}:[ \\t]*(?:PR[ \\t]*#[0-9]+[ \\t]*/[ \\t]*)?(" + id_regex + ")"; "ig") ]
       + [ $t | match("\\breview(?!-lead)[ \\t]*\\(?[ \\t]*(" + id_regex + ")"; "ig") ])
    | map(.captures[0].string) | unique;
# Judged id-shaped by the id grammar of the board itself: a token
# <prefix>-<rest>[.n…] counts only when <prefix> matches a prefix observed in
# the real board ids — so hyphenated prose ("re-review", "data-safety",
# "starved-gate") never trips it, while a foreign "psa-xyz" does even when
# that bead is absent from the board (the wrong-target title in the R2 repro
# named a bead that did not exist on the two-row board).
def foreign_target_ids($tid; $own_id; $title; $prefixes):
    titled_target_ids($title)
    | map(select(
          (split("-")[0] as $p | ($prefixes | index($p)) != null)
          and (. != $tid)
          and (. != $own_id)
          and ((startswith($tid + ".")) | not)
      ));

def gate_unset: ((.metadata // {}).review_gate // "") == "";
def head_of:    ((.metadata // {}).review_gate_head // "");
def pointers_of:
    ((.metadata // {}).review_gate_reviews // "")
    | split(",") | map(gsub("^\\s+|\\s+$"; "")) | map(select(length > 0));

($now_iso | ts2e) as $now
| $board_wrap[0] as $bd
| ($bd | map(.id)) as $board_ids
| ($board_ids | map(split("-")[0]) | unique) as $prefixes
| ($bd | map(select(
      (.status == "open" or .status == "in_progress")
      and gate_unset
      and (((.assignee // "") == $lead) or (head_of != ""))
  ))) as $population
| ($population | map(
      . as $t
      | $t.id as $tid
      # Identified review legs of this target (title-shape; any status). The
      # child-id arm holds ONLY while the title names no different id-shaped
      # target IN TARGET POSITION — hierarchy alone must not override
      # contradictory title evidence (review R2), but an id that merely
      # appears in prose is incidental, not a contradiction (review R3). A
      # title that mentions the target explicitly always counts: positive
      # title evidence beats hierarchy and beats incidental foreign ids.
      | ($bd | map(select(
            (.id != $tid)
            and looks_like_review(.title)
            and ((perspectives_of(.title) | length) > 0)
            and (mentioned($tid; .title)
                 or ((.id | startswith($tid + "."))
                     and ((foreign_target_ids($tid; .id; .title; $prefixes) | length) == 0)))
        ))) as $legs
      | ($legs | map(.id)) as $leg_ids
      # Validity per leg: open-ish = reviewer working; closed needs evidence.
      | ($legs | map(
            . as $l
            | (if $l.status != "closed" then "working"
               elif (($l | notes_text) | gsub("\\s+"; "") | length) == 0 then "closed_no_notes"
               elif (($l | notes_text) | has_verdict_line | not) then "closed_no_verdict"
               elif (($l | notes_text) | has_verdict_decision | not) then "closed_bad_verdict"
               else "evidenced" end) as $disp
            | {id: $l.id, status: $l.status, perspectives: perspectives_of($l.title), disposition: $disp}
        )) as $classified
      | ($classified | map(select(.disposition == "working"))) as $working
      | ($classified | map(select(.disposition == "evidenced"))) as $evidenced
      | ($classified | map(select(.disposition == "closed_no_notes" or .disposition == "closed_no_verdict" or .disposition == "closed_bad_verdict"))) as $closed_invalid
      # Pointer accounting: dangling (no such bead), validated (an identified
      # leg of this target), mispointed (exists, but not a review of this target).
      | ($t | pointers_of) as $ptrs
      | ($ptrs | map(select(. as $p | ($board_ids | index($p)) == null))) as $dangling
      | ($ptrs | map(select(. as $p | (($board_ids | index($p)) != null) and (($leg_ids | index($p)) == null)))) as $mispointed_ids
      | ($bd | map(select(.id as $b | ($mispointed_ids | index($b)) != null))
              | map({id, status, title, reason: "pointer in review_gate_reviews names an existing bead that is not a review of this target"})) as $mispointed
      | ($ptrs | map(select(. as $p | ($leg_ids | index($p)) != null))) as $pointer_validated
      | ((($closed_invalid | map({id, status, reason:
              (if .disposition == "closed_no_notes"
               then "closed with no durable notes — no completed-review evidence"
               elif .disposition == "closed_no_verdict"
               then "closed notes lack a terminal VERDICT line — no completed-review evidence"
               else "closed notes carry a VERDICT marker but no recognised terminal decision (want VERDICT: APPROVE/REVISE/PROCEED/PASS) — no completed-review evidence" end)}))
          + ($mispointed | map({id, status, title, reason}))) ) as $invalid_inputs
      | (($working | length) + ($evidenced | length)) as $valid_count
      | ($invalid_inputs | length) as $invalid_count
      # Covered = perspectives with VALID inputs only; an invalid leg covers nothing.
      | ((($working + $evidenced) | map(.perspectives) | add // []) | unique) as $covered
      | (($t.updated_at // null) | ts2e) as $upd
      | (if $upd != null then ((($now - $upd) / 60) | floor) else null end) as $mins_untouched
      | ($valid_count == 0 and $invalid_count == 0) as $starved
      | ((($state[$tid] // {}).first_seen) // null) as $prior_seen
      | (if $starved then ($prior_seen // $now_iso) else null end) as $observed_since
      | (if $starved then ((($now - (($observed_since | ts2e) // $now)) / 60) | floor) else null end) as $mins_observed
      | (if $starved then ([($mins_untouched // 0), ($mins_observed // 0)] | max) else null end) as $parked_mins
      | (if $invalid_count > 0 then "UNKNOWN"
         elif $valid_count > 0 then "OK"
         elif ($parked_mins >= $threshold) then "ALARM"
         else "WATCH" end) as $verdict
      | (if $verdict == "UNKNOWN" then ((($state[$tid] // {}).unknown_at) // $now_iso) else null end) as $unknown_since
      | {
          id: $tid,
          title: $t.title,
          status: $t.status,
          assignee: ($t.assignee // null),
          population_reason:
              (if (($t.assignee // "") == $lead) and (($t | head_of) != "") then "assignee+gate_head"
               elif (($t.assignee // "") == $lead) then "assignee"
               else "gate_head" end),
          review_gate_head: ($t | head_of),
          pointer_ids: $ptrs,
          pointer_hits: $pointer_validated,
          dangling_pointers: $dangling,
          mispointed_pointers: $mispointed_ids,
          inputs: $classified,
          input_count: ($legs | length),
          inputs_working: ($working | length),
          inputs_evidenced: ($evidenced | length),
          inputs_closed: ($classified | map(select(.status == "closed")) | length),
          valid_input_count: $valid_count,
          invalid_inputs: $invalid_inputs,
          invalid_input_count: $invalid_count,
          covered_perspectives: $covered,
          missing_perspectives: (canonical_perspectives - $covered),
          updated_at: ($t.updated_at // null),
          updated_at_unparseable: ($upd == null),
          minutes_untouched: $mins_untouched,
          observed_starved_since: $observed_since,
          minutes_observed_starved: $mins_observed,
          parked_minutes: $parked_mins,
          threshold_minutes: $threshold,
          starved: $starved,
          verdict: $verdict,
          unknown_since: $unknown_since,
          newly_alarmed: ($verdict == "ALARM" and ((($state[$tid] // {}).alarmed_at // null) == null)),
          newly_unknown: ($verdict == "UNKNOWN" and ((($state[$tid] // {}).unknown_at // null) == null))
        }
  )) as $records
| {
    detector: "starved-review-gate",
    ok: true,
    rig: $rig,
    now: $now_iso,
    review_lead: $lead,
    owner_hint: $owner,
    threshold_minutes: $threshold,
    board_beads: ($bd | length),
    population_count: ($population | length),
    alarms: [ $records[] | select(.verdict == "ALARM") | .id ],
    watch: [ $records[] | select(.verdict == "WATCH") | .id ],
    unknown: [ $records[] | select(.verdict == "UNKNOWN") | .id ],
    newly_alarmed: [ $records[] | select(.newly_alarmed) | .id ],
    newly_unknown: [ $records[] | select(.newly_unknown) | .id ],
    population: $records,
    remedy: ("Perspective-review GENERATION is owed by the PR owner (" + $owner
             + "); " + $lead + " only synthesises review beads that exist. UNKNOWN beads need a"
             + " human to verify whether the reviews actually happened (re-record durable notes"
             + " with a VERDICT line, or regenerate; fix review_gate_reviews if mispointed)."
             + " This detector is read-only: it does not generate reviews, re-route, set gate"
             + " fields, or merge."),
    new_state: ($records | map(select(.starved or .verdict == "UNKNOWN")
        | { key: .id,
            value: {
                first_seen: .observed_starved_since,
                alarmed_at: (if .verdict == "ALARM"
                             then ((($state[.id] // {}).alarmed_at) // $now_iso)
                             else null end),
                unknown_at: .unknown_since
            } })
        | from_entries)
  }
'

REPORT_FILE="$WORK_DIR/report.json"
if ! jq -n \
        --slurpfile board_wrap "$BOARD_FILE" \
        --argjson state "$STATE_JSON" \
        --argjson threshold "$THRESHOLD_MINS" \
        --arg now_iso "$NOW_ISO" \
        --arg lead "$REVIEW_LEAD" \
        --arg owner "$OWNER_HINT" \
        --arg rig "$RIG" \
        "$JQ_CORE" >"$REPORT_FILE" 2>"$WORK_DIR/jq.err"; then
    sed 's/^/  jq: /' "$WORK_DIR/jq.err" >&2 || true
    die "decision core failed — refusing to report all-clear"
fi

# ---- persist state (only after a successful evaluation; failure is LOUD) ------
# Write path is symlink-safe across EVERY path component, not just the leaf
# (psa-qqaka.8 R4 — arbitrary-file-write). The earlier guard only ran `test -L`
# on the final component, but dirname/mktemp/the temp reopen/mv all re-resolve
# the WHOLE path: a symlink at any ANCESTOR directory, or a leaf symlink planted
# between the check and the rename, redirected the write outside the intended
# tree (GNU `mv -f` treats a symlink-to-directory as a directory and moves the
# temp INTO it). The fix PINS the write to the parent's canonical real path:
#   - realpath -e (resolves symlinks) must equal realpath -s (does NOT) for the
#     parent, so ANY symlink in the ancestor chain is refused, not followed;
#   - all file ops happen inside that resolved real directory;
#   - the rename uses --no-target-directory, so a leaf symlink planted mid-run is
#     REPLACED via rename() (which never follows the final symlink) instead of
#     descended into — verified, and the final result is checked to be a regular
#     (non-symlink) file.
# The temp is still mktemp-random (O_EXCL, unpredictable name — a planted
# "<state>.tmp" symlink is never opened). The load-time check already refused a
# pre-existing symlinked state path; this closes the write path completely.
if [ "$NO_STATE" -eq 0 ]; then
    state_dir="$(dirname "$STATE_FILE")"
    state_base="$(basename "$STATE_FILE")"

    mkdir -p "$state_dir" 2>/dev/null \
        || die "state directory could not be created: $state_dir (fix the path/permissions, or run --no-state)"
    real_dir="$(realpath -e "$state_dir" 2>/dev/null)" \
        || die "state directory could not be resolved: $state_dir (fix the path, or run --no-state)"
    [ "$real_dir" = "$(realpath -s "$state_dir" 2>/dev/null)" ] \
        || die "state path traverses a symlinked directory: $state_dir -> $real_dir — refusing to write through it (point --state-file under a real directory, or run --no-state)"

    dest="$real_dir/$state_base"
    [ ! -L "$dest" ] \
        || die "state path is a symlink: $STATE_FILE — refusing to write through or over it (point --state-file at a regular file, or run --no-state)"

    STATE_TMP=""
    if STATE_TMP="$(mktemp "$real_dir/.starved-review-gate.XXXXXXXX" 2>/dev/null)" \
            && jq '.new_state' "$REPORT_FILE" >"$STATE_TMP" 2>/dev/null \
            && mv -f --no-target-directory "$STATE_TMP" "$dest" 2>/dev/null \
            && [ -f "$dest" ] && [ ! -L "$dest" ]; then
        :
    else
        [ -z "$STATE_TMP" ] || rm -f "$STATE_TMP" 2>/dev/null || true
        die "state file could not be written: $STATE_FILE — the observed-starved/unknown clocks would silently reset, postponing alarms (fix the path/permissions, or run --no-state)"
    fi
fi

ATTN_COUNT="$(jq -r '(.alarms | length) + (.unknown | length)' "$REPORT_FILE")"

# ---- report --------------------------------------------------------------------
if [ "$JSON_OUT" -eq 1 ]; then
    jq . "$REPORT_FILE"
else
    jq -r --argjson verbose "$VERBOSE" '
        def mins: if . == null then "?" else "\(.)m" end;
        def parked_line:
            "    parked: assignee \(.assignee // "«none»"), status \(.status), via \(.population_reason), head \(.review_gate_head | if . == "" then "«none»" else . end)\n";
        def bead_block:
            "  \(.verdict) \(.id) — parked ~\(.parked_minutes // 0)m (threshold \(.threshold_minutes)m; untouched \(.minutes_untouched | mins), observed-starved \(.minutes_observed_starved | mins))\n"
            + "    title:  \(.title)\n"
            + parked_line
            + "    inputs: ZERO valid perspective-review beads exist — missing: \(.missing_perspectives | join(", "))\n"
            + (if (.dangling_pointers | length) > 0
               then "    DANGLING: review_gate_reviews names \(.dangling_pointers | join(", ")) but no such bead(s) exist\n"
               else "" end)
            + (if .updated_at_unparseable then "    NOTE: updated_at unparseable (\(.updated_at // "null")) — using observed-starved clock only\n" else "" end);
        def unknown_block:
            "  UNKNOWN \(.id) — gate inputs exist but \(.invalid_input_count) cannot be trusted as evidence\n"
            + "    title:  \(.title)\n"
            + parked_line
            + "    invalid: " + (.invalid_inputs | map("\(.id) — \(.reason)") | join("; ")) + "\n"
            + "    valid:   \(.inputs_working) working, \(.inputs_evidenced) evidenced-closed; covered: \(.covered_perspectives | join(", ") | if . == "" then "«none»" else . end)\n";
        def ok_block:
            "  OK \(.id) — \(.valid_input_count) valid review input(s) (\(.inputs_working) working = reviewer active, \(.inputs_evidenced) evidenced-closed); covered: \(.covered_perspectives | join(", ") | if . == "" then "«none classified»" else . end)\n";
        "[starved-review-gate] rig=\(.rig) now=\(.now) beads=\(.board_beads) population=\(.population_count) alarms=\(.alarms | length) unknown=\(.unknown | length) watch=\(.watch | length)"
        + (if (.alarms | length) > 0 then
              "\n\nALARM — review gate starved of its inputs (\(.alarms | length) bead(s)):\n"
              + ([.population[] | select(.verdict == "ALARM") | bead_block] | join(""))
              + "\n  owed: \(.remedy)\n"
              + "  responder: verify on the board, then have \(.owner_hint) generate the four perspective reviews. This detector will not do it."
           else "" end)
        + (if (.unknown | length) > 0 then
              "\n\nUNKNOWN — invalid gate inputs, a human must look (\(.unknown | length) bead(s)):\n"
              + ([.population[] | select(.verdict == "UNKNOWN") | unknown_block] | join(""))
              + "\n  action: verify whether these reviews actually happened. Re-record durable notes with a terminal VERDICT line, or have \(.owner_hint) regenerate; fix review_gate_reviews if mispointed. Closed-without-evidence must never read as reviewed."
           else "" end)
        + (if (.watch | length) > 0 then
              "\n\nWATCH — starved but under threshold (\(.watch | length) bead(s)):\n"
              + ([.population[] | select(.verdict == "WATCH") | bead_block] | join(""))
           else "" end)
        + (if $verbose == 1 then
              (if ([.population[] | select(.verdict == "OK")] | length) > 0 then
                  "\n\npopulation with valid inputs present (healthy):\n"
                  + ([.population[] | select(.verdict == "OK") | ok_block] | join(""))
               else "\n\npopulation with valid inputs present (healthy): none" end)
           else "" end)
        + (if (.alarms | length) == 0 and (.unknown | length) == 0 and (.watch | length) == 0 then
              "\nall clear — every gate-armed bead has at least one valid review input (population \(.population_count))."
           else "" end)
    ' "$REPORT_FILE"
fi

[ "$ATTN_COUNT" -eq 0 ] && exit 0 || exit 1
