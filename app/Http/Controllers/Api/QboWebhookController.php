<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessQboWebhook;
use App\Models\QboWebhook;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class QboWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->json()->all();
        $ourRealmId = Setting::getValue('qbo_realm_id');

        foreach ($payload['eventNotifications'] ?? [] as $notification) {
            if ($notification['realmId'] !== $ourRealmId) {
                Log::debug('[QBO Webhook] Skipping notification for realm '.$notification['realmId']);

                continue;
            }

            foreach ($notification['dataChangeEvent']['entities'] ?? [] as $entity) {
                if ($entity['name'] !== 'Invoice') {
                    continue;
                }

                // Basic dedup: skip if same entity+operation received within last 60s
                $exists = QboWebhook::where('entity_id', $entity['id'])
                    ->where('operation', $entity['operation'])
                    ->where('created_at', '>', now()->subMinutes(1))
                    ->exists();

                if ($exists) {
                    Log::debug('[QBO Webhook] Dedup: skipping duplicate for entity '.$entity['id']);

                    continue;
                }

                $webhook = QboWebhook::create([
                    'entity_type' => $entity['name'],
                    'entity_id' => $entity['id'],
                    'operation' => $entity['operation'],
                    'realm_id' => $notification['realmId'],
                    'payload' => $entity,
                ]);

                ProcessQboWebhook::dispatch($webhook->id);
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
