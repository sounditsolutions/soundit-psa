<?php

namespace App\Console\Commands;

use App\Services\Ninja\NinjaClient;
use App\Services\Ninja\NinjaClientException;
use Illuminate\Console\Command;

class NinjaTest extends Command
{
    protected $signature = 'ninja:test';
    protected $description = 'Test NinjaRMM API connectivity';

    public function handle(NinjaClient $ninja): int
    {
        $this->info('Testing NinjaRMM API connection...');

        try {
            $orgs = $ninja->getOrganizations();
            $this->info('Connected successfully!');
            $this->info('Organizations found: ' . count($orgs));

            if (count($orgs) <= 10) {
                foreach ($orgs as $org) {
                    $this->line("  - [{$org['id']}] {$org['name']}");
                }
            } else {
                foreach (array_slice($orgs, 0, 5) as $org) {
                    $this->line("  - [{$org['id']}] {$org['name']}");
                }
                $this->line('  ... and ' . (count($orgs) - 5) . ' more');
            }

            return self::SUCCESS;
        } catch (NinjaClientException $e) {
            $this->error('Connection failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
