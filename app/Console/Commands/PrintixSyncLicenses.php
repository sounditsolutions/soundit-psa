<?php

namespace App\Console\Commands;

use App\Services\Printix\PrintixClient;
use App\Services\Printix\PrintixLicenseSyncService;
use App\Support\PrintixConfig;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheInterface;

class PrintixSyncLicenses extends Command
{
    protected $signature = 'printix:sync-licenses';

    protected $description = 'Sync Printix license counts for mapped clients';

    public function handle(): int
    {
        if (! PrintixConfig::isConfigured()) {
            $this->error('Printix is not configured.');

            return self::FAILURE;
        }

        $client = new PrintixClient(
            [
                'client_id' => PrintixConfig::get('client_id'),
                'client_secret' => PrintixConfig::get('client_secret'),
                'partner_id' => PrintixConfig::get('partner_id'),
            ],
            app(CacheInterface::class),
        );

        $service = new PrintixLicenseSyncService($client);

        $this->info('Syncing Printix licenses...');
        $result = $service->syncLicenses();

        $this->info("Done: {$result->summary()}");

        if ($result->errors > 0) {
            foreach ($result->errorMessages as $msg) {
                $this->warn("  - {$msg}");
            }
        }

        return $result->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
