<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLevelWebhook;
use App\Models\LevelWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LevelWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();

        $eventType = $payload['event_type'] ?? 'unknown';
        $eventId = $payload['event_id'] ?? null;
        $deviceId = $payload['data']['id'] ?? null;

        // Deduplicate by event_id
        if ($eventId && LevelWebhook::where('event_id', $eventId)->exists()) {
            Log::info('[Level Webhook] Duplicate event ignored', ['event_id' => $eventId]);
            return response()->json(['status' => 'ok']);
        }

        $webhook = LevelWebhook::create([
            'event_type' => $eventType,
            'level_device_id' => $deviceId,
            'event_id' => $eventId,
            'payload' => $payload,
        ]);

        Log::info('[Level Webhook] Received', [
            'event_type' => $eventType,
            'event_id' => $eventId,
            'device_id' => $deviceId,
        ]);

        ProcessLevelWebhook::dispatch($webhook->id);

        return response()->json(['status' => 'ok']);
    }
}
