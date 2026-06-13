# Sound PSA

Purpose-built PSA (Professional Services Automation) for managed service providers. Standalone-first: every module works natively with its own schema and logic. Halo PSA sync is available for teams migrating from Halo and will be deprecated once migration is complete.

**Live at:** https://your-psa-domain

## Stack

- **Backend:** Laravel 12 / PHP 8.3
- **Frontend:** Blade templates + Bootstrap 5.3 (CDN, no build step)
- **Database:** MariaDB 10.11
- **Server:** VPS (Ubuntu 24.04, Nginx)
- **Auth:** Microsoft Entra ID SSO (single-tenant per deployment)
- **Integrations:**
  - **RMM:** NinjaRMM, Level RMM, Tactical RMM, ScreenConnect
  - **Backup:** Comet, Servosity
  - **Security/DNS:** Huntress (EDR/ITDR), Control D, Zorus, Mesh Email Security
  - **Microsoft 365:** CIPP, Microsoft Graph (email send/receive, SSO via Entra ID)
  - **Billing:** QuickBooks Online, Stripe (alternative), AppRiver (M365 licensing)
  - **Telephony:** Plivo (softphone + IVR caller-resolution endpoint for PHLO routing)
  - **Helpdesk submission:** Tier2Tickets (HelpDesk Button), Huntress incident webhooks
  - **AI:** Anthropic / OpenAI (triage pipeline, reply drafting, voicemail transcription via Whisper, ticket subject auto-fill from call context)

## Architecture

Sound PSA is a **standalone MSP PSA** — not a wrapper or companion app. Each module is independently functional.

### Design principles

1. **Standalone-first** — every module works natively with its own schema, independent of Halo
2. **Contracts as the billing hub** — every billable service requires a contract. SKUs, licenses, assets, and people attach to contracts for dynamic quantity-based billing.
3. **Tenant-ready** — most config lives in the in-app Settings UI (`settings` table). No hardcoded business logic. Other MSPs can clone and deploy their own instance.
4. **Halo interoperability** — 1-way sync from Halo during transition. This sync layer will be retired once migration is complete.

### Key services

| Service | Purpose |
|---------|---------|
| `app/Services/BillingService.php` | Invoice generation, quantity resolution (contract-scoped), cost tracking |
| `app/Services/SkuService.php` | SKU/product catalog management |
| `app/Services/ContractAssignmentService.php` | Asset/person/license assignment to contracts, rule evaluation |
| `app/Services/ProfitabilityService.php` | Revenue/cost/margin aggregation (contract, client, business-wide) |
| `app/Services/Qbo/QboSyncService.php` | QuickBooks Online: SKU sync, invoice push, payment status pull |
| `app/Services/Mesh/MeshClient.php` | Mesh Email Security API client (license sync) |
| `app/Services/Cipp/CippClient.php` | CIPP/M365 API client (OAuth2, license sync) |
| `app/Services/Halo/HaloSyncService.php` | Halo PSA transition sync (clients, assets, contracts, tickets) |
| `app/Services/Wiki/` | Client Wiki — auto-maintained environment documentation (enable with the `wiki_enabled` setting; spec: docs/superpowers/specs/2026-06-12-client-wiki-design.md) |
| `app/Services/Wiki/Mining/` | Ticket-close mining pipeline — redaction, AI extraction, fact merge/dispute; gated by `wiki_auto_mine` (default OFF) |

### Core entities

| Entity | Table | Key relationships |
|--------|-------|-------------------|
| Client | `clients` | assets, people, contracts, invoices, licenses |
| Contract | `contracts` | profiles, invoices, assigned assets/people/licenses, rules, activities |
| SKU | `skus` | profile lines, invoice lines, QBO item sync |
| License Type | `license_types` | licenses, linked SKU for cost |
| License | `licenses` | per client, assigned to contracts, synced from Mesh/CIPP |
| Recurring Profile | `recurring_invoice_profiles` | line items with quantity types, generates invoices |
| Invoice | `invoices` | line items with cost tracking, QBO sync, margin |
| Asset | `assets` | synced from Ninja/Level/Halo, assigned to contracts |
| Ticket | `tickets` | linked to contracts, assets, contacts |

### Web routes

| Path | Description |
|------|-------------|
| `/` | Dashboard |
| `/clients`, `/clients/{id}` | Client list and detail (with license summary, coverage alerts) |
| `/tickets`, `/tickets/{id}` | Ticket list and detail |
| `/assets`, `/assets/{id}` | Asset list and detail (with "Covered by" contract badges) |
| `/contracts`, `/contracts/{id}` | Contract list and detail (assignments, profiles, prepay, profitability) |
| `/profiles/{id}` | Recurring profile editor (SKU picker, quantity types, cost override) |
| `/invoices`, `/invoices/{id}` | Invoice list and detail (cost/margin columns, QBO sync) |
| `/skus` | SKU/product catalog (CRUD, QBO import/push) |
| `/licenses` | License list (Mesh, CIPP, manual) |
| `/license-types` | License type management (vendor, SKU link) |
| `/profitability` | Profitability dashboards (business, client, contract drill-down) |
| `/settings/general` | Timezone, billing asset type mapping |
| `/settings/integrations` | All integration config (Halo, Ninja, Level, Mesh, CIPP, QBO, Graph, Plivo, AI) |

## Local Development

See **[docs/INSTALL.md](docs/INSTALL.md)** for the full installation guide.

```bash
cd ~/repos/soundit-psa
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
# optional — load the BlueTier IT Solutions demo dataset (refuses to run outside local + *_dev DB):
php artisan db:seed --class=DevDataSeeder --force
```

Start the dev server:
```bash
php -S 127.0.0.1:8080 -t public
```

## Deployment

```bash
ssh your-vps "cd /var/www/psa && git pull && composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache"
```

Or use the Claude Code slash command: `/deploy`
