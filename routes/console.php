<?php

use App\Support\AppTimezone;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Schedule;

// NinjaRMM alert reconciliation — catch missed RESET webhooks
Schedule::command('ninja:reconcile-alerts')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// NinjaRMM full device sync — every 4 hours (inventory, hardware detail, status, creates, deletes)
Schedule::command('ninja:sync-devices')
    ->everyFourHours()
    ->withoutOverlapping()
    ->runInBackground();

// Level RMM device sync — every 4 hours for mapped clients
Schedule::command('level:sync-devices')
    ->everyFourHours()
    ->withoutOverlapping()
    ->runInBackground();

// Mesh license sync — daily
Schedule::command('mesh:sync-licenses')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();

// CIPP M365 license sync — daily
Schedule::command('cipp:sync-licenses')
    ->dailyAt('04:45')
    ->withoutOverlapping()
    ->runInBackground();

// Huntress EDR/ITDR license sync — daily (only if configured)
Schedule::command('huntress:sync-licenses')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\HuntressConfig::isConfigured());

// Control D DNS security license sync — daily (only if configured + clients mapped)
Schedule::command('controld:sync-licenses')
    ->dailyAt('05:10')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\ControlDConfig::isConfigured()
        && \App\Models\Client::whereNotNull('controld_org_id')->exists());

// Control D DNS device enrichment — daily (only if configured + clients mapped)
Schedule::command('controld:sync-devices')
    ->dailyAt('05:12')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\ControlDConfig::isConfigured()
        && \App\Models\Client::whereNotNull('controld_org_id')->exists());

// Zorus license + device sync — daily (only if configured + clients mapped)
Schedule::command('zorus:sync-licenses')
    ->dailyAt('05:18')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\ZorusConfig::isConfigured()
        && \App\Models\Client::whereNotNull('zorus_customer_id')->exists());

Schedule::command('zorus:sync-devices')
    ->dailyAt('05:20')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\ZorusConfig::isConfigured()
        && \App\Models\Client::whereNotNull('zorus_customer_id')->exists());

// ScreenConnect license counting — daily (only if configured)
Schedule::command('screenconnect:count-licenses')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\ScreenConnectConfig::isConfigured());

// Tactical RMM alert reconciliation — catch missed resolved webhooks
Schedule::command('tactical:reconcile-alerts')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\TacticalConfig::isConfigured());

// Tactical RMM device sync — daily (only if configured + clients mapped)
Schedule::command('tactical:sync-devices')
    ->dailyAt('05:32')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\TacticalConfig::isConfigured()
        && \App\Models\Client::whereNotNull('tactical_site_id')->exists());

// Tactical RMM script library sync — daily (only if configured)
Schedule::command('tactical:sync-scripts')
    ->dailyAt('05:35')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\TacticalConfig::isConfigured());

// NinjaRMM backup usage + license sync — daily
Schedule::command('ninja:sync-backup')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Models\Client::whereNotNull('ninja_org_id')->exists());

// Comet Backup usage + license sync — daily (only if configured + clients mapped)
Schedule::command('comet:sync-backup')
    ->dailyAt('05:40')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\CometConfig::isConfigured()
        && \App\Models\Client::whereNotNull('comet_group_id')->exists());

// Servosity backup license sync — daily (only if configured + clients mapped)
Schedule::command('servosity:sync-licenses')
    ->dailyAt('05:45')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\ServosityConfig::isConfigured()
        && \App\Models\Client::whereNotNull('servosity_company_id')->exists());

Schedule::command('servosity:provision-backups')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\ServosityConfig::isConfigured()
        && \App\Models\Asset::where('servosity_backup_enabled', true)
            ->whereNull('servosity_dr_backup_id')->exists());

Schedule::command('appriver:sync-licenses')
    ->dailyAt('05:50')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Services\AppRiver\AppRiverClient::isConnected()
        && \App\Models\Client::whereNotNull('appriver_customer_id')->exists());

// Printix license sync — daily
Schedule::command('printix:sync-licenses')
    ->dailyAt('05:52')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\PrintixConfig::isConfigured()
        && \App\Models\Client::whereNotNull('printix_tenant_id')->exists());

// CIPP M365 contact sync — daily (only if enabled + clients mapped)
Schedule::command('cipp:sync-contacts')
    ->dailyAt('05:55')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\CippConfig::isContactSyncEnabled()
        && \App\Models\Client::whereNotNull('cipp_tenant_domain')->exists());

// CIPP Intune device sync — daily (only if device sync enabled + clients mapped)
Schedule::command('cipp:sync-devices')
    ->dailyAt('05:59')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\CippConfig::isDeviceSyncEnabled()
        && \App\Models\Client::whereNotNull('cipp_tenant_domain')->exists());

// CIPP tenant mail security posture — daily snapshot (transport, Safe Links, Safe Attachments)
Schedule::command('cipp:sync-tenant-security')
    ->dailyAt('06:01')
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => \App\Support\CippConfig::isConfigured()
        && \App\Models\Client::whereNotNull('cipp_tenant_domain')->exists());

// Contract assignment rules — daily reconciliation (event-driven handles real-time)
Schedule::command('contracts:evaluate-rules')
    ->dailyAt('05:15')
    ->withoutOverlapping()
    ->runInBackground();

// Tickets — auto-close resolved tickets after N days
Schedule::command('tickets:close-resolved')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground();

// Billing — generate recurring invoices daily at 6 AM
Schedule::command('billing:generate')
    ->dailyAt('06:00')
    ->withoutOverlapping(10)
    ->runInBackground();

// Asset user assignment — match RMM last-logged-on-user to contacts
Schedule::command('assets:assign-users')
    ->dailyAt('06:15')
    ->withoutOverlapping()
    ->runInBackground();

// QBO — pull payment status every 4 hours (always runs)
Schedule::command('qbo:sync-invoices --pull-status')
    ->everyFourHours()
    ->withoutOverlapping(10)
    ->runInBackground();

// QBO — auto-push drafts every 4 hours (only if enabled in Settings)
Schedule::command('qbo:sync-invoices --push-drafts')
    ->everyFourHours()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->when(fn () => \App\Models\Setting::getValue('qbo_auto_push_invoices') === '1');

// Stripe — pull payment status every 4 hours (always runs if configured)
Schedule::command('stripe:sync-invoices --pull-status')
    ->everyFourHours()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->when(fn () => \App\Support\StripeConfig::isConfigured());

// Stripe — auto-push drafts every 4 hours (only if enabled in Settings)
Schedule::command('stripe:sync-invoices --push-drafts')
    ->everyFourHours()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->when(fn () => \App\Models\Setting::getValue('stripe_auto_push_invoices') === '1');

// Microsoft Graph — renew webhook subscription (max lifetime ~3 days)
Schedule::command('email:subscription-renew')
    ->everyTwoHours()
    ->withoutOverlapping(10)
    ->runInBackground();

// Email — fallback poll for anything webhooks missed
Schedule::command('email:poll')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

// AI Triage — review open tickets for status changes (only if enabled)
// Frequency configurable via triage_review_frequency_minutes setting (default: 60)
Schedule::command('triage:review-open')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->when(function () {
        if (! \App\Support\TriageConfig::autoReviewEnabled()) {
            return false;
        }
        $freq = \App\Support\TriageConfig::reviewFrequencyMinutes();
        $cacheKey = 'triage:review-open:last-run';
        $lastRun = cache($cacheKey);
        if ($lastRun && now()->diffInMinutes($lastRun) < $freq) {
            return false;
        }
        cache([$cacheKey => now()], now()->addHours(24));

        return true;
    });

// Calls — resolve missing recordings from Plivo API (safety net for missed webhooks)
Schedule::command('calls:resolve-recordings')
    ->hourly()
    ->withoutOverlapping(5)
    ->runInBackground();

// Prepay — forfeit unconsumed remainder of expired prepaid-time credits (daily,
// before reconcile/billing). No-op until a contract sets prepay_expiry_months.
Schedule::command('prepay:expire')
    ->dailyAt('04:10')
    ->withoutOverlapping()
    ->runInBackground();

// Prepay — check for low balances and trigger alerts/auto-top-ups
Schedule::command('prepay:check-balances')
    ->hourly()
    ->withoutOverlapping(5)
    ->runInBackground();

// Attachments — clean up orphaned uploads from abandoned editor sessions
Schedule::command('attachments:clean-orphans')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

// Integrations — prune processed/terminal webhook rows older than 30 days
Schedule::command('integrations:prune-webhooks')
    ->dailyAt('04:05')
    ->withoutOverlapping()
    ->runInBackground();

// Wiki — nightly maintenance sweeps (staleness/contradiction/link-lint/open-ticket/stale-only regen)
// withoutOverlapping(60): 60-minute overlap-lock guard for a scheduled command.
// NOT ->expireAfter() — that is queue-middleware-only and throws BadMethodCallException on Schedule events.
Schedule::command('wiki:maintain')
    ->dailyAt('03:00')
    ->withoutOverlapping(60)
    ->runInBackground()
    ->when(fn () => \App\Support\WikiConfig::maintenanceEnabled());

// AI Technician — worker-liveness ping every 5 minutes; the worker processing it proves
// the technician queue is draining even when no tickets are flowing, and records the heartbeat.
Schedule::job(new \App\Jobs\TechnicianPing)
    ->everyFiveMinutes()
    ->when(fn () => \App\Support\TechnicianConfig::enabled());

// AI Technician — daily operator digest at the operator-local configured time (default 08:00).
// Fires once per local day at the configured HH:MM minute; send is skipped inside the command
// when the subsystem is disabled, so the schedule guard is a cheap early-exit only.
Schedule::command('technician:digest')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->when(function () {
        if (! TechnicianConfig::enabled() || ! TechnicianConfig::digestEnabled()) {
            return false;
        }
        // Fire only at the operator-local digest minute, and only once per local day.
        $localNow = now()->setTimezone(AppTimezone::get());
        if ($localNow->format('H:i') !== TechnicianConfig::digestTimeLocal()) {
            return false;
        }
        $last = TechnicianConfig::lastDigestAt();

        return $last === null || $last->setTimezone(AppTimezone::get())->toDateString() !== $localNow->toDateString();
    });
