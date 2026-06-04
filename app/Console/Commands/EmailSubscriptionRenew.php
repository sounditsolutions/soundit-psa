<?php

namespace App\Console\Commands;

use App\Services\Graph\GraphClientException;
use App\Services\Graph\GraphWebhookManager;
use Illuminate\Console\Command;

class EmailSubscriptionRenew extends Command
{
    protected $signature = 'email:subscription-renew';

    protected $description = 'Ensure the Microsoft Graph webhook subscription for email is active';

    public function handle(GraphWebhookManager $manager): int
    {
        $this->info('Checking Graph webhook subscription...');

        try {
            $manager->ensureSubscription();
            $this->info('Subscription is active.');

            return self::SUCCESS;
        } catch (GraphClientException $e) {
            $this->error('Failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
