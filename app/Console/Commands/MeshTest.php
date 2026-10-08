<?php

namespace App\Console\Commands;

use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshClientException;
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

        // The container's MeshClient is built from the same MeshConfig
        // values (AppServiceProvider); resolving it is the test seam (G-5).
        $client = app(MeshClient::class);

        if ($client->isHealthy()) {
            $this->info('Connected to Mesh Email Security successfully!');

            // Show customer count. #5882: a failure here is reported by
            // statusPhrase() only. Left uncaught, the console renderer would
            // print the chained Guzzle exception's message (request URI with
            // user-info and host, vendor body summary).
            try {
                $customers = $client->getCustomers(size: 1);
            } catch (MeshClientException $e) {
                $this->error('Connected, but '.$e->statusPhrase('the customer read').'.');

                return self::FAILURE;
            }
            if (is_array($customers)) {
                $this->info('API responded with customer data.');
            }

            return self::SUCCESS;
        }

        $this->error('Failed to connect to Mesh API. Check your API key and base URL.');

        return self::FAILURE;
    }
}
