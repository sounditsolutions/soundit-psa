<?php

namespace App\Console\Commands;

use App\Services\VersionService;
use Illuminate\Console\Command;

class VersionRefresh extends Command
{
    protected $signature = 'version:refresh';

    protected $description = 'Refresh cached version info after deploy';

    public function handle(VersionService $service): int
    {
        $service->clearCache();

        $current = $service->refreshCurrent();
        $this->info("Version: {$current['commit_short']} ({$current['branch']})");
        $this->info('Commit date: '.($current['commit_date'] ?? 'unknown'));

        $updates = $service->checkForUpdates();
        if ($updates['error']) {
            $this->warn("Update check failed: {$updates['error']}");
        } else {
            $this->info("Updates: {$updates['commits_behind']} commit(s) behind origin/main");
        }

        return self::SUCCESS;
    }
}
