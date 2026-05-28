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
            $remoteAlerts = $client->patch('alerts/', ['timeFilter' => 30]);
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

        $this->info('Fetched ' . count($alertStatus) . ' alert(s) from Tactical RMM.');

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
