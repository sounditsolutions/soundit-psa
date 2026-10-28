#!/usr/bin/env bash
#
# gc-verify.sh — SoundIT PSA pipeline quality gate.
#
# The single source of truth for "is this change shippable?". Run by the
# Gas City implementer formula's `check` step (so a bead cannot close and a
# PR cannot be opened unless it is green), by the CI workflow on every PR,
# and by humans on demand.
#
# Gates:
#   1. php artisan test          — the full PHPUnit suite must pass without warnings.
#      Serial by default; GC_VERIFY_PARALLEL=<N> opts into ParaTest with N
#      processes (see stage 1). phpunit.xml's source attributes
#      ignoreSuppressionOfWarnings and ignoreSuppressionOfPhpWarnings close
#      the @-suppressed warnings gap on both paths (see stage 1's probe).
#      Serial remains the default and gate of record; parallel remains opt-in.
#      Making parallel the default needs a separate Jeeves ruling.
#      Enforced by TWO independent belts, because --fail-on-warning alone was
#      measured to be partial: it does not fail on the @-suppressed read at
#      vendor/vlucas/phpdotenv/src/Store/File/Reader.php:73 that Laravel's error
#      handler surfaces in the summary's `warnings` column. Belt one is
#      --fail-on-warning (PHPUnit's own exit policy); belt two parses the
#      summary line and requires warnings == 0. An unrecognised summary is a
#      FAIL, never a PASS — this gate fails closed on a format it cannot read.
#      Risky tests and deprecations (including PHPUnit metadata deprecations)
#      retain PHPUnit/config defaults; this gate does not newly fail on them.
#   2. pint --test (changed PHP) — code style, scoped to the PHP files this
#                                  branch changed vs main. The repo carries
#                                  pre-existing style debt, so we hold only
#                                  NEW/changed code to the standard, not the
#                                  whole tree.
#   3. real-data / secret guard  — fail if the diff reintroduces operator
#                                  emails, private keys, or known token shapes
#                                  (this is a public OSS repo).
#
# Environment: vendor/ must already be installed. A missing .env is PROVISIONED
# here the way CI does it (cp .env.example .env; php artisan key:generate)
# rather than tolerated, because a worktree without .env does not fail — it
# silently converts ~7100 real passes into `warnings` and still exits 0.
# Exits non-zero on the first failing gate.
set -euo pipefail

# Resolve this script's own directory BEFORE the cd below, and absolutely. The
# library path is derived from it, and `dirname "${BASH_SOURCE[0]}"` is relative
# to the CALLER's cwd: `bash scripts/gc-verify.sh` run from a subdirectory would
# otherwise resolve `scripts/lib/...` against the repo root we are about to move
# to and not find the file. Computed once, here, so the source below cannot
# depend on where the gate was invoked from.
GC_VERIFY_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

cd "$(git rev-parse --show-toplevel)"

# Resolve the base commit to diff against (prefer origin/main, then main).
BASE=""
for ref in origin/main main; do
    if git rev-parse --verify --quiet "$ref" >/dev/null 2>&1; then
        BASE="$(git merge-base HEAD "$ref" 2>/dev/null || true)"
        [ -n "$BASE" ] && break
    fi
done

echo "==> [0/3] environment (.env)"
if [ ! -f .env ]; then
    if [ ! -f .env.example ]; then
        echo "ERROR: no .env and no .env.example to provision one from." >&2
        echo "==> gc-verify: FAIL (no app environment)" >&2
        exit 1
    fi
    echo "    .env absent — provisioning from .env.example (as CI does)"
    if ! cp .env.example .env; then
        echo "==> gc-verify: FAIL (could not provision .env)" >&2
        exit 1
    fi
    # key:generate's exit status proves nothing: handle() returns void on every
    # path, so the command exits 0 even when it wrote no key. That happens when
    # APP_KEY is already set in the ambient environment (the replacement pattern
    # is then /^APP_KEY=<the ambient value>/m, which cannot match the empty
    # APP_KEY= line we just copied in), and when .env.example carries no APP_KEY=
    # line at all. So verify the file itself, and on failure remove the .env WE
    # created: leaving an unkeyed one behind would trip the refusal branch below
    # on this and every later run, bricking the gate for this worktree until
    # someone deleted the file by hand.
    php artisan key:generate || true
    if ! grep -qE '^APP_KEY=.+' .env; then
        rm -f .env
        echo "ERROR: provisioned .env from .env.example, but key:generate wrote no APP_KEY." >&2
        echo "       Usual causes: APP_KEY is already set in this shell/container" >&2
        echo "       environment (unset it and re-run), or .env.example carries no" >&2
        echo "       APP_KEY= line. The provisioned .env has been removed, so the" >&2
        echo "       worktree is as it was and a re-run can provision cleanly." >&2
        echo "==> gc-verify: FAIL (could not generate APP_KEY)" >&2
        exit 1
    fi
fi
# Never rewrite a .env this script did not create; an unkeyed one is a refusal.
if ! grep -qE '^APP_KEY=.+' .env; then
    echo "ERROR: .env exists but APP_KEY is empty; refusing to modify it." >&2
    echo "       Run: php artisan key:generate" >&2
    echo "==> gc-verify: FAIL (no APP_KEY)" >&2
    exit 1
fi
echo "    .env present with APP_KEY"

# --- belt two's parser is loaded HERE, before the expensive stages -----------
# THE PARSER LIVES IN scripts/lib/gc-verify-summary.sh, and is sourced rather
# than defined here. Both test harnesses need the SAME function; while it lived
# inline they recovered it by regex and `eval`'d the text they recovered, which
# is GitHub #2650/#2655/#2665 -- a sed range bounded by where it ends, not by
# what it contains. Sourcing a file that defines the function and executes
# nothing removes the extraction, the validation and the eval together. Ruled by
# Jeeves on Trello card 6aadcd16 (2026-09-18): one definition, two consumers.
#
# This is the only source path, so a missing or unloadable library is a FAIL,
# never a skipped belt: belt two silently not running is how a 7286-warning
# floor passed as PASS in the first place.
#
# IT RUNS BEFORE STAGE 1 BY DELIBERATE ORDERING. In round 1 this block sat after
# `php artisan test`, so "library missing" was reported only after a ~7800-test,
# ~900s run had already completed. Loading the parser costs milliseconds and has
# no dependency on any stage, so there is no reason for the discovery to be
# expensive.
GC_VERIFY_LIB="$GC_VERIFY_SCRIPT_DIR/lib/gc-verify-summary.sh"
# -f as well as -r: `[ -r ]` is TRUE for a directory, and a directory at this
# path used to reach the `.` below and abort the gate with bash's own "is a
# directory" and no named verdict (review r1, contract:2/diff:7).
if [ ! -f "$GC_VERIFY_LIB" ] || [ ! -r "$GC_VERIFY_LIB" ]; then
    echo "ERROR: $GC_VERIFY_LIB is not a readable regular file; belt two (summary parsing) is unavailable." >&2
    echo "==> gc-verify: FAIL (summary parser library missing)" >&2
    exit 1
fi
# PROBE IN A SUBSHELL BEFORE SOURCING FOR REAL.
#
# This is not a lexical guard and it does not inspect the library's text: it
# LOADS the file and asks whether loading it actually produced the parser. Three
# r1 findings collapse into that one question, and all three were measured:
#
#   * a syntactically broken or partially-written library aborted the gate with a
#     raw `syntax error` and NO named FAIL (it failed closed, but silently);
#   * a library with a top-level `exit 0` terminated the gate at rc=0 with belt
#     two, pint and the secret guard NEVER RUN -- a green gate that checked
#     nothing, which is precisely the silent-skip class this wiring exists to
#     close, arriving through the new source path;
#   * `declare -F` alone was satisfied by an exported function INHERITED from the
#     caller's environment, so an impostor could stand in for the parser while
#     the library on disk was empty.
#
# The probe closes all three because it is an execution test, not a text test:
# `$( )` is a subshell, so a top-level `exit` ends the PROBE rather than the
# gate and simply fails to print the sentinel; a broken file fails to source and
# prints nothing; and `unset -f` inside the probe means a name that survives can
# only have come from the file. The sentinel must be printed AFTER the source
# returns, which is what makes "the source completed" observable at all.
# STDIN IS CLOSED FOR THE PROBE and the library's own stderr is KEPT.
# Round 2's review measured both: the probe inherited the gate's stdin, so a
# library containing `read` CONSUMED it and left the rest of the gate with
# nothing; and `2>/dev/null` discarded the library's own diagnosis, so a file
# that failed for a nameable reason reported only the generic cause-list below.
# A guard that makes the verdict loud and the cause silent is half a guard.
GC_VERIFY_PROBE_ERR="$(mktemp "${TMPDIR:-/tmp}/gc-verify-probe.XXXXXX")"
if [ "$( unset -f assert_no_warnings 2>/dev/null; . "$GC_VERIFY_LIB" >/dev/null 2>"$GC_VERIFY_PROBE_ERR" </dev/null; declare -F assert_no_warnings >/dev/null 2>&1 && printf LOADED )" != LOADED ]; then
    echo "ERROR: sourcing $GC_VERIFY_LIB did not yield assert_no_warnings." >&2
    echo "       The file exists but does not load cleanly to a definition: it may be" >&2
    echo "       syntactically broken, partially written, or exit before defining it." >&2
    if [ -s "$GC_VERIFY_PROBE_ERR" ]; then
        echo "       The library said, on its own stderr:" >&2
        sed 's/^/         /' "$GC_VERIFY_PROBE_ERR" >&2
    else
        echo "       It printed nothing on stderr." >&2
    fi
    rm -f "$GC_VERIFY_PROBE_ERR"
    echo "==> gc-verify: FAIL (summary parser library did not load)" >&2
    exit 1
fi
rm -f "$GC_VERIFY_PROBE_ERR"
# An inherited definition must not survive into the real load either: the probe
# proved THE FILE defines the parser, and this makes the file the only thing that
# can have defined the one we are about to call.
unset -f assert_no_warnings 2>/dev/null || true
# THE REAL LOAD GETS `</dev/null` TOO, and that is not belt-and-braces. With it
# only on the probe, a library containing `read` consumed ONE line of the gate's
# stdin instead of two -- measured, L1 eaten, L2/L3 left. Halving a defect is not
# closing it. A library is contracted to define a function and do nothing else;
# neither load has any business reading the gate's input.
# shellcheck source=lib/gc-verify-summary.sh
. "$GC_VERIFY_LIB" </dev/null
# Cheap backstop. After the unset above this can only be true because the file
# defined it, so it now means what it always claimed to mean.
if ! declare -F assert_no_warnings >/dev/null; then
    echo "ERROR: $GC_VERIFY_LIB did not define assert_no_warnings." >&2
    echo "==> gc-verify: FAIL (summary parser library did not load)" >&2
    exit 1
fi

# --- optional parallel run (opt-in; serial stays the default) ----------------
# GC_VERIFY_PARALLEL=<N> runs stage 1 as `php artisan test --parallel
# --processes=<N>` (ParaTest, a require-dev package). Unset, empty or 0 keeps
# the serial argv and exit policy as they were, provided GC_VERIFY_JUNIT is
# also unset (see below). The runner flag --fail-on-warning and the belt-two
# parser are the same on both paths. Belt two is pinned against both ParaTest
# summary labels in GcVerifySummaryWarningGateTest.
#
# SUPPRESSED-WARNING GAP CLOSED (#7271): phpunit.xml's <source> sets
# ignoreSuppressionOfWarnings="true" and ignoreSuppressionOfPhpWarnings="true".
# Forge's 2026-10-28 probe on fc61f643 with those attributes measured suppressed
# E_USER_WARNING and PHP warnings: both artisan modes exited 1; serial reported
# "2 warnings", parallel "Tests: 2, Assertions: 2, Warnings: 2." Without the
# attributes parallel exited 0 with "OK", while serial's summary caught them.
# GcVerifyParallelSuppressionTest exercises real PHPUnit and ParaTest through
# this script using an artisan adapter and source attributes read from the repo.
# Its "Warnings: N" summaries and nonzero gate exits pin both warning classes.
# Serial remains the default and gate of record; GC_VERIFY_PARALLEL is opt-in.
# Making parallel the default needs a separate Jeeves ruling.
# Ruled by Jeeves on Trello card 6ab5736b (2026-10-19 03:26 PT): parallel is
# opt-in, serial stays the default, and --fail-on-warning and belt two are
# kept. Forge's choices, not ruled text: the 1-99 range; any other value is a
# FAIL, never a quiet fallback to serial, so a typo cannot change what the
# gate measured without saying so; and the absolute-path rule below.
#
# GC_VERIFY_JUNIT=<absolute path> additionally passes --log-junit <path>, so a
# caller can compare the passed/skipped sets by name between runs. It acts on
# either path, serial included. It can fail the run: a relative value is a
# FAIL here, and the runner's JUnit write (which creates missing parent
# directories) can fail the run if it cannot write the report.
#
# ORDER MATTERS: artisan's test command binds its own declared options
# (--parallel among them; --processes is not one of them and is passed through
# to ParaTest) only BEFORE the first option it does not know; everything from
# there on is forwarded to the runner. Measured: `artisan test --fail-on-warning
# --parallel` hands --parallel to PHPUnit, which refuses it ("Unknown option").
# So the parallel options go first and --fail-on-warning follows.
GC_TEST_ARGS=()
case "${GC_VERIFY_PARALLEL:-}" in
    ''|0) ;;
    *)
        if [[ "$GC_VERIFY_PARALLEL" =~ ^[1-9][0-9]?$ ]]; then
            GC_TEST_ARGS+=(--parallel "--processes=$GC_VERIFY_PARALLEL")
        else
            echo "ERROR: GC_VERIFY_PARALLEL must be unset, 0, or a process count 1-99." >&2
            echo "==> gc-verify: FAIL (bad GC_VERIFY_PARALLEL)" >&2
            exit 1
        fi ;;
esac
GC_TEST_ARGS+=(--fail-on-warning)
if [ -n "${GC_VERIFY_JUNIT:-}" ]; then
    case "$GC_VERIFY_JUNIT" in
        /*) GC_TEST_ARGS+=(--log-junit "$GC_VERIFY_JUNIT") ;;
        *)
            echo "ERROR: GC_VERIFY_JUNIT must be an absolute path." >&2
            echo "==> gc-verify: FAIL (bad GC_VERIFY_JUNIT)" >&2
            exit 1 ;;
    esac
fi
# Consumed: unset both so nothing the suite starts inherits them. The suite
# itself runs gc-verify.sh in fixtures (GcVerify*Test); measured on the first
# parallel gate of this change, an inherited GC_VERIFY_PARALLEL sent --parallel
# to those fixtures' plain PHPUnit runner (6 failures), and an inherited
# GC_VERIFY_JUNIT would have pointed their reports at this run's file.
unset GC_VERIFY_PARALLEL GC_VERIFY_JUNIT

echo "==> [1/3] php artisan test ${GC_TEST_ARGS[*]}"
if ! php artisan config:clear --ansi >/dev/null; then
    echo "==> gc-verify: FAIL (configuration clear)" >&2
    exit 1
fi

# Belt one: PHPUnit's own exit policy.
TEST_LOG="$(mktemp "${TMPDIR:-/tmp}/gc-verify-tests.XXXXXX")"
trap 'rm -f "$TEST_LOG"' EXIT
set +e
php artisan test "${GC_TEST_ARGS[@]}" 2>&1 | tee "$TEST_LOG"
TEST_STATUS="${PIPESTATUS[0]}"
set -e
if [ "$TEST_STATUS" -ne 0 ]; then
    echo "==> gc-verify: FAIL (test run failed or reported warnings)" >&2
    exit 1
fi

# Belt two: the summary line must exist, be a dialect we recognise, and report
# zero warnings. --fail-on-warning is known not to cover every warning class
# (see the header), so a green exit code is not accepted on its own. The parser
# itself was loaded and proved present BEFORE stage 1, above.
if ! assert_no_warnings "$TEST_LOG"; then
    echo "==> gc-verify: FAIL (PHPUnit warnings, or an unreadable summary)" >&2
    exit 1
fi

echo "==> [2/3] pint --test (changed PHP files)"
changed_php() {
    { [ -n "$BASE" ] && git diff --name-only --diff-filter=ACMR "$BASE"...HEAD -- '*.php'
      git diff --name-only --diff-filter=ACMR -- '*.php'
      git diff --name-only --diff-filter=ACMR --cached -- '*.php'; } 2>/dev/null | sort -u
}
FILES=()
while IFS= read -r f; do [ -n "$f" ] && [ -f "$f" ] && FILES+=("$f"); done < <(changed_php)
if [ "${#FILES[@]}" -gt 0 ]; then
    printf '    %s\n' "${FILES[@]}"
    vendor/bin/pint --test "${FILES[@]}"
else
    echo "    (no changed PHP files — skipping)"
fi

echo "==> [3/3] real-data / secret guard"
GUARD_RE='@couttspnw\.com|-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[baprs]-[0-9A-Za-z-]{8,}|AKIA[0-9A-Z]{16}'
DIFF="$( { [ -n "$BASE" ] && git diff -U0 "$BASE"...HEAD; git diff -U0; } 2>/dev/null || true )"
if printf '%s' "$DIFF" | grep -nEi "$GUARD_RE" >/dev/null 2>&1; then
    echo "ERROR: possible real-data/secret leak in diff:" >&2
    printf '%s' "$DIFF" | grep -nEi "$GUARD_RE" >&2
    exit 1
fi

echo "==> gc-verify: PASS"
