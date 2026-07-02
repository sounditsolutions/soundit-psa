<?php

namespace App\Jobs;

use App\Models\SignalDelivery;
use App\Services\Signals\SignalHub;
use App\Services\Signals\Sinks\EmailSink;
use App\Services\Signals\Sinks\McpSink;
use App\Services\Signals\Sinks\WebhookSink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class DeliverSignal implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $deliveryId,
    ) {}

    public function handle(): void
    {
        $delivery = SignalDelivery::with(['destination', 'event'])->findOrFail($this->deliveryId);
        $destination = $delivery->destination;
        $event = $delivery->event;

        try {
            match ($destination->type) {
                'webhook' => app(WebhookSink::class)->deliver($destination, $event, $delivery),
                'email' => app(EmailSink::class)->deliver($destination, $event, $delivery),
                'mcp' => app(McpSink::class)->deliver($destination, $event, $delivery),
                default => throw new \RuntimeException("Unsupported signal destination type {$destination->type}"),
            };
        } catch (\Throwable $e) {
            $error = Str::limit($e->getMessage(), 500, '');

            $delivery->forceFill([
                'status' => 'failed',
                'error' => $error,
            ])->save();

            $destination->forceFill([
                'last_delivery_status' => 'failed',
                'last_error' => $error,
            ])->save();

            app(SignalHub::class)->emit(
                'signal.delivery_failed',
                $destination,
                "delivery {$delivery->id} failed",
                ['destination_id' => $destination->id],
                $event->id,
            );
        }
    }
}
