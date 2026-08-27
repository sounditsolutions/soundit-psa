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
    BACKUP_FILE="$BACKUP_DIR/attempt-$STAMP.sql.gz"
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
    BACKUP_FILE="$BACKUP_DIR/attempt-$STAMP.sqlite"
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
# This dump is written before the ff-only guard, so it is only an ATTEMPT dump:
# it lives in its own attempt-* namespace, separate from the retained
# pre-deploy-* restore points, and is promoted across once the deploy actually
# reaches the migrate step — immediately BEFORE migrate runs, not after it
# succeeds (see below). Two consequences, both deliberate: a deploy that
# aborts at the guard (or at composer) can never evict a real pre-migration
# backup from an earlier successful deploy, and the attempt dumps themselves
# stay bounded because this prune runs on every attempt, failed ones included —
# repeated retries can no longer grow storage/app/backups without limit.
ls -1t "$BACKUP_DIR"/attempt-* 2>/dev/null | tail -n +11 | xargs -r rm -f

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

# We are about to migrate, so this attempt's dump may be a genuine pre-migration
# restore point — but ONLY if the database is actually still pre-migration.
# Promoting BEFORE migrate rather than after a zero exit is deliberate and
# load-bearing: on MySQL/MariaDB DDL is implicitly committed per statement, so a
# migration that fails part-way leaves prod partially migrated and that dump is
# the ONLY clean pre-migration snapshot — exactly the one that must not be left
# in attempt-*, where the count-based prune would evict it after 10 further
# attempts (and a failed migration is precisely what generates a rapid pile of
# retries). But those same retries dump an ALREADY-DIRTY database, so promoting
# them unconditionally would (a) put snapshots of the half-applied schema into
# the namespace an operator restores from, indistinguishable by name or content
# from real ones, and (b) fill all 10 retention slots so the next successful
# prune evicts the one clean snapshot. Promotion is therefore gated on two
# markers, both written immediately before migrate:
#   .migrate-incomplete — removed as soon as migrate exits zero. Its presence
#     means only "the last migrate did not exit zero": the database MAY be
#     half-applied, but it may equally be untouched (MySQL unreachable, a bad
#     .env, a PHP fatal during boot), and nothing on disk can tell those apart.
#     So this attempt's dump is not promoted UP FRONT — but it is not abandoned
#     in attempt-* either: if this attempt's own migrate then exits zero, that
#     dump IS the pre-migration state those migrations applied to, so it is
#     promoted after migrate under a pre-deploy-postincident-* name (same
#     retained namespace, honestly labelled as taken after an incomplete run,
#     and EXCLUDED from the clean-restore-point lookup below — that dump may
#     capture a half-applied schema, so it must never be the file an operator
#     is pointed at under the word "clean").
#     Otherwise it stays in attempt-*, so a retry storm still never adds to or
#     evicts from pre-deploy-*.
#   .deploy-incomplete — written at the MOMENT A DUMP IS PROMOTED (up front, or
#     after migrate in the postincident case) and removed only after the LAST
#     step of the deploy. It records "$TARGET @ $STAMP" and the gate below
#     matches its TARGET against this deploy's, so it means exactly "THIS
#     deploy's restore point is already retained": every later attempt of the
#     same deploy must not promote again, including one whose migrate flaked in
#     between, or each retry would spend another retention slot and evict a
#     genuine older restore point. Matching on TARGET is what stops a marker
#     left behind by an abandoned deploy from suppressing promotion for the
#     next, unrelated one — a mismatched marker is simply overwritten when this
#     deploy promotes.
# Attempts that abort earlier — at the ff-only guard or at composer, with the
# database untouched — never reach this line either.
MIGRATE_MARKER="$BACKUP_DIR/.migrate-incomplete"
DEPLOY_MARKER="$BACKUP_DIR/.deploy-incomplete"
PROMOTE_AFTER_MIGRATE=""
# `|| true`: under `set -eo pipefail` an unmatched pre-deploy-* glob makes ls
# exit 2 and pipefail propagates it, which would kill the deploy right here —
# an empty retained namespace is exactly the case the warnings below exist for.
# The grep drops pre-deploy-postincident-*: those dumps were taken after a
# migrate that did NOT exit zero, so they may hold a half-applied schema and can
# never be the file this script announces as the clean restore point. They stay
# on disk under their own name for an operator who knows what they are.
RESTORE_POINT="$(ls -1t "$BACKUP_DIR"/pre-deploy-* 2>/dev/null | grep -v '/pre-deploy-postincident-' | head -1)" || true
# The "already retained" gate is checked FIRST and matched against THIS deploy's
# target: once this deploy has a retained restore point, no later attempt of it
# promotes again, whatever its migrate did in between — a migrate that flakes
# mid-retry must not buy another retention slot.
if [ -e "$DEPLOY_MARKER" ] && [ "$(cut -d' ' -f1 "$DEPLOY_MARKER" 2>/dev/null)" = "$TARGET" ]; then
  echo "  NOTE: an earlier attempt of THIS deploy already retained its pre-migration dump ($(cat "$DEPLOY_MARKER" 2>/dev/null))." >&2
  echo "  Leaving this attempt's dump in attempt-* as $BACKUP_FILE rather than spending" >&2
  echo "  another retention slot on every retry." >&2
  if [ -e "$MIGRATE_MARKER" ]; then
    echo "  WARNING: the last migrate did not complete ($(cat "$MIGRATE_MARKER" 2>/dev/null)) — the" >&2
    echo "  database may be PARTIALLY MIGRATED. The restore point below predates this deploy." >&2
  fi
  echo "  Clean restore point remains: ${RESTORE_POINT:-NONE FOUND — do not restore blind}" >&2
elif [ -e "$MIGRATE_MARKER" ]; then
  PROMOTE_AFTER_MIGRATE=1
  echo "  WARNING: a previous migrate did not complete ($(cat "$MIGRATE_MARKER" 2>/dev/null))." >&2
  echo "  The database may be PARTIALLY MIGRATED, so this attempt's dump is NOT a clean" >&2
  echo "  pre-migration snapshot — holding it in attempt-* as $BACKUP_FILE until this" >&2
  echo "  attempt's own migrate succeeds, then retaining it as pre-deploy-postincident-*." >&2
  echo "  Clean restore point remains: ${RESTORE_POINT:-NONE FOUND — do not restore blind}" >&2
else
  mv "$BACKUP_FILE" "$BACKUP_DIR/pre-deploy-${BACKUP_FILE#$BACKUP_DIR/attempt-}"
  BACKUP_FILE="$BACKUP_DIR/pre-deploy-${BACKUP_FILE#$BACKUP_DIR/attempt-}"
  RESTORE_POINT="$BACKUP_FILE"
  # This deploy's restore point is now retained — later attempts of it must not
  # promote a second dump. Overwrites any marker left by an abandoned deploy.
  echo "$TARGET @ $STAMP" > "$DEPLOY_MARKER"
fi

echo "  Running migrations (restore point: ${RESTORE_POINT:-NONE RETAINED — see warning above})..."
# Written BEFORE migrate. Under set -e a failed migrate leaves it in place; a
# zero exit clears it. The deploy marker is deliberately NOT written here: it is
# written at the moment a dump is promoted, so it records "this deploy's restore
# point is retained" rather than the weaker "this deploy reached migrate".
echo "$TARGET @ $STAMP" > "$MIGRATE_MARKER"
php artisan migrate --force
rm -f "$MIGRATE_MARKER"
if [ -n "$PROMOTE_AFTER_MIGRATE" ]; then
  # The migrations just applied cleanly from the state this dump captures, so it
  # is the only pre-migration restore point for THIS schema change — it must not
  # be left in attempt-*, where the count prune evicts it after 10 attempts. The
  # postincident- infix keeps it distinguishable from a dump taken on a database
  # no failed migrate had touched.
  PROMOTED_FILE="$BACKUP_DIR/pre-deploy-postincident-${BACKUP_FILE#$BACKUP_DIR/attempt-}"
  mv "$BACKUP_FILE" "$PROMOTED_FILE"
  BACKUP_FILE="$PROMOTED_FILE"
  # This deploy's dump is now retained, so no later attempt of it promotes again.
  echo "$TARGET @ $STAMP" > "$DEPLOY_MARKER"
  # Deliberately NOT assigned to RESTORE_POINT: it was taken after a migrate that
  # did not exit zero, so it may hold a half-applied schema and must never be the
  # file this script hands an operator as the clean restore point.
  echo "  Retained this attempt's dump as $PROMOTED_FILE (the state those migrations applied to — taken after an INCOMPLETE migrate, so it is NOT a clean pre-migration snapshot)."
fi

echo "  Caching config/routes/views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "  Fixing storage permissions..."
chown -R www-data:www-data storage bootstrap/cache

# Retention prune runs LAST: only a deploy that reached this line — every step
# done — may delete from the retained namespace, so an unsuccessful deploy never
# does, whether it failed before migrate or after it. `|| true` for the same
# empty-glob-under-pipefail reason as the restore-point lookup above.
ls -1t "$BACKUP_DIR"/pre-deploy-* 2>/dev/null | tail -n +11 | xargs -r rm -f || true
rm -f "$DEPLOY_MARKER"

echo "  Done!"
REMOTE

echo ""
echo "=== Deploy complete ==="
echo "Live at: https://$DEPLOY_DOMAIN"
