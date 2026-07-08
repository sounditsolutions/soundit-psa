# Run artisan command

Run a `php artisan` command in the local dev environment.

## Arguments
$ARGUMENTS - The artisan command and arguments (e.g., "migrate", "route:list", "tinker")

## How to run

```bash
cd ~/repos/soundit-psa && php artisan $ARGUMENTS
```

## Important notes
- For interactive commands like `tinker`, warn the user that interactive commands don't work well through Claude and suggest they run it manually in a terminal
- Commands that modify the database (migrate, db:seed) run against the local SQLite database by default
- To run artisan commands against production, SSH directly: `ssh your-vps "cd /var/www/psa && php artisan <command>"`
- Use `/deploy` when you want the full production deploy sequence
