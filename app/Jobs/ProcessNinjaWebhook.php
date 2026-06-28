<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\NinjaWebhook;
use App\Services\Ninja\NinjaAlertService;
use App\Services\Ninja\NinjaClientException;
use App\Services\Ninja\NinjaSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessNinjaWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $webhookId,
    ) {}

    public function handle(NinjaSyncService $sync): void
    {
        $webhook = NinjaWebhook::find($this->webhookId);

        if (! $webhook || ! $webhook->isPending()) {
            return;
        }

        if (! \App\Support\NinjaConfig::isEnabled()) {
            $webhook->markSkipped('NinjaRMM integration is disabled');

            return;
        }

        $type = $webhook->activity_type;
        $deviceId = $webhook->ninja_device_id;

        // --- Device events (NODE_*) ---

        if ($type === 'NODE_DELETED') {
            if (! $deviceId) {
                $webhook->markSkipped('No device ID in NODE_DELETED payload');

                return;
            }

            $asset = Asset::where('ninja_id', $deviceId)->first();
            if ($asset) {
                // psa-u97k: a device leaving Ninja via webhook must NEVER delete/deactivate
                // the shared PSA Asset — it may still be managed by another RMM (Tactical etc.).
                // Clear ONLY Ninja's own vendor fields; the Asset persists.
                $asset->update([
                    'ninja_id' => null,
                    'ninja_url' => null,
                    'ninja_synced_at' => null,
                ]);
                Log::info('[NinjaSync] Device removed from Ninja via webhook — unlinked, asset retained', [
                    'ninja_id' => $deviceId,
                    'asset_id' => $asset->id,
                ]);
            }
            $webhook->markProcessed();

            return;
        }

        if (in_array($type, ['NODE_CREATED', 'NODE_UPDATED', 'NODE_RE_ENROLLED'])) {
            if (! $deviceId) {
                $webhook->markSkipped('No device ID in payload');

                return;
            }

            $asset = Asset::where('ninja_id', $deviceId)->first();

            if ($asset) {
                try {
                    $sync->syncDeviceDetail($asset);
                } catch (NinjaClientException $e) {
                    $webhook->markFailed("Failed to sync device detail: {$e->getMessage()}");

                    return;
                }
            } else {
                try {
                    $sync->syncDeviceFromWebhook($deviceId);
                } catch (NinjaClientException $e) {
                    $webhook->markFailed("Failed to fetch new device: {$e->getMessage()}");

                    return;
                }
            }

            $webhook->markProcessed();

            return;
        }

        // --- Condition/Alert events (TRIGGERED, RESET) ---
        if (in_array($type, ['TRIGGERED', 'RESET'])) {
            $activityType = $webhook->payload['activityType'] ?? null;

            if ($activityType === 'CONDITION') {
                $alertService = app(NinjaAlertService::class);

                if ($type === 'TRIGGERED') {
                    $alertService->handleTriggered($webhook->payload);
                } else {
                    $alertService->handleReset($webhook->payload);
                }

                $webhook->markProcessed();

                return;
            }

            $webhook->markSkipped("Non-condition alert event: {$activityType}");

            return;
        }

        // --- Org events (CLIENT_*) — Ninja calls orgs "clients" ---
        // Log for visibility but no action needed — org mapping is manual/auto-map
        if (in_array($type, ['CLIENT_CREATED', 'CLIENT_UPDATED', 'CLIENT_DELETED'])) {
            Log::info('[Ninja Webhook] Org event received', [
                'type' => $type,
                'payload' => $webhook->payload,
            ]);
            $webhook->markProcessed();

            return;
        }

        // --- End User events — not used, just log ---
        if (str_starts_with($type, 'END_USER_') || str_starts_with($type, 'ENDUSER_')) {
            $webhook->markSkipped("End user event ignored: {$type}");

            return;
        }

        // Anything else — log and skip
        $webhook->markSkipped("Unhandled activity type: {$type}");
    }

    public function failed(\Throwable $e): void
    {
        $webhook = NinjaWebhook::find($this->webhookId);

        if ($webhook) {
            $webhook->markFailed($e->getMessage());
            Log::error('[NinjaSync] Webhook processing failed', [
                'webhook_id' => $this->webhookId,
                'activity_type' => $webhook->activity_type,
                'device_id' => $webhook->ninja_device_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
