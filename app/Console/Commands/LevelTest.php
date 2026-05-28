<?php

namespace App\Console\Commands;

use App\Services\Level\LevelClient;
use App\Services\Level\LevelClientException;
use Illuminate\Console\Command;

class LevelTest extends Command
{
    protected $signature = 'level:test';
    protected $description = 'Test Level RMM API connectivity';

    public function handle(LevelClient $level): int
    {
        $this->info('Testing Level RMM API connection...');

        try {
            $groups = $level->getGroups();
            $this->info('Connected successfully!');
            $this->info('Groups found: ' . count($groups));

            if (count($groups) <= 10) {
                foreach ($groups as $group) {
                    $deviceCount = $group['device_count'] ?? 0;
                    $this->line("  - [{$group['id']}] {$group['name']} ({$deviceCount} devices)");
                }
            } else {
                foreach (array_slice($groups, 0, 5) as $group) {
                    $deviceCount = $group['device_count'] ?? 0;
                    $this->line("  - [{$group['id']}] {$group['name']} ({$deviceCount} devices)");
                }
                $this->line('  ... and ' . (count($groups) - 5) . ' more');
            }

            return self::SUCCESS;
        } catch (LevelClientException $e) {
            $this->error('Connection failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
