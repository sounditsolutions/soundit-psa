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
#   3. real-data / secret guard  — fail if the diff reintroduces operator
#                                  emails, private keys, or known token shapes
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
GUARD_RE='@couttspnw\.com|-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[baprs]-[0-9A-Za-z-]{8,}|AKIA[0-9A-Z]{16}'
DIFF="$( { [ -n "$BASE" ] && git diff -U0 "$BASE"...HEAD; git diff -U0; } 2>/dev/null || true )"
if printf '%s' "$DIFF" | grep -nEi "$GUARD_RE" >/dev/null 2>&1; then
    echo "ERROR: possible real-data/secret leak in diff:" >&2
    printf '%s' "$DIFF" | grep -nEi "$GUARD_RE" >&2
    exit 1
fi

echo "==> gc-verify: PASS"
