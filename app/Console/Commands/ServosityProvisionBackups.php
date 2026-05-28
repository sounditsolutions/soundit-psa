<?php

namespace App\Console\Commands;

use App\Services\Servosity\ServosityDeploymentService;
use App\Support\ServosityConfig;
use Illuminate\Console\Command;

class ServosityProvisionBackups extends Command
{
    protected $signature = 'servosity:provision-backups';

    protected $description = 'Provision DR backup accounts for assets awaiting Servosity One registration';

    public function handle(): int
    {
        if (! ServosityConfig::isConfigured()) {
            $this->warn('Servosity is not configured.');

            return self::SUCCESS;
        }

        $service = new ServosityDeploymentService();
        $stats = $service->provisionPendingBackups();

        $this->info("Provisioned: {$stats['provisioned']}, Skipped (not ready): {$stats['skipped']}, Failed: {$stats['failed']}");

        return self::SUCCESS;
    }
}
