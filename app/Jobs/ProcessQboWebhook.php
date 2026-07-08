<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\QboWebhook;
use App\Services\Qbo\QboSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessQboWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $webhookId,
    ) {}

    public function handle(QboSyncService $qboSyncService): void
    {
        $webhook = QboWebhook::find($this->webhookId);

        if (! $webhook || ! $webhook->isPending()) {
            return;
        }

        // Find matching local invoice
        $invoice = Invoice::where('qbo_invoice_id', $webhook->entity_id)->first();

        if (! $invoice) {
            $webhook->markSkipped('No matching local invoice for QBO ID '.$webhook->entity_id);

            return;
        }

        try {
            $qboSyncService->syncInvoiceStatusFromQbo($invoice);
            $webhook->markProcessed();
            Log::warning("[QBO Webhook] Synced invoice #{$invoice->invoice_number} from QBO ID {$webhook->entity_id}");
        } catch (\Throwable $e) {
            $webhook->markFailed($e->getMessage());
            Log::error("[QBO Webhook] Failed to sync QBO ID {$webhook->entity_id}: {$e->getMessage()}");

            if ($webhook->isPending()) {
                throw $e; // Still has retries left — let queue retry
            }
        }
    }
}
