# Docker Compose

Docker Compose is an optional path for local development, self-hosted evaluation, and production-style examples for Sound PSA. It does not replace the manual Composer/VPS install flow documented in [INSTALL.md](INSTALL.md).

## Prerequisites

- Docker
- Docker Compose plugin (`docker compose`, not the legacy `docker-compose` binary)

## Local Development / Evaluation

From the repository root:

```bash
cp .env.docker.example .env
docker compose build
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

Optionally seed the BlueTier IT Solutions demo dataset:

```bash
docker compose exec app php artisan db:seed --class=DevDataSeeder --force
```

Open http://localhost:8080.

The local Compose file is `compose.yml`. It uses Docker-friendly local defaults from `.env.docker.example`, exposes `${APP_PORT:-8080}:80`, and bind-mounts the repository so local file edits are visible in the containers.

## Common Commands

View logs:

```bash
docker compose logs -f
docker compose logs -f app nginx db
```

Open a shell in the Laravel container:

```bash
docker compose exec app bash
```

Run Artisan commands:

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan route:list
```

Run tests:

```bash
docker compose exec app php artisan test
```

Stop containers:

```bash
docker compose down
```

Reset the database volume. This deletes the local MariaDB data for this Compose project:

```bash
docker compose down -v
```

## Queue and Scheduler

The Compose file includes optional `queue` and `scheduler` services behind the `workers` profile. Start them with the app stack when you want background jobs and scheduled tasks running locally:

```bash
docker compose --profile workers up -d
```

The queue service runs:

```bash
php artisan queue:work database --sleep=3 --tries=3 --timeout=90
```

The scheduler service runs `php artisan schedule:run` every 60 seconds.

## Production-Style Example

The repository also includes `docker-compose.prod.yml` and `.env.production.example` for a production-style Compose example:

```bash
cp .env.production.example .env.production
# edit .env.production with real values before running
docker compose --env-file .env.production -f compose.yml -f docker-compose.prod.yml config
docker compose --env-file .env.production -f compose.yml -f docker-compose.prod.yml up -d
docker compose --env-file .env.production -f compose.yml -f docker-compose.prod.yml exec app php artisan key:generate
docker compose --env-file .env.production -f compose.yml -f docker-compose.prod.yml exec app php artisan migrate --force
```

This is a production-style example, not a full hardening guide. Operators are responsible for HTTPS, backups, monitoring, secret management, updates, firewalling, and database recovery.

The production override removes the local source bind mounts, runs from the built image contents, sets production-oriented defaults such as `APP_ENV=production` and `APP_DEBUG=false`, uses `restart: unless-stopped`, and keeps only the web service exposed. The MariaDB service is only available on the Compose network.

## Environment and Secrets

`.env.docker.example` is safe to commit and contains Docker-friendly local defaults for MariaDB, file cache, file sessions, database queues, and log mail. Copy it to `.env` before starting Compose.

`.env.production.example` is also safe to commit because it contains placeholders only. Copy it to `.env.production`, fill in real values, generate a real `APP_KEY`, and set strong database passwords before using the production-style override.

Real Microsoft, Plivo, AI, mail, billing, and other integration credentials still need to be configured manually for your own environment. Do not commit real secrets in `.env`, `.env.production`, or any other file.

## Notes

- MariaDB 10.11 is the default database in Compose to match the documented deployment database.
- The app image does not bake in `.env` and does not run migrations during build.
- Nginx serves the Laravel `public` directory and forwards PHP requests to the `app` PHP-FPM service.
- The host port defaults to `8080`. Set `APP_PORT` in `.env` to use another port.
- For production-style runs, configure HTTPS/TLS at a reverse proxy or load balancer and configure backups for the `db_data` volume.
