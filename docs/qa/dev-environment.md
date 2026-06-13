# QA Dev Environment Contract

The QA agent drives the **dev server only**. Dev is disposable staged data.

## Access
- URL: `https://soundit-dev` (also reachable by IP; `192.168.1.51` is DHCP-reserved for the box). Self-signed cert — the harness sets `ignoreHTTPSErrors`.
- Auth bypass (no Entra): `GET /dev/login/{id}` — logs in as that user. Only registered when `APP_ENV=local`. User `1` = a seeded staff user. Requesting it with `Accept: application/json` returns JSON and sets the session cookie without the post-login redirect (the harness uses this).
- Served by systemd `soundit-psa-dev.service` (`php -S 127.0.0.1:8080 -t public` behind nginx 443) from `/home/charlie/repos/soundit-psa` on `main`.

## Queue worker (REQUIRED for async flows)
Async jobs (triage, wiki mining) run on the **database** queue. A worker MUST be running or those flows stall.
- Managed by systemd `soundit-psa-queue.service` (`php artisan queue:work --tries=2 --timeout=900 --sleep=1 --rest=1`), `WorkingDirectory=/home/charlie/repos/soundit-psa`.
- **After deploying code that changes a queued job (mining/triage), restart the worker so it runs the new code:** `sudo systemctl restart soundit-psa-queue.service`. (The worker is long-running and caches loaded PHP.)
- Reset the queue (dev only): `php artisan queue:clear`.

## Reset
Dev data is staged/disposable. Clearing the queue or mutating tickets/wiki during QA runs is expected and acceptable.
