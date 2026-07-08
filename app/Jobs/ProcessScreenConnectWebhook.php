<?php

namespace App\Jobs;

use App\Models\ScreenConnectWebhook;
use App\Services\ScreenConnect\ScreenConnectSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessScreenConnectWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $webhookId,
    ) {}

    public function handle(ScreenConnectSyncService $sync): void
    {
        $webhook = ScreenConnectWebhook::find($this->webhookId);

        if (! $webhook || ! $webhook->isPending()) {
            return;
        }

        $result = $sync->processWebhook($webhook->payload);

        Log::debug('[ScreenConnect] Webhook processed', [
            'webhook_id' => $webhook->id,
            'event_type' => $webhook->event_type,
            'result' => $result,
        ]);

        if (str_starts_with($result, 'Skipped') || str_starts_with($result, 'No matching')) {
            $webhook->markSkipped($result);
        } else {
            $webhook->markProcessed();
        }
    }

    public function failed(\Throwable $e): void
    {
        $webhook = ScreenConnectWebhook::find($this->webhookId);

        if ($webhook) {
            $webhook->markFailed($e->getMessage());
            Log::warning('[ScreenConnect] Webhook processing failed', [
                'webhook_id' => $this->webhookId,
                'event_type' => $webhook->event_type,
                'session_id' => $webhook->session_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
