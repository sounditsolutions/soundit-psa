# Contributing to Sound PSA

Sound PSA is MIT-licensed and developed in the open. Patches, issues and downstream forks are all welcome. It is built for small MSPs and owner/operators, and it is API- and MCP-first — **contributions from agents are as welcome as contributions from humans**, held to exactly the same gate.

## Before you start

- Open issues include **unfixed defects and work in progress**. Check the [issue tracker](https://github.com/sounditsolutions/soundit-psa/issues) before starting; the thing you are about to fix may already have a branch.

## Getting it running

Requires **PHP 8.2 or newer** — `composer.json` (`"php": "^8.2"`) is the authoritative floor; CI builds on 8.3, and that is the version the project is developed against. The contributor path is a manual Composer setup:

```
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

`.env.example` ships `DB_CONNECTION=sqlite` and leaves `DB_DATABASE` unset, so Laravel falls back to `database/database.sqlite` — a file that is not in the repo. Create it before migrating; that is what the `touch` is for. Use `php artisan serve` rather than `php -S ... -t public`: the built-in server without a router script treats any request path containing a dot as a static file and returns 404 instead of reaching the framework, which matters on an API-first app whose routes end in `.json` or `.csv`.

The steps above are the whole contributor path. [`docs/INSTALL.md`](docs/INSTALL.md) is a different document — a production deployment guide for standing up your own instance on a VPS (domain, TLS, nginx, queue workers); reach for it when you are deploying, not when you are contributing. The [README](README.md) covers the stack and architecture, and [`docs/DOCKER.md`](docs/DOCKER.md) documents the Docker Compose path.

### Getting logged in

That leaves you a running app with an empty database and **no way to sign in**: every route is behind auth and `/login` offers Microsoft Entra ID SSO only — there is no username/password form. On a local machine:

1. Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env` (both ship blank), then create the user:

   ```
   php artisan db:seed --class=AdminSeeder --force
   ```

   `AdminSeeder` creates nothing and prints an error if either variable is unset.

2. With the dev server running, open `http://127.0.0.1:8000/dev/login/1` — a bypass route registered **only** when `APP_ENV=local` (`routes/web.php`). The path segment is the user id; `1` is the first user created. It must never be reachable anywhere but a local dev machine.

### Demo data

A demo dataset — the fictional MSP "BlueTier IT Solutions" — is available for local work, but it needs **MariaDB**: it truncates with `SET FOREIGN_KEY_CHECKS=0`, which the shipped SQLite default does not support. It also **refuses to run** unless `APP_ENV=local` *and* the configured database name contains `_dev`. That guard is by design — satisfy it, do not defeat it:

1. Create a MariaDB database whose name contains `_dev`, for example `soundit_psa_dev`.
2. In `.env`, keep `APP_ENV=local`, set `DB_CONNECTION=mariadb`, and fill in `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`. `.env.example` carries that block commented out.
3. Run `php artisan migrate` against it, then:

   ```
   php artisan db:seed --class=DevDataSeeder --force
   ```

The seeder truncates `users` first, so run it **before** `AdminSeeder` — or re-run `AdminSeeder` afterwards.

## The gate

One command decides whether a change is acceptable, and CI runs the same one:

```
bash scripts/gc-verify.sh
```

It runs, in order: `php artisan test` · `vendor/bin/pint --test` scoped to the PHP files your branch changed against `main` · a real-data and secret guard. That script is the single source of truth — CI runs it, maintainers run it, and so should you before opening a pull request.

CI runs on pull requests and on pushes to `main`. Pushing a branch with no open pull request runs nothing, so open the PR if you want the gate's verdict. Two things can legitimately differ between your machine and CI: CI is PHP 8.3 and you may be on 8.2, and the changed-file set Pint checks is computed against `main`, so a stale branch gets a larger set there than locally. Rebase and re-run before concluding the gate is wrong — but if it still passes locally and fails in CI, say so in the PR; that is worth knowing on its own.

## Before you write code

The repo documents its own intent; read rather than infer. [`DESIGN.md`](DESIGN.md) for architectural decisions, [`PRODUCT.md`](PRODUCT.md) for what the product is trying to be, [`AGENTS.md`](AGENTS.md) and [`CLAUDE.md`](CLAUDE.md) for the working conventions — those last two apply to human contributors as much as to agents.

## Pull requests

- Branch from `main`; keep a pull request to one coherent change.
- **Say what you tested.** A change to money, permissions, or a customer-visible surface needs a test that would have failed before the change.
- Explain *why*, not just *what*. The history is the durable record of the reasoning.
- Reviews may come from automated review seats as well as maintainers. Treat a machine-authored review as you would a human one: argue with it if it is wrong.

## Things that will be sent back

- Secrets, real customer data, or production hostnames in the diff. Keeping them out is **your** job, not the guard's. The secret check in `scripts/gc-verify.sh` is a single regex covering one maintainer email domain, `BEGIN ... PRIVATE KEY` blocks, Slack tokens and AWS access key ids. It matches **no** hostnames, IP addresses, client names, absolute infrastructure paths, API keys, bearer tokens or connection strings — a green `gc-verify` says nothing about any of those. This repo is world-readable; use placeholders, as the README does with `your-psa-domain` and `your-vps`.
- Formatting-only churn mixed into a behavioural change.
- A fix to a reported defect with no test pinning the reported behaviour.

## Licence

By contributing you agree your contribution is licensed under the [MIT Licence](LICENSE).
