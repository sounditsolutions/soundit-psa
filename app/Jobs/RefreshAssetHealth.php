<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\AssetHealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Recompute an asset's health with an AI-written narrative.
 *
 * Dispatched after the asset page responds (so the AI call never blocks the
 * render) to upgrade a deterministic on-view score into an AI explanation. The
 * daily assets:refresh-health command does the same in bulk. Pessimistic lock
 * prevents two concurrent refreshes racing on the same asset.
 */
class RefreshAssetHealth implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        private readonly int $assetId,
    ) {}

    public function handle(AssetHealthService $service): void
    {
        DB::transaction(function () use ($service) {
            $asset = Asset::withTrashed()->where('id', $this->assetId)->lockForUpdate()->first();

            if (! $asset) {
                return;
            }

            $service->refresh($asset, useAi: true);
        });
    }
}
