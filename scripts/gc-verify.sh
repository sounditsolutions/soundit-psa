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
#   3. real-data / secret guard  — fail if the outbound history reintroduces
#                                  operator emails, private keys, or known token
#                                  shapes, OR if a secret-bearing FILE
#                                  (env/backup/key) is tracked at HEAD or was
#                                  added-then-deleted in the pushed range
#                                  (php artisan secret:scan --range, so-ssoj).
#                                  Fails CLOSED on any enumeration error.
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

echo "==> [1/3] php artisan test --fail-on-warning"
if ! php artisan config:clear --ansi >/dev/null; then
    echo "==> gc-verify: FAIL (configuration clear)" >&2
    exit 1
fi

# Belt one: PHPUnit's own exit policy.
TEST_LOG="$(mktemp "${TMPDIR:-/tmp}/gc-verify-tests.XXXXXX")"
trap 'rm -f "$TEST_LOG"' EXIT
set +e
php artisan test --fail-on-warning 2>&1 | tee "$TEST_LOG"
TEST_STATUS="${PIPESTATUS[0]}"
set -e
if [ "$TEST_STATUS" -ne 0 ]; then
    echo "==> gc-verify: FAIL (PHPUnit failed or reported warnings)" >&2
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
# Content guard: reject operator emails, private keys, and known token shapes.
# Scan the FULL outbound history (git log -p), not the net BASE...HEAD diff, so a
# secret added and then removed in an intermediate commit is still seen. FAIL
# CLOSED: a failed enumeration must not read as an empty (clean) diff — the old
# `|| true` here did exactly that (psa-6vfw1 review).
GUARD_RE='@couttspnw\.com|-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[baprs]-[0-9A-Za-z-]{8,}|AKIA[0-9A-Z]{16}'
HIST=""
if [ -n "$BASE" ]; then
    if ! HIST="$(git log -p --no-color --diff-filter=ACMR "$BASE..HEAD" 2>/dev/null)"; then
        echo "ERROR: secret guard could not enumerate history ($BASE..HEAD) — failing closed" >&2
        exit 1
    fi
fi
if ! WORK="$(git diff -U0 2>/dev/null)"; then
    echo "ERROR: secret guard could not enumerate the working diff — failing closed" >&2
    exit 1
fi
if printf '%s\n%s' "$HIST" "$WORK" | grep -nEi "$GUARD_RE" >/dev/null 2>&1; then
    echo "ERROR: possible real-data/secret leak in history/diff:" >&2
    printf '%s\n%s' "$HIST" "$WORK" | grep -nEi "$GUARD_RE" >&2
    exit 1
fi

# Filename + content guard (so-ssoj / psa-6vfw1): the GUARD_RE above scans CONTENT
# for token shapes, but the leak that started this was a `.env.bak-*` FILE whose
# secrets (APP_KEY / DB_PASSWORD / HALO / PLIVO) match none of those shapes. The
# tip scan catches such a file if it is tracked at HEAD; the --range scan catches
# it even when a later commit deleted it before the tip (the add-then-delete gap
# the review found). Both fail closed if git cannot be enumerated.
php artisan secret:scan || exit 1
if [ -n "$BASE" ]; then
    php artisan secret:scan --range="$BASE..HEAD" || exit 1
fi

echo "==> gc-verify: PASS"
