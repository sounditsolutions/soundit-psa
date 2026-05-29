# Deploy to VPS

Push current commits to GitHub and deploy to the VPS.

## Deploy targets

Real deploy targets live in `scripts/deploy.env` (gitignored — not in this public
repo). Load them before running the commands below:

```
set -a; . scripts/deploy.env; set +a
```

This provides `$DEPLOY_HOST`, `$DEPLOY_PATH`, and `$DEPLOY_DOMAIN`. If the file is
missing, copy `scripts/deploy.env.example` to `scripts/deploy.env` and fill it in.

## Steps

1. Run `git push` to push current branch to GitHub
2. SSH and run the deploy sequence:
   ```
   ssh "$DEPLOY_HOST" "cd $DEPLOY_PATH && git pull && composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan version:refresh && php artisan queue:restart || true"
   ```
3. Clear OPcache (PHP-FPM serves stale bytecode otherwise):
   ```
   ssh "$DEPLOY_HOST" "echo '<?php opcache_reset(); echo \"ok\";' > $DEPLOY_PATH/public/opcache-clear.php && curl -s https://$DEPLOY_DOMAIN/opcache-clear.php && rm $DEPLOY_PATH/public/opcache-clear.php"
   ```
4. Verify the deployment by hitting the health endpoint:
   ```
   curl -sS https://$DEPLOY_DOMAIN/api/health
   ```
5. Report the health check result to the user

If the deploy fails, show the error output and suggest fixes. Do NOT retry automatically.
