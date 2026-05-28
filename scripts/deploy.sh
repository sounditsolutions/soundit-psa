#!/bin/bash
# Deploy soundit-psa to VPS
# Usage: bash scripts/deploy.sh
# Or from WSL: ./scripts/deploy.sh

set -e

echo "=== Deploying PSA PSA ==="

# Push current branch
echo "Pushing to GitHub..."
git push

# Deploy on VPS
echo "Deploying on VPS..."
ssh your-vps bash -s << 'REMOTE'
set -e
cd /var/www/psa

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
echo "Live at: https://your-psa-domain"
