# PSA — Installation & Configuration Guide

Deploy your own instance of this PSA on a fresh VPS. This guide covers everything from server provisioning to first login.

---

## 1. Prerequisites

Before you begin, you need:

- **Domain name** with DNS pointed to your server (e.g., `psa.yourmsp.com`)
- **SSL certificate** — Cloudflare origin cert or Let's Encrypt
- **Microsoft Entra ID (Azure AD) tenant** — for single sign-on (SSO)

Server requirements:

| Component | Version |
|-----------|---------|
| Ubuntu | 24.04 LTS |
| PHP | 8.3+ |
| Composer | 2.x |
| MariaDB | 10.11+ (or MySQL 8.0+) |
| Nginx | Latest stable |
| ffmpeg | Latest stable (optional) | Speaker-separated call transcription |

---

## 2. Provision a VPS

**Recommended:** Vultr Cloud Compute — but any Ubuntu 24.04 VPS works (DigitalOcean, Hetzner, Linode, etc.).

**Recommended spec:** 1 vCPU, 2 GB RAM, 55 GB NVMe SSD

### Initial server setup

SSH in as root, then:

```bash
# Update packages
apt update && apt upgrade -y

# Create a non-root user
adduser deploy
usermod -aG sudo deploy

# Set up SSH key auth for the new user
mkdir -p /home/deploy/.ssh
cp ~/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
chmod 600 /home/deploy/.ssh/authorized_keys

# Disable password auth
sed -i 's/#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart sshd

# Configure firewall
ufw allow OpenSSH
ufw allow 80
ufw allow 443
ufw enable
```

> **Tip:** If you have a static IP, restrict SSH access: `ufw allow from YOUR_IP to any port 22` instead of `ufw allow OpenSSH`.

From here on, SSH in as `deploy` (or your non-root user).

---

## 3. Install server stack

### PHP 8.3 + extensions

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

sudo apt install -y \
    php8.3-fpm \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-curl \
    php8.3-zip \
    php8.3-mysql \
    php8.3-bcmath \
    php8.3-intl \
    php8.3-gd
```

### Composer

```bash
cd /tmp
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### Nginx

```bash
sudo apt install -y nginx
```

### MariaDB

```bash
sudo apt install -y mariadb-server
sudo mysql_secure_installation
```

During `mysql_secure_installation`: set a root password, remove anonymous users, disallow remote root login, remove test database.

### Create database and user

```bash
sudo mysql -u root -p
```

```sql
CREATE DATABASE soundit_psa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'soundit_psa'@'localhost' IDENTIFIED BY 'YOUR_SECURE_PASSWORD';
GRANT ALL PRIVILEGES ON soundit_psa.* TO 'soundit_psa'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## 4. Deploy the application

### Clone and install

```bash
sudo mkdir -p /var/www/psa
sudo chown deploy:deploy /var/www/psa

git clone https://github.com/YOUR_ORG/soundit-psa.git /var/www/psa
cd /var/www/psa

composer install --no-dev --optimize-autoloader
```

### Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Confirm `.env` is listed in `.gitignore` — **never commit your `.env` file**.

Edit `.env` and set the following:

#### Core Laravel

```ini
APP_NAME="Your MSP PSA"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://psa.yourmsp.com
```

> **Important:** `APP_DEBUG=false` in production. Debug mode exposes stack traces and environment variables.

#### Database

```ini
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=soundit_psa
DB_USERNAME=soundit_psa
DB_PASSWORD=YOUR_SECURE_PASSWORD
```

#### Session & cache

```ini
SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
CACHE_STORE=file
```

> **Recommended:** Set `SESSION_ENCRYPT=true` in production (the `.env.example` default is `false`).

> **Warning:** Do NOT set `SESSION_DOMAIN` to a wildcard domain (e.g., `.yourmsp.com`). The PSA uses separate session guards for staff (Entra SSO) and portal (email+password). A wildcard domain can cause session conflicts between the two auth systems. Leave `SESSION_DOMAIN` unset (the default) and let Laravel scope sessions to the app's domain automatically.

#### Microsoft SSO (REQUIRED)

```ini
MICROSOFT_CLIENT_ID=your-application-client-id
MICROSOFT_CLIENT_SECRET=your-client-secret
MICROSOFT_TENANT_ID=your-directory-tenant-id
MICROSOFT_REDIRECT_URI=https://psa.yourmsp.com/auth/microsoft/callback
```

> **SECURITY WARNING:** `MICROSOFT_TENANT_ID` is **required**. If omitted, the app defaults to accepting tokens from *any* Microsoft tenant globally. This means anyone with a Microsoft account could log in. Always set this to your organization's tenant ID.

> **Important:** `MICROSOFT_REDIRECT_URI` must exactly match the redirect URI registered in your Azure app registration, including the protocol and path.

#### Other integrations

All other integrations (NinjaRMM, Level RMM, QuickBooks Online, Plivo) are configured via **Settings > Integrations** in the web UI after your first login. No `.env` variables are needed for these.

### Run migrations and cache

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Create storage symlink

```bash
php artisan storage:link
```

This creates a symlink from `public/storage` to `storage/app/public`, required for serving user-uploaded files (e.g., profile pictures).

### Set file permissions

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

> **Never use `chmod 777`** — this makes files world-readable/writable, including cached config that contains your secrets.

---

## 5. Configure Nginx

Create the site config:

```bash
sudo nano /etc/nginx/sites-available/psa.yourmsp.com
```

Paste the following (replace `psa.yourmsp.com` with your domain and update SSL cert paths):

```nginx
server {
    listen 80;
    server_name psa.yourmsp.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name psa.yourmsp.com;

    # SSL — adjust paths for your certificate
    # Cloudflare origin cert:
    ssl_certificate /etc/ssl/cloudflare/yourmsp.com.pem;
    ssl_certificate_key /etc/ssl/cloudflare/yourmsp.com.key;
    # Or Let's Encrypt (managed by certbot):
    # ssl_certificate /etc/letsencrypt/live/psa.yourmsp.com/fullchain.pem;
    # ssl_certificate_key /etc/letsencrypt/live/psa.yourmsp.com/privkey.pem;

    root /var/www/psa/public;
    index index.php;
    client_max_body_size 25m;  # Required for contract document uploads
    error_page 413 /errors/413.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;

        add_header X-Frame-Options "DENY" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-XSS-Protection "1; mode=block" always;
    }

    # Deny access to dotfiles (except .well-known for certbot)
    location ~ /\.(?!well-known).* {
        deny all;
    }

    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
}
```

Enable the site and test:

```bash
sudo ln -s /etc/nginx/sites-available/psa.yourmsp.com /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### SSL with Let's Encrypt (if not using Cloudflare)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d psa.yourmsp.com
```

---

## 6. Configure cron

The Laravel scheduler handles all recurring tasks. Add one crontab entry:

```bash
sudo crontab -e -u www-data
```

Add this line:

```
* * * * * cd /var/www/psa && php artisan schedule:run >> /dev/null 2>&1
```

### What the scheduler runs

These commands execute automatically based on their schedule:

| Command | Schedule | Purpose |
|---------|----------|---------|
| `ninja:sync-devices` | Every 4 hours | Full device sync from NinjaRMM (inventory, hardware detail, status, creates, deletes) |
| `level:sync-devices` | Every 4 hours | Sync devices from Level RMM (online status updated in real-time via webhooks) |
| `tactical:reconcile-alerts` | Hourly | Resolve PSA alerts whose Tactical alerts have closed — the at-least-once backstop for the no-retry alert webhook (only if Tactical configured) |
| `tactical:sync-devices` | Daily at 05:32 | Sync devices from Tactical RMM into `tactical_assets` and hostname-link to assets (only if configured + clients mapped to a Tactical site) |
| `tactical:sync-scripts` | Daily at 05:35 | Sync the script library from Tactical RMM (only if configured) |
| `mesh:sync-licenses` | Daily at 04:30 | Sync license counts from Mesh Email Security |
| `cipp:sync-licenses` | Daily at 04:45 | Sync M365 license counts from CIPP |
| `huntress:sync-licenses` | Daily at 05:00 | Sync EDR/ITDR license counts from Huntress (only if configured) |
| `controld:sync-licenses` | Daily at 05:10 | Sync DNS security device counts from Control D (only if configured + clients mapped) |
| `controld:sync-devices` | Daily at 05:12 | Sync DNS device data from Control D to local assets (only if configured + clients mapped) |
| `zorus:sync-licenses` | Daily at 05:18 | Sync DNS endpoint counts from Zorus (only if configured + clients mapped) |
| `zorus:sync-devices` | Daily at 05:20 | Sync DNS endpoint data from Zorus to local assets (only if configured + clients mapped) |
| `contracts:evaluate-rules` | Daily at 05:15 | Evaluate contract assignment rules (reconciliation) |
| `ninja:sync-backup` | Daily at 05:30 | Sync backup storage usage and license counts from NinjaRMM (only if Ninja orgs mapped) |
| `comet:sync-backup` | Daily at 05:40 | Sync backup storage usage and license counts from Comet Backup (only if configured + orgs mapped) |
| `servosity:sync-licenses` | Daily at 05:45 | Sync backup license counts from Servosity (only if configured + clients mapped) |
| `appriver:sync-licenses` | Daily at 05:50 | Sync M365 subscription seat counts from AppRiver (only if configured + clients mapped) |
| `cipp:sync-contacts` | Daily at 05:55 | Sync M365 users as contacts + mailbox/MFA enrichment from CIPP (only if contact sync enabled + tenants mapped) |
| `cipp:sync-devices` | Daily at 05:59 | Sync Intune devices + Defender state to assets from CIPP (only if device sync enabled + tenants mapped) |
| `billing:generate` | Daily at 06:00 | Generate recurring invoices |
| `tickets:close-resolved` | Daily at 06:00 | Auto-close resolved tickets after configured number of days |
| `qbo:sync-invoices --pull-status` | Every 4 hours | Pull payment status from QuickBooks |
| `qbo:sync-invoices --push-drafts` | Every 4 hours | Auto-push draft invoices to QuickBooks (only if enabled in Settings) |
| `stripe:sync-invoices` | Every 4 hours | Push/pull invoices to/from Stripe (if configured). Add `--import` for one-time historical import. `--full` resets incremental marker. `--since=YYYY-MM-DD` overrides start date. |
| `email:subscription-renew` | Every 2 hours | Renew Microsoft Graph webhook subscription |
| `email:poll` | Hourly | Fallback email poll (catches anything webhooks missed) |
| `triage:review-open` | Hourly | AI review of open tickets — assesses conversation state, writes recommendation notes. Only runs if triage auto-review is enabled in Settings. |
| `attachments:clean-orphans` | Daily at 04:00 | Deletes unlinked attachment files older than 24h (orphans from abandoned editor sessions) |
| `prepay:expire` | Daily at 04:10 | Forfeit the unconsumed remainder of expired prepaid-time credits (no-op until a contract sets an expiration policy). Use `--dry-run` to preview, `--contract=ID` to scope. |
| `prepay:check-balances` | Hourly | Check prepay contracts for low balances; trigger alerts / auto-top-ups |

**Ad-hoc maintenance commands** (not scheduled — run manually when needed):

| Command | Purpose |
|---------|---------|
| `prepay:reconcile` | Recalculate prepay balances from the transaction ledger. Use `--contract=ID` for a specific contract. |
| `version:refresh` | Refresh cached version info (commit, branch, update count). Runs automatically during deploy. |
| `tickets:recalculate-sla` | Recompute ticket SLA deadlines (`response_due_at`, `due_at`) from contract SLA terms. Open tickets only unless `--all`. See "SLA deadline recalculation" below. |

> **Note:** Commands only execute if their respective integration is configured. It's safe to have the cron entry active even before you set up any integrations.

---

## 7. Set up Microsoft Entra ID SSO

### Create an app registration

1. Go to [Azure Portal](https://portal.azure.com/) > **Microsoft Entra ID** > **App registrations** > **New registration**
2. **Name:** `Your MSP PSA` (or any descriptive name)
3. **Supported account types:** Accounts in this organizational directory only (single tenant)
4. **Redirect URI:** Web — `https://psa.yourmsp.com/auth/microsoft/callback`
5. Click **Register**

### Copy credentials to `.env`

- **Application (client) ID** on the Overview page → `MICROSOFT_CLIENT_ID`
- **Directory (tenant) ID** on the Overview page → `MICROSOFT_TENANT_ID`
- Go to **Certificates & secrets** > **New client secret** > copy the Value → `MICROSOFT_CLIENT_SECRET`

> **Important:** Note the client secret expiration date. Set a calendar reminder to rotate it before it expires.

### Configure API permissions

**Delegated permissions** (for SSO login):

1. Go to **API permissions** > **Add a permission**
2. Select **Microsoft Graph** > **Delegated permissions**
3. Add: `openid`, `profile`, `email`
4. Click **Grant admin consent for [Your Organization]**

**Application permissions** (for email integration — optional, only if using email features):

1. Go to **API permissions** > **Add a permission**
2. Select **Microsoft Graph** > **Application permissions**
3. Add: `Mail.Read` and `Mail.Send`
4. Click **Grant admin consent for [Your Organization]**

> **Important:** `Mail.Read` and `Mail.Send` must be **Application** permissions (not Delegated). This allows the PSA to read and send from the shared mailbox using client credentials without a user session.

**Application permissions** (for profile photo sync — optional):

1. Add **Application** permission: `User.ReadBasic.All`
2. Click **Grant admin consent for [Your Organization]**

> This allows the PSA to fetch profile photos from Entra ID for user avatars. This is the least-privileged permission that covers photos (basic profile properties only). Without it, users can still upload their own photos manually.

### Rebuild config cache

After updating `.env`:

```bash
cd /var/www/psa
php artisan config:cache
```

---

## 8. First login & post-install

1. Visit `https://psa.yourmsp.com/login`
2. Click **Sign in with Microsoft**
3. Authenticate with your Entra ID account
4. Your user is auto-created on first SSO login

### Verify the installation

```bash
curl -s https://psa.yourmsp.com/api/health | python3 -m json.tool
```

Expected output:

```json
{
    "status": "ok",
    "app": "Your MSP PSA",
    "php": "8.3.x",
    "laravel": "12.x.x",
    "database": true,
}
```

### Security check

Try logging in with a Microsoft account from a **different tenant** (e.g., a personal outlook.com account). It should be rejected. If it's not, verify that `MICROSOFT_TENANT_ID` is set correctly in `.env` and run `php artisan config:cache`.

### Next steps

Go to **Settings > Integrations** to configure your optional integrations (see Section 9).

---

## 9. Optional integrations

### Queue worker (required for background features)

Several features run as queued jobs: AI triage, call transcription, T2T webhook callbacks, and **Tactical RMM alert webhooks** (inbound Tactical alerts are persisted and processed by a queued `ProcessTacticalWebhook` job — without a worker, alerts are received and stored but never turn into PSA alerts/tickets). To process these jobs in the background:

1. Set `QUEUE_CONNECTION=database` in `.env`
2. Run `php artisan queue:work --daemon` (or set up a systemd service / supervisor)

Without this, jobs either run synchronously (blocking webhook responses) or not at all depending on the feature.

**Production systemd service** (recommended):
```ini
# /etc/systemd/system/soundit-queue.service
[Unit]
Description=Sound PSA Queue Worker
After=network.target mariadb.service

[Service]
User=www-data
WorkingDirectory=/var/www/psa
ExecStart=/usr/bin/php artisan queue:work database --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Then: `sudo systemctl enable --now soundit-queue`

### General settings

Before configuring integrations, visit **Settings > General Settings** to set your **display timezone**. All timestamps in the app display in this timezone (the database always stores UTC). For example, set `America/New_York` for US Eastern time.

All other integrations are configured through the **Settings > Integrations** page in the web UI. None require `.env` changes.

### NinjaRMM

1. Settings > Integrations > NinjaRMM
2. Enter your Ninja **client ID** and **client secret**
3. After connecting, go to Settings > Ninja Org Mapping to map Ninja organizations to PSA clients
4. Once orgs are mapped, backup storage usage syncs daily at 05:30. Trigger manually via **Sync Backup Usage** button or `php artisan ninja:sync-backup`

### Comet Backup

Syncs backup storage usage, device protection status, and license counts from Comet Backup. Creates tickets for failed backup jobs via webhook.

1. Settings > Integrations > Comet Backup (RMM & Monitoring tab)
2. Enter your Comet server **URL**, **Admin Username**, and **Admin Password**
3. Click **Test Connection** to verify
4. Map PSA clients to Comet organizations in the **Organization Mapping** section
5. Click **Sync Backup Usage** or wait for the daily 05:40 cron (`php artisan comet:sync-backup`)
6. **Webhooks (optional):** Click **Generate Webhook Key**, then configure a webhook event streamer in your Comet server for `job.completed` events pointing to `https://psa.yourmsp.com/api/webhooks/comet` with `Authorization: Bearer <key>` header

**Composer dependency:** `cometbackup/comet-php-sdk`

### Level RMM

1. Settings > Integrations > Level RMM
2. Enter your Level **API key**
3. After connecting, go to Settings > Level Group Mapping to map Level groups to PSA clients
4. **Webhooks (optional):** Click "Generate" to create a webhook secret, save, then follow the Webhook Setup instructions in the card to configure Level to push real-time device updates

### Tactical RMM

Self-hosted RMM (amidaware/tacticalrmm). Syncs device inventory and a script library into PSA, and ingests alert webhooks into the unified alerts inbox (which can become tickets). One PSA deployment points at one self-hosted Tactical instance; PSA clients map to Tactical client/site pairs.

1. Settings > Integrations > Tactical RMM (RMM & Monitoring tab)
2. Enter your Tactical **API URL** (e.g. `https://api-rmm.yourmsp.com`) and **API key**, then click **Test Connection**. The API key comes from Tactical under **Settings > Global Settings > API Keys**.
3. Map PSA clients to Tactical client/site pairs in **Site Mapping** (`settings.tactical-sites`). Devices only sync for clients that have a `tactical_site_id` set.
4. Click **Sync Devices** / **Sync Scripts**, or wait for the daily crons (`tactical:sync-devices` at 05:32, `tactical:sync-scripts` at 05:35).
5. **Webhooks (alert → ticket):**
   - In the Tactical card, click **Generate Webhook Key** and copy the generated key (shown once).
   - In Tactical, create an **Alert Template** and add a `URLAction` (REST). Point both the failure action and the resolved action at `https://psa.yourmsp.com/api/webhooks/tactical` with header `X-Webhook-Key: <key>` (a `Authorization: Bearer <key>` header also works). Tactical's webhook body templates **must be single-line JSON** and should include at least an `event` field (`alert_failure` / `alert_resolved`) plus `agent_id` and `alert_id`.
   - Tactical's outbound action has an **8-second timeout and no retry** and can double-deliver, so PSA acks immediately and processes asynchronously, deduping replays by `alert_id`. **A queue worker is required** (see "Queue worker" above) — without one, alerts are stored but never become PSA alerts/tickets. The hourly `tactical:reconcile-alerts` poll is the at-least-once backstop for dropped resolve webhooks.
   - Webhook health (last alert received, 24h processed count, failed count) is shown on the Tactical settings card.

**Least-privilege service user (required):** the API key inherits the role of the Tactical user it belongs to and **bypasses 2FA**, so create a dedicated service user with the narrowest role PSA needs and treat the key as a high-value secret. Concrete ALLOW/DENY for the service role:

- **ALLOW** — List/Read Agents; Run Scripts + **Send Command** + **Reboot/Shutdown** + **Recover agent services** + **Edit Agent** (the remote actions PSA ships: `POST /agents/<id>/cmd/`, `/reboot/`, `/shutdown/`, `/recover/`, and `PUT /agents/<id>/` for the maintenance-mode toggle); Manage Checks (read); List/Manage Alerts (read + the resolve poll); List Clients/Sites + Create Client/Site (provisioning); Run installer/deployment lookups. These map exactly to the endpoints PSA calls.
- **DENY** — User Management (accounts/roles/API keys); Global Settings; Code Signing; any permission PSA does not exercise. A least-privilege key means a leaked key cannot escalate or reconfigure Tactical. **With ad-hoc command now shipped, the `Send Command` permission this role grants is what makes a leaked key an RCE foothold (see the callout below) — keep the role as narrow as PSA's endpoint list, and treat the key accordingly.**

Optionally set a key expiry and rotate periodically (no rotation runbook is automated yet).

**Action audit trail (immutable):** every endpoint-affecting action (run-script, reboot, shutdown, ad-hoc command, agent-services recovery, and the maintenance-mode toggle) flows through one audited pipeline (`TacticalActionService`) and writes an append-only row to `tactical_action_logs` (actor, action key, agent/asset/ticket, redacted params, normalized result, retcode, redacted+truncated output, correlation id). The table is immutable: a model guard blocks `update()/delete()` everywhere, and on MariaDB `BEFORE UPDATE`/`BEFORE DELETE` triggers `SIGNAL` to block raw query-builder writes too. Scope of the guarantee: it blocks **app-tier** UPDATE/DELETE (including the raw query builder); it does **not** stop `TRUNCATE`/`DROP` or a DBA. This is also the ITIL change history for endpoint actions.

> **Output-redaction caveat (important for the curated script library AND ad-hoc commands):** redaction is **best-effort, not a guarantee.** It scrubs shaped secrets — `key=value`, PEM blocks, connection strings, the argv `-Flag <secret>` form (`-Password`, `-ApiKey`, `-ServosityCredPass`, …), a few well-known command shapes (`mysql -p<secret>` / `--password=<secret>` / `net user <name> <secret>`), and bare high-entropy tokens (AWS `AKIA…` keys, `Bearer <token>`, a lone 32+ char run). This applies to **all three places a secret could surface for an ad-hoc command: the audit `params` (the typed command), the audit `output` (its stdout/stderr), and the redacted ticket note** — none persists a recognized secret verbatim. But **short, low-entropy positional secrets** (e.g. a bare `Hunter2` typed as a positional argument with no recognizable flag/shape) can still slip through. **Do not type inline credentials into an ad-hoc command, and curated library scripts must not print bare credentials** — pass secrets as flagged args and avoid echoing them.

> **"Audit all" honesty:** every request that *reaches* the bus is audited — including capability-denied, param-rejected, confirm-blocked, and offline outcomes. Requests rejected by Laravel's `auth` middleware (truly unauthenticated) never reach the bus; those denials live in the application log, not `tactical_action_logs`.

**Destructive-action confirm flow:** the destructive actions (**reboot, shutdown, and ad-hoc command** — all shipped) require an explicit confirmation. In the UI you type the device's exact hostname (case-insensitive, trimmed) to arm the action; the server mints a short-lived (~10 min) confirm token bound to `{action, agent, actor}` and the bus refuses to execute a destructive action without a valid token. For **ad-hoc command**, the token is additionally bound to a `payloadHash` (sha256 of the canonical `{shell, cmd, timeout}`), so a confirmation for `whoami` cannot be replayed to run `format C:` — change the command and the bus blocks the stale token. The confirm modal renders the **full resolved command** in a multi-line block (nothing scrolled out of view); shutdown's modal states the device **cannot be powered back on remotely** (recovery needs physical/IPMI access). The action POSTs are CSRF-protected (web middleware group). If the token expires, the UI prompts to re-confirm. An **offline agent is a normal, surfaced result** (a clear "offline" message), never a 500 — the Tactical card always renders for a linked device and shows an "offline as of <last sync>" affordance rather than disappearing; the live action result, not the daily snapshot, is the source of truth.

> **⚠️ Ad-hoc command = arbitrary remote code execution.** "Send Command" runs an operator-supplied command directly on the endpoint, in the agent's context (**SYSTEM on Windows, root on Linux/Mac**). This is the single most dangerous capability in the integration. Because the model is **single-tier**, **any authenticated staff user** can run it on any linked device — there is no per-user reboot/cmd tier in this release. And because the Tactical API key **bypasses 2FA**, a leaked key is no longer just a data-exposure or reconfigure risk: **it is an RCE foothold across the entire linked fleet.** The compensating controls are the confirm token + typed-hostname gate + the immutable redacted audit trail + the **least-privilege service role** above — which is exactly why that role must stay as narrow as PSA's endpoint list and the key must be treated as a top-tier secret (short expiry, rotation, no sharing). On the **ticket page** the surface is ad-hoc command **only** (the "diagnostic while working the incident" flow); shutdown/recover/maintenance remain asset-page-only.

**SSRF-validated API URL:** because the API key bypasses 2FA, the **API URL** is validated on save (`App\Rules\SafeTacticalUrl`): it must be `https://`, and it is rejected if it is — or resolves to — a private/reserved/link-local/metadata address (127.0.0.0/8, 169.254.0.0/16, 10/172.16/192.168, `::1`, `fe80::/10`, `0.0.0.0`), including IPv6 and decimal-encoded literals; an unresolvable host fails closed. The outbound Tactical client also disables redirect-following so a redirect cannot bounce the key to a metadata endpoint. (Residual, deferred: request-time peer-IP re-pinning for the DNS-rebinding window — see deferred items.)

> **Maintenance mode — "forgotten-on" risk:** the maintenance toggle suppresses Tactical alerting for the device. A device left in maintenance is a **silently muted endpoint** — real failures raise no alert and create no ticket until it is toggled back off. The current maintenance state is rendered prominently on the Tactical card (a warning badge when ON), but PSA's badge reads the **daily-synced snapshot**, so a toggle made directly in Tactical (or a stale snapshot) can disagree with reality. Reconciling the displayed maintenance state against Tactical ground-truth on page load is a tracked follow-up → bead **psa-rrxj**. Until then, treat the badge as advisory and prefer toggling maintenance from PSA so the audit trail records who muted the device and when.

**Deferred (tracked, not in this release):**
- Bulk / multi-agent actions + a queued `RunTacticalActionJob` with count-confirmation and the async result path (`recover mode=tacagent` surfacing); business-hours / maintenance-window awareness → bead **psa-d76b**. (The single-agent remote actions — reboot, shutdown, cmd, recover `mode=mesh`, maintenance toggle — shipped here; the bus's `execute()` is synchronous, so anything needing the queued/async path is tracked there.)
- Request-time peer-IP re-validation against the deny-list (DNS-rebinding TOCTOU hardening) → bead **psa-rkf6**.
- Migrating the Servosity async deploy (`enableServosityBackup`, fire-and-forget `runScriptAsync`) onto the bus → bead **psa-nfqd** (needs the async bus path, which is deferred to **psa-d76b**).
- A configurable Tactical **web** URL + fixing the asset-page "Open in Tactical RMM" link (currently points at the API root) → bead **psa-6h5r** (P4).
- Installer-download host check + encrypting `tactical_api_url` at rest → deferred (the save-time SSRF guard + no-redirects are the current controls).
- Optional destructive-action **reason** capture on the audit row (the "why", beyond the optional ticket link) → bead **psa-jke6**. P3 stays schema-free (no migration); for now the optional ticket link on a cmd/shutdown confirm is the per-incident traceability.
- A scheduled maintenance auto-expiry ("maintenance for 2h", then PSA un-toggles) → follow-up, not in this release.

**API schema pinning / drift guard:** Tactical exposes no versioned API contract, so PSA pins the fields it reads and ships a contract test (`tests/Feature/Tactical/TacticalSchemaDriftTest.php`) against a trimmed snapshot at `tests/Fixtures/tactical/api_schema.json`. That snapshot is a periodic **manual** refresh, not a live check: enable `SWAGGER_ENABLED` in Tactical, then `curl -s https://<tactical-host>/api/schema/ > tests/Fixtures/tactical/api_schema.json` and re-trim to the agent + alert schemas, bumping the pinned version in its `_meta`. The pinned version is recorded in that file (currently Tactical 1.5.0).

<!--
  P3 (remote actions: reboot/shutdown/cmd/recover/maintenance) — explicit setup
  NEGATIVES, so a future editor doesn't add config that isn't needed:
  - NO .env.example change: all Tactical config (API URL/key, site mapping,
    webhook key) is DB-backed via Setting / TacticalConfig, not env.
  - NO cron-table change: every P3 action is SYNCHRONOUS (the bus execute() is a
    single-target sync NATS round-trip). The bulk/async path that WOULD need a
    queued job is deferred to psa-d76b; the existing queue worker requirement is
    for webhooks/triage, unchanged here.
  - NO new PHP extensions.
  - NO README route-table change: README lists routes at the resource level and
    (by house style) omits every POST sub-action — including the already-shipped
    reboot/run-script — so the new cmd/shutdown/recover/maintenance action routes
    are intentionally NOT added there; doing so would break that consistency.
-->

### QuickBooks Online

1. Create an app in the [Intuit Developer Portal](https://developer.intuit.com/)
2. Settings > Integrations > QuickBooks Online — enter your QBO **client ID** and **client secret**
3. Click **Connect to QuickBooks** to authorize via OAuth
4. Go to Settings > QBO Client Matching to map QBO customers to PSA clients
5. **Webhooks (real-time sync, optional):** In the Intuit Developer Portal, go to your app → Webhooks
6. Set the endpoint URL to `https://psa.yourmsp.com/api/webhooks/qbo`
7. Copy the **Verifier Token** into Settings > Integrations > QBO > Webhook Verifier Token, then Save Credentials
8. Subscribe to **Invoice** events — requires a queue worker to be running (see Section 5)

### Microsoft Graph (Email)

1. Ensure your Entra app registration has the `Mail.Read` and `Mail.Send` **Application** permissions with admin consent (see Section 7)
2. Settings > Integrations > Microsoft Graph (Email)
3. Enter the **shared mailbox address** you want to monitor (e.g., `helpdesk@yourmsp.com`)
4. Click **Test Connection** to verify the PSA can read the mailbox
5. Emails are delivered in near-real-time via Graph webhooks, with an hourly poll as fallback
6. **(Optional)** Enable **Auto-create tickets from inbound emails** in the signature form to automatically generate tickets from known client emails that don't match an existing conversation thread. Disabled by default; auto-created tickets are P3/Incident with no assignee.

> **Note:** The Graph integration reuses the same Entra app registration and `MICROSOFT_*` credentials as SSO. No additional `.env` variables are needed.

### AI Provider

1. Settings > Integrations > AI Provider
2. Select **Claude (Anthropic)** or **OpenAI**
3. Enter your **API key** (from [console.anthropic.com](https://console.anthropic.com/) or [platform.openai.com](https://platform.openai.com/))
4. Optionally override the default model (defaults: `claude-sonnet-4-6` for Anthropic, `gpt-4o` for OpenAI)
5. Click **Test Connection** to verify

### Plivo Softphone

1. Settings > Integrations > Plivo
2. Enter your Plivo **auth ID**, **auth token**, **DID number**, **app ID**, and **webhook secret**

**IVR caller-routing endpoint (optional, used with a Plivo PHLO flow):**
PSA exposes a synchronous lookup that a Plivo PHLO HTTP-request node can call to identify the inbound caller before dialing:

- **URL:** `POST https://YOUR-PSA-DOMAIN/api/plivo/{webhook_secret}/resolve-caller`
- **Body (form-encoded — PHLO's default):** `From`, `To`, `CallUUID`
- **Response (JSON):**
  ```json
  {
    "known": true|false,      // matched anything (client/blocked/allowed)
    "client": true|false,     // matched a Person in the people table
    "blocked": true|false,
    "allowed": true|false,
    "person_id": …, "person_name": …, "person_first_name": …,
    "client_id": …, "client_name": …,
    "caller_label": "Justin – Microsoft Support" | null
  }
  ```

Suggested PHLO routing on the response: `blocked` → hang up; `allowed` → ring through (use `caller_label` in the greeting); `client` → ring through with client context; otherwise (`known` is false) → screening menu or voicemail.

**Important:** PHLO sends Plivo variables in the **request body** (form-encoded), not in headers. The endpoint reads `$request->input('From')` so either form or query string works, but the body is what PHLO uses by default.

**Phone Directory:** Manage blocked and allow-listed numbers at **Sidebar → Phone Directory** (tabs for each list, search, manual add, bulk remove). One-click block or allow from any inbound call detail page. `resolve-caller` checks the directory before the people lookup; allow-listed entries return their `caller_label` so the PHLO can announce non-client vendors and outside techs by name.

### Call Transcription

Transcribes call recordings using OpenAI Whisper (speech-to-text) and analyzes them with your configured AI provider.

1. Settings > Integrations > Call Transcription
2. Enter an **OpenAI API key** for Whisper (if your AI provider above is already OpenAI, this is optional — it reuses that key)
3. Optionally enable **Auto-transcribe** to automatically transcribe calls with recordings
4. Set a **minimum duration** (default 30 seconds) to skip short calls
5. Click **Test Whisper** to verify the key works

**Manual transcription:** On any call detail page with a recording, click the **Transcribe** button.

**Speaker identification (optional):** Install `ffmpeg` for automatic speaker labeling. Plivo recordings are stereo (one speaker per channel); ffmpeg splits them for independent transcription. Without ffmpeg, transcription works but speaker identification relies on AI heuristics (less accurate).

```bash
sudo apt install -y ffmpeg
```

**Important:** For auto-transcription to run in the background without blocking webhook responses, set `QUEUE_CONNECTION=database` in your `.env` and ensure a queue worker is running (`php artisan queue:work`). With `QUEUE_CONNECTION=sync`, transcription still works but runs synchronously after each webhook response.

**Troubleshooting:**
- "Transcription is not configured" — Add an OpenAI API key in Settings > Integrations > Call Transcription, or set your AI provider to OpenAI with a valid key
- Transcription stuck on "Processing" — Check `php artisan queue:work` is running (if `QUEUE_CONNECTION=database`), or verify the job completed in `failed_jobs` table
- "Recording too large for Whisper" — OpenAI Whisper has a 25 MB file size limit. Very long recordings may exceed this

### AI Ticket Triage

Automatically classifies, enriches, and prepares new tickets for technician review. Deterministic stages (junk filter, contact resolution, asset matching) work without an AI API key. AI stages (classification, technical analysis, conversation review) require the AI provider configured above.

1. Settings > Integrations > AI Ticket Triage
2. Enable **AI Triage** (master toggle)
3. Optionally enable **Auto-triage new tickets** to run the pipeline on every ticket creation
4. Optionally enable **Auto-review open tickets** for hourly conversation analysis (recommend-only, no auto-close)
5. Set a **Default Assignee** (fallback when the client has no primary tech)
6. Set a **System User** (the user identity AI uses for notes and status changes — defaults to the first user)

**Manual triage:** On any ticket detail page, click the **Run Triage** or **Review** button in the AI Triage sidebar card.

**Important:** Triage runs as a queued job. Set `QUEUE_CONNECTION=database` in your `.env` and ensure a queue worker is running (`php artisan queue:work`). See the queue worker setup in the Call Transcription section.

**What the pipeline does:**
- **Junk Filter** — auto-closes spam, OOO replies, bouncebacks (deterministic pattern matching)
- **Contact Resolution** — links unlinked tickets to the correct client/contact by email, hostname, or RMM device
- **Classification** — AI determines managed/break-fix/no-contract, prepaid status, coverage (requires AI)
- **Asset Assignment** — links the relevant workstation to the ticket
- **Technical Analysis** — AI writes a detailed private tech note with resolution steps, similar past tickets, device health (requires AI)
- **Conversation Review** — hourly cron assesses open ticket status and writes recommendations (requires AI)

**Cost control:** Per-run token budget (default 200K) and daily token ceiling (default 2M) prevent runaway API spend. Token usage is tracked on each triage run.

**Search keyword backfill:** Triage stamps `search_keywords` on every ticket it processes so future searches find related tickets. To populate keywords on tickets that pre-date the triage run, use:

```bash
# Open tickets + tickets closed in the last 30 days; skips tickets that already have keywords
php artisan tickets:backfill-keywords

# Common flags
php artisan tickets:backfill-keywords --limit=50 --dry-run
php artisan tickets:backfill-keywords --force        # re-run on already-keyworded tickets
```

**SLA deadline recalculation:** Ticket SLA deadlines (`response_due_at`, `due_at`) are stamped at creation from the contract's `sla_terms`, anchored on the open time. After you change a contract's SLA terms — or import tickets that lack deadlines — re-derive them with:

```bash
# Open tickets only, anchored on each ticket's opened_at (falls back to created_at)
php artisan tickets:recalculate-sla

# Preview without saving
php artisan tickets:recalculate-sla --dry-run

# Scope to one client or one ticket
php artisan tickets:recalculate-sla --client=42
php artisan tickets:recalculate-sla --ticket=1234

# Include resolved/closed tickets (rewrites historical deadlines — use deliberately)
php artisan tickets:recalculate-sla --all

# Also null a deadline when the contract no longer defines hours for the ticket's priority
php artisan tickets:recalculate-sla --clear-missing
```

Tickets without a contract, or whose contract carries no `sla_terms`, are skipped. The command is idempotent — re-running it without changing terms reports `updated=0`.

### Mesh Email Security

Syncs license counts from Mesh for automated billing.

1. Settings > Integrations > Mesh Email Security
2. Enter your **API key** (from the Mesh admin portal under Settings > API)
3. Optionally change the **Base URL** (defaults to `https://hub-us.emailsecurity.app`)
4. Click **Test Connection** to verify
5. Go to Settings > Mesh Customer Mapping to map Mesh customers to PSA clients
6. Licenses sync daily at 04:30, or run `php artisan mesh:sync-licenses` manually

### CIPP / Microsoft 365

Syncs M365 license counts from CIPP (CyberDrain Improved Partner Portal) for automated billing.

1. Create an Azure AD app registration with **client credentials** (client_id + client_secret)
2. Settings > Integrations > CIPP / Microsoft 365
3. Enter your **CIPP API URL** (e.g., `https://your-cipp.azurewebsites.net`), **Azure AD Tenant ID**, **Client ID**, **Client Secret**, and optionally **Application ID** (defaults to Client ID)
4. Click **Test Connection** to verify OAuth2 and tenant list retrieval
5. Go to Settings > CIPP Tenant Mapping to map CIPP tenants to PSA clients (uses `defaultDomainName` as the tenant filter)
6. Licenses sync daily at 04:45, or run `php artisan cipp:sync-licenses` manually
7. **Contact sync** (optional): Enable "Sync M365 users to contacts" toggle in the CIPP card on the Integrations page. This syncs M365 users as client contacts (daily at 05:55). On the Tenant Mapping page, optionally select a security group per tenant to filter which users sync — if no group is selected, all tenant users are synced. Synced contacts are created with portal access disabled. Run `php artisan cipp:sync-contacts` manually, or use `--dry-run` to preview changes before writing. Use `--client=X` to sync a single client.

### Stripe (Invoicing)

Alternative to QuickBooks Online for invoice creation and payment tracking. Each MSP deployment uses one billing backend (QBO or Stripe), not both.

1. Get your **Secret Key** from [Stripe Dashboard → API keys](https://dashboard.stripe.com/apikeys) (starts with `sk_test_` or `sk_live_`)
2. Settings > Integrations > Stripe
3. Enter your **Secret Key** and select **Mode** (Test or Live)
4. Click **Test Connection** to verify
5. Go to Settings > Stripe Customer Matching to map Stripe customers to PSA clients (auto-match available by name or email)
6. Generate an invoice from a recurring profile, then use the **Push to Stripe** button on the invoice detail page
7. Optionally enable **Auto-push draft invoices** to push automatically every 4 hours
8. Payment status syncs every 4 hours — paid invoices in Stripe are marked Paid in the PSA
9. Stripe Tax: if enabled in your Stripe account, tax is automatically calculated when invoices are finalized. Tax amounts sync back to the PSA.
10. Product sync: `php artisan stripe:sync-products --import` imports Stripe Products as SKUs, `--push` pushes local SKUs to Stripe
11. **Import historical invoices**: Click **Import Invoices** on Settings > Integrations (Stripe card), or run `php artisan stripe:sync-invoices --import`. To re-import from scratch: `php artisan stripe:sync-invoices --import --full`

### Tier2Tickets / HelpDesk Buttons

Desktop shortcut/application that lets MSP clients submit support tickets directly from their workstation. Uses a ConnectWise Manage API compatibility layer built into Sound PSA.

**PSA-side setup:**
1. Settings > Integrations > Tier2Tickets / HelpDesk Buttons
2. Click **Generate New API Key** — copy the key immediately (it won't be shown again)
3. Optionally select a **System User** for audit trail attribution on T2T-created tickets
4. Note the **API URL** shown in the card (e.g., `https://psa.yourmsp.com/api/tier2tickets`)

**T2T-side setup:**
1. In Tier2Tickets admin panel, set **Integration Type** to **ConnectWise Manage**
2. Enter the API URL from the PSA settings card as the **Server URL**
3. Enter any value for **CompanyId** (e.g., your MSP name) and **PublicKey** (e.g., `t2t`)
4. Enter the generated API key as the **PrivateKey**
5. Submit a test ticket from a real HelpDeskButton device to verify

**Notes:**
- Tickets created via T2T appear with source "Helpdesk Button" and are automatically linked to the contact (by email) and asset (by hostname)
- Status updates in the PSA are pushed back to T2T via webhook callbacks (requires a queue worker — see Call Transcription section for queue setup)
- The API URL path includes `v4_6_release/apis/3.0/` — this is normal and matches the ConnectWise Manage API format that T2T expects

### Huntress (License Sync)

Sync EDR agent counts and ITDR user counts from Huntress for license billing.

1. Settings > Integrations > Huntress EDR / ITDR (Licensing tab)
2. Enter your **API Key** and **API Secret** from the Huntress portal (Account > API Credentials)
3. Click **Test Connection** to verify
4. Go to **Organization Mapping** to map Huntress organizations to local clients
5. Click **Sync Licenses Now** or wait for the daily 05:00 cron

### Huntress (Incident Tickets)

Receive security incident reports from Huntress as tickets via ConnectWise-compatible webhooks.

**PSA-side setup:**
1. Settings > Integrations > Huntress Incident Reports (Communications tab)
2. Click **Generate New Credentials** — copy all four values immediately
3. Optionally select a **System User** for audit trail attribution

**Huntress-side setup:**
1. In the Huntress portal, go to **Integrations > ConnectWise Manage**
2. Enter the **ConnectWise Host** from the PSA settings card
3. Enter the **Company ID** and **Public Key** from the PSA settings card
4. Enter the **Private Key** from the PSA settings card
5. Huntress will automatically send incident reports as tickets

**Notes:**
- Incident severity maps to priority: CRITICAL → P1, HIGH → P2, LOW → P3
- Tickets are created with source "Huntress" and type "Incident"
- Assets are linked by hostname match when available
- Post-remediation updates set tickets to "Resolved" (not "Closed") for human verification

### Servosity (Backup Licensing)

Syncs backup account counts (M365 mailboxes, DR servers, etc.) from Servosity for license billing.

1. Settings > Integrations > Servosity Backup (Licensing tab)
2. Enter your **API Token** (from the Servosity portal)
3. Click **Test Connection** to verify
4. Go to **Company Mapping** to map Servosity companies to local clients
5. Click **Sync Licenses Now** or wait for the daily 05:45 cron

### Control D (DNS Security)

Syncs endpoint and router device counts from Control D sub-organizations for license billing.

1. Settings > Integrations > Control D DNS Security (Licensing tab)
2. Enter your **API Key** (Bearer token from the Control D admin portal)
3. Click **Test Connection** to verify
4. Go to **Organization Mapping** to map Control D sub-organizations to local clients (Auto-Match available for exact name matches)
5. Click **Sync Licenses Now** or wait for the daily 05:10 cron
6. Optionally click **Sync Devices** to enrich local assets with DNS agent data

### Zorus (DNS Security)

Syncs DNS endpoint counts and agent data from Zorus for license billing and asset enrichment.

1. Settings > Integrations > Zorus DNS Security
2. Enter your **API Key** (Impersonation key from the Zorus developer portal)
3. Click **Test Connection** to verify
4. Go to **Customer Mapping** to map Zorus customers to local clients (Auto-Match available for exact name matches)
5. Click **Sync Licenses Now** or wait for the daily 05:18 cron
6. Click **Sync Devices** to enrich local assets with Zorus agent data

### ScreenConnect (ConnectWise Control)

Webhook-based integration for real-time device status, activity audit, and remote access deep links. No API key required — ScreenConnect pushes data via webhook automations.

1. Settings > Integrations > ScreenConnect (RMM tab)
2. Enter your **Instance URL** (e.g., `https://yourcompany.screenconnect.com`) and save to generate a webhook secret
3. Copy the displayed **Webhook URL**
4. In ScreenConnect Admin > Automations, create a Session Event automation
5. Configure it to POST JSON to the webhook URL (JSON template provided in the settings UI)
6. Enable the integration toggle

**Scheduled commands:**
- `screenconnect:count-licenses` — daily at 05:30, counts Access agents per client

### AppRiver / OpenText (M365 Licensing)

Syncs M365 subscription seat counts from AppRiver (reseller-side view). Shows assigned vs total for utilization tracking. Supports inline seat count changes for onboarding/offboarding.

1. Settings > Integrations > AppRiver (OpenText) M365 Licensing (Licensing tab)
2. Enter your **Client ID** and **Client Secret** (from the OpenText Cloud Management Portal at cp.appriver.com > Integrations > API)
3. Click **Test Connection** to verify OAuth2 credentials
4. Go to **Customer Mapping** to map AppRiver customers to local clients (Auto-Match available for exact name matches)
5. Click **Sync Licenses Now** or wait for the daily 05:50 cron
6. View license utilization on the Licenses page — filter "Waste only" to find unused seats
7. Click the edit icon next to an AppRiver license quantity to adjust seat counts (pushes to AppRiver API)

**Note:** If you also use CIPP, both integrations coexist — CIPP shows the tenant-side M365 view while AppRiver shows the reseller-side view. They use separate vendor/license type records.

### Client Portal

A self-service portal where your clients can view tickets, invoices, devices, and service agreements. Clients log in with email+password at `/portal` — completely separate from staff SSO.

**Prerequisites:**
- **Microsoft Graph email** must be configured (Section 9 > Microsoft Graph) — required for sending portal invites and password resets
- **Queue worker** must be running — portal notification emails are dispatched as queued jobs

**Setup:**

1. Settings > Integrations > Client Portal
2. Enable **Client Portal**
3. Set your **Company Name** (shown in portal header, emails, and login page)
4. Optionally set a **Logo URL** for portal branding
5. Optionally set **Billing Portal URL** and **Label** (for MSPs using external billing like BenjiPays)
6. Optionally set **Prepaid Order URL** (supports `{client_id}` placeholder, shown as "Purchase Prepaid Time" link)

**Creating portal users:**

1. Navigate to a client's detail page and click **Portal**
2. Click **Invite** next to a contact with an email address
3. The contact receives a welcome email with a link to set their password
4. After setting a password, the contact can log in at `/portal`

**Access levels:**

- **Own tickets** (default): Contact sees only tickets where they are the contact
- **Company-wide**: Contact sees all tickets for their client. Toggle via the access level button on the Portal management page

**Portal features for clients:**
- **Dashboard**: Open ticket count, outstanding balance, prepaid balance, recent tickets and invoices
- **Tickets**: View, create, reply. Urgency toggle (Normal/Urgent). Confirm Resolved or Reopen resolved tickets
- **Invoices**: View posted/paid invoices with line items (no cost data). "Pay Online" button for Stripe invoices
- **Devices**: View active devices (hostname, type, OS, online/offline status)
- **Service Agreements**: View active contracts with prepaid balance and assigned devices/people
- **Account**: Update name and phone. Change password

**Portal notifications:**
When enabled, portal-enabled contacts automatically receive email notifications when:
- A technician posts a public reply on their ticket
- Their ticket is marked Resolved (with a link to confirm or reopen)
- Their ticket is set to Pending Client (with a link to reply)

### MCP server — staff tool surface

A read-mostly MCP (Model Context Protocol) server is exposed at `POST /api/mcp/staff`, intended for use as a remote MCP endpoint by the [Claude Teams Teammate](https://github.com/Wldc4rd/claude-teams-teammate) via Anthropic's MCP connector beta. Same tool surface as the inline ticket-page AI assistant (read-only, general-purpose set in V1).

**Enable:**
1. Generate a bearer token: `php artisan mcp:rotate-staff-token` (token only displayed once)
2. In the Teams bot's `MCP_SERVERS_FILE`, add an entry with `url: "https://your-psa-domain/api/mcp/staff"` and the generated token as `authorization_token`
3. The bot identity is treated as a single service account — the existing Entra Object ID allowlist on the bot side gates who can use it

**Audit log:** every MCP call (initialize / tools/list / tools/call) is logged to the `mcp_audit_logs` table with method, tool, arguments, status, duration, and source IP. Useful for forensics and cost tracking.

**Protocol notes:** JSON-RPC 2.0 over HTTP. Implements `initialize`, `tools/list`, `tools/call` only — the MCP connector beta only supports tool calls. Tool input schemas are translated at the boundary (`input_schema` → `inputSchema`) to match the MCP spec.

---

## 10. Updating

When a new version is available:

```bash
cd /var/www/psa

# Put the site in maintenance mode
php artisan down

# Pull latest code and rebuild
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan version:refresh
php artisan queue:restart

# Bring the site back up
php artisan up
```

Verify after update:

```bash
curl -s https://psa.yourmsp.com/api/health | python3 -m json.tool
```

---

## 11. Troubleshooting

### 502 Bad Gateway

PHP-FPM is not running or the socket path doesn't match.

```bash
# Check if PHP-FPM is running
sudo systemctl status php8.3-fpm

# Restart it
sudo systemctl restart php8.3-fpm

# Verify the socket path matches your Nginx config
ls -la /var/run/php/php8.3-fpm.sock
```

### File upload fails (413 or blank error)

Check Nginx `client_max_body_size` and PHP upload limits:

```bash
# Nginx: ensure client_max_body_size is set in the server block
# (should already be in the config template above)
sudo nginx -t && sudo systemctl reload nginx

# PHP: check upload limits
php -i | grep -E 'upload_max_filesize|post_max_size'
# If too low, edit /etc/php/8.3/fpm/php.ini:
#   upload_max_filesize = 20M
#   post_max_size = 25M
sudo systemctl restart php8.3-fpm
```

### SSO redirect URI mismatch

The `MICROSOFT_REDIRECT_URI` in `.env` must **exactly** match the redirect URI registered in Azure — including protocol (`https://`), domain, and path (`/auth/microsoft/callback`).

After changing `.env`, always rebuild the config cache:

```bash
php artisan config:cache
```

### Storage permission errors

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Viewing Laravel logs

```bash
tail -f /var/www/psa/storage/logs/laravel.log
```

### Cron not running

```bash
# Verify crontab exists
sudo crontab -l -u www-data

# Test the scheduler manually
cd /var/www/psa && php artisan schedule:run
```

### Prepay balances not appearing

Prepay balances are calculated from the transaction ledger. If balances look wrong, run `php artisan prepay:reconcile` (or `--contract=ID` for a specific contract) to recalculate from all transactions. Auto-deposits happen when invoices are generated from recurring profiles with SKUs that have `prepaid_time_minutes` set. Check the SKU and profile line configuration if deposits aren't appearing.

### 403 Forbidden when sending email (reply or compose)

The Entra app registration is missing the `Mail.Send` Application permission. Go to Azure Portal > App registrations > your app > API permissions, add `Mail.Send` under Microsoft Graph Application permissions, and grant admin consent. See Section 7.

### Portal password reset emails not arriving

1. Verify Microsoft Graph email is configured: Settings > Integrations > Microsoft Graph — test connection
2. Check that the `graph_mailbox` setting is set (this is the "from" address for all portal emails)
3. Check `storage/logs/laravel.log` for `[PortalNotification]` errors
4. Verify the queue worker is running: `sudo systemctl status soundit-queue` (portal emails are queued jobs)
5. Check `failed_jobs` table: `php artisan queue:failed`

### APP_KEY not set

If you see "No application encryption key has been specified":

```bash
cd /var/www/psa
php artisan key:generate
php artisan config:cache
```
