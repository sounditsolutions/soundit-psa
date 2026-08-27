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

# Resolve the base commit to diff against (prefer origin/main, then main).
BASE=""
for ref in origin/main main; do
    if git rev-parse --verify --quiet "$ref" >/dev/null 2>&1; then
        BASE="$(git merge-base HEAD "$ref" 2>/dev/null || true)"
        [ -n "$BASE" ] && break
    fi
done

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
