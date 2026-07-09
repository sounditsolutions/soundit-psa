<?php

namespace App\Console\Commands;

use App\Services\AlertService;
use App\Services\Huntress\HuntressClient;
use App\Services\Huntress\HuntressEscalationReconcileService;
use App\Services\TicketService;
use App\Support\HuntressConfig;
use Illuminate\Console\Command;

class HuntressReconcileEscalations extends Command
{
    protected $signature = 'huntress:reconcile-escalations';

    protected $description = 'Resolve bridged Huntress escalation tickets whose escalation was resolved upstream (auto-resolve status-sync gap)';

    public function handle(TicketService $ticketService, AlertService $alertService): int
    {
        if (! HuntressConfig::isConfigured()) {
            $this->error('Huntress is not configured. Add API credentials in Settings → Integrations.');

            return self::FAILURE;
        }

        $client = new HuntressClient([
            'api_key' => HuntressConfig::get('api_key'),
            'api_secret' => HuntressConfig::get('api_secret'),
        ]);

        $service = new HuntressEscalationReconcileService($client, $ticketService, $alertService);

        $this->info('Reconciling Huntress escalation tickets...');

        $result = $service->reconcile();

        $summary = "{$result->updated} resolved".($result->errors > 0 ? ", {$result->errors} errors" : '');
        $this->info("Done: {$summary}.");

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
