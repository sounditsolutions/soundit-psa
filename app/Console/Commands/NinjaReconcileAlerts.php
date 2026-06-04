<?php

namespace App\Console\Commands;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Services\AlertService;
use App\Services\Ninja\NinjaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NinjaReconcileAlerts extends Command
{
    protected $signature = 'ninja:reconcile-alerts {--dry-run : Show what would be resolved without making changes}';

    protected $description = 'Reconcile local alert records against NinjaRMM active alerts API';

    public function handle(NinjaClient $client, AlertService $alertService): int
    {
        $dryRun = $this->option('dry-run');

        // Fetch all active alerts from Ninja API
        try {
            $remoteAlerts = $client->get('/v2/alerts', ['pageSize' => 1000]);
        } catch (\Throwable $e) {
            $this->error('Failed to fetch alerts from NinjaRMM: '.$e->getMessage());

            return self::FAILURE;
        }

        $remoteUids = collect($remoteAlerts)->pluck('uid')->filter()->all();

        $this->info('Active alerts in NinjaRMM: '.count($remoteUids));

        // Find local Ninja alerts that are open but no longer in Ninja
        $localActive = Alert::where('source', AlertSource::Ninja)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->get();

        $this->info('Open Ninja alerts in PSA: '.$localActive->count());

        $stale = $localActive->filter(fn ($alert) => ! in_array($alert->source_alert_id, $remoteUids, true));

        if ($stale->isEmpty()) {
            $this->info('All local Ninja alerts are still active in NinjaRMM. Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->info('Stale alerts to resolve: '.$stale->count());

        foreach ($stale as $alert) {
            $assetInfo = $alert->asset_id ? " on asset #{$alert->asset_id}" : '';
            $ticketInfo = $alert->ticket_id ? " (ticket #{$alert->ticket_id})" : '';
            $this->line("  - Alert #{$alert->id}: {$alert->title}{$assetInfo}{$ticketInfo}");

            if (! $dryRun) {
                $alertService->resolve($alert, 'Alert no longer active in NinjaRMM (caught by reconciliation).');
            }
        }

        if ($dryRun) {
            $this->warn('Dry run — no changes made.');
        } else {
            $this->info("Resolved {$stale->count()} stale alert(s).");
            Log::warning('[NinjaAlert] Reconciliation resolved stale alerts', [
                'count' => $stale->count(),
                'alert_ids' => $stale->pluck('id')->all(),
            ]);
        }

        return self::SUCCESS;
    }
}
