<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessNinjaWebhook;
use App\Models\NinjaWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NinjaWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->all();

        // Ninja sends: activityType is the broad category (e.g. "SYSTEM"),
        // statusCode is the specific event (e.g. "CLIENT_UPDATED", "NODE_CREATED")
        $statusCode = $payload['statusCode'] ?? 'unknown';
        $deviceId = $payload['deviceId']
            ?? $payload['data']['message']['params']['nodeId']
            ?? null;

        $webhook = NinjaWebhook::create([
            'activity_type' => $statusCode,
            'ninja_device_id' => $deviceId ?: null,
            'payload' => $payload,
        ]);

        Log::info('[Ninja Webhook] Received', [
            'status_code' => $statusCode,
            'device_id' => $deviceId,
        ]);

        ProcessNinjaWebhook::dispatch($webhook->id);

        return response()->json(['status' => 'ok']);
    }
}
