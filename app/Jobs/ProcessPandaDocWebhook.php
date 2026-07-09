<?php

namespace App\Jobs;

use App\Models\PandaDocWebhook;
use App\Services\PandaDoc\PandaDocService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Processes a stored PandaDoc webhook event: reconciles the referenced
 * document's status into the local record (downloading the signed PDF on
 * completion). Mirrors ProcessQboWebhook.
 */
class ProcessPandaDocWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $webhookId,
    ) {}

    public function handle(PandaDocService $service): void
    {
        $webhook = PandaDocWebhook::find($this->webhookId);

        if (! $webhook || ! $webhook->isPending()) {
            return;
        }

        if (! $webhook->document_id) {
            $webhook->markSkipped('Event carried no document id.');

            return;
        }

        try {
            $service->handleWebhookEvent($webhook->payload['data'] ?? []);
            $webhook->markProcessed();
            Log::info("[PandaDoc Webhook] Processed {$webhook->event_type} for {$webhook->document_id}");
        } catch (\Throwable $e) {
            $webhook->markFailed($e->getMessage());
            Log::error("[PandaDoc Webhook] Failed for {$webhook->document_id}: {$e->getMessage()}");

            if ($webhook->isPending()) {
                throw $e; // Retries remain — let the queue retry.
            }
        }
    }
}
