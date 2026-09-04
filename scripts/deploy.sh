#!/bin/bash
# Deploy soundit-psa to VPS
# Usage: bash scripts/deploy.sh [REVIEWED_REF]
#
# REVIEWED_REF (optional): the exact reviewed commit/ref to deploy (e.g. a SHA or
# tag that is ALREADY on origin via the review/merge gate). Defaults to
# origin/<DEPLOY_BRANCH> (origin/main). The deploy fast-forwards the VPS checkout
# to this ref ff-only, so a diverged production checkout FAILS LOUD instead of
# silently creating a merge commit on prod (so-e67m cutover-safety).
#
# Real deploy targets are read from scripts/deploy.env (gitignored). Copy
# scripts/deploy.env.example to scripts/deploy.env and fill in your values.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/deploy.env"

if [ ! -f "$ENV_FILE" ]; then
  echo "Error: $ENV_FILE not found." >&2
  echo "Copy scripts/deploy.env.example to scripts/deploy.env and fill in your values." >&2
  exit 1
fi

set -a
# shellcheck source=/dev/null
. "$ENV_FILE"
set +a

# Absolute path of the file bash is actually executing, for the deploy gate: it
# hashes this file and refuses if it differs from the blob at the sha being
# deployed. BOTH halves of the path are re-derived here from ${BASH_SOURCE[0]}
# itself -- deliberately NOT from SCRIPT_DIR, which is assigned above, BEFORE
# deploy.env is sourced under `set -a`, and so is reassignable by that
# gitignored, ungated file: taking the directory from it would let a
# `SCRIPT_DIR=/tmp/decoy` line aim the gate at a pristine copy while this file
# is the one running.
#
# Assigning after the source, and `readonly`, guard only against an ACCIDENTAL
# PSA_DEPLOY_SELF= collision in deploy.env (if the name is already readonly the
# assignment fails and `set -e` aborts, which is the correct direction to fail).
# They are NOT a boundary against a hostile deploy.env: that file is executed as
# shell code in this shell (`. "$ENV_FILE"`), so it can shadow `export` and
# `readonly` with functions, install a trap, or exec something else entirely.
# A real boundary would require not sourcing it as code at all.
PSA_DEPLOY_SELF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/${BASH_SOURCE[0]##*/}"
export PSA_DEPLOY_SELF
readonly PSA_DEPLOY_SELF

: "${DEPLOY_HOST:?not set in scripts/deploy.env}"
: "${DEPLOY_PATH:?not set in scripts/deploy.env}"
: "${DEPLOY_DOMAIN:?not set in scripts/deploy.env}"

DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"
# Deploy a ref that is ALREADY on origin (landed via the review/merge gate). We do
# NOT push local state from here — the deploy target is origin's reviewed ref, never
# whatever happens to be checked out on the deploying box. so-e67m: this replaces the
# old 'git push' (unpinned local branch) + VPS 'git pull' (unconditional non-ff merge).
REVIEWED_REF="${1:-origin/$DEPLOY_BRANCH}"

echo "=== Deploying to $DEPLOY_HOST ==="
echo "Target ref: $REVIEWED_REF (must already be on origin — land code via the review gate, not this script)"

# =============================================================================
# REVIEW GATE (so-xodo5, for so-kvdoh). Refuses a deploy whose window contains
# held or un-approved work.
#
# Before this block, deploy.sh contained ZERO gate lines — "nothing ungated
# reaches clients" was a BEHAVIOUR, not a control: it held only while whoever
# ran the deploy remembered to read bead metadata first. On 2026-07-26 that was
# tested for real — 17 commits and 4 migrations from two PRs, both carrying an
# explicit "DO NOT MERGE" hold (one with an unresolved auth-bypass blocker),
# reached main. The only thing between that and production was a person
# choosing to check.
#
# ⭐ IT FAILS CLOSED. A missing or non-executable gate is a REFUSAL, never a
# skip — otherwise deleting one file silently disables the whole control and
# "the gate is absent" becomes indistinguishable from "the gate passed".
# =============================================================================
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PSA_GATE="${PSA_DEPLOY_GATE:-/home/charlie/soundit-office/scripts/psa-deploy-gate.sh}"

# Resolve the ref to an IMMUTABLE SHA and deploy THAT. The gate has to judge the
# exact commit that ships: if we gated "origin/main" and then let the VPS fetch
# "origin/main" for itself, anything merged in between would ship un-gated
# through a gate that returned PASS. Pinning closes that window.
echo "Refreshing origin refs..."
git -C "$REPO_DIR" fetch --prune --quiet origin
if ! TARGET_SHA="$(git -C "$REPO_DIR" rev-parse --verify "${REVIEWED_REF}^{commit}" 2>/dev/null)"; then
  echo "🔴 DEPLOY REFUSED: cannot resolve '$REVIEWED_REF' to a commit in $REPO_DIR." >&2
  echo "   The target must already be on origin. Nothing was deployed." >&2
  exit 2
fi
echo "Pinned target: $TARGET_SHA"

if [ ! -x "$PSA_GATE" ]; then
  echo "🔴 DEPLOY REFUSED: review gate not found or not executable at:" >&2
  echo "     $PSA_GATE" >&2
  echo "   This is a HARD REFUSAL, not a skip. A missing gate is not a passing gate." >&2
  echo "   Restore it, or if you are certain, set an explicit recorded override:" >&2
  echo "     PSA_DEPLOY_GATE_OVERRIDE=\"<reason>\" $0 $*" >&2
  # An override must still be reachable here or a lost file bricks emergency
  # deploys — but it has to be the SAME explicit, recorded override, never a
  # silent fallthrough.
  if [ -z "${PSA_DEPLOY_GATE_OVERRIDE:-}" ]; then
    exit 2
  fi
  echo "⚠️  OVERRIDE ACCEPTED (gate missing): ${PSA_DEPLOY_GATE_OVERRIDE}"
  # An override that is not recorded is just an off switch, so a failed append
  # must SCREAM rather than be swallowed.
  # ⚠ COUPLING: this path points at the office city from a file in the client
  # repo. Move or rename that directory and overrides stop being recorded —
  # which is exactly why this falls back and shouts instead of failing silently.
  _PSA_AUDIT="${PSA_DEPLOY_GATE_AUDIT:-/home/charlie/soundit-office/.gc/psa-deploy-gate.log}"
  _PSA_AUDIT_FB="${PSA_DEPLOY_GATE_AUDIT_FALLBACK:-${TMPDIR:-/tmp}/psa-deploy-gate.audit.log}"
  _PSA_LINE="$(date -u +%Y-%m-%dT%H:%M:%SZ) OVERRIDE-GATE-MISSING target=$TARGET_SHA reason=${PSA_DEPLOY_GATE_OVERRIDE}"
  if ! echo "$_PSA_LINE" >> "$_PSA_AUDIT" 2>/dev/null; then
    if echo "$_PSA_LINE" >> "$_PSA_AUDIT_FB" 2>/dev/null; then
      echo "⚠️  AUDIT LOG UNWRITABLE ($_PSA_AUDIT) — recorded to fallback: $_PSA_AUDIT_FB" >&2
    else
      echo "############################################################################" >&2
      echo "## ⚠  GATE-MISSING OVERRIDE **NOT RECORDED ANYWHERE ON DISK**             ##" >&2
      echo "##    LOST LINE: $_PSA_LINE" >&2
      echo "##    COPY THIS INTO THE BEAD BY HAND before you proceed." >&2
      echo "############################################################################" >&2
    fi
  fi
else
  # The gate handles (and records) its own override internally. Exit codes:
  #   0 = clear, or explicitly overridden and logged
  #   2 = BLOCKED
  #   3 = cannot assess — also a refusal: unable-to-assess is not permission.
  if ! "$PSA_GATE" "$TARGET_SHA"; then
    echo "" >&2
    echo "🔴 DEPLOY REFUSED by the review gate (reasons above). Nothing was deployed." >&2
    echo "   Fix it upstream of the deploy — in the merge — not here." >&2
    exit 2
  fi
fi

# Deploy on VPS (deploy path + PINNED reviewed sha passed as $1/$2 into the remote shell)
echo "Deploying on VPS..."
ssh "$DEPLOY_HOST" bash -s "$DEPLOY_PATH" "$TARGET_SHA" << 'REMOTE'
set -eo pipefail
cd "$1"
TARGET="$2"

echo "  Fetching origin..."
git fetch --prune origin

# Non-mutating ff check BEFORE the dump: a deploy that is going to be refused at
# the ff-only guard below must not write a backup slot or prune an older one, or
# a retry loop on a diverged checkout evicts the very pre-migration snapshots the
# 10-slot retention window exists to keep. This only decides whether to continue;
# the `git merge --ff-only` below is still the thing that moves the checkout.
if ! git merge-base --is-ancestor HEAD "$TARGET"; then
  echo "  ERROR: cannot fast-forward the production checkout to $TARGET." >&2
  echo "  The prod checkout has DIVERGED from origin (local commits, or a non-ancestor ref)." >&2
  echo "  Refusing to merge/rewrite history on production. Inspect 'git status' / 'git log --oneline -5'" >&2
  echo "  and reconcile manually — do NOT --force. (so-e67m cutover-safety guard.)" >&2
  echo "  Nothing was backed up, pruned, or changed." >&2
  exit 1
fi

# ff-only refuses for a second reason ancestry cannot see: a working tree the
# fast-forward would have to overwrite. HEAD is still an ancestor in that state,
# so the check above passes and the dump + prune would run before the refusal —
# same eviction, different input. Both checks are read-only.
# Scoped to the paths the fast-forward actually rewrites — ff-only carries local
# edits to files it does not touch straight across, so an unrelated dirty file
# must not block the deploy. --no-renames so a rename is reported as its D source
# plus its A destination (both of which the checkout really does write) instead of
# a single R pair that the pathspec would then miss.
TRACKED_BLOCKERS="$(git diff -z --name-only --no-renames HEAD "$TARGET" | xargs -0 -r git --literal-pathspecs diff --name-only HEAD --)"
if [ -n "$TRACKED_BLOCKERS" ]; then
  echo "  ERROR: uncommitted changes on prod to tracked files that $TARGET rewrites:" >&2
  echo "$TRACKED_BLOCKERS" | sed 's/^/    /' >&2
  echo "  'git merge --ff-only' would refuse to overwrite them, so the deploy stops here." >&2
  echo "  Inspect 'git status' / 'git diff' and either land those edits upstream or discard" >&2
  echo "  them on prod ('git checkout --') — do NOT --force. (so-e67m cutover-safety guard.)" >&2
  echo "  Nothing was backed up, pruned, or changed." >&2
  exit 1
fi

# Same for untracked files sitting where $TARGET adds a file: scoped to the paths
# the fast-forward would actually create, so unrelated stray files don't block.
# --no-renames because diff.renames defaults on: a rename destination would be
# reported as R and dropped by --diff-filter=A, yet ff-only still refuses to
# overwrite an untracked file sitting at it.
UNTRACKED_BLOCKERS="$(git diff -z --name-only --no-renames --diff-filter=A HEAD "$TARGET" | xargs -0 -r git --literal-pathspecs ls-files --others --exclude-standard --)"
if [ -n "$UNTRACKED_BLOCKERS" ]; then
  echo "  ERROR: untracked files on prod occupy paths that $TARGET adds:" >&2
  echo "$UNTRACKED_BLOCKERS" | sed 's/^/    /' >&2
  echo "  'git merge --ff-only' would refuse to overwrite them, so the deploy stops here." >&2
  echo "  Move or remove them on prod — do NOT --force. (so-e67m cutover-safety guard.)" >&2
  echo "  Nothing was backed up, pruned, or changed." >&2
  exit 1
fi

# The backup runs BEFORE the code swap (issue #733). It reads only .env and the
# live database — nothing from the new tree — so ordering it first costs nothing
# and buys two things: the serve-new-code-before-migrate window shrinks from
# composer + a multi-minute mysqldump down to composer install alone (it does
# NOT close — closing it is #675's app-side durable-ingest half), and a FAILED
# backup now aborts before anything mutates, instead of stranding prod on
# already-swapped code with no migration run. Accepted trade: the dump is taken
# minutes earlier, so a rollback restore is very slightly staler.
echo "  Backing up database (pre-checkout/pre-migration safety net)..."
BACKUP_DIR="storage/app/backups"
mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
# Read DB settings straight from the app's .env (strip surrounding double quotes).
env_val() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_CONNECTION="$(env_val DB_CONNECTION)"
case "$DB_CONNECTION" in
  mysql|mariadb)
    DB_HOST="$(env_val DB_HOST)"; DB_PORT="$(env_val DB_PORT)"
    DB_USERNAME="$(env_val DB_USERNAME)"; DB_DATABASE="$(env_val DB_DATABASE)"
    BACKUP_FILE="$BACKUP_DIR/pre-deploy-$STAMP.sql.gz"
    # --single-transaction: consistent snapshot without locking a live InnoDB DB.
    # --no-tablespaces: avoids needing the PROCESS privilege. Password via MYSQL_PWD
    # so it never appears in the process list. pipefail aborts the deploy if the
    # dump fails (a failed/empty gzip must NOT look like success).
    MYSQL_PWD="$(env_val DB_PASSWORD)" mysqldump \
      --single-transaction --quick --no-tablespaces \
      -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" \
      -u "$DB_USERNAME" "$DB_DATABASE" | gzip > "$BACKUP_FILE"
    ;;
  sqlite)
    DB_FILE="$(env_val DB_DATABASE)"
    BACKUP_FILE="$BACKUP_DIR/pre-deploy-$STAMP.sqlite"
    cp "${DB_FILE:-database/database.sqlite}" "$BACKUP_FILE"
    ;;
  *)
    echo "  ERROR: unsupported DB_CONNECTION '$DB_CONNECTION' — refusing to migrate without a backup." >&2
    exit 1
    ;;
esac
if [ ! -s "$BACKUP_FILE" ]; then
  echo "  ERROR: backup $BACKUP_FILE is empty — aborting before migrate." >&2
  exit 1
fi
echo "  Backed up to $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))"
# Retain only the 10 most recent pre-deploy backups.
ls -1t "$BACKUP_DIR"/pre-deploy-* 2>/dev/null | tail -n +11 | xargs -r rm -f

echo "  Fast-forwarding to $TARGET (ff-only — a diverged prod checkout FAILS LOUD, no silent merge)..."
if ! git merge --ff-only "$TARGET"; then
  echo "  ERROR: cannot fast-forward the production checkout to $TARGET." >&2
  echo "  The prod checkout has DIVERGED from origin (local commits, or a non-ancestor ref)." >&2
  echo "  Refusing to merge/rewrite history on production. Inspect 'git status' / 'git log --oneline -5'" >&2
  echo "  and reconcile manually — do NOT --force. (so-e67m cutover-safety guard.)" >&2
  exit 1
fi

echo "  Installing dependencies..."
composer install --no-dev --optimize-autoloader --quiet

echo "  Running migrations..."
php artisan migrate --force

echo "  Caching config/routes/views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "  Fixing storage permissions..."
chown -R www-data:www-data storage bootstrap/cache

echo "  Done!"
REMOTE

echo ""
echo "=== Deploy complete ==="
echo "Live at: https://$DEPLOY_DOMAIN"
