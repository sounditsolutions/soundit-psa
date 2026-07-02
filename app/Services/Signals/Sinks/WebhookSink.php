<?php

namespace App\Services\Signals\Sinks;

use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Services\Signals\SignalDeliveryState;
use App\Services\Technician\Notify\TeamsNotifier;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;

class WebhookSink
{
    private Client $http;

    public function __construct(
        ?Client $http = null,
        ?callable $resolver = null,
    ) {
        $this->http = $http ?? new Client([
            'handler' => self::handlerStack($resolver ?? 'gethostbynamel'),
            'timeout' => 5,
            'allow_redirects' => false,
        ]);
    }

    public static function handlerStack(callable $resolver): HandlerStack
    {
        $stack = HandlerStack::create();
        $stack->push(TeamsNotifier::ssrfPinMiddleware($resolver), 'technician_webhook_ssrf_pin');

        return $stack;
    }

    public function deliver(SignalDestination $destination, SignalEvent $event, SignalDelivery $delivery): void
    {
        if (! $destination->address) {
            $this->markFailed($destination, $delivery, 'webhook address missing');

            throw new \RuntimeException('webhook address missing');
        }

        $attempt = 0;
        while (true) {
            try {
                $response = $this->http->request('POST', $destination->address, [
                    'json' => $this->payload($event, $delivery),
                    'timeout' => 5,
                    'allow_redirects' => false,
                    'http_errors' => false,
                ]);
            } catch (ConnectException $e) {
                if ($attempt === 0) {
                    $attempt++;

                    continue;
                }

                $this->markFailed($destination, $delivery, 'connect failed');

                throw new \RuntimeException('connect failed', previous: $e);
            }

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $this->markDelivered($destination, $delivery);

                return;
            }

            if ($status >= 500 && $attempt === 0) {
                $attempt++;

                continue;
            }

            $error = trim("HTTP {$status} ".$response->getReasonPhrase());
            $this->markFailed($destination, $delivery, $error);

            throw new \RuntimeException($error);
        }
    }

    private function payload(SignalEvent $event, SignalDelivery $delivery): array
    {
        return [
            'event' => $event->type_key,
            'summary' => $event->summary,
            'entity' => [
                'type' => $event->entity_type,
                'id' => $event->entity_id,
            ],
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'delivery_id' => $delivery->id,
        ];
    }

    private function markDelivered(SignalDestination $destination, SignalDelivery $delivery): void
    {
        SignalDeliveryState::markDelivered($destination, $delivery);
    }

    private function markFailed(SignalDestination $destination, SignalDelivery $delivery, string $error): void
    {
        SignalDeliveryState::markFailed(
            $destination,
            $delivery,
            SignalDeliveryState::safeFailureMessage($error, 'webhook failed'),
        );
    }
}
