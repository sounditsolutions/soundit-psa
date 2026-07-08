<?php

namespace App\Console\Commands;

use App\Services\Comet\CometBackupSyncService;
use App\Services\Comet\CometClient;
use App\Support\CometConfig;
use Illuminate\Console\Command;

class CometSyncBackup extends Command
{
    protected $signature = 'comet:sync-backup
                            {--client= : Sync a single client by ID}
                            {--dry-run : Log changes without writing}';

    protected $description = 'Sync Comet Backup storage usage and license counts';

    public function handle(): int
    {
        if (! CometConfig::isConfigured()) {
            $this->warn('Comet Backup is not configured. Set comet_server_url, comet_admin_user, and comet_admin_password in Settings.');

            return self::FAILURE;
        }

        $clientId = $this->option('client') !== null ? (int) $this->option('client') : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('[dry-run] No changes will be written.');
        }

        if ($clientId !== null) {
            $this->info("Syncing Comet Backup usage for client ID {$clientId}...");
        } else {
            $this->info('Syncing Comet Backup usage for all mapped clients...');
        }

        $service = new CometBackupSyncService(new CometClient);
        $result = $service->syncBackupUsage($clientId, $dryRun);

        $this->newLine();
        $this->info("Done: {$result->summary()}");

        foreach ($result->errorMessages as $error) {
            $this->warn("  Error: {$error}");
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
