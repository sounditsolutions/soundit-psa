<?php

namespace App\Services\Signals;

use App\Jobs\RouteSignalEvent;
use App\Models\SignalEvent;
use App\Services\Wiki\Mining\WikiRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class SignalHub
{
    private const ALLOWED_CONTEXT_KEYS = [
        'category',
        'priority',
        'client_id',
        'destination_id',
    ];

    public function __construct(
        private readonly WikiRedactor $redactor,
    ) {}

    public function emit(
        string $typeKey,
        ?Model $entity,
        string $summary,
        array $context = [],
        ?int $originEventId = null,
    ): ?SignalEvent {
        try {
            if (! SignalEventTypes::has($typeKey)) {
                Log::warning('[Signals] Unknown event type', ['type_key' => $typeKey]);

                return null;
            }

            $event = SignalEvent::create([
                'type_key' => $typeKey,
                'entity_type' => $entity?->getMorphClass(),
                'entity_id' => $entity?->getKey(),
                'summary' => $this->sanitizeSummary($summary),
                'context' => $this->sanitizeContext($context),
                'origin_event_id' => $originEventId,
                'occurred_at' => now(),
            ]);

            RouteSignalEvent::dispatch($event->id);

            return $event;
        } catch (\Throwable $e) {
            Log::error('[Signals] Emit failed', [
                'type_key' => $typeKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function sanitizeSummary(string $summary): string
    {
        if ($this->redactor->scan($summary) !== []) {
            return '[detail withheld]';
        }

        return mb_substr($summary, 0, 500);
    }

    private function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (! in_array($key, self::ALLOWED_CONTEXT_KEYS, true) || ! is_scalar($value)) {
                continue;
            }

            if (is_string($value) && $this->redactor->scan($value) !== []) {
                $sanitized[$key] = '[detail withheld]';

                continue;
            }

            $sanitized[$key] = $value;
        }

        while ($sanitized !== [] && strlen(json_encode($sanitized)) > 2048) {
            array_pop($sanitized);
        }

        return $sanitized;
    }
}
