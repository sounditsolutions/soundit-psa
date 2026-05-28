<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Comet\CometAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CometWebhookController extends Controller
{
    public function __construct(
        private readonly CometAlertService $alertService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $data = $request->json()->all();

        Log::debug('[Comet Webhook] Received', ['type' => $data['Type'] ?? 'unknown']);

        try {
            $type = $data['Type'] ?? null;

            if ($type === 'job.completed') {
                $jobData = $data['Data'] ?? $data;
                $status = $jobData['Status'] ?? null;

                if ($status === 7002) {
                    $alert = $this->alertService->handleJobFailure($jobData);
                    return response()->json([
                        'status' => 'processed',
                        'alert_id' => $alert?->id,
                    ]);
                }

                if ($status === 5000) {
                    $alert = $this->alertService->handleJobSuccess($jobData);
                    return response()->json([
                        'status' => 'processed',
                        'alert_id' => $alert?->id,
                    ]);
                }

                Log::debug('[Comet Webhook] Ignoring job status', ['status' => $status]);
                return response()->json(['status' => 'ignored']);
            }

            Log::debug('[Comet Webhook] Ignoring event type', ['type' => $type]);
            return response()->json(['status' => 'ignored']);

        } catch (\Exception $e) {
            Log::error('[Comet Webhook] Error processing webhook', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
