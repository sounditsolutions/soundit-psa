# Docker Compose

Docker Compose is the containerized deployment path for Sound PSA. The production-style setup is the primary container workflow for MSPs and other operators. A smaller local development setup is also available for maintainers.

This guide does not replace the manual Composer/VPS install flow in [INSTALL.md](INSTALL.md). It also is not a full hardening guide: operators are responsible for HTTPS, backups, monitoring, secret management, updates, firewalling, and database recovery.

## Prerequisites

- Docker
- Docker Compose plugin (`docker compose`, not the legacy `docker-compose` binary)
- A server or VM with enough disk for the MariaDB volume and backups
- A DNS name and HTTPS/TLS termination at a reverse proxy or load balancer

## Production-Style Deployment

Use `compose.yml` plus `compose.prod.yml` together. The base file defines the services; the production override removes local source bind mounts, runs the application from the built image contents, sets production-oriented defaults, starts queue and scheduler services, uses `restart: unless-stopped`, and exposes only the web service.

Create the production environment file:

```bash
cp .env.production.example .env.production
```

Edit `.env.production` before starting containers:

- Set `APP_URL` to the public HTTPS URL.
- Generate and set a real `APP_KEY`.
- Set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, and `MYSQL_ROOT_PASSWORD`.
- Fill in mail, Microsoft, Plivo, AI, and other integration credentials only when you are ready to use those integrations.
- Keep `.env.production` private. Never commit real `.env` files.

Generate an app key without printing or committing secrets elsewhere:

```bash
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml build
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml run --rm app php artisan key:generate --show
```

Copy the generated `base64:...` value into `APP_KEY` in `.env.production`.

Validate the merged production configuration:

```bash
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml config
```

Start the production-style stack:

```bash
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml up -d
```

Run migrations:

```bash
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml exec app php artisan migrate --force
```

Open the URL from `APP_URL` through your HTTPS reverse proxy or load balancer.

## Production Operations

View service status:

```bash
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml ps
```

View logs:

```bash
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml logs -f
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml logs -f app nginx db queue scheduler
```

Run Artisan commands:

```bash
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml exec app php artisan about
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml exec app php artisan migrate --force
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml exec app php artisan config:clear
```

Open a shell in the app container:

```bash
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml exec app bash
```

Rebuild and restart after pulling new code:

```bash
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml build
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml up -d
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml exec app php artisan migrate --force
```

Stop containers without deleting the database volume:

```bash
docker compose --env-file .env.production -f compose.yml -f compose.prod.yml down
```

Do not use `down -v` in production unless you intentionally want to delete the MariaDB volume.

## Backups and Security

Configure backups for the `db_data` MariaDB volume before using the system with real customer data. Test restores regularly.

At minimum, production operators should provide:

- HTTPS/TLS at a reverse proxy or load balancer.
- Firewall rules that expose only required public ports.
- Secure storage for `.env.production` and all integration secrets.
- Database backups and documented recovery steps.
- Monitoring for app, queue, scheduler, web, and database containers.
- A process for applying application, Docker image, OS, and database updates.

The database service is not published to the host by the Compose files. It is reachable only by services on the Compose network unless you add your own port mapping.

## Queue and Scheduler

The production-style override enables `queue` and `scheduler` by default because background jobs and scheduled tasks are part of normal app operation.

The queue service runs:

```bash
php artisan queue:work database --sleep=3 --tries=3 --timeout=90
```

The scheduler service runs `php artisan schedule:run` every 60 seconds.

## Maintainer Local Development

Maintainers can use the base `compose.yml` by itself for local development and evaluation. It uses `.env.docker.example`, exposes `${APP_PORT:-8080}:80`, and bind-mounts the repository so local file edits are visible in the containers.

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

Common maintainer commands:

```bash
docker compose logs -f
docker compose exec app bash
docker compose exec app php artisan test
docker compose down
```

For local background worker testing only:

```bash
docker compose --profile workers up -d
```

Resetting the local development database volume deletes all local MariaDB data:

```bash
docker compose down -v
```

## Environment Files

`.env.production.example` contains placeholders only. Copy it to `.env.production`, fill in real values, generate a real `APP_KEY`, and set strong database passwords before starting production-style containers.

`.env.docker.example` contains local development defaults and should not be used as a production environment file.

The app image does not bake in `.env` or `.env.production`, and migrations are not run during image build.
