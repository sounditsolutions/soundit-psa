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
#   1. php artisan test          — the full PHPUnit suite must pass.
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
# Assumes a ready app environment (.env with APP_KEY, vendor/ installed).
# Exits non-zero on the first failing gate.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

# Resolve the base commit to diff against (prefer an explicit GC_VERIFY_BASE — the
# escape hatch for shallow / fork CI checkouts — then origin/main, then main).
BASE=""
if [ -n "${GC_VERIFY_BASE:-}" ]; then
    if ! BASE="$(git rev-parse --verify --quiet "${GC_VERIFY_BASE}^{commit}")"; then
        echo "ERROR: GC_VERIFY_BASE='${GC_VERIFY_BASE}' does not resolve to a commit — failing closed" >&2
        exit 1
    fi
else
    for ref in origin/main main; do
        if git rev-parse --verify --quiet "$ref" >/dev/null 2>&1; then
            BASE="$(git merge-base HEAD "$ref" 2>/dev/null || true)"
            [ -n "$BASE" ] && break
        fi
    done
fi

echo "==> [1/3] php artisan test"
php artisan config:clear --ansi >/dev/null
php artisan test

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
# No base commit means every history scan below covers NOTHING while the run still
# prints PASS — the exact fail-open this gate exists to prevent (shallow clones and
# fork CI checkouts hit it). An unresolvable base is therefore a FAILURE, not a
# silent skip; fetch the base branch or point GC_VERIFY_BASE at a fetched commit.
if [ -z "$BASE" ]; then
    echo "ERROR: secret guard could not resolve a base commit (no origin/main or main," >&2
    echo "       or no merge-base with HEAD) — history coverage would be empty." >&2
    echo "       Failing CLOSED. Fix: git fetch origin main, or GC_VERIFY_BASE=<ref|sha>." >&2
    exit 1
fi

# Content guard: reject operator emails, private keys, and known token shapes.
# Scan the FULL outbound history (git log -p), not the net BASE...HEAD diff, so a
# secret added and then removed in an intermediate commit is still seen. `-m` so a
# MERGE commit is diffed against each parent — without it git prints nothing for a
# merge and content introduced by a conflict resolution is never scanned. FAIL
# CLOSED: a failed enumeration must not read as an empty (clean) diff — the old
# `|| true` here did exactly that (psa-6vfw1 review).
GUARD_RE='@couttspnw\.com|-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[baprs]-[0-9A-Za-z-]{8,}|AKIA[0-9A-Z]{16}'
if ! HIST="$(git log -p -m --no-color --diff-filter=ACMR "$BASE..HEAD" 2>/dev/null)"; then
    echo "ERROR: secret guard could not enumerate history ($BASE..HEAD) — failing closed" >&2
    exit 1
fi
if ! WORK="$(git diff -U0 2>/dev/null)"; then
    echo "ERROR: secret guard could not enumerate the working diff — failing closed" >&2
    exit 1
fi
# NEVER echo the matched line. This runs in CI on a PUBLIC repo, so the log is
# world-readable and long-lived: printing the hit would republish the secret in a
# second place. Report the COUNT only (like secret:scan, which prints path+reason
# and never the value) and let the developer reproduce it locally.
if HITS="$(printf '%s\n%s' "$HIST" "$WORK" | grep -cEi "$GUARD_RE")"; then
    echo "ERROR: possible real-data/secret leak: ${HITS} matching line(s) in history/diff." >&2
    echo "       The matched text is deliberately NOT printed — this log is public." >&2
    echo "       Reproduce locally: git log -p -m --diff-filter=ACMR $BASE..HEAD | grep -nEi '$GUARD_RE'" >&2
    exit 1
fi

# Filename + content guard (so-ssoj / psa-6vfw1): the GUARD_RE above scans CONTENT
# for token shapes, but the leak that started this was a `.env.bak-*` FILE whose
# secrets (APP_KEY / DB_PASSWORD / HALO / PLIVO) match none of those shapes. The
# tip scan catches such a file if it is tracked at HEAD; the --range scan catches
# it even when a later commit deleted it before the tip (the add-then-delete gap
# the review found). Both fail closed if git cannot be enumerated.
php artisan secret:scan || exit 1
php artisan secret:scan --range="$BASE..HEAD" || exit 1

echo "==> gc-verify: PASS"
