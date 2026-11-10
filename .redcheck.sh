#!/usr/bin/env bash
# Red-check harness: apply one BEHAVIOURAL mutation, run the suite, record the
# failure count, restore from the COMMITTED tip (never from an unverified tree).
#
# Each mutation must change behaviour while leaving signatures intact. A mutant
# that only changes a declaration would prove the suite asserts declarations.
#
# A mutant is KILLED only when the suite ACTUALLY RAN and ACTUALLY FAILED. The
# per-mutant verdict is parsed from the PHPUnit summary and cross-checked against
# the process status; it is never inferred from an exit code alone, because a
# shared exit code is not an assertion. A run that could not start (no vendor/,
# no binary, timeout) is an ERROR -- neither a kill nor a survivor -- and is
# loud. Any survivor, skip or error makes the whole harness exit non-zero.
#
# set -e is deliberately NOT used: this script runs commands that are EXPECTED to
# exit non-zero (every killed mutant), and an implicit exit between mutate and
# restore would leave the tree dirty. Statuses are checked explicitly instead.
set -uo pipefail
cd "$(dirname "$0")"

PHPUNIT=${REDCHECK_PHPUNIT:-./vendor/bin/phpunit}
FILTER=${REDCHECK_FILTER:-HdbApiReportClientTest}
TIMEOUT=${REDCHECK_TIMEOUT:-900}

TIP=$(git rev-parse HEAD)
echo "tip: $TIP"
echo "phpunit: $PHPUNIT  filter: $FILTER  timeout: ${TIMEOUT}s"
git diff --quiet || { echo "ABORT: tree dirty, commit first"; exit 1; }

# Ordered record of the mutant set actually run: one "VERDICT<TAB>name<TAB>reason"
# line per run() call, so a reader can tell 13 mutants from 14 by NAME without
# grepping this file.
RESULTS=()
N_KILLED=0
N_SURVIVED=0
N_SKIPPED=0
N_ERRORED=0

record() { # verdict name reason
  RESULTS+=("$1"$'\t'"$2"$'\t'"$3")
  case "$1" in
    KILLED)   N_KILLED=$((N_KILLED + 1)) ;;
    SURVIVED) N_SURVIVED=$((N_SURVIVED + 1)) ;;
    SKIPPED)  N_SKIPPED=$((N_SKIPPED + 1)) ;;
    *)        N_ERRORED=$((N_ERRORED + 1)) ;;
  esac
}

# classify <status> <output> -> prints "VERDICT<TAB>reason".
# Fail closed: an output shape this function does not recognise is an ERROR, not
# a pass. A verdict is only KILLED/SURVIVED when the runner's own summary marker
# AND its process status agree; disagreement is an ERROR too.
classify() {
  local st="$1" out="$2" marker=""

  if [ "$st" -eq 124 ] || [ "$st" -eq 137 ]; then
    printf 'ERRORED\ttimed out after %ss (status %s)\n' "$TIMEOUT" "$st"; return
  fi
  if [ "$st" -eq 126 ] || [ "$st" -eq 127 ]; then
    printf 'ERRORED\trunner could not be executed (status %s)\n' "$st"; return
  fi
  # Every guard and marker is matched with a here-string, never a pipe: under
  # pipefail a `printf | grep -q` whose match lands early is killed by SIGPIPE
  # (141) once the output exceeds the pipe buffer, and the guard would then read
  # as false as a function of output size rather than of content.
  #
  # The runner's OWN SUMMARY is read FIRST and outranks everything below it. The
  # infrastructure guards scan the WHOLE captured output for generic English
  # that ordinary PHP exception and assertion text routinely contains ("No such
  # file or directory"), so consulting them ahead of the summary turned a
  # genuine kill into an ERROR and refused a green tip at the baseline with a
  # false "runner missing" diagnosis. They now speak only when the runner
  # printed no summary at all -- which is the case where it really did not run.

  # Red markers (PHPUnit classic, PHPUnit 10/11 summary line, Pest).
  if grep -qE '^(FAILURES|ERRORS)!|Tests:.*(Failures|Errors): *[1-9]|Tests:.*[1-9][0-9]* +(failed|errored)' <<<"$out"; then
    marker=RED
  # Green markers.
  elif grep -qE '^OK \(|^OK, but|Tests:.*[1-9][0-9]* +passed' <<<"$out"; then
    marker=GREEN
  fi

  if [ -z "$marker" ]; then
    if grep -qE 'No such file or directory|command not found|Permission denied' <<<"$out"; then
      printf 'ERRORED\trunner missing or not executable (%s)\n' "$PHPUNIT"; return
    fi
    if grep -qiE 'please run composer|failed to open stream.*autoload|autoload\.php.*(not found|No such)' <<<"$out"; then
      printf 'ERRORED\tdependencies not installed (run composer install)\n'; return
    fi
    # "No tests executed!" is the producer's own marker for numberOfTestsRun()
    # == 0. "Could not find" used to sit in this list and is NOT a producer
    # string at all -- it is ordinary exception English, so it is gone.
    if grep -qiE 'No tests executed|No tests found|No filter matched' <<<"$out"; then
      printf 'ERRORED\tsuite ran no tests for filter %s\n' "$FILTER"; return
    fi
    printf 'ERRORED\tunrecognised runner output; no pass/fail summary found (status %s)\n' "$st"; return
  fi

  # A green BANNER is not proof that anything was ASSERTED, and "not skipped"
  # is not the same as "asserted". There are TWO green banner shapes and BOTH
  # have to be counted -- not just the one that is followed by a "Tests:" line:
  #
  #   SummaryPrinter::print() emits "OK (N tests, M assertions)" when the run
  #   was successful with nothing skipped and no issues. M is allowed to be 0
  #   (#[DoesNotPerformAssertions], expectNotToPerformAssertions(), or
  #   beStrictAboutTestsThatDoNotTestAnything="false"), so this banner is NOT
  #   proof of assertion either -- and it IS that run's count line: no separate
  #   "Tests: ..." line is printed alongside it.
  #
  #   The bare "OK, but there were issues!" banner carries NO counts of its own
  #   and is always followed by the "Tests: N, Assertions: M, ..." line.
  #
  # There are TWO of them, and an earlier revision of this guard only understood
  # one. Read from the producer (vendor/phpunit/.../TextUI/Output/SummaryPrinter.php
  # and Runner/TestResult/TestResult.php), not from output I happened to see:
  #
  #   wasSuccessful()      = no errored, no failed, no phpunit-error events.
  #                          It IGNORES incomplete and risky entirely.
  #   hasTestsWithIssues() = risky OR incomplete OR deprecations OR notices
  #                          OR warnings.
  #
  # so a run in which every test calls markTestIncomplete() in its BODY is
  # "successful with issues": it prints "OK, but there were issues!" with
  # "Tests: 14, Assertions: 0, Incomplete: 14." and exits 0. Those tests were
  # prepared and DID emit testFinished, so "No tests executed!" never fires and
  # numberOfTestsRun() is 14 -- while zero assertions were made. Subtracting
  # only "Skipped:" admits exactly that run, and the harness would then score 14
  # mutants against a suite that asserted nothing and call them all SURVIVED.
  #
  # So: require at least one test to have run that was NOT skipped and NOT
  # incomplete, AND require at least one assertion -- read from WHICHEVER of
  # the two shapes the producer printed, so neither banner can bypass the
  # check. Risky is NOT subtracted: a risky test RAN and its assertions ARE
  # counted (the Collector only flags it), so subtracting it would refuse a
  # healthy, asserting baseline; an all-risky run that asserted nothing is
  # already caught by the zero-assertion check below. Skipped and Incomplete
  # are printed under their own tokens by printCountString(), never folded
  # into another.
  if [ "$marker" = GREEN ] && grep -qE '^OK \(|^OK, but' <<<"$out"; then
    local counts okline ntests nassert nskipped nincomplete nreal
    counts=$(grep -m1 -E '^Tests: [0-9]+, Assertions: [0-9]+' <<<"$out")
    if [ -z "$counts" ]; then
      # No "Tests:" line: normalise the "OK (N tests, M assertions)" banner
      # into the same shape so ONE set of checks reads both.
      okline=$(grep -m1 -oE '^OK \([0-9]+ tests?, [0-9]+ assertions?\)' <<<"$out")
      if [ -n "$okline" ] && [[ "$okline" =~ ([0-9]+)\ tests?,\ ([0-9]+)\ assertion ]]; then
        counts="Tests: ${BASH_REMATCH[1]}, Assertions: ${BASH_REMATCH[2]}"
      fi
    fi
    if [ -z "$counts" ]; then
      printf 'ERRORED\tgreen banner with no counts; cannot prove any test ran or asserted (status %s)\n' "$st"; return
    fi
    countof() { # token -> value, or 0 when the token is absent
      local v
      v=$(grep -oE "$1: [0-9]+" <<<"$counts" | head -1)
      v=${v##* }
      printf '%s' "${v:-0}"
    }
    ntests=$(countof 'Tests')
    nassert=$(countof 'Assertions')
    nskipped=$(countof 'Skipped')
    nincomplete=$(countof 'Incomplete')
    nreal=$(( ntests - nskipped - nincomplete ))
    if [ "$nreal" -le 0 ]; then
      printf 'ERRORED\tno test actually executed: %s collected, %s skipped, %s incomplete (filter %s)\n' \
        "$ntests" "$nskipped" "$nincomplete" "$FILTER"; return
    fi
    if [ "$nassert" -eq 0 ]; then
      printf 'ERRORED\tsuite reported a green banner with ZERO assertions (%s tests); nothing was asserted for filter %s\n' \
        "$ntests" "$FILTER"; return
    fi
  fi
  if [ "$marker" = RED ] && [ "$st" -eq 0 ]; then
    printf 'ERRORED\tsummary says failed but runner exited 0 (untrustworthy runner)\n'; return
  fi
  if [ "$marker" = GREEN ] && [ "$st" -ne 0 ]; then
    printf 'ERRORED\tsummary says passed but runner exited %s (untrustworthy runner)\n' "$st"; return
  fi
  if [ "$marker" = RED ]; then
    printf 'KILLED\tsuite failed under the mutation (status %s)\n' "$st"
  else
    printf 'SURVIVED\tsuite still green under the mutation (status 0)\n'
  fi
}

run() {
  local name="$1" file="$2" from="$3" to="$4"
  # Prove the mutation landed exactly once, or the run is meaningless.
  # Counted on the WHOLE FILE as a substring (grep -c counts lines, which is
  # wrong for a multi-line anchor and silently skipped four mutants).
  if ! python3 - "$file" "$from" "$to" <<'PY'
import sys
p,f,t=sys.argv[1],sys.argv[2],sys.argv[3]
s=open(p).read()
n=s.count(f)
if n!=1:
    print(f'anchor appears {n} times'); sys.exit(1)
open(p,'w').write(s.replace(f,t))
PY
  then
    echo "MUTANT $name => SKIPPED: bad anchor in $file (mutation not applied)"
    record SKIPPED "$name" "bad anchor in $file"
    return
  fi

  # Capture status and output separately. The status must come from the runner
  # itself, so no pipeline is placed between it and $? -- output is trimmed
  # afterwards, out of band.
  local out st verdict reason line
  out=$(timeout "$TIMEOUT" "$PHPUNIT" --filter "$FILTER" 2>&1)
  st=$?

  line=$(classify "$st" "$out")
  verdict=${line%%$'\t'*}
  reason=${line#*$'\t'}

  echo "MUTANT $name => $verdict: $reason"
  if [ "$verdict" != KILLED ]; then
    # A non-kill is the interesting case: show the runner's own tail so the
    # reader can see what the harness read, not just what it concluded.
    # Newline-terminated: $out comes from command substitution, which strips
    # trailing newlines, so a bare `printf '%s'` would glue the NEXT mutant's
    # "MUTANT ... =>" line onto the end of this dump.
    printf '%s\n' "$out" | tail -5 | sed "s/^/    | /"
  fi
  record "$verdict" "$name" "$reason"

  git checkout "$TIP" -- "$file"
  git diff --quiet -- "$file" || { echo "ABORT: restore failed for $file"; exit 1; }
}

# Baseline. The UNMUTATED suite must run and pass BEFORE the first mutation, or
# every KILLED below is indistinguishable from the tip being red for a reason
# that predates the mutant: a suite already failing at $TIP scores 14/14 and
# reports PASSED. Costs one extra suite run and is the precondition of any
# mutation verdict. Judged by the same classify(), so "could not start" is not
# mistaken for "red".
BASE_OUT=$(timeout "$TIMEOUT" "$PHPUNIT" --filter "$FILTER" 2>&1)
BASE_ST=$?
BASE_LINE=$(classify "$BASE_ST" "$BASE_OUT")
if [ "${BASE_LINE%%$'\t'*}" != SURVIVED ]; then
  echo "BASELINE (unmutated) => NOT GREEN: ${BASE_LINE#*$'\t'}"
  printf '%s\n' "$BASE_OUT" | tail -5 | sed "s/^/    | /"
  echo "RED CHECK FAILED: unmutated suite is not green at $TIP; no mutation verdict is meaningful (rc=4)"
  exit 4
fi
echo "baseline: unmutated suite ran and passed under filter $FILTER"

# The portal-reader mutation set was retired with its implementation in S3.
# This retained classifier harness has no default mutants; an empty set fails
# closed below rather than claiming mutation coverage.

echo "restored tip: $(git rev-parse HEAD); dirty: $(git status --porcelain | wc -l)"

# ---------------------------------------------------------------------------
# Tally. The mutant set actually run is printed BY NAME: the prior 13-vs-14
# confusion came from counting with a regex instead of reading the names.
# ---------------------------------------------------------------------------
TOTAL=${#RESULTS[@]}
echo
echo "=== RED CHECK: mutant set actually run ($TOTAL mutants) ==="
for r in "${RESULTS[@]}"; do
  printf '  %-9s %-38s %s\n' "${r%%$'\t'*}" "$(cut -f2 <<<"$r")" "$(cut -f3- <<<"$r")"
done
echo "=== TALLY: total=$TOTAL killed=$N_KILLED survived=$N_SURVIVED skipped=$N_SKIPPED errored=$N_ERRORED ==="

for v in SURVIVED SKIPPED ERRORED; do
  names=$(for r in "${RESULTS[@]}"; do [ "${r%%$'\t'*}" = "$v" ] && cut -f2 <<<"$r"; done | paste -sd' ' -)
  [ -n "$names" ] && echo "$v: $names"
done

if [ "$TOTAL" -eq 0 ]; then
  echo "RED CHECK FAILED: no mutants were run at all"
  exit 2
fi

rc=0
[ "$N_SURVIVED" -gt 0 ] && rc=2
[ "$N_SKIPPED"  -gt 0 ] && rc=2
[ "$N_ERRORED"  -gt 0 ] && rc=3

if [ "$rc" -ne 0 ]; then
  echo "RED CHECK FAILED: green requires every named mutant to run and die (rc=$rc)"
  exit "$rc"
fi

echo "RED CHECK PASSED: unmutated baseline green; all $TOTAL named mutants ran and were killed"
exit 0
