<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPandaDocWebhook;
use App\Models\PandaDocWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives PandaDoc webhook notifications. Signature is verified upstream by
 * VerifyPandaDocWebhookSignature. Store-then-dispatch (mirrors QBO): persist
 * each event, then hand off to a queued job so processing never blocks the 200.
 *
 * PandaDoc delivers a JSON array of event objects, each shaped roughly as:
 *   { "event": "document_state_changed", "data": { "id", "status", "name" } }
 */
class PandaDocWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $events = $request->json()->all();

        // A single-event delivery may arrive as one object rather than an array.
        if (isset($events['event']) || isset($events['data'])) {
            $events = [$events];
        }

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $data = $event['data'] ?? [];
            $documentId = $data['id'] ?? null;

            $webhook = PandaDocWebhook::create([
                'event_type' => (string) ($event['event'] ?? 'unknown'),
                'document_id' => $documentId,
                'document_status' => $data['status'] ?? null,
                'payload' => $event,
            ]);

            ProcessPandaDocWebhook::dispatch($webhook->id);
        }

        Log::debug('[PandaDoc Webhook] Received '.count($events).' event(s)');

        return response()->json(['status' => 'ok']);
    }
}
