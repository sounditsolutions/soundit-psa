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
#   Starved — a population bead for which ZERO review inputs exist, where an input is:
#       * a bead named in metadata.review_gate_reviews that actually exists, OR
#       * a bead in ANY status whose title looks like a perspective review of this
#         bead: contains the word "review" (not as part of "review-lead"), at least
#         one perspective keyword (product / ux / a11y / arch / correctness /
#         security / safety), and either the target id word-bounded in the title or
#         a child id of the target (<target>.<n>).
#     Review beads that exist but are still open/in_progress are a reviewer WORKING,
#     not a stall — they suppress the alarm. The alarm is for ZERO inputs, not slow
#     ones. Pointers in review_gate_reviews that name beads which do NOT exist are
#     dangling: they are reported, and they do not count as inputs.
#   Alarm — a starved bead whose parked duration reaches the threshold. Parked
#   duration is the MAX of two lower-bound clocks (both reported):
#       * untouched-for:      now - updated_at   (survives detector restarts)
#       * observed-starved:   now - first_seen   (from this script's state file;
#                             survives bead chatter that keeps touching updated_at)
#   Below threshold the bead is reported as WATCH (visible, exit 0).
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
# WHAT IT DELIBERATELY DOES NOT DO (bead psa-qqaka, ask #5)
#   No review generation, no re-routing, no gate writes, no merges, no bead writes
#   of any kind, no mail. Detect and report only. Generation is a judgement call
#   that stays with the PR owner. The only thing this script writes is its own
#   state file.
#
# USAGE
#   scripts/detect-starved-review-gate.sh [options]         # run inside the town tree
#     --rig <name>            rig to scan (default: psa)
#     --review-lead <addr>    synthesiser address (default: <rig>/review-lead)
#     --owner-hint <name>     who owes generation, for the report (default: <rig>-lead)
#     --threshold-mins <n>    alarm threshold in minutes (default: 45)
#     --input <file>          read the board from a JSON file instead of gc (tests)
#     --state-file <path>     persistence file (default:
#                             ${XDG_STATE_HOME:-$HOME/.local/state}/gc-detectors/
#                             starved-review-gate-<rig>.json)
#     --no-state              do not read or write the state file
#     --now <iso8601Z>        clock override for deterministic tests
#     --json                  emit the machine-readable report instead of text
#     --verbose               also print healthy population beads (input accounting)
#
# EXIT CODES
#   0  no alarm (population may include WATCH items — printed)
#   1  ALARM: at least one starved bead at/over threshold
#   2  detector error (scan failed, empty/invalid board, bad args) — NEVER all-clear
#
# WIRING EXAMPLES (delivery is the operator's choice; the contract is exit codes +
# report; .newly_alarmed in --json output makes an alarm bridge naturally deduped —
# it lists only beads whose alarm began this run)
#   cron, every 10 min, log-only:
#     */10 * * * * cd /path/to/town && repo/scripts/detect-starved-review-gate.sh >> /var/log/starved-review-gate.log 2>&1
#   witness patrol, mail only on NEW alarms:
#     R="$(repo/scripts/detect-starved-review-gate.sh --json)"; rc=$?
#     [ "$rc" -eq 1 ] && [ "$(printf '%s' "$R" | jq '.newly_alarmed|length')" -gt 0 ] \
#       && gc mail send psa/gastown.witness -s "ALARM: review gate starved [HIGH]" -m "$R"
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

if ! jq -en --arg t "$NOW_ISO" '($t | (sub("\\.[0-9]+Z$"; "Z") | fromdateiso8601)?) != null' >/dev/null 2>&1; then
    die "--now is not parseable ISO-8601 UTC (want YYYY-MM-DDTHH:MM:SSZ): $NOW_ISO"
fi

WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT
BOARD_FILE="$WORK_DIR/board.json"

# ---- acquire the board -------------------------------------------------------
if [ -n "$INPUT_FILE" ]; then
    [ -r "$INPUT_FILE" ] || die "board input file not readable: $INPUT_FILE"
    cp -- "$INPUT_FILE" "$BOARD_FILE"
else
    # THE RELIABLE SCAN PATH — verbatim; see header before changing anything here.
    if ! gc bd --rig "$RIG" list --all --include-gates --limit 0 --json \
            >"$BOARD_FILE" 2>"$WORK_DIR/gc.err"; then
        sed 's/^/  gc: /' "$WORK_DIR/gc.err" >&2 || true
        die "board scan failed: gc bd --rig $RIG list --all --include-gates --limit 0 --json exited non-zero"
    fi
fi

jq -e 'type == "array"' "$BOARD_FILE" >/dev/null 2>&1 \
    || die "board payload is not a JSON array — refusing to evaluate (never all-clear on a bad read)"
jq -e 'length > 0' "$BOARD_FILE" >/dev/null 2>&1 \
    || die "board scan returned ZERO beads — impossible on a live rig; this is the silent-false-negative signature (so-2ck1), not an all-clear"

# ---- load detector state (first-seen / alarmed-at clocks) ---------------------
STATE_JSON="{}"
if [ "$NO_STATE" -eq 0 ] && [ -f "$STATE_FILE" ]; then
    if jq -e 'type == "object"' "$STATE_FILE" >/dev/null 2>&1; then
        STATE_JSON="$(cat "$STATE_FILE")"
    else
        echo "WARN: state file corrupt, continuing with empty state: $STATE_FILE" >&2
    fi
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

def gate_unset: ((.metadata // {}).review_gate // "") == "";
def head_of:    ((.metadata // {}).review_gate_head // "");
def pointers_of:
    ((.metadata // {}).review_gate_reviews // "")
    | split(",") | map(gsub("^\\s+|\\s+$"; "")) | map(select(length > 0));

($now_iso | ts2e) as $now
| $board_wrap[0] as $bd
| ($bd | map(select(
      (.status == "open" or .status == "in_progress")
      and gate_unset
      and (((.assignee // "") == $lead) or (head_of != ""))
  ))) as $population
| ($population | map(
      . as $t
      | $t.id as $tid
      | ($t | pointers_of) as $ptrs
      | ($bd | map(select(.id as $bid | ($ptrs | index($bid)) != null))) as $ptr_hits
      | ($ptrs - ($ptr_hits | map(.id))) as $dangling
      | ($bd | map(select(
            (.id != $tid)
            and looks_like_review(.title)
            and ((perspectives_of(.title) | length) > 0)
            and ((.id | startswith($tid + ".")) or mentioned($tid; .title))
        ))) as $title_hits
      | (($ptr_hits + $title_hits) | unique_by(.id)) as $inputs
      | ($inputs | map(perspectives_of(.title)) | add // [] | unique) as $covered
      | (($t.updated_at // null) | ts2e) as $upd
      | (if $upd != null then ((($now - $upd) / 60) | floor) else null end) as $mins_untouched
      | (($inputs | length) == 0) as $starved
      | ((($state[$tid] // {}).first_seen) // null) as $prior_seen
      | (if $starved then ($prior_seen // $now_iso) else null end) as $observed_since
      | (if $starved then ((($now - (($observed_since | ts2e) // $now)) / 60) | floor) else null end) as $mins_observed
      | (if $starved then ([($mins_untouched // 0), ($mins_observed // 0)] | max) else null end) as $parked_mins
      | (if $starved and ($parked_mins >= $threshold) then "ALARM"
         elif $starved then "WATCH"
         else "OK" end) as $verdict
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
          pointer_hits: ($ptr_hits | map(.id)),
          dangling_pointers: $dangling,
          inputs: ($inputs | map({id, status, perspectives: perspectives_of(.title)})),
          input_count: ($inputs | length),
          inputs_open: ($inputs | map(select(.status == "open" or .status == "in_progress")) | length),
          inputs_closed: ($inputs | map(select(.status == "closed")) | length),
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
          newly_alarmed: ($verdict == "ALARM" and ((($state[$tid] // {}).alarmed_at // null) == null))
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
    newly_alarmed: [ $records[] | select(.newly_alarmed) | .id ],
    population: $records,
    remedy: ("Perspective-review GENERATION is owed by the PR owner (" + $owner
             + "); " + $lead + " only synthesises review beads that exist. This detector is"
             + " read-only: it does not generate reviews, re-route, set gate fields, or merge."),
    new_state: ($records | map(select(.starved)
        | { key: .id,
            value: {
                first_seen: .observed_starved_since,
                alarmed_at: (if .verdict == "ALARM"
                             then ((($state[.id] // {}).alarmed_at) // $now_iso)
                             else null end)
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

# ---- persist state (only after a successful evaluation) -----------------------
if [ "$NO_STATE" -eq 0 ]; then
    if mkdir -p "$(dirname "$STATE_FILE")" 2>/dev/null \
            && jq '.new_state' "$REPORT_FILE" >"$STATE_FILE.tmp" 2>/dev/null \
            && mv "$STATE_FILE.tmp" "$STATE_FILE" 2>/dev/null; then
        :
    else
        echo "WARN: could not write state file (persistence clock degraded to updated_at only): $STATE_FILE" >&2
        rm -f "$STATE_FILE.tmp" 2>/dev/null || true
    fi
fi

ALARM_COUNT="$(jq -r '.alarms | length' "$REPORT_FILE")"

# ---- report --------------------------------------------------------------------
if [ "$JSON_OUT" -eq 1 ]; then
    jq . "$REPORT_FILE"
else
    jq -r --argjson verbose "$VERBOSE" '
        def mins: if . == null then "?" else "\(.)m" end;
        def bead_block:
            "  \(.verdict) \(.id) — parked ~\(.parked_minutes // 0)m (threshold \(.threshold_minutes)m; untouched \(.minutes_untouched | mins), observed-starved \(.minutes_observed_starved | mins))\n"
            + "    title:  \(.title)\n"
            + "    parked: assignee \(.assignee // "«none»"), status \(.status), via \(.population_reason), head \(.review_gate_head | if . == "" then "«none»" else . end)\n"
            + "    inputs: ZERO perspective-review beads exist — missing: \(.missing_perspectives | join(", "))\n"
            + (if (.dangling_pointers | length) > 0
               then "    DANGLING: review_gate_reviews names \(.dangling_pointers | join(", ")) but no such bead(s) exist\n"
               else "" end)
            + (if .updated_at_unparseable then "    NOTE: updated_at unparseable (\(.updated_at // "null")) — using observed-starved clock only\n" else "" end);
        def ok_block:
            "  OK \(.id) — \(.input_count) review input(s) exist (\(.inputs_open) open/in_progress = reviewer working, \(.inputs_closed) closed); covered: \(.covered_perspectives | join(", ") | if . == "" then "«none classified»" else . end)\n";
        "[starved-review-gate] rig=\(.rig) now=\(.now) beads=\(.board_beads) population=\(.population_count) alarms=\(.alarms | length) watch=\(.watch | length)"
        + (if (.alarms | length) > 0 then
              "\n\nALARM — review gate starved of its inputs (\(.alarms | length) bead(s)):\n"
              + ([.population[] | select(.verdict == "ALARM") | bead_block] | join(""))
              + "\n  owed: \(.remedy)\n"
              + "  responder: verify on the board, then have \(.owner_hint) generate the four perspective reviews. This detector will not do it."
           else "" end)
        + (if (.watch | length) > 0 then
              "\n\nWATCH — starved but under threshold (\(.watch | length) bead(s)):\n"
              + ([.population[] | select(.verdict == "WATCH") | bead_block] | join(""))
           else "" end)
        + (if $verbose == 1 then
              (if ([.population[] | select(.verdict == "OK")] | length) > 0 then
                  "\n\npopulation with inputs present (healthy):\n"
                  + ([.population[] | select(.verdict == "OK") | ok_block] | join(""))
               else "\n\npopulation with inputs present (healthy): none" end)
           else "" end)
        + (if (.alarms | length) == 0 and (.watch | length) == 0 then
              "\nall clear — every gate-armed bead has at least one review input (population \(.population_count))."
           else "" end)
    ' "$REPORT_FILE"
fi

[ "$ALARM_COUNT" -eq 0 ] && exit 0 || exit 1
