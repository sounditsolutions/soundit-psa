# Contributing to Sound PSA

Sound PSA is MIT-licensed and developed in the open. Patches, issues and downstream forks are all welcome. It is built for small MSPs and owner/operators, and it is API- and MCP-first — **contributions from agents are as welcome as contributions from humans**, held to exactly the same gate.

## Before you start

- Open issues include **unfixed defects and work in progress**. Check the [issue tracker](https://github.com/sounditsolutions/soundit-psa/issues) before starting; the thing you are about to fix may already have a branch.

## Getting it running

Requires **PHP 8.3 or newer**. Do not trust `composer.json` here: its `"php": "^8.2"` constraint is stale and looser than the code. The tree uses typed class constants — an 8.3-only syntax feature — in `app/Services/Tactical/TacticalAlertService.php` and `app/Services/Technician/PromptFence.php`, so on 8.2 `composer install` succeeds and then anything that autoloads those classes, `php artisan test` included, dies with a `ParseError`. CI builds on 8.3 and that is the only version the project is developed and tested against. The contributor path is a manual Composer setup:

```
git clone https://github.com/sounditsolutions/soundit-psa.git
cd soundit-psa
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

You need the PHP extensions CI installs — `mbstring`, `pdo_sqlite`, `sqlite3`, `bcmath`, `intl`, `gd`, `zip` — **plus `dom`, `libxml`, `xml`, `xmlwriter` and `curl`**, which CI's runner PHP already bundles but a stock distro build does not. On Debian/Ubuntu those are the `php8.3-xml` and `php8.3-curl` packages, separate from `php8.3-cli`; [`docs/INSTALL.md`](docs/INSTALL.md) installs them for the same reason. Dependencies declare all five (`soundasleep/html2text` and `tijsverkoyen/css-to-inline-styles` require `ext-dom`/`ext-libxml`, `cometbackup/comet-php-sdk` requires `ext-curl`, and — because the bare `composer install` above resolves dev dependencies too — `phpunit/phpunit` and `laravel/pint` require `ext-xml`, `phpunit/phpunit` and `phpunit/php-code-coverage` require `ext-xmlwriter`), so without them the very first command above aborts on Composer's platform check — `soundasleep/html2text ... requires ext-dom * -> it is missing from your system` — rather than failing later. Debian/Ubuntu ship the whole libxml family in one `php8.3-xml` package; distros that split them per-extension, and hand-built or containerised PHP, need each of `dom`, `xml` and `xmlwriter` enabled individually. With those in place the SQLite path above installs and tests clean; the other extensions Composer checks (`json`, `tokenizer`, `ctype`, `openssl`, `fileinfo`, `phar`, `zlib`, `iconv`) are compiled in by default on a normal build, and a platform check that does fail names the missing extension — install that one and re-run. CI only ever runs SQLite. Add `pdo_mysql` too if you intend to follow [Demo data](#demo-data), which needs MariaDB; without it `php artisan migrate` fails with `could not find driver`. Nothing in the tree declares the database drivers, so a missing one shows up as a runtime error rather than a failed install.

`.env.example` ships `DB_CONNECTION=sqlite` and leaves `DB_DATABASE` unset, so Laravel falls back to `database/database.sqlite` — a file that is not in the repo. Create it before migrating; that is what the `touch` is for. Use `php artisan serve` rather than `php -S ... -t public`: the built-in server without a router script treats any request path containing a dot as a static file and returns 404 instead of reaching the framework, which matters on an API-first app whose routes end in `.json` or `.csv`.

The steps above are the whole contributor path. [`docs/INSTALL.md`](docs/INSTALL.md) is a different document — a production deployment guide for standing up your own instance on a VPS (domain, TLS, nginx, queue workers); reach for it when you are deploying, not when you are contributing. The [README](README.md) covers the stack and architecture, and [`docs/DOCKER.md`](docs/DOCKER.md) documents the Docker Compose path.

### Getting logged in

That leaves you a running app with an empty database and **no way into the staff application**: its routes are all behind auth, and the staff `/login` offers Microsoft Entra ID SSO only — no username/password form. (The separate client portal does ship its own email/password login at `portal/login`; that is not a way into the staff side.) On a local machine:

1. Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env` (both ship blank), then create the user:

   ```
   php artisan db:seed --class=AdminSeeder --force
   ```

   `AdminSeeder` creates nothing and prints an error if either variable is unset.

2. With the dev server running, open `http://127.0.0.1:8000/dev/login/1` — a bypass route registered **only** when `APP_ENV=local` (`routes/web.php`). The path segment is the user id, and on the empty database you just migrated the `AdminSeeder` user is the only row, so it is `1`. That stops being true once you load [Demo data](#demo-data); an id with no row behind it 404s.

   **`APP_ENV` is what arms that route, and `.env.example` ships `APP_ENV=local`.** So the setup above deliberately turns on an unauthenticated admin login, which is right for a laptop and catastrophic anywhere reachable. If you are deploying rather than contributing, set `APP_ENV=production` — check that first, before anything else in [`docs/INSTALL.md`](docs/INSTALL.md).

### Demo data

A demo dataset — the fictional MSP "BlueTier IT Solutions" — is available for local work, but it needs **MariaDB**: it truncates with `SET FOREIGN_KEY_CHECKS=0`, which the shipped SQLite default does not support. It also **refuses to run** unless `APP_ENV=local` *and* the configured database name contains `_dev`. That guard is by design — satisfy it, do not defeat it:

1. Create a MariaDB database whose name contains `_dev`, for example `soundit_psa_dev`.
2. In `.env`, keep `APP_ENV=local`, set `DB_CONNECTION=mariadb`, and fill in `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`. `.env.example` carries that block commented out.
3. Run `php artisan migrate` against it, then:

   ```
   php artisan db:seed --class=DevDataSeeder --force
   ```

The seeder truncates `users` first and creates its own five fictional BlueTier staff accounts, so run it **before** `AdminSeeder` — or re-run `AdminSeeder` afterwards.

Two things change about logging in once you are here:

- Step 2 switched `DB_CONNECTION` from sqlite to mariadb. The admin you made in [Getting logged in](#getting-logged-in) is still in `database/database.sqlite` and does not exist in this database; `AdminSeeder` has to be run again against MariaDB if you want it.
- After the truncate, user `1` is the demo account Alex Morgan, not your admin — a re-run of `AdminSeeder` appends yours after the five demo users. So `/dev/login/1` is a demo login here. List the ids (`php artisan tinker`, then `User::pluck('email', 'id')`) and use the one you actually want.

## The gate

One command decides whether a change is acceptable, and CI runs the same one:

```
bash scripts/gc-verify.sh
```

It runs, in order: `php artisan test` · `vendor/bin/pint --test` scoped to the PHP files your branch changed against `main` · a real-data and secret guard. That script is the single source of truth — CI runs it, maintainers run it, and so should you before opening a pull request.

CI runs on pull requests and on pushes to `main`. Pushing a branch with no open pull request runs nothing, so open the PR if you want the gate's verdict.

One caveat worth knowing, because it makes the gate quietly weaker rather than louder: both the Pint check and the secret guard diff against `git merge-base HEAD origin/main`, falling back to `main`. **If neither ref resolves — a shallow clone, or a fork whose remote is named something else — that base is empty and both checks silently fall back to your uncommitted changes only.** Committed work is then never examined and the script still prints `PASS`. Fetch `main` before trusting a green run. If it passes locally and fails in CI with `main` present on both sides, say so in the PR; that is worth knowing on its own.

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
