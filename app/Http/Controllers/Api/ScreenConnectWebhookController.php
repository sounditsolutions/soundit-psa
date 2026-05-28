<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessScreenConnectWebhook;
use App\Models\ScreenConnectWebhook;
use App\Services\ScreenConnect\ScreenConnectSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScreenConnectWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();

        // Extract key fields (handles both native {*:json} and legacy flat format)
        $normalized = ScreenConnectSyncService::normalizePayload($payload);
        $eventType = $normalized['event_type'] ?? 'unknown';
        $sessionId = $normalized['session_id'] ?? null;

        $webhook = ScreenConnectWebhook::create([
            'event_type' => $eventType,
            'session_id' => $sessionId,
            'payload' => $payload,
        ]);

        Log::debug('[ScreenConnect] Webhook received', [
            'event_type' => $eventType,
            'session_id' => $sessionId,
            'webhook_id' => $webhook->id,
        ]);

        ProcessScreenConnectWebhook::dispatch($webhook->id);

        return response()->json(['status' => 'ok']);
    }
}
