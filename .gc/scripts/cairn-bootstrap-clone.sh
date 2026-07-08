#!/bin/sh
# cairn-bootstrap-clone.sh — one-time activation: create soundit-office's scoped
# WRITABLE clone of the household vault, sparse-checked-out to 'Gas Cities/' ONLY.
# (hs-zo7uh office Mode A, Charlie's fully-writable-clone decision 2026-07-04.)
#
# PERSONAL-EXCLUSION GUARANTEE (hs-w70qr/hs-s9py5 boundary, strongest form):
# sparse-checkout in cone mode materializes ONLY the 'Gas Cities' tree — the
# household/personal notes never touch this box's disk at all. The read-only
# Sound IT slice (~/soundit-office/vault-shared) is untouched by this and keeps
# flowing canon-down as before.
#
# PRECONDITIONS: the box's vault-hub deploy key added on the hub (Jeeves/Charlie),
# ~/.ssh/config Host block 'coutts-vault-hub' present (staged alongside).
# REVERSIBLE: rm -rf "$CLONE" (and remove the hub key) restores the status quo.
set -eu
CLONE="${CAIRN_VAULT_CLONE:-$HOME/coutts-vault-gascities}"
REMOTE="${CAIRN_VAULT_REMOTE:-coutts-vault-hub:coutts-vault.git}"
if [ -e "$CLONE/.git" ]; then
  echo "clone already exists at $CLONE — nothing to do"
  exit 0
fi
# Prefer a blobless partial clone (small disk); fall back to a plain clone if
# the hub lacks uploadpack.allowFilter.
git clone --no-checkout --filter=blob:none "$REMOTE" "$CLONE" 2>/dev/null \
  || git clone --no-checkout "$REMOTE" "$CLONE"
cd "$CLONE"
git config core.symlinks false
git sparse-checkout set --cone "Gas Cities"
git checkout main 2>/dev/null || git checkout master
echo "sparse contents:"
ls -la "$CLONE"
echo "OK: scoped writable clone ready at $CLONE (only 'Gas Cities/' materialized)"
