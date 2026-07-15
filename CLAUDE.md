# Sound PSA - Claude Context

## What this is
Sound PSA — a modern, self-hosted PSA for managed service providers. Standalone-first: every module works natively with its own schema and logic. Laravel 12 + Blade + Bootstrap 5.3 CDN (no build step currently, but not opposed to adding one if needed).

## Working style
- **Thoroughness over speed** — We have 1M context tokens available. Be greedy with context. Prefer thoroughness and accuracy over economy and speed.

## Slash commands
- `/deploy` — Push to GitHub and deploy to VPS (git push, pull, composer install, migrate, cache)
- `/artisan <command>` — Run any `php artisan` command
- `/serve` — Start local dev server for testing
- `/review-plan` — Multi-perspective plan review using parallel agents and stakeholder personas
- `/verify-done` — Verify implementation completeness against the plan before declaring done

## Development environment
- **Dev VM**: a Linux VM/host with PHP 8.3 and Composer installed natively.
- **Repo location**: `~/repos/psa/` (or wherever you cloned)
- Run artisan commands directly: `php artisan <command>` from the repo root
- **VPS**: SSH via `ssh your-vps` (<deploy-user>@<your-vps>), deployed at `/var/www/psa/`

### Dev server
- PHP runs on `127.0.0.1:8080` (internal). nginx handles SSL on port 443 and proxies to it.
- Browser access (from workstation): `https://172.25.229.117` (accept self-signed cert warning once)
- Start PHP server: `php -S 127.0.0.1:8080 -t public`
- For automated testing in scripts: `php -S 127.0.0.1:8080 -t public & SRVPID=$!`, curl against `http://127.0.0.1:8080`, then `kill $SRVPID`
- Use `fuser -k 8080/tcp` to kill by port if needed
- nginx must be running: `sudo systemctl status nginx`

### Demo / dev data
`database/seeders/DevDataSeeder.php` builds a full demo dataset for the fictional MSP **BlueTier IT Solutions** — 10 clients (with a reseller pair), assets, tickets across all sources, AI triage runs, voicemail with transcript, merged ticket pair, alerts, contracts, recurring billing, prepay, contractor pool, install token, etc. Use it for screenshots, MSP demos, and feature walkthroughs. Refresh:
```bash
php artisan db:seed --class=DevDataSeeder --force
```
Hard-guarded: aborts unless `APP_ENV=local` AND `DB_DATABASE` contains `_dev`. Never wired into `DatabaseSeeder::run()`, so `db:seed` without `--class` won't fire it.

## Branding

The shipped default branding (navy + gold, Montserrat headings, Inter body) is a starting point — operators should replace it with their own. The default values live in:

- Logo: `public/images/SoundIT_head_overlay_high-res.png` (referenced from Blade views). Replace this file with your own logo at a similar resolution.
- Favicon: `public/favicon.ico`
- App name: `APP_NAME` in `.env`, surfaced everywhere via `config('app.name')`
- Colors / typography: live in the layout's `<head>` (Google Fonts import) and `public/css/app.css`

For deployments that want a different brand, swap the logo file, set `APP_NAME` in `.env`, and edit the CSS variables.

## Key architecture decisions
- **Sound PSA is a standalone MSP PSA** — every module works natively with its own schema and logic. Historical `halo_id` columns exist in the database from a completed Halo PSA migration. These are inert and will be removed in a future cleanup phase.
- **No frontend build step (for now)** — Bootstrap 5.3 + Bootstrap Icons via CDN. Not opposed to adding Vite/npm if a feature demands it.
- **Entity build order** — Clients → Users/Contacts → Assets → Contracts/Billing → Tickets. Least volatile first, most complex last.
- **Thin controllers** — business logic goes in services, not controllers
- **Dual auth system** — no starter kit (Breeze/Fortify). Staff: Entra ID SSO via `web` guard (User model). Client portal: email+password via `portal` guard (Person model extends Authenticatable). Separate session drivers, separate password reset brokers. See "Client Portal" section below.
- **File drivers** for cache and sessions (no Redis)
- **Tenant-ready, not multi-tenant** — Most tenant-specific config lives in the in-app Settings UI (`settings` table). Only secrets and infrastructure config in `.env`. No hardcoded tenant-specific logic. Other MSPs can clone and deploy their own instance. Do not add tenant scoping or multi-tenant complexity.
- **Display timezone** — DB always stores UTC. `AppTimezone::get()` (`app/Support/AppTimezone.php`) returns the configured timezone from the `app_timezone` setting (default `'UTC'`). Use `$timestamp->toAppTz()->format(...)` in all Blade views for datetime fields. A Carbon macro `toAppTz()` is registered in `AppServiceProvider::boot()` and resolves the timezone at call time (not boot time). `due_at` inputs accept local time; `TicketService` converts to UTC on save. `diffForHumans()` needs no conversion — both `now()` and stored timestamps are UTC so relative differences are always correct. Date-only fields (no time component) are also unaffected.

## Contracts & Billing architecture
- **Contracts are the billing hub** — every billable service requires a contract. Even $0 break-fix contracts set legal terms. Non-technical communications can happen outside contracts but service delivery is contract-centric.
- **SKU strategy** — PSA-native product catalog (`skus` table). Seeded from QBO import, managed in PSA, pushed back to QBO. `qbo_sync_hash` prevents bidirectional sync loops. SKUs have price, cost, category, taxable flag.
- **Contract assignments** — Assets, people, and licenses link to contracts via pivot tables (`contract_asset`, `contract_person`, `contract_license`). Manual assignment or preset-based auto-assignment rules (`ContractAssignmentRule` with `AssignmentRuleType` enum). Rules add matching entities and remove rule-based assignments that no longer match. Manual assignments (`assignment_source = 'manual'`) are never auto-removed. Event-driven via `AssetObserver`/`PersonObserver` + daily `contracts:evaluate-rules` cron reconciliation.
- **Billing cadence on profiles** — `billing_period`, `billing_day`, and `payment_terms_days` live on the recurring invoice profile, not the contract. Contracts retain these fields as defaults for new profiles. `BillingService::generateInvoice()` reads billing period and payment terms from the profile. This allows different profiles under one contract to have different billing frequencies (e.g., monthly managed services + quarterly license true-ups).
- **Contract-scoped billing** — `BillingService::resolveQuantity()` counts from contract pivots when assignments exist, falls back to client-wide. `QuantityType` enum: Fixed, PerWorkstation, PerServer, PerUser, PerLicense, PerLicenseType, Overage.
- **Overage quantity type** — Parameterized usage-based billing: `max(0, ceil((usage - base × included_per_base_unit) / overage_divisor))`. Profile line fields: `usage_license_type_id` (what to measure), `base_license_type_id` (what provides included count, optional — defaults to 1), `included_per_base_unit`, `overage_divisor`. Reuses `countLicensesByType()` for both usage and base lookups. SKU `included_per_unit` auto-fills the "Included per Base" field in the UI. Works for any usage-based billing pattern: backup overage, email security seats, EDR agent overage, etc.
- **Backup storage tier billing** — `QuantityType::PerBackupStorageGb` bills for cloud backup usage read from `assets.backup_cloud_bytes` (populated by NinjaOne/Comet/Servosity backup syncs). `BillingService::countBackupStorageGb()` sums `backup_cloud_bytes` across the contract's assigned assets (contract-scoped) or client-wide, then converts bytes → whole GB with the same binary rounding (`round(bytes / 1024³)`) the vendor sync uses for the `cloud_usage_gb` license type. **Volume-based tier pricing** via `backup_storage_tiers` (per-SKU rate card: `up_to_gb` inclusive upper bound, `null` = unbounded catch-all; `unit_price` per GB). `Sku::priceForStorageGb(int $gb)` selects the first tier whose bound covers the measured GB and bills the whole quantity at that tier's rate (returns `null` when no tiers → caller falls back to the flat line `unit_price`; usage above all bounded tiers with no catch-all bills the top bounded rate). Applied via `BillingService::resolveUnitPrice()` and recorded in the invoice line `quantity_source` as `[volume tier rate $X/GB]`. Tiers are managed on the SKU edit form (shown when the SKU's default quantity type is "Backup Storage (GB)").
- **Tiered (graduated) pricing** — Profile lines can price their resolved quantity across graduated bands ("first 10 units @ $X, next 20 @ $Y") instead of a flat rate. Stored in a nullable `pricing_tiers` JSON column on `recurring_invoice_profile_lines` (`[{up_to:int|null, unit_price}]`, ascending, final band unbounded). A line is tiered iff `pricing_tiers` is non-empty (`RecurringInvoiceProfileLine::isTiered()`). Math lives in `App\Support\TieredPricing` (`normalize`/`breakdown`/`total`, pure + unit-tested). Composes with any `QuantityType` — it's a pricing concern, not a quantity one. **Key invariant:** `BillingService::generateInvoice()`/`previewInvoice()` **expand** a graduated line into one invoice line per consumed band, each with exact `amount = round(quantity × unit_price, 2)`, so the Stripe (taxable `unit_amount × quantity`) and QBO (`Qty`/`UnitPrice`) push paths stay correct. Emitted line descriptions are annotated `(A–B <noun> @ $P)` where `<noun>` is `QuantityType::unitNoun()` — the label lands on a client-facing invoice, so it names the quantity domain where the type knows it (`GB`, `workstations`, `users`, `licenses`) and falls back to `units` where it does not (Fixed, Overage). Cost and prepaid minutes split by band quantity; each emitted line gets its own `sort_order` (bands must not tie — QBO pairs lines up by sort_order *position* on both push and readback). The stored line `unit_price` mirrors the first band. Configured in the profile create/show UIs via a per-line "Tiered pricing" toggle + tier editor (mirrors the overage panel).
- **Graduated vs volume tiers — two rate cards, one seam, line overrides SKU default** — The billing engine carries two *different, both legitimate* tier pricing models with deceptively similar shapes (each is an ascending `up_to` + `unit_price` list with an unbounded final entry). **Volume** (per-SKU `backup_storage_tiers`, backup-storage lines only): picks the one tier covering the measured quantity and bills the *whole* quantity at that single rate. **Graduated** (per-profile-line `pricing_tiers`, any quantity type): splits the quantity into bands and bills *each band* at its own rate. Same numbers, different money — 300 GB over 1.00/0.80/0.60 is **$240 volume** but **$260 graduated**. They are deliberately kept distinct: same shape with opposite semantics is exactly how you mis-bill a client, so do not "unify" them without also unifying the semantics.
  - **The SKU's pricing method is a DEFAULT, not a constraint.** A line that carries its own graduated bands overrides the SKU's volume rate card — allowed at every door (profile line create/edit, bulk `set_quantity_type`, and the SKU tiers form gaining a card under an already-graduated line), never refused, no confirm dialog. `BillingService::priceLineSegments()` — the single seam where a (line, quantity) becomes money — encodes **graduated (line) > volume (SKU) > flat**: a line-level setting beats one inherited from the product, the same precedence as `unit_cost_override ?? sku->unit_cost`. (Owner ruling 2026-07-13: SoundPSA targets small/single-member MSPs where the SKU-creator is usually the invoice-creator and per-client pricing varies; a mandatory SKU method is friction without benefit.)
  - **The override is never silent.** Real money must not hang on a code-precedence rule the operator never sees, so which card applies is stated everywhere the billing decision is made or reviewed: an inline note beside the graduated toggle in the profile line editors (driven by `data-has-volume-tiers` on the SKU options), a per-line rate-card badge + explicit override notice on the profile show page, a note on the SKU tiers editor naming the profile lines that override its card, the applied card in invoice preview (`quantity_source`), and an info log at generation. `App\Support\PricingModelOverride` is the single predicate all of them consult — an override needs all three of: graduated bands on the line, a volume rate card on its SKU, **and** `quantity_type = PerBackupStorageGb` (the only type that reads the card; a graduated per-user line on the same SKU overrides nothing, and no surface may claim it does).
  - **Audit record.** Where a *non-flat* rate card priced a line, the invoice line's `quantity_source` names it — `[graduated: N bands]` or `[volume tier rate $X/GB]` — so the record can never claim a rate that was not applied. It is appended to the quantity description, or stands alone when there is none: a **Fixed** line records no quantity source (the operator typed the number; nothing to audit about *how much*), but Fixed + graduated still records `[graduated: N bands]`. Only a plain **flat Fixed** line has a null `quantity_source`.
- **Software licenses** — `LicenseType` (vendor, vendor_sku_id, linked SKU for cost) + `License` (per client, quantity, status). Synced from Mesh (API-key auth) and CIPP/M365 (OAuth2 client credentials). Manual entry also supported.
- **Profitability tracking** — `unit_cost`/`cost_amount` on invoice lines, `total_cost`/`margin` on invoices. Cost resolved from profile line `unit_cost_override` → SKU `unit_cost` → 0. `ProfitabilityService` aggregates by contract, client, and business-wide.
- **Prepay balance management** — `PrepayService` (`app/Services/PrepayService.php`) handles prepay deposits, manual credits/debits, and balance reconciliation. `PrepayTransactionSource` enum: `HaloSync`, `InvoiceDeposit`, `ManualCredit`, `ManualDebit`. SKUs can carry `prepaid_time_minutes` (per-unit); profile lines can override via `prepaid_time_override`. `BillingService::generateInvoice()` computes total prepaid minutes per invoice line (qty × minutes). `InvoiceObserver` triggers `PrepayService::depositFromInvoice()` when invoice status transitions to Paid — prepaid time is only available after the invoice is paid, not at generation. Guards: deposits only on PSA-managed contracts, skips dollar-based prepay, idempotent via invoice_id+source. Manual credit/debit via contract detail UI with activity logging. `prepay:reconcile` command recalculates balances from the transaction ledger. Prepay source of truth follows `billing_source`: Halo-managed → Halo sync, PSA-managed → transaction ledger. Halo sync orphan deletion guarded with `whereNotNull('halo_id')` to protect PSA-native transactions.
- **Portal prepaid purchases** — `PrepayOrderService` (`app/Services/PrepayOrderService.php`) handles portal self-service prepaid time purchases. Each contract can link to a purchasable SKU via `portal_prepay_sku_id` (FK to `skus`). `Contract::is_portal_purchasable` checks: active, SKU set, hours-based (not dollar), not Halo-read-only. `PortalPrepayController` manages the purchase flow: contract selection → quantity form (JS live total, TOCTOU price guard, duplicate-order guard) → invoice creation → confirmation page. Invoice created as `Posted` with `due_date = today`. Billing backend push (Stripe/QBO) via `app()->terminating()`. Staff notified via `NotificationEventType::PortalPrepayPurchase`. When invoice is marked Paid, `InvoiceObserver` → `PrepayService::depositFromInvoice()` deposits hours automatically.
- **Contractor time tracking** — `is_contractor` boolean on `users` table marks staff as contractors. `ContractorTimeService` (`app/Services/ContractorTimeService.php`) manages the hours pool. Credits (purchases, initial balances, adjustments) are stored in `contractor_time_transactions` table. Consumed hours are **computed in real-time** from `ticket_notes` (`SUM(time_minutes/60) WHERE author_id = contractor`), not ledgered — balance auto-corrects when notes are edited or deleted. `ContractorTimeSource` enum: `manual_credit`, `manual_debit`, `initial_balance`. Pool detail page at `/contractors/{user}/time-pool`. Ticket detail sidebar shows contractor balance when assignee is a contractor. Staff list shows contractor badge and "Time Pool" action link.
- **Reseller billing** — `reseller_id` FK on `clients` table, self-referencing. A client with `reseller_id` is billed through its reseller. `QuantityType::PerResellerLicenseType` counts active licenses of a specific `license_type_id` across ALL active clients where `reseller_id` = the contract's client. Not contract-scoped. Used for billing a reseller for aggregated vendor license counts. Reseller License Report at `/reseller-report` shows per-license-type breakdown across a reseller's child clients.
- **Proration policy** — No proration. Quantity changes take effect at next billing cycle. `quantity_source` snapshot on each invoice line is the audit record.
- **Empty invoice suppression** — `billing_skip_zero_invoices` global setting (default off). Per-profile `skip_zero_invoices` nullable boolean overrides: `null` = use global, `true` = always skip, `false` = never skip. Skips when profile has no lines or all quantities resolve to 0. Profiles with $0-priced lines at qty > 0 still generate (record of coverage). When skipped, `next_run_date` still advances.
- **QBO webhook sync** — `POST /api/webhooks/qbo` receives real-time notifications from QuickBooks. HMAC-SHA-256 verification via `qbo_webhook_verifier_token` setting (encrypted). Store-then-dispatch pattern (mirrors Level): `qbo_webhooks` table + `QboWebhook` model + `ProcessQboWebhook` queued job. `VerifyQboWebhookSignature` middleware. Only processes Invoice entities. Reuses `QboSyncService::syncInvoiceStatusFromQbo()` for status/amount sync. `InvoiceObserver` handles downstream effects (prepay deposits on Paid/Void). Supplements (does not replace) the 4-hour `qbo:sync-invoices --pull-status` cron. Line item details (description, quantity, unit_price, amount) sync back via positional matching. Partial payments are not synced.
- **Contract documents** — `ContractDocument` model with `DocumentSummaryStatus` enum (Pending/Processing/Completed/Failed/Skipped). `ContractDocumentService` handles upload to `contract-documents/{contract_id}/` on local disk, PDF text extraction via `smalot/pdfparser`, and AI summarization via `AiClient::complete()`. `SummarizeContractDocument` job dispatched via `afterResponse()`, pessimistic locking, `$tries=2`, `$timeout=300`. Summaries injected into triage context by `ContextBuilder` (max 3 per contract, truncated at 2K chars). Soft deletes with disk cleanup via Eloquent `deleting` event. Restrict FK (not cascade) to prevent file orphaning.
- **Contract activity log** — `contract_activities` table tracks assignment changes, rule evaluations, and modifications with user attribution.

## Mesh integration
- **MeshConfig** (`app/Support/MeshConfig.php`) — static helper. Settings: `mesh_api_key` (encrypted), `mesh_base_url` (default `https://hub-us.emailsecurity.app`).
- **MeshClient** (`app/Services/Mesh/MeshClient.php`) — Guzzle, `API-KEY` header auth. Methods: `getCustomers()`, `getCustomer(uuid)`, `isHealthy()`.
- **MeshLicenseSyncService** — syncs `licenses_billed` from customer detail into `license_types`/`licenses` tables. Vendor = `mesh`.
- **MeshEmailParser** (`app/Services/Mesh/MeshEmailParser.php`) — static helper that detects and parses Mesh delivery request notification emails. When end users click "request delivery" in their quarantine digest, Mesh sends an email from `noreply@emailsecurity.app` with subject `Email Delivery Request: user@domain.com` containing structured fields (Sender, Recipient, Subject, Queue ID, Category, portal URL). `isMeshDeliveryRequest(Email)` detects these; `parse(Email)` extracts fields via regex; `buildDescription(array)` formats a ticket description. Used by `EmailService` during inbound email processing.
- **Email ingestion**: `EmailService::isAutoReply()` exempts Mesh delivery requests (checks both `@emailsecurity.app` domain AND `Email Delivery Request:` subject prefix). `resolveSender()` falls back to `MeshEmailParser` to extract the recipient email from the body and resolve the correct client/contact. `autoCreateTicketFromEmail()` creates enriched `ServiceRequest` tickets with parsed Mesh context and deduplicates repeated requests within a 2-hour window.
- Client mapping via `mesh_customer_id` (UUID) on `clients` table, managed in Settings > Mesh Customer Mapping.

## CIPP integration
- **CippConfig** (`app/Support/CippConfig.php`) — static helper. Settings: `cipp_api_url`, `cipp_tenant_id`, `cipp_client_id`, `cipp_client_secret` (encrypted), `cipp_application_id`.
- **CippClient** (`app/Services/Cipp/CippClient.php`) — Guzzle, OAuth2 client credentials via Azure AD. Token cached in Laravel Cache with 55-min TTL, auto-retry on 401 + 429 (exponential backoff). Response unwrapping: `{"Results": [...]}` → `[...]`. Methods: `listTenants()`, `listLicenses()`, `listUsers()`, `listGroups()`, `listUserGroups()`, `listMailboxes()`, `listMFAUsers()`, `listDevices()`, `listDefenderState()`.
- **CippLicenseSyncService** — calls `ListLicenses` per mapped tenant, upserts `license_types`/`licenses` with consumed units. Vendor = `cipp_m365`.
- **CippContactSyncService** (`app/Services/Cipp/CippContactSyncService.php`) — syncs M365 users into `people` table via `ListUsers`. Per-client group filtering via `cipp_sync_group_id` on `clients` (Azure AD group objectId, null = sync all). Match by `cipp_user_id` (Azure AD objectId) first, then email, then create new. Uses `PersonService` for all creates/updates (phone normalization, PersonObserver fires). Null-safe updates: M365 nulls don't overwrite manually-entered PSA data. Stale cleanup: deactivates synced persons (`cipp_user_id IS NOT NULL`) not seen in current run, guarded by `$fetchSucceeded`. Pessimistic locking per client. `accountEnabled → is_active` (deactivating removes from contract assignments + disables portal). Synced contacts created with `portal_enabled = false`. Toggle: `cipp_contact_sync_enabled` setting (default off, separate from `cipp_enabled`). Schedule: `cipp:sync-contacts` daily at 05:55. Supports `--dry-run` and `--client=X`.
- **CippContactEnrichmentService** (`app/Services/Cipp/CippContactEnrichmentService.php`) — enriches existing synced contacts with mailbox size (`ListMailboxes` → `mailbox_size_bytes`, `mailbox_item_count`) and MFA status (`ListMFAUsers` → `mfa_enabled`). Separate from contact sync (independent API calls, independent failure). Keyed by `cipp_upn`. Sets `cipp_enriched_at` (distinct from `cipp_synced_at`). Schedule: `cipp:enrich-contacts` daily at 05:57.
- **M365 profile photos** — the same enrichment pass syncs each mapped user's M365 photo to a local avatar. `CippClient::getUserPhoto()` (built on a new `getRaw()` that returns raw response bytes + content type) calls `api/ListUserPhoto`, which returns image bytes when a photo is set or a JSON `{"error":{"code":"ImageNotFound"}}` payload when not. `AvatarHelper::cropToSquareJpeg()` normalizes the bytes to a 200px square JPEG stored at `avatars/people/{id}.jpg` on the `public` disk; `people.avatar_path`/`avatar_synced_at` record it. `Person::avatar_url` prefers the synced photo over the Gravatar fallback, so it flows through every `<x-avatar>`/`<x-person-badge>` automatically. Per-user call, so it's bounded by a 30-day `avatar_synced_at` TTL (photoless users are stamped too, so we don't re-poll daily).
- **CippDeviceSyncService** (`app/Services/Cipp/CippDeviceSyncService.php`) — syncs Intune managed devices to `assets` table via `ListDevices` + `ListDefenderState`. Match by `m365_device_id` first, hostname second (case-insensitive, scoped to client_id). Fields: `m365_device_id`, `m365_compliance_state`, `m365_is_compliant`, `m365_enrollment_type`, `m365_os_version`, `m365_last_sync_at`, `m365_device_owner_type`, `m365_defender_status`, `m365_defender_version`, `m365_last_scan_at`, `m365_synced_at`. Stale cleanup nulls all m365 fields on unmatched assets. Pessimistic locking, dry-run support. Schedule: `cipp:sync-devices` daily at 05:59.
- **Person enrichment fields** (from ListUsers, zero extra API calls): `cipp_upn`, `department`, `office_location`, `is_hybrid`, `m365_user_type` (Member/Guest). Staleness indicator on UI when `cipp_enriched_at` > 48h.
- Client mapping via `cipp_tenant_domain` on `clients` table, managed in Settings > CIPP Tenant Mapping. Uses `defaultDomainName` from `ListTenants` as the `TenantFilter`. Group selector on mapping page for per-tenant contact sync filtering.

## NinjaRMM integration
- **NinjaClient** (`app/Services/Ninja/NinjaClient.php`) — Guzzle, OAuth2 client credentials. Settings: `ninja_client_id`, `ninja_client_secret` (encrypted), `ninja_instance_url`.
- **NinjaBackupSyncService** (`app/Services/Ninja/NinjaBackupSyncService.php`) — syncs backup usage from NinjaRMM into assets + license types. Vendor = `ninjaone` for all. Backup license types: `cloud_backup_server`, `cloud_backup_workstation` (device counts by asset_type), `cloud_usage_gb` (storage in GB). RMM license type: `rmm_devices` (total device count from API). Scheduled via `ninja:sync-backup` artisan command.
- Client mapping via `ninja_org_id` on `clients` table, managed in Settings > Ninja Organization Mapping.

## Stripe integration
- **StripeConfig** (`app/Support/StripeConfig.php`) — static helper. Settings: `stripe_secret_key` (encrypted), `stripe_mode` ('test' or 'live').
- **StripeClient** (`app/Services/Stripe/StripeClient.php`) — Guzzle, Bearer token auth. Uses `application/x-www-form-urlencoded` (not JSON). Auto-retry on 429 rate limit. Stripe API version pinned to `2024-12-18.acacia`.
- **StripeSyncService** (`app/Services/Stripe/StripeSyncService.php`) — mirrors QboSyncService: `pushInvoiceToStripe()` (create draft → add items → finalize → read back tax), `syncInvoiceStatusFromStripe()`, customer matching, product sync.
- **Amounts in cents**: Stripe uses integer cents. `dollarsToCents()` and `centsToDollars()` helpers handle conversion. $25.50 = 2550.
- **Stripe Tax**: Set `automatic_tax[enabled]=true` on invoice creation. Taxable lines use `tax_behavior=exclusive`. Non-taxable use `tax_code=txcd_00000000`. Tax is calculated on finalize and synced back to PSA `tax`/`total` fields.
- **Hosted invoice URL**: Stored in `stripe_invoice_url` on invoices — gives clients a payment link.
- **Stripe Prices are immutable**: When pushing SKU price changes, a new Price object is created each time (old one remains but isn't used).
- **Alternative to QBO**: Each MSP deployment uses one billing backend. Invoice push buttons show based on which backend has the client mapped (`stripe_customer_id` vs `qbo_customer_id`).
- Client mapping via `stripe_customer_id` on `clients` table, managed in Settings > Stripe Customer Matching.
- **Invoice import** (Stripe → PSA): `StripeSyncService::importInvoicesFromStripe()` pulls finalized Stripe invoices into local DB. Dedup key: `stripe_invoice_id` (unique column). Skips drafts and PSA-originated invoices (detected via `metadata.psa_invoice_id`). Incremental sync via `stripe_invoice_import_last_sync` setting storing max `created` timestamp. Page-by-page processing to avoid memory issues. Status regression protection: never downgrades Void/Paid. Uses `withTrashed()` to handle soft-deleted records. Imported invoices have `contract_id = null` and are effectively read-only (no push buttons shown).

## Tier2Tickets / HelpDesk Buttons (CW Compat)
- **Purpose**: Minimal ConnectWise Manage API compatibility layer so Tier2Tickets can submit tickets to Sound PSA. T2T thinks it's talking to ConnectWise Manage.
- **T2TConfig** (`app/Support/T2TConfig.php`) — static helper. Settings: `t2t_api_key` (encrypted), `t2t_callback_url`, `t2t_system_user_id`.
- **VerifyT2TApiKey** (`app/Http/Middleware/VerifyT2TApiKey.php`) — validates Basic auth PrivateKey portion against stored key. CW format: `CompanyId+PublicKey:PrivateKey`.
- **T2TFieldMapper** (`app/Services/T2T/T2TFieldMapper.php`) — static bidirectional mapping between CW integer IDs and PSA enums (priorities, statuses, types). Single virtual "Service Desk" board.
- **T2TService** (`app/Services/T2T/T2TService.php`) — business logic: contact/asset lookups, ticket creation with field allowlist, duplicate detection, callback URL validation.
- **T2TController** (`app/Http/Controllers/Api/T2TController.php`) — thin controller, 11 endpoints + catch-all at `/api/tier2tickets/v4_6_release/apis/3.0/*`. Rate limited 120/min.
- **TicketObserver** (`app/Observers/TicketObserver.php`) — dispatches `SendT2TCallback` job on status change for `helpdesk_button` source tickets.
- **SendT2TCallback** (`app/Jobs/SendT2TCallback.php`) — queued Guzzle POST of CW-format ticket payload to registered callback URL. 2 retries, 30s timeout.
- **TicketSource::HelpdeskButton** — new source enum value for T2T-created tickets.
- Contact resolution: email lookup on `people` table. Asset matching: hostname lookup on `assets` table, scoped to client.
- All requests logged with `[CW Compat]` prefix. Full body at DEBUG level.

## Huntress integration
- **HuntressConfig** (`app/Support/HuntressConfig.php`) — static helper. Settings: `huntress_api_key` (encrypted), `huntress_api_secret` (encrypted), `huntress_cw_api_key` (encrypted), `huntress_system_user_id`.
- **HuntressClient** (`app/Services/Huntress/HuntressClient.php`) — Guzzle, HTTP Basic Auth (`api_key:api_secret`). Base URL `https://api.huntress.io/v1/`. Methods: `get()`, `isHealthy()`, `getOrganizations()` (paginated), `getAgents()`.
- **HuntressLicenseSyncService** (`app/Services/Huntress/HuntressLicenseSyncService.php`) — syncs EDR agent counts + ITDR user counts into `license_types`/`licenses`. Vendors: `huntress_edr`, `huntress_itdr`. Scheduled daily at 05:00 (only if configured).
- Client mapping via `huntress_organization_id` (integer) on `clients` table, managed in Settings > Huntress Organization Mapping.
- **CW Compat shim** — Huntress incident reports received via ConnectWise-compatible webhooks at `/api/huntress/v4_6_release/apis/3.0/*`.
- **HuntressFieldMapper** (`app/Services/Huntress/HuntressFieldMapper.php`) — severity to priority: CRITICAL→P1, HIGH→P2, LOW→P3.
- **HuntressService** (`app/Services/Huntress/HuntressService.php`) — ticket creation from CW-format payload. Title parsing, client resolution via org mapping, dedup on `client_id` + subject hash (15-min window), asset linking by hostname. Post-remediation maps to Resolved (not Closed). Ingest captures an `escalation_id` into alert metadata when the payload body carries an `escalations/{id}` URL (feeds the escalation reconcile id fast path).
- **Auto-resolve status-sync reconcile** — Huntress auto-resolves incidents/escalations **without** firing the CW-Manage status webhook, stranding the bridged PSA ticket open. Two hourly, configured-gated commands close the gap, each resolving (not closing) only on **positive correspondence**, idempotently, skipping human-touched tickets, never mis-closing: `HuntressIncidentReconcileService` (`huntress:reconcile-incidents`) scopes to "Incident on <host>" tickets, corresponds by exact incident id or host-in-body+window. `HuntressEscalationReconcileService` (`huntress:reconcile-escalations`, psa-oe19) scopes to "…Escalation…" tickets (not "Incident on"), corresponds by ingest-captured escalation id (`getEscalation`) or a **unique** org+subject match within the creation window (uniqueness across all statuses guards the sibling-close vector). Account-level escalations (empty `organizations[]`, e.g. "Failed to Deliver") have no org/id correspondence and are skipped — manual closure is the accepted fallback.
- **VerifyHuntressApiKey** (`app/Http/Middleware/VerifyHuntressApiKey.php`) — CW Basic Auth, delegates to `CwAuthHelper`.
- **CwAuthHelper** (`app/Support/CwAuthHelper.php`) — shared CW Basic Auth parsing, used by both T2T and Huntress middleware.
- **TicketSource::Huntress** — source enum value for Huntress-created tickets.
- Requests logged with `[Huntress CW]` prefix. Full body at DEBUG level.
- **MCP read tools** (`HuntressReadOnlyToolset`, epic psa-ppl9) — six P1 read tools on the staff MCP surface (`huntress_list/get_incident_reports`, `huntress_list/get_escalations`, `huntress_list/get_organizations`) for Chet. Wired via `ChetDataSurfaceTools` (live gated on `HuntressConfig::isConfigured()` — ships dormant; grant catalog ungated) + `ChetDataSurfaceToolExecutor`; `huntress_` prefix mapped in `McpToolRegistry`. Reads use `HuntressClient` (single-page + `next_page_token` cursor, 429 backoff). **Data boundary (shared account):** org metadata is account-wide (mapping helper, annotated with the mapped PSA client), but incident/escalation security data is **mapped-orgs-only** (`clients.huntress_organization_id`) — mirrors `HuntressIncidentReconcileService`. Per-sink redaction via `ChetDataSurfaceTextSanitizer` + a bounded recursive leaf-sanitizer for nested untrusted structures (entities, remediations).

## Servosity integration
- **ServosityConfig** (`app/Support/ServosityConfig.php`) — static helper. Settings: `servosity_api_token` (encrypted), `servosity_base_url` (default `https://api.servosity.com`).
- **ServosityClient** (`app/Services/Servosity/ServosityClient.php`) — Guzzle, Token auth (`Authorization: Token <token>`). Django REST Framework pagination. Methods: `getCompanies()`, `isHealthy()`.
- **ServosityLicenseSyncService** — syncs backup account counts into `license_types`/`licenses` tables. Vendor = `servosity`. SKU IDs: `m365_mailboxes`, `dr_server`, `dr_desktop`, `standard`, `pro`, `nas`.
- Client mapping via `servosity_company_id` on `clients` table, managed in Settings > Servosity Company Mapping.

## Control D integration
- **ControlDConfig** (`app/Support/ControlDConfig.php`) — static helper. Settings: `controld_api_key` (encrypted), `controld_analytics_token` (encrypted), `controld_stats_endpoint`. Methods: `isConfigured()`, `isEnabled()`, `isAnalyticsConfigured()`.
- **ControlDClient** (`app/Services/ControlD/ControlDClient.php`) — Guzzle, Bearer token auth. Base URL `https://api.controld.com`. Methods: `get()`, `getForOrg(endpoint, orgPk)` (adds `X-Force-Org-Id` header), `getDevices(orgPk)`, `getSubOrganizations()`, `isHealthy()`.
- **ControlDLicenseSyncService** — syncs endpoint and router device counts into `license_types`/`licenses` tables. Vendor = `controld`. SKU IDs: `endpoints`, `routers`. Skips sub-orgs with `status != 1`. Deactivates missing sub-orgs (zeros out quantities).
- **ControlDDeviceSyncService** (`app/Services/ControlD/ControlDDeviceSyncService.php`) — syncs per-device DNS security data to `assets` table. Matches by `controld_device_id` first, then case-insensitive hostname. Stores: profile name, device status, agent status/version, last seen. Clears stale data for devices removed from Control D. Scheduled daily at 05:12.
- **ControlDAnalyticsClient** (`app/Services/ControlD/ControlDAnalyticsClient.php`) — separate Guzzle client for analytics endpoint (`{stats_endpoint}.analytics.controld.com`). Uses dashboard session token (not API key). Method: `getActivityLog(orgPk, startTime, endTime, endpointId, page)`. Returns DNS query records: domain, action (allowed/blocked/nxdomain), trigger, source IP.
- Asset columns: `controld_device_id` (string, unique), `controld_profile_name`, `controld_status` (0=pending, 1=active, 2/3=disabled), `controld_agent_status` (1=connected), `controld_agent_version`, `controld_last_seen_at`, `controld_synced_at`.
- Client mapping via `controld_org_id` (string) on `clients` table, managed in Settings > Control D Organization Mapping.
- **Manual asset linking**: Asset detail page shows "Link to Control D" dropdown for unlinked assets when client has org mapping. Routes: `POST /assets/{asset}/controld/link` and `/unlink`.
- **AI triage tools**: `controld_get_devices` (list devices for client), `controld_dns_queries` (query DNS activity log, requires analytics token). ContextBuilder includes DNS profile and agent status for linked assets.

## Zorus integration
- **ZorusConfig** (`app/Support/ZorusConfig.php`) — static helper. Settings: `zorus_api_key` (encrypted). Methods: `get(key)`, `isConfigured()`, `isEnabled()`.
- **ZorusClient** (`app/Services/Zorus/ZorusClient.php`) — Guzzle, `Authorization: Impersonation {key}` header, `Zorus-Api-Version: 1.0` required. Base URL `https://developer.zorustech.com`. All list endpoints use `POST /api/{resource}/search`. Methods: `searchCustomers()`, `searchEndpoints()`, `isHealthy()`.
- **ZorusLicenseSyncService** — syncs endpoint counts into `license_types`/`licenses` tables. Vendor = `zorus`. SKU IDs: `endpoints`, `filtering`, `cybersight`. Counts from `deploymentInfo` on customer records.
- **ZorusDeviceSyncService** (`app/Services/Zorus/ZorusDeviceSyncService.php`) — syncs per-endpoint DNS data to `assets` table. **Customer filter is unreliable** — fetches all endpoints and groups by `customerUuid` client-side. Matches by `zorus_endpoint_id` first, then hostname. Stale cleanup guarded by `$fetchSucceeded` flag. Scheduled daily at 05:20.
- Asset columns: `zorus_endpoint_id` (string 36, unique), `zorus_group_name`, `zorus_filtering_enabled` (boolean), `zorus_cybersight_enabled` (boolean), `zorus_agent_version`, `zorus_agent_state`, `zorus_last_seen_at`, `zorus_synced_at`.
- Client mapping via `zorus_customer_id` (string 36) on `clients` table, managed in Settings > Zorus Customer Mapping.
- **Manual asset linking**: Asset detail page shows "Link to Zorus" dropdown for unlinked assets when client has customer mapping. Server-side ownership validation (verifies endpoint's `customerUuid` matches). Routes: `POST /assets/{asset}/zorus/link` and `/unlink`.
- **AI triage tools**: `zorus_get_endpoints` (list endpoints for client from local DB). ContextBuilder includes DNS filtering and CyberSight status for linked assets.
- **Dual-vendor note**: Both Control D and Zorus cards can show simultaneously on the same asset during vendor migrations.

## AppRiver integration
- **AppRiverConfig** (`app/Support/AppRiverConfig.php`) — static helper. Settings: `appriver_client_id` (encrypted), `appriver_client_secret` (encrypted), `appriver_base_url` (default `https://unityapi.webrootcloudav.com`).
- **AppRiverClient** (`app/Services/AppRiver/AppRiverClient.php`) — Guzzle, OAuth2 client credentials via `POST /auth/token`. Token cached 4 min (5 min TTL). Auto-retry on 401. Methods: `getCustomers()`, `getSubscriptions()`, `getSubscriptionDetail()`, `updateSubscriptionQuantity()`.
- **AppRiverLicenseSyncService** (`app/Services/AppRiver/AppRiverLicenseSyncService.php`) — syncs subscription seat counts (`TotalLicenses`, `AssignedLicenses`) into `license_types`/`licenses`. Vendor = `appriver`. `updateQuantity()` pushes seat count changes via PATCH to AppRiver API with audit logging.
- Client mapping via `appriver_customer_id` (GUID) on `clients` table, managed in Settings > AppRiver Customer Mapping.
- **CIPP/AppRiver overlap**: Both sync M365 license data. CIPP shows tenant-side view (allocated vs consumed). AppRiver shows reseller-side view (purchased vs assigned). For AppRiver-managed clients, AppRiver is the billing source of truth. Both coexist as separate vendors with separate `license_type` records.
- **License utilization tracking** — `assigned_quantity` column on `licenses` (nullable integer). Populated by any vendor sync that provides assigned/in-use counts. `License` model has vendor-agnostic accessors: `unassigned_quantity`, `utilization_percent`, `utilization_status` (good/warning/waste at 90%/70% thresholds). License list shows utilization column and "waste only" filter for any license with assigned data. Inline seat editing for AppRiver licenses with confirmation dialog and double-click prevention.

## AI integration
- **AiConfig** (`app/Support/AiConfig.php`) — static helper following PlivoConfig pattern. `AiConfig::provider()`, `AiConfig::model()`, `AiConfig::isConfigured()`, `AiConfig::get('api_key')`. Settings keys: `ai_provider`, `ai_api_key` (encrypted), `ai_model`.
- Default models: `claude-sonnet-4-6` (Anthropic), `gpt-4o` (OpenAI)
- **AiClient** (`app/Services/Ai/AiClient.php`) — provider-agnostic AI client. Methods: `complete()` (simple completion), `completeJson()` (JSON response with code-fence stripping), `confirmYesNo()` (safe boolean), `runToolLoop()` (Anthropic-only agentic tool loop with token budget and wall-clock timer). Tracks cumulative token usage. `AiResponse` value object for all responses. Used by triage pipeline; TranscriptionService still uses Guzzle directly (will be migrated later).
- **TranscriptionConfig** (`app/Support/TranscriptionConfig.php`) — static helper for Whisper transcription settings. `whisperApiKey()` returns dedicated `openai_api_key` setting, falls back to AiConfig key if provider is OpenAI. `isConfigured()`, `autoTranscribeEnabled()`, `minDurationSeconds()` (default 30).
- **TranscriptionService** (`app/Services/TranscriptionService.php`) — Pipeline: download recording → Whisper STT → AI analysis → parse structured fields (sentiment, charge classification, coaching). Uses the configured AI provider (Anthropic or OpenAI) for analysis. Whisper always uses OpenAI. **Stereo diarization**: If ffmpeg is installed, detects stereo recordings (Plivo records one speaker per channel), splits into mono channels, transcribes each independently with `verbose_json` timestamps, then interleaves segments chronologically with speaker labels. Channel mapping: outbound calls have agent on left (A-leg) and customer on right (B-leg); inbound is reversed. Gracefully falls back to mono transcription if ffmpeg is unavailable or audio is mono.
- **TranscribePhoneCall** (`app/Jobs/TranscribePhoneCall.php`) — Queued job dispatched via `afterResponse()` to avoid blocking webhook responses. Uses pessimistic locking to prevent duplicate transcriptions. `$tries = 2`, `$timeout = 600`.
- Auto-transcribe triggers in `PlivoWebhookController::handle()` when recording webhook arrives, if enabled via settings. Manual transcribe button available on call detail page.
- **Staff MCP tool-surface discovery** (`list_tool_surface`, psa-ve9v) — always-callable transport built-in (like `whoami`) on the staff MCP server. Classifies the full grant catalog (`McpToolRegistry::groups()`) per caller via `McpToolSurface` (`app/Support/McpToolSurface.php`): `granted` (in token allowlist + live), `available_ungranted` (built + config-on, needs operator token grant), `unavailable_config` (built, integration off — infra config); absent = doesn't exist (dev build). Capability names, categories, one-line descriptions only — no data/secrets. `McpToolSurface::liveGeneral/ClientScopedToolDefinitions()` is the same assembly `tools/list` publishes, so discovery can't drift from the live surface. **request_tool auto-classify**: a `tool_missing` report whose text names a catalog tool (exact or spaced lexical match, longest wins) is reclassified to its remedy — `ToolUnused` (already granted), `ToolUngranted` (enablement ask), `ToolUnconfigured` (config ask) — with `tool_name` set; system classifications are not agent-selectable (`ToolingGapClassification::fromAgentInput`).
- **Purpose**: Automatic ticket classification, enrichment, and preparation for technician review. Ported from HaloClaude Python triage pipeline into native Laravel. Reads from local DB (no Halo API dependency).
- **TriageConfig** (`app/Support/TriageConfig.php`) — static helper following T2TConfig pattern. `isEnabled()`, `autoTriageEnabled()`, `autoReviewEnabled()`, `stageEnabled(stage)`, `systemUserId()` (AI pseudo-user for notes/actions), `defaultAssigneeId()`, `model()`, `maxTokensPerRun()` (default 200K), `dailyTokenLimit()` (default 2M), `reviewBatchSize()` (default 20).
- **TriagePipeline** (`app/Services/Triage/TriagePipeline.php`) — orchestrator. `run(Ticket, mode, triggeredByUserId): TriageRun`. Modes: `triage` (new tickets) and `review` (existing tickets). Runs stages in sequence, catches per-stage errors, tracks cumulative tokens, checks daily ceiling.
- **TriageRun** (`app/Models/TriageRun.php`) — tracks pipeline execution. `triage_runs` table: mode, status, stages_completed (JSON), stage_results (JSON), errors (JSON), ai_tokens_used (JSON), duration_ms.
- **RunTriagePipeline** (`app/Jobs/RunTriagePipeline.php`) — queued job. `$tries = 2`, `$timeout = 600`. Pessimistic locking to prevent concurrent runs on same ticket.
- **Pipeline stages** (each individually toggleable via `triage_stage_*` settings):
  - **Stage 0 — Contact Resolution** (`ContactResolver`): Email → Person lookup, hostname → Asset lookup, all-caps tokens, AI hostname extraction. Updates ticket `client_id`/`contact_id`.
  - **Stage 0.5 — Junk Filter** (`JunkDetector`): Deterministic pattern matching for auto-reply, bounce, spam, notifications. Monitoring allowlist (NinjaRMM, SentinelOne, etc.), security keywords prevent closure. High-confidence auto-closes; medium-confidence gets AI confirmation (defaults to safe side on failure).
  - **Stage 1 — Classification** (`TriageClassifier`): AI classifies managed_services / break_fix / no_contract. Uses actual contract data from DB, with general coverage rules as fallback.
  - **Stage 2c — Asset Assignment** (`AssetMatcher`): Links workstation via person's contract assignments, NinjaRMM last-logged-on-user, or name matching.
  - **Stage 3 — Technical Triage** (`TechnicalTriager`): Agentic tool loop (Anthropic only, max 10 rounds, 200K token budget, 240s wall clock). Tools: `search_tickets` (client-scoped), NinjaRMM device queries, Mesh email security, CIPP M365. Sets priority, writes private `AiTriage` note, auto-assigns ticket.
  - **Review Mode** (`ConversationReviewer`): Assesses ticket conversation state (resolved/waiting_customer/waiting_us/junk/active). Recommend-only (no auto-close). Priority-based cooldowns (P1/P2: 4h, P3: 12h, P4: 24h). Skips human-touched tickets.
- **Graceful degradation**: Deterministic stages (junk filter, contact resolution, asset matching) run without an AI API key. AI stages are skipped with a log message.
- **Client scoping**: All tool calls in the agentic loop enforce `client_id` filtering in `TriageToolExecutor` — no cross-client data leakage.
- **System user**: Uses `TriageConfig::systemUserId()` (configurable `triage_system_user_id` setting, falls back to first user) for all notes, status changes, and assignments. Follows T2TConfig pattern.
- **TicketObserver** — `created()` dispatches `RunTriagePipeline` if `autoTriageEnabled()`. Recursion guard: skips if ticket was created by the triage system user.
- **Prompts** (`app/Services/Triage/Prompts.php`) — all prompt templates ported from HaloClaude `triage/prompts.py`. Constants: `TRIAGE_SYSTEM_PROMPT`, `TECHNICAL_TRIAGE_SYSTEM_PROMPT`, `REVIEW_SYSTEM_PROMPT`, `JUNK_CONFIRMATION_PROMPT`.
- **ContextBuilder** (`app/Services/Triage/ContextBuilder.php`) — builds formatted context from local DB with truncation limits (ticket body 5K, notes 10x2K, contracts 1K each, assets 500 each, contract doc summaries 3x2K per contract).
- **Cron**: `triage:review-open` runs hourly (gated by `autoReviewEnabled()`). Batched, priority-ordered, skips human-touched tickets.
- **UI**: Triage card in ticket detail sidebar (classification badge, prepay status, reasoning, triage/review buttons). AI triage notes collapsed by default in timeline with purple robot avatar. Robot icon on ticket list for triaged tickets. Settings in Integrations page.
- **Logging**: `[Triage]` prefix. `[AiClient]` prefix for AI calls. Tool calls logged at DEBUG level.
- **Note type**: `NoteType::AiTriage` (`ai_triage`) — icon `bi-robot`, `isSystemGenerated() = true`.

## Client Portal
- **Purpose**: Client-facing portal at `/portal` where contacts can view tickets, invoices, devices, and service agreements. Email+password authentication, completely separate from staff SSO.
- **Auth**: `portal` guard (session driver) + `portal` provider (eloquent, `Person::class`) + `portal` password broker (`portal_password_reset_tokens` table). Person model extends `Authenticatable`.
- **Route file**: `routes/portal.php` — loaded via `then:` callback in `bootstrap/app.php`. All routes use `portal.` name prefix.
- **Middleware**: `PortalAuthenticate` (checks `portal` guard, verifies `is_active` + `portal_enabled`) and `PortalClientScope` (resolves `client_id` from Person, sets request attributes `portal_client_id`/`portal_person`, shares with views, aborts 403 if no client).
- **PortalConfig** (`app/Support/PortalConfig.php`) — static helper following T2TConfig pattern. Settings: `portal_enabled`, `portal_company_name`, `portal_logo_url`, `portal_billing_url`, `portal_billing_label`, `portal_order_url`. Methods: `isEnabled()`, `companyName()`, `logoUrl()`, `supportEmail()`, `billingUrl()`, `billingLabel()`, `orderUrl()`, `orderUrlForClient(int)`.
- **Portal fields on Person**: `password` (hashed cast), `portal_enabled` (bool), `company_wide_access` (bool), `portal_last_login_at` (datetime), `remember_token`. Scope: `scopePortalEnabled()`.
- **Note visibility**: `TicketNote::scopePortalVisible()` — `is_private = false` AND excludes `NoteType::systemGenerated()` types (AiTriage, System, StatusChange, Escalation).
- **Portal replies**: `TicketService::addPortalReply(Ticket, Person, body)` — creates note with `author_id = null`, `author_name`, `who_type = WhoType::EndUser`, `note_type = Reply`. Separate from `addNote()` because `author_id` FKs to `users` table.
- **Ticket creation**: `TicketSource::Portal`, urgency toggle (Normal→P3, Urgent→P2), `TicketType::ServiceRequest` default.
- **Invoice visibility**: Only Posted, Paid, Void statuses shown. Cost/margin columns hidden.
- **Portal notifications**: `SendPortalNotification` job sends emails to portal-enabled contacts on: staff public reply, status→Resolved, status→PendingClient. Uses `EmailService::sendNew()` via Graph API.
- **Staff management**: `/clients/{client}/portal` — invite contacts, toggle portal access, toggle company-wide access, send password resets. Requires Graph email configured for invites/resets.
- **Triage integration**: `JunkDetector` skips `TicketSource::Portal` (authenticated submissions aren't junk). `ConversationReviewer` treats `who_type = EndUser` as human touch.
- **Client-friendly labels**: "Devices" (not Assets), "Service Agreements" (not Contracts). Priority: P1→Critical, P2→High, P3→Normal, P4→Low.
- **External billing links**: `portal_billing_url` (Billing Portal nav link), `portal_order_url` (Purchase Prepaid Time with `{client_id}` placeholder). Optional — Stripe users get inline "Pay Online" buttons instead.
- **Product shop (catalog orders)** — `PortalOrderService` (`app/Services/PortalOrderService.php`) handles self-service product orders beyond prepaid time. Opt-in via `portal_shop_enabled` setting (`PortalConfig::shopEnabled()`, default off) + per-SKU `portal_orderable` flag (`Sku::scopePortalOrderable()` = active + orderable); optional `portal_description` blurb. `PortalOrderController` flow: catalog (`/portal/shop`, SKUs grouped by category, JS live total) → multi-item `store` (validates quantities + `expected_prices` TOCTOU guard, resolves against live orderable catalog, duplicate-order guard) → confirmation. Creates one `Posted` invoice with `contract_id = null` (catalog purchase, not contract-scoped) and one `InvoiceLine` per product; `subtotal == total` (tax added by billing backend on push). Billing push (Stripe/QBO) via `app()->terminating()`; staff notified via `NotificationEventType::PortalProductOrder`. Mirrors the prepaid-purchase flow but generalized to N items with no contract requirement.
- **Portal MCP server** (`app/Http/Controllers/Api/McpPortalController.php`, epic psa-i6p) — client-facing sibling of the staff MCP server, at `POST /api/mcp/portal`, for a client "Teams agent" that acts on behalf of an end user. Dormant until a token is minted (`McpConfig::isPortalEnabled()` = `mcp_portal_token` setting present; `php artisan mcp:rotate-portal-token`). **Auth (two parts):** `VerifyMcpPortalToken` — a shared bearer token authenticates the bridge, and the `X-Mcp-Portal-Object-Id` header (the Teams sender's Entra Object ID) is resolved by `PortalMcpIdentityResolver` to a portal `Person` via `people.cipp_user_id`, gated by the same predicate as the browser portal login (`Person::canAccessPortal()` + `person_type->canHavePortal()`), fail-closed to null. Identity is set as request attribute `mcp_portal_person`, never from tool input. **Tool surface** (`PortalMcpToolDefinitions` + `PortalMcpToolExecutor`, `app/Services/Mcp/`): `list_my_open_tickets`, `get_my_ticket`, `search_my_tickets`, `create_ticket`, `add_my_ticket_reply`, `list_my_assets` — client-locked from the resolved Person (mirrors `PortalChatbotToolExecutor`'s constructor-bound scope + `ticketScope()`/`clean()`), tickets honour `company_wide_access`. Writes reuse `TicketService::createTicket`/`addPortalReply` (source `Portal`, contact = caller; urgency normal→P3/urgent→P2). No cross-client, admin, billing, or vendor tools. Audited to `mcp_audit_logs` (`server_name = 'portal'`, `actor_label = portal:{id}`, ticket subject/body redacted).
- **Portal AI Chatbot** (`portal_chatbot_enabled` setting, default off) — client-facing "Ask AI" assistant at `/portal/chatbot`. `PortalChatbotController` (index + `send`), `PortalChatbotService` (orchestration via `AiClient::runChatWithTools`, injected for testability; per-conversation + per-person daily token caps; blocking JSON, no streaming), `PortalChatbotToolExecutor` (READ-ONLY, client-locked — mirrors `TriageToolExecutor`'s constructor-bound `clientId` invariant), `PortalChatbotToolDefinitions` (6 read tools: account summary, tickets, invoices, devices, agreements). `PortalChatConversation`/`PortalChatMessage` models persist visible turns (keyed to client_id + person_id; ownership re-verified on every send). Scope binds from the authenticated `Person` — never from tool input; tickets honor `company_wide_access`; invoices restricted to Posted/Synced/Paid; devices to active; contracts to active (prepay balance only for company-wide contacts). Anthropic-only (the tool loop requires it); `PortalConfig::chatbotEnabled()` + `PortalChatbotService::isAvailable()` gate it. Do NOT reuse the staff `AssistantToolExecutor` here — it is global/cross-client and has write tools.

## Graph API gotchas
- **Two Guzzle clients**: GraphClient uses separate clients for `graph.microsoft.com` (API) and `login.microsoftonline.com` (token). Token endpoint is `/{tenant}/oauth2/v2.0/token` with `grant_type=client_credentials` and `scope=https://graph.microsoft.com/.default`.
- **Application vs Delegated permissions**: Email integration uses `Mail.Read` and `Mail.Send` Application permissions (client credentials). SSO uses Delegated permissions (user login flow). Both on the same app registration.
- **sendMail returns 202 with empty body**: `POST /users/{mailbox}/sendMail` returns HTTP 202 with no response body. Outbound email records have `graph_id = NULL`. The UNIQUE constraint on `graph_id` + nullable is intentional and safe (multiple NULLs allowed on both MariaDB and SQLite).
- **Webhook subscription max lifetime**: 4230 minutes (~2.9 days) for mail resources. Must be renewed before expiry. Scheduler runs `email:subscription-renew` every 2 hours with 24-hour renewal buffer.
- **Pagination**: Graph returns `@odata.nextLink` absolute URLs for next pages. `getAllPages()` handles this internally — callers get a flat array.
- **graph_mailbox NOT in singleton**: The GraphClient singleton holds only auth config. Mailbox address is read from Settings at call time by EmailService to prevent stale-mailbox bugs after config changes.

## Vendor response shapes — READ THE SOURCE, and FIXTURE FROM IT (hard-won, psa-7lgo)

We shipped a CIPP read surface where **six tools silently lost data and three returned nothing at all** — for months, with green tests. The AI technician was structurally blind to external mail auto-forwarding, to every inbox rule (the canonical BEC persistence mechanism), to OAuth consent grants, and to the entire audit log. Nothing failed. Nothing warned. The tools just confidently answered **"nothing found."** Three rules come out of that, and they are not optional.

**1. Field names come from the vendor's source, never from a guess.** Every one of those bugs was a plausible-looking key name that the vendor does not emit. Do not infer a payload's shape from the vendor's docs, from the resource it *wraps*, or from what the field "obviously" must be called. Go read the code that builds the response, and follow it to its true producer — a `Select-Object` may **rename** properties, a cache table may hold the **raw** upstream object, an endpoint may **flatten** a nested structure, and a wrapper may re-dispatch to a completely different function. Cite the source file in a comment next to the field list, so the next person can re-verify instead of re-guessing.

Casing is a trap in particular: CIPP passes Microsoft Graph through as camelCase but hands Exchange/PowerShell objects back in PascalCase, sometimes **mixing both in one payload**. Assume nothing about casing.

**2. Test fixtures MUST be copied from the vendor's real payload — never from the shape our code expects.** This is the root cause, and it is the one that let everything else hide. Our tests mocked CIPP returning exactly what the projection wanted, so they asserted our assumptions against our assumptions and passed while the tool returned `{}` in production. **A mock you authored from the code under test proves nothing.** Take the fixture from a captured live response or the vendor's own projection, and it will fail loudly the moment the code is wrong — which is the entire point.

**3. A degraded read must SCREAM, never return a clean empty result.** For a security surface, a confident `[]` is worse than an exception: "no malicious inbox rules", "no OAuth consents", "no CA gaps" is precisely the answer an attacker would like the analyst to receive, and an agent cannot tell it apart from a real all-clear. So:
- Never fail **closed** into an empty list. If a filter can't find its key, that is a bug, not "no matches".
- If a capability is structurally unavailable upstream, **hard-error with a message that says so** and names the working alternative. Do not return an empty result and let it read as an all-clear. `CippMcpToolRelay::unanswerableRequest()` is the pattern.
- Detect drift in-band. `CippMcpToolRelay` warns when a projected field's key is absent from **every** row — distinguishing "key missing" (drift) from "key present holding null" (a genuine no-value), so partial drops surface instead of rotting silently.

Applies to every vendor integration in this repo, not just CIPP.

## API documentation references
When you need to look up API endpoints, schemas, or integration details, read from `~/repos/HaloClaude/docs/`:

### Vendor docs (`docs/<vendor>/`)
| Vendor | Key file | Notes |
|--------|----------|-------|
| **CIPP** | `api-endpoints.md` | 436 endpoints, OAuth2, tenant-scoped Microsoft management |
| **Mesh** | `mesh-api-index.md` + `Mesh-API-v1.json` | Email security — log search, event trace, API key auth |
| **NinjaRMM** | `NinjaRMM-API-v2.json` | OpenAPI spec only (~1.3 MB), no markdown index |
| **Todyl** | `SGN-SASE-SIEM-field-mappings.txt` | Field mapping reference for security/SIEM |
| **Servosity** | `Servosity-API-v1.json` | OpenAPI spec, Token auth, Django REST pagination |
| **Zorus** | `zorus-api.json` | OpenAPI spec only (~55 KB) |

## Database
- **Local dev**: MariaDB (or SQLite for quick local work). Configure via `.env`.
- **Production**: MariaDB on the VPS.
- DB credentials live in `.env` (never committed).

## Living documentation — `docs/INSTALL.md`
When making changes that affect installation or configuration, **update `docs/INSTALL.md`** to match. This includes:
- New or changed `.env` variables
- New PHP extensions or dependencies
- New integrations added to Settings UI
- Changes to scheduled commands in `routes/console.php`
- Nginx config changes
- New artisan commands relevant to deployment


<!-- BEGIN BEADS INTEGRATION v:1 profile:minimal hash:7510c1e2 -->
## Beads Issue Tracker

This project uses **bd (beads)** for issue tracking. Run `bd prime` to see full workflow context and commands.

### Quick Reference

```bash
bd ready              # Find available work
bd show <id>          # View issue details
bd update <id> --claim  # Claim work
bd close <id>         # Complete work
```

### Rules

- Use `bd` for ALL task tracking — do NOT use TodoWrite, TaskCreate, or markdown TODO lists
- Run `bd prime` for detailed command reference and session close protocol
- Use `bd remember` for persistent knowledge — do NOT use MEMORY.md files

**Architecture in one line:** issues live in a local Dolt DB; sync uses `refs/dolt/data` on your git remote; `.beads/issues.jsonl` is a passive export. See https://github.com/gastownhall/beads/blob/main/docs/SYNC_CONCEPTS.md for details and anti-patterns.

## Session Completion

**When ending a work session**, you MUST complete ALL steps below. Work is NOT complete until `git push` succeeds.

**MANDATORY WORKFLOW:**

1. **File issues for remaining work** - Create issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **PUSH TO REMOTE** - This is MANDATORY:
   ```bash
   git pull --rebase
   git push
   git status  # MUST show "up to date with origin"
   ```
5. **Clean up** - Clear stashes, prune remote branches
6. **Verify** - All changes committed AND pushed
7. **Hand off** - Provide context for next session

**CRITICAL RULES:**
- Work is NOT complete until `git push` succeeds
- NEVER stop before pushing - that leaves work stranded locally
- NEVER say "ready to push when you are" - YOU must push
- If push fails, resolve and retry until it succeeds
<!-- END BEADS INTEGRATION -->
