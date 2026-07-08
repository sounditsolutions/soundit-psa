<?php

namespace App\Jobs;

use App\Enums\AutoPushMode;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\NotificationService;
use App\Services\Qbo\QboSyncService;
use App\Services\Stripe\StripeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PushInvoiceToBilling implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        private readonly Invoice $invoice,
    ) {}

    public function handle(NotificationService $notificationService): void
    {
        $invoice = $this->invoice->fresh(['client', 'profile']);

        if (! $invoice || $invoice->status !== InvoiceStatus::Draft) {
            Log::info("[AutoPush] Skipping invoice {$this->invoice->id} — status is no longer Draft");

            return;
        }

        $mode = $invoice->profile?->auto_push_mode;
        if (! $mode) {
            return;
        }

        $client = $invoice->client;
        $backend = null;

        try {
            if ($client->stripe_customer_id) {
                $backend = 'Stripe';
                $sendEmail = $mode === AutoPushMode::PushAndSend;
                app(StripeSyncService::class)->pushInvoiceToStripe($invoice, $sendEmail);
            } elseif ($client->qbo_customer_id) {
                $backend = 'QBO';
                app(QboSyncService::class)->pushInvoiceToQbo($invoice);
            } else {
                Log::warning("[AutoPush] Invoice {$invoice->invoice_number}: client \"{$client->name}\" not mapped to any billing backend");
                $notificationService->notifyInvoicePushFailed(
                    $invoice,
                    'None',
                    "Client \"{$client->name}\" is not mapped to QBO or Stripe.",
                );

                return;
            }

            Log::info("[AutoPush] Invoice {$invoice->invoice_number} pushed to {$backend}".($mode === AutoPushMode::PushAndSend ? ' (sent)' : ''));
        } catch (\Throwable $e) {
            Log::error("[AutoPush] Failed to push invoice {$invoice->invoice_number} to {$backend}: {$e->getMessage()}");

            // Only notify on final attempt to avoid duplicate notifications
            if ($this->attempts() >= $this->tries) {
                $notificationService->notifyInvoicePushFailed($invoice, $backend, $e->getMessage());
            }

            throw $e; // Re-throw so the job retries
        }
    }
}
