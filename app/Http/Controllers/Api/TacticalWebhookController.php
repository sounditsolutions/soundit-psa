<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tactical\TacticalAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TacticalWebhookController extends Controller
{
    public function __construct(
        private readonly TacticalAlertService $alertService,
    ) {}

    /**
     * Receive alert webhooks from Tactical RMM.
     *
     * Tactical sends a configurable JSON payload with template variables.
     * We expect the webhook to include: agent_id, hostname, client_name,
     * site_name, alert_message, alert_type, severity, check_name, check_output,
     * and an "event" field ("alert_failure" or "alert_resolved").
     *
     * Note: Webhook body templates in Tactical MUST be single-line JSON.
     * Pretty-printed (multi-line) templates cause double-encoding on the
     * resolved action path.
     */
    public function handle(Request $request): JsonResponse
    {
        $data = $request->json()->all();

        Log::debug('[Tactical Webhook] Received', [
            'event' => $data['event'] ?? 'unknown',
            'agent_id' => $data['agent_id'] ?? null,
            'hostname' => $data['hostname'] ?? null,
        ]);

        $event = $data['event'] ?? 'alert_failure';

        try {
            $alert = match ($event) {
                'alert_resolved' => $this->alertService->handleAlertResolved($data),
                default => $this->alertService->handleAlertFailure($data),
            };

            return response()->json([
                'status' => 'ok',
                'alert_id' => $alert?->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Tactical Webhook] Processing failed', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process webhook',
            ], 500);
        }
    }
}
