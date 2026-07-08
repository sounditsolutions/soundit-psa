#!/bin/sh
# cairn-vault-sync.sh — soundit-office's bidirectional vault sync for the Cairn
# memory tree (hs-zo7uh office Mode A; staged by the house-staff artificer).
#
# TWO-KEY DEFAULT-DENY — no-ops unless BOTH markers exist:
#   <city>/.gc/cairn.enabled        pack master switch (reviewed enable)
#   <city>/.gc/cairn-sync.enabled   THIS wiring — arm: touch .gc/cairn-sync.enabled
# Reversible: rm .gc/cairn-sync.enabled (or delete orders/cairn-vault-sync.toml).
#
# SINGLE-WRITER-PER-SUBTREE (hs-vx8xt discipline, enforced two ways):
#   1. Only 'Gas Cities/soundit-office/' is ever staged (git add -- <subtree>).
#   2. A scope ASSERT aborts (and flags) if anything staged falls outside it —
#      belt over the add filter.
# The clone is sparse ('Gas Cities/' only), so personal vault content is not
# even present on this box, let alone writable.
#
# Conflict handling mirrors the household vault-brain-sync: pull --rebase; on
# repeated conflict, write a CONFLICT-STRANDED flag + best-effort mail so a
# strand can't sit silent.
set -u
CITY="${CAIRN_SYNC_CITY:-/home/charlie/soundit-office}"
CLONE="${CAIRN_VAULT_CLONE:-/home/charlie/coutts-vault-gascities}"
SUBTREE="Gas Cities/soundit-office"
GC_BIN="${CAIRN_SYNC_GC:-/home/charlie/go/bin/gc}"
STATE_DIR="$HOME/.local/state/cairn-vault-sync"
mkdir -p "$STATE_DIR" 2>/dev/null || true

[ -f "$CITY/.gc/cairn.enabled" ] || { echo 'cairn-vault-sync: pack not enabled — no-op'; exit 0; }
[ -f "$CITY/.gc/cairn-sync.enabled" ] || { echo 'cairn-vault-sync: wiring not armed (operator: touch .gc/cairn-sync.enabled) — no-op'; exit 0; }
[ -d "$CLONE/.git" ] || { echo "cairn-vault-sync: clone missing at $CLONE (run cairn-bootstrap-clone.sh) — no-op"; exit 0; }

cd "$CLONE" || exit 0

# Stage ONLY this city's subtree; everything else stays untouched.
git add -- "$SUBTREE" 2>/dev/null || true

# Scope assert (belt): nothing outside the subtree may be staged.
STAGED="$(git diff --cached --name-only)"
if [ -n "$STAGED" ]; then
  BAD="$(printf '%s\n' "$STAGED" | grep -v "^$SUBTREE/" || true)"
  if [ -n "$BAD" ]; then
    printf 'cairn-vault-sync: SCOPE VIOLATION — refusing to commit paths outside %s:\n%s\n' "$SUBTREE" "$BAD" >&2
    printf '%s\n' "$BAD" > "$STATE_DIR/SCOPE-VIOLATION"
    git reset -q -- .
    exit 0
  fi
  git commit -q -m "soundit-office: cairn sync $(date -u +%Y-%m-%dT%H:%M:%SZ)"
fi

# Pull --rebase; on conflict abort + count a strike; flag after 3 in a row.
BR="$(git rev-parse --abbrev-ref HEAD)"
if ! git pull --rebase -q origin "$BR" 2>"$STATE_DIR/pull.err"; then
  git rebase --abort 2>/dev/null || true
  streak=$(( $(cat "$STATE_DIR/conflict.streak" 2>/dev/null || echo 0) + 1 ))
  echo "$streak" > "$STATE_DIR/conflict.streak"
  echo "cairn-vault-sync: pull --rebase failed (streak=$streak) — skipping push" >&2
  if [ "$streak" -ge 3 ] && [ ! -f "$STATE_DIR/CONFLICT-STRANDED" ]; then
    date -u > "$STATE_DIR/CONFLICT-STRANDED"
    cd "$CITY" && "$GC_BIN" mail send --to manager -s "cairn-vault-sync STRANDED (repeated rebase conflict)" \
      -m "The office memory-tree sync hit $streak consecutive rebase conflicts. Resolve in $CLONE and push; then rm $STATE_DIR/CONFLICT-STRANDED + conflict.streak. Until then office memory writes stay local-only (nothing lost)." >/dev/null 2>&1 || true
  fi
  exit 0
fi
rm -f "$STATE_DIR/conflict.streak" "$STATE_DIR/CONFLICT-STRANDED" 2>/dev/null || true

AHEAD="$(git rev-list --count '@{u}..HEAD' 2>/dev/null || echo 0)"
if [ "$AHEAD" -gt 0 ]; then
  if git push -q origin "$BR" 2>"$STATE_DIR/push.err"; then
    echo "cairn-vault-sync: pushed $AHEAD commit(s)"
  else
    echo "cairn-vault-sync: push failed (will retry next tick): $(head -c200 "$STATE_DIR/push.err")" >&2
  fi
else
  echo "cairn-vault-sync: up to date"
fi
