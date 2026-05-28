# Deploy to VPS

Push current commits to GitHub and deploy to the VPS.

## Steps

1. Run `git push` to push current branch to GitHub
2. SSH to `your-vps` and run the deploy sequence:
   ```
   ssh your-vps "cd /var/www/psa && git pull && composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan version:refresh && php artisan queue:restart || true"
   ```
3. Clear OPcache (PHP-FPM serves stale bytecode otherwise):
   ```
   ssh your-vps "echo '<?php opcache_reset(); echo \"ok\";' > /var/www/psa/public/opcache-clear.php && curl -s https://your-psa-domain/opcache-clear.php && rm /var/www/psa/public/opcache-clear.php"
   ```
4. Verify the deployment by hitting the health endpoint:
   ```
   curl -sS https://your-psa-domain/api/health
   ```
5. Report the health check result to the user

If the deploy fails, show the error output and suggest fixes. Do NOT retry automatically.
