# Contributing to Sound PSA

Sound PSA is MIT-licensed and developed in the open. Patches, issues and downstream forks are all welcome. It is built for small MSPs and owner/operators, and it is API- and MCP-first — **contributions from agents are as welcome as contributions from humans**, held to exactly the same gate.

## Before you start

- Open issues include **unfixed defects and work in progress**. Check the tracker before starting; the thing you are about to fix may already have a branch.

## Getting it running

Requires **PHP 8.2 or newer** (CI runs 8.3). The contributor path is a manual Composer setup:

```
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php -S 127.0.0.1:8080 -t public
```

See [`docs/INSTALL.md`](docs/INSTALL.md) for the full instructions, and the [README](README.md) for the stack and architecture. Docker Compose is also available for maintainer convenience — see [`docs/DOCKER.md`](docs/DOCKER.md).

A demo dataset is available for local work:

```
php artisan db:seed --class=DevDataSeeder --force
```

It **refuses to run** outside `APP_ENV=local` with a database whose name contains `_dev`, by design. Do not defeat that guard.

## The gate

One command decides whether a change is acceptable, and CI runs the same one:

```
bash scripts/gc-verify.sh
```

It runs, in order: `php artisan test` · `vendor/bin/pint --test` scoped to the PHP files you changed · a real-data and secret guard. That script is the single source of truth — CI runs it, maintainers run it, and so should you before opening a pull request. If it passes locally and fails in CI, that is a bug worth reporting on its own.

## Before you write code

The repo documents its own intent; read rather than infer. [`DESIGN.md`](DESIGN.md) for architectural decisions, [`PRODUCT.md`](PRODUCT.md) for what the product is trying to be, [`AGENTS.md`](AGENTS.md) and [`CLAUDE.md`](CLAUDE.md) for the working conventions — those last two apply to human contributors as much as to agents.

## Pull requests

- Branch from `main`; keep a pull request to one coherent change.
- **Say what you tested.** A change to money, permissions, or a customer-visible surface needs a test that would have failed before the change.
- Explain *why*, not just *what*. The history is the durable record of the reasoning.
- Reviews may come from automated review seats as well as maintainers. Treat a machine-authored review as you would a human one: argue with it if it is wrong.

## Things that will be sent back

- Secrets, real customer data, or production hostnames in the diff — the guard in `scripts/gc-verify.sh` catches most, not all.
- Formatting-only churn mixed into a behavioural change.
- A fix to a reported defect with no test pinning the reported behaviour.

## Licence

By contributing you agree your contribution is licensed under the [MIT Licence](LICENSE).
