#!/bin/bash
# Deploy soundit-psa to VPS
# Usage: bash scripts/deploy.sh
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

echo "=== Deploying to $DEPLOY_HOST ==="

# Push current branch
echo "Pushing to GitHub..."
git push

# Deploy on VPS (deploy path passed as $1 into the remote shell)
echo "Deploying on VPS..."
ssh "$DEPLOY_HOST" bash -s "$DEPLOY_PATH" << 'REMOTE'
set -eo pipefail
cd "$1"

echo "  Pulling latest..."
git pull

echo "  Installing dependencies..."
composer install --no-dev --optimize-autoloader --quiet

echo "  Backing up database (pre-migration safety net)..."
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
