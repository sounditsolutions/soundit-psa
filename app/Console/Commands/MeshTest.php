<?php

namespace App\Console\Commands;

use App\Services\Mesh\MeshClient;
use App\Support\MeshConfig;
use Illuminate\Console\Command;

class MeshTest extends Command
{
    protected $signature = 'mesh:test';

    protected $description = 'Test Mesh Email Security API connectivity';

    public function handle(): int
    {
        if (! MeshConfig::isConfigured()) {
            $this->error('Mesh is not configured. Add API key in Settings → Integrations.');
            return self::FAILURE;
        }

        $this->info('Testing Mesh API connection...');

        $client = new MeshClient([
            'api_key' => MeshConfig::get('api_key'),
            'base_url' => MeshConfig::get('base_url'),
        ]);

        if ($client->isHealthy()) {
            $this->info('Connected to Mesh Email Security successfully!');

            // Show customer count
            $customers = $client->getCustomers(size: 1);
            if (is_array($customers)) {
                $this->info('API responded with customer data.');
            }

            return self::SUCCESS;
        }

        $this->error('Failed to connect to Mesh API. Check your API key and base URL.');
        return self::FAILURE;
    }
}
