<?php

namespace App\Services\Signals\Sinks;

use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalInboxEntry;
use App\Services\Signals\SignalDeliveryState;
use GuzzleHttp\Client;
use Illuminate\Support\Str;

class McpSink
{
    private Client $http;

    public function __construct(
        ?Client $http = null,
        ?callable $resolver = null,
    ) {
        $this->http = $http ?? new Client([
            'handler' => WebhookSink::handlerStack($resolver ?? 'gethostbynamel'),
            'timeout' => 5,
            'allow_redirects' => false,
        ]);
    }

    public function deliver(SignalDestination $destination, SignalEvent $event, SignalDelivery $delivery): void
    {
        $this->pruneOldAckedRows($destination);

        SignalInboxEntry::create([
            'destination_id' => $destination->id,
            'event_id' => $event->id,
            'delivery_id' => $delivery->id,
            'payload' => $this->payload($event),
        ]);

        $this->markDelivered($destination, $delivery);

        if ($destination->wake_url) {
            $this->postDoorbell($destination);
        }
    }

    private function payload(SignalEvent $event): array
    {
        return [
            'event' => $event->type_key,
            'entity' => [
                'type' => $event->entity_type,
                'id' => $event->entity_id,
            ],
            'category' => $event->context['category'] ?? null,
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ];
    }

    private function postDoorbell(SignalDestination $destination): void
    {
        if (! $destination->wake_secret) {
            $this->recordDoorbellError($destination, 'missing wake secret');

            return;
        }

        $payload = [
            'destination_id' => $destination->id,
            'pending_count' => SignalInboxEntry::query()
                ->where('destination_id', $destination->id)
                ->whereNull('acked_at')
                ->count(),
        ];
        $body = json_encode($payload);

        try {
            $response = $this->http->request('POST', $destination->wake_url, [
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-SoundPSA-Signature' => 'sha256='.hash_hmac('sha256', $body, $destination->wake_secret),
                ],
                'timeout' => 5,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                $this->recordDoorbellError(
                    $destination,
                    trim('HTTP '.$response->getStatusCode().' '.$response->getReasonPhrase()),
                );
            }
        } catch (\Throwable $e) {
            $this->recordDoorbellError(
                $destination,
                SignalDeliveryState::safeFailureMessage($e, 'doorbell failed'),
            );
        }
    }

    private function markDelivered(SignalDestination $destination, SignalDelivery $delivery): void
    {
        SignalDeliveryState::markDelivered($destination, $delivery);
    }

    private function recordDoorbellError(SignalDestination $destination, string $error): void
    {
        $destination->forceFill([
            'last_error' => 'doorbell: '.Str::limit($error, 490, ''),
        ])->save();
    }

    private function pruneOldAckedRows(SignalDestination $destination): void
    {
        SignalInboxEntry::query()
            ->where('destination_id', $destination->id)
            ->where('acked_at', '<', now()->subDays(30))
            ->delete();
    }
}
