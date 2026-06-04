<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\Servosity\ServosityDeploymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Retry provisioning for a single asset with exponential backoff.
 *
 * Starts at 30 seconds, doubles each attempt (30s → 60s → 120s → ...),
 * caps at 1 hour. Runs indefinitely until the asset is provisioned,
 * disabled, or deleted. The hourly cron is a redundant safety net.
 */
class ServosityProvisionAsset implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;            // Unlimited retries

    public int $timeout = 120;        // Per-attempt timeout

    private const INITIAL_DELAY = 30;     // seconds

    private const MAX_DELAY = 3600;       // 1 hour

    public function __construct(
        private readonly int $assetId,
    ) {}

    public function handle(): void
    {
        $asset = Asset::find($this->assetId);

        if (! $asset || ! $asset->servosity_backup_enabled || $asset->servosity_dr_backup_id) {
            // Already provisioned, disabled, or deleted — stop
            return;
        }

        $service = new ServosityDeploymentService;
        $result = $service->provisionSingleAsset($asset);

        if ($result === 'provisioned') {
            Log::info('[Servosity] Asset provisioned via retry job', [
                'asset_id' => $asset->id,
                'hostname' => $asset->hostname,
                'attempt' => $this->attempts(),
            ]);

            return;
        }

        // Calculate next delay: 30s × 2^(attempt-1), capped at 1 hour
        $delay = min(self::INITIAL_DELAY * (2 ** ($this->attempts() - 1)), self::MAX_DELAY);

        if ($result === 'failed') {
            Log::warning('[Servosity] Provision attempt failed, retrying in '.$delay.'s', [
                'asset_id' => $asset->id,
                'hostname' => $asset->hostname,
                'attempt' => $this->attempts(),
            ]);
        }

        $this->release($delay);
    }
}
