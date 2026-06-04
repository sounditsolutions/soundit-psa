<?php

namespace App\Console\Commands;

use App\Services\Stripe\StripeClient;
use App\Support\StripeConfig;
use Illuminate\Console\Command;

class StripeTest extends Command
{
    protected $signature = 'stripe:test';

    protected $description = 'Test Stripe API connectivity';

    public function handle(): int
    {
        if (! StripeConfig::isConfigured()) {
            $this->error('Stripe is not configured. Add API key in Settings → Integrations.');

            return self::FAILURE;
        }

        $this->info('Testing Stripe API connection...');
        $this->info('Mode: '.StripeConfig::get('mode'));

        $client = new StripeClient(['secret_key' => StripeConfig::get('secret_key')]);

        if ($client->isHealthy()) {
            $this->info('Connected to Stripe successfully!');

            return self::SUCCESS;
        }

        $this->error('Failed to connect to Stripe. Check your API key.');

        return self::FAILURE;
    }
}
