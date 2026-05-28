<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\Client;
use App\Models\LevelWebhook;
use App\Services\Level\LevelSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessLevelWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $webhookId,
    ) {}

    public function handle(LevelSyncService $sync): void
    {
        $webhook = LevelWebhook::find($this->webhookId);

        if (!$webhook || !$webhook->isPending()) {
            return;
        }

        $type = $webhook->event_type;
        $data = $webhook->payload['data'] ?? [];
        $deviceId = $webhook->level_device_id;

        // --- Device deleted ---
        if ($type === 'device_deleted') {
            $asset = $deviceId ? Asset::where('level_id', $deviceId)->first() : null;
            if ($asset) {
                $asset->update(['is_active' => false]);
                $asset->delete();
                Log::info('[LevelSync] Device deleted via webhook', [
                    'level_id' => $deviceId,
                    'asset_id' => $asset->id,
                ]);
            }
            $webhook->markProcessed();
            return;
        }

        // --- Device created / updated ---
        if (in_array($type, ['device_created', 'device_updated'])) {
            if (!$deviceId) {
                $webhook->markSkipped('No device ID in payload');
                return;
            }

            // Resolve the client from the device's group_id
            $groupId = $data['group_id'] ?? null;
            $client = $groupId ? Client::where('level_group_id', $groupId)->first() : null;

            if (!$client) {
                // Device belongs to an unmapped group — log and skip
                $webhook->markSkipped("Group {$groupId} not mapped to a client");
                return;
            }

            $sync->upsertDeviceFromData($data, $client);
            $webhook->markProcessed();
            return;
        }

        // --- Alert events (future: could create tickets) ---
        if (in_array($type, ['alert_active', 'alert_resolved'])) {
            Log::info('[Level Webhook] Alert event received', [
                'type' => $type,
                'device_id' => $deviceId,
            ]);
            $webhook->markProcessed();
            return;
        }

        // --- Group events (manual mapping, just log) ---
        if (in_array($type, ['group_created', 'group_updated', 'group_deleted'])) {
            Log::info('[Level Webhook] Group event received', [
                'type' => $type,
                'payload' => $webhook->payload,
            ]);
            $webhook->markProcessed();
            return;
        }

        $webhook->markSkipped("Unhandled event type: {$type}");
    }

    public function failed(\Throwable $e): void
    {
        $webhook = LevelWebhook::find($this->webhookId);

        if ($webhook) {
            $webhook->markFailed($e->getMessage());
            Log::error('[LevelSync] Webhook processing failed', [
                'webhook_id' => $this->webhookId,
                'event_type' => $webhook->event_type,
                'device_id' => $webhook->level_device_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
