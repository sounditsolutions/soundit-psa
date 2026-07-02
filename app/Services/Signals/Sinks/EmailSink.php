<?php

namespace App\Services\Signals\Sinks;

use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Services\EmailService;
use App\Services\Signals\SignalDeliveryState;

class EmailSink
{
    private const MAX_EMAILS_PER_DESTINATION_PER_HOUR = 10;

    public function __construct(
        private readonly EmailService $email,
    ) {}

    public function deliver(SignalDestination $destination, SignalEvent $event, SignalDelivery $delivery): void
    {
        if ($this->rateLimited($destination)) {
            $this->markSuppressed($destination, $delivery, 'email-rate-limit');

            return;
        }

        try {
            $this->email->sendNew(
                $destination->address,
                "Sound PSA signal: {$event->type_key}",
                $this->body($event, $delivery),
                null,
                null,
                null,
            );
        } catch (\Throwable $e) {
            $this->markFailed($destination, $delivery, 'email failed');

            throw new \RuntimeException('email failed', previous: $e);
        }

        $this->markDelivered($destination, $delivery);
    }

    private function rateLimited(SignalDestination $destination): bool
    {
        return SignalDelivery::query()
            ->where('destination_id', $destination->id)
            ->where('status', 'delivered')
            ->where('created_at', '>', now()->subHour())
            ->count() >= self::MAX_EMAILS_PER_DESTINATION_PER_HOUR;
    }

    private function body(SignalEvent $event, SignalDelivery $delivery): string
    {
        $entity = $event->entity_type !== null && $event->entity_id !== null
            ? "{$event->entity_type} #{$event->entity_id}"
            : 'none';

        return implode("\n", [
            "Event: {$event->type_key}",
            "Summary: {$event->summary}",
            "Entity: {$entity}",
            'Occurred: '.$event->occurred_at->toIso8601String(),
            "Delivery: {$delivery->id}",
        ]);
    }

    private function markDelivered(SignalDestination $destination, SignalDelivery $delivery): void
    {
        SignalDeliveryState::markDelivered($destination, $delivery);
    }

    private function markSuppressed(SignalDestination $destination, SignalDelivery $delivery, string $reason): void
    {
        $delivery->forceFill([
            'status' => 'suppressed',
            'error' => $reason,
        ])->save();

        $destination->forceFill([
            'last_delivery_status' => 'suppressed',
            'last_error' => $reason,
        ])->save();
    }

    private function markFailed(SignalDestination $destination, SignalDelivery $delivery, string $error): void
    {
        SignalDeliveryState::markFailed($destination, $delivery, $error);
    }
}
