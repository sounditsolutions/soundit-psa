<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\Ninja\NinjaBackupSyncService;
use Illuminate\Console\Command;

class NinjaSyncBackup extends Command
{
    protected $signature = 'ninja:sync-backup';
    protected $description = 'Sync backup storage usage and license counts from NinjaRMM';

    public function handle(NinjaBackupSyncService $sync): int
    {
        $mappedCount = Client::whereNotNull('ninja_org_id')->count();
        if ($mappedCount === 0) {
            $this->warn('No clients are mapped to NinjaRMM organizations.');

            return self::SUCCESS;
        }

        $this->info("Syncing backup usage for {$mappedCount} mapped client(s)...");

        $result = $sync->syncBackupUsage();

        $this->newLine();
        $this->info("Done: {$result->summary()}");

        foreach ($result->errorMessages as $error) {
            $this->warn("  Error: {$error}");
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
