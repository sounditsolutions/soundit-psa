<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTacticalWebhook;
use App\Models\TacticalWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class TacticalWebhookController extends Controller
{
    /**
     * Max accepted webhook body size (bytes). Tactical alert payloads are small
     * single-line JSON; anything larger is rejected to bound storage + parse cost.
     */
    private const MAX_BODY_BYTES = 65_536;

    /**
     * Receive alert webhooks from Tactical RMM.
     *
     * Tactical's outbound URLAction has an 8s timeout and NO retry, and can
     * double-deliver (proxy/retry) with a replayable static key. So we:
     *   1. validate shape + size,
     *   2. dedup via a unique idempotency key (drop replays),
     *   3. persist + queue async processing,
     *   4. ack fast (204) — the queued ProcessTacticalWebhook does the real work.
     *
     * Tactical sends a configurable JSON payload with template variables, incl.
     * agent_id, hostname, severity, check_name, check_output, alert_id, and an
     * "event" field ("alert_failure" or "alert_resolved").
     *
     * Note: Webhook body templates in Tactical MUST be single-line JSON.
     */
    public function handle(Request $request): Response
    {
        // Bound the body size before doing anything else.
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            throw ValidationException::withMessages([
                'payload' => 'Webhook payload exceeds the maximum allowed size.',
            ]);
        }

        $data = $request->json()->all();

        // Validate shape: a non-empty string "event" is required. We intentionally do
        // NOT restrict the event to a known set here — unknown events are still
        // persisted and then skipped by the job, preserving durability/auditability.
        $validator = validator($data, [
            'event' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $event = $data['event'];
        $agentId = $data['agent_id'] ?? null;

        // Idempotency key: prefer the Tactical alert id, scoped by event so a
        // failure and its later resolve are distinct rows but a re-delivery of the
        // same event collides. Falls back to a hash of the canonical payload.
        $dedupKey = $this->dedupKey($event, $data);

        $webhook = TacticalWebhook::firstOrCreate(
            ['dedup_key' => $dedupKey],
            [
                'event' => $event,
                'agent_id' => $agentId ? (string) $agentId : null,
                'payload' => $data,
                'status' => 'pending',
            ],
        );

        // Only dispatch for genuinely new rows — a replay collides on dedup_key and is dropped.
        if ($webhook->wasRecentlyCreated) {
            ProcessTacticalWebhook::dispatch($webhook->id);
        }

        // Ack fast; the queued job does the work.
        return response()->noContent();
    }

    /**
     * Build the replay-protection key. Uses the Tactical alert id when present
     * (the field TacticalAlertService keys on), otherwise a sha256 of the
     * canonicalized payload so identical re-deliveries collide.
     */
    private function dedupKey(string $event, array $data): string
    {
        $alertId = $data['alert_id'] ?? null;

        if ($alertId !== null && $alertId !== '') {
            return "{$event}:{$alertId}";
        }

        // Recursively sort so a re-delivery with reordered keys — at any nesting depth —
        // canonicalizes to the same hash and still collides on the unique dedup_key.
        $canonical = $data;
        $this->recursiveKsort($canonical);

        return $event.':'.hash('sha256', json_encode($canonical));
    }

    /**
     * Recursively sort an array by key in place, so structurally-identical payloads
     * with differently-ordered keys serialize identically.
     */
    private function recursiveKsort(array &$data): void
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->recursiveKsort($value);
            }
        }
        unset($value);

        ksort($data);
    }
}
