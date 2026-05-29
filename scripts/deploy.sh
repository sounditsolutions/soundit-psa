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
set -e
cd "$1"

echo "  Pulling latest..."
git pull

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
