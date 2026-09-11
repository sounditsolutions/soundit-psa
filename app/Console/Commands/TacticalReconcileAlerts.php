<?php

namespace App\Console\Commands;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Services\AlertService;
use App\Services\Tactical\TacticalClient;
use App\Support\TacticalConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TacticalReconcileAlerts extends Command
{
    protected $signature = 'tactical:reconcile-alerts {--dry-run : Show what would be resolved without making changes}';

    protected $description = 'Resolve PSA alerts whose Tactical alerts have been resolved (catches missed webhooks)';

    /**
     * Seconds allowed for the one PATCH this command makes (psa-1355).
     *
     * The client's 30s default is right for the rest of the Tactical API and
     * wrong for this call: `alerts/` with a 30-day filter is an analytical query
     * and was measured answering in 41-85s, so every hourly run timed out before
     * a response arrived. 150 is roughly twice the slowest measurement — headroom
     * for a worse day without letting a genuinely hung endpoint hold an hourly
     * command indefinitely.
     */
    private const FETCH_TIMEOUT_SECONDS = 150.0;

    public function handle(TacticalClient $client, AlertService $alertService): int
    {
        if (! TacticalConfig::isConfigured()) {
            $this->error('Tactical RMM is not configured.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // Get open Tactical alerts from the unified alerts table
        $openAlerts = Alert::where('source', AlertSource::Tactical)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->get();

        if ($openAlerts->isEmpty()) {
            $this->info('No open Tactical alerts found.');

            return self::SUCCESS;
        }

        $this->info("Found {$openAlerts->count()} open Tactical alert(s) to check.");

        // Fetch alerts from Tactical (last 30 days covers most open alerts)
        try {
            $remoteAlerts = $client->patch('alerts/', ['timeFilter' => 30], self::FETCH_TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            $this->error("Failed to fetch alerts from Tactical RMM: {$e->getMessage()}");

            return self::FAILURE;
        }

        // Build source_alert_id => resolved status map
        $alertStatus = [];
        foreach ($remoteAlerts as $alert) {
            if (isset($alert['id'])) {
                $alertStatus[(string) $alert['id']] = $alert['resolved'] ?? false;
            }
        }

        $this->info('Fetched '.count($alertStatus).' alert(s) from Tactical RMM.');

        // FAIL CLOSED (psa-1355). The not-returned branch below reads "absent
        // from the response" as "resolved in Tactical", which is only sound when
        // the response is a real answer. A successful-but-empty list — an API key
        // that lost alert-read permission, a timeFilter regression, a filter
        // semantics change upstream — would otherwise resolve EVERY open Tactical
        // alert in one run, each with an audit reason reading like a considered
        // decision. There are open alerts to check (we returned above otherwise),
        // so a response carrying no usable alert is a signal about the response,
        // not a statement about the alerts. Refuse, and let the hourly failure say
        // so. Non-zero deliberately still trusts the response: any threshold above
        // zero is a tunable guess, and zero removes the whole catastrophic class.
        if ($alertStatus === []) {
            $this->error('Refusing to reconcile: Tactical RMM returned no usable alerts while '
                ."{$openAlerts->count()} PSA alert(s) are open. Nothing was resolved.");

            Log::error('[Tactical] Reconciliation refused: no usable alerts in the Tactical response', [
                'open_alerts' => $openAlerts->count(),
                // The raw row count separates two different faults that reach
                // this branch identically: an empty list, and rows that arrived
                // in a shape carrying no id. Zero usable alerts is the trigger;
                // this says which kind of zero it was.
                'rows_returned' => count($remoteAlerts),
            ]);

            return self::FAILURE;
        }

        $resolved = 0;

        foreach ($openAlerts as $alert) {
            $sourceAlertId = $alert->source_alert_id;
            $isResolved = $alertStatus[$sourceAlertId] ?? null;

            if ($isResolved === null) {
                // Alert not returned by Tactical (too old or deleted) — treat as resolved
                $reason = 'Alert not found in Tactical RMM (too old or deleted)';
            } elseif ($isResolved === true) {
                $reason = 'Alert resolved in Tactical RMM (caught by reconciliation)';
            } else {
                // Still open in Tactical — no action needed
                continue;
            }

            if ($dryRun) {
                $this->line("  Would resolve: alert #{$alert->id} (source_alert_id: {$sourceAlertId}) — {$alert->title}");
            } else {
                $alertService->resolve($alert, "{$reason}.");
                $this->line("  Resolved: alert #{$alert->id} (source_alert_id: {$sourceAlertId}) — {$alert->title}");
            }

            $resolved++;
        }

        if ($dryRun) {
            $this->warn("Dry run — would resolve {$resolved} alert(s).");
        } else {
            $this->info("Resolved {$resolved} alert(s).");
            if ($resolved > 0) {
                Log::warning('[Tactical] Reconciliation resolved alerts for stale Tactical alerts', [
                    'count' => $resolved,
                ]);
            }
        }

        return self::SUCCESS;
    }
}
