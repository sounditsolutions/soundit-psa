<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\EmailService;
use App\Services\Graph\GraphClient;
use App\Services\Graph\GraphClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GraphWebhookController extends Controller
{
    public function __construct(
        private readonly EmailService $emailService,
        private readonly GraphClient $graphClient,
    ) {}

    public function handle(Request $request)
    {
        // Validation handshake: Graph POSTs with ?validationToken to verify the endpoint
        if ($request->has('validationToken')) {
            return response($request->input('validationToken'), 200)
                ->header('Content-Type', 'text/plain');
        }

        $notifications = $request->input('value', []);

        if (empty($notifications)) {
            return response()->json(['status' => 'ok']);
        }

        $expectedClientState = Setting::getValue('graph_webhook_client_state');

        foreach ($notifications as $notification) {
            // Verify clientState matches our stored secret
            $clientState = $notification['clientState'] ?? null;
            if ($clientState !== $expectedClientState) {
                Log::warning('[GraphWebhook] clientState mismatch, skipping notification');
                continue;
            }

            $resource = $notification['resource'] ?? null;
            if (!$resource) {
                continue;
            }

            try {
                // Fetch the full message from Graph API
                $message = $this->graphClient->get($resource, [
                    '$select' => 'id,internetMessageId,conversationId,from,toRecipients,ccRecipients,subject,bodyPreview,body,hasAttachments,importance,receivedDateTime,internetMessageHeaders',
                ]);

                $this->emailService->importSingleMessage($message);

                Log::info('[GraphWebhook] Email imported', [
                    'graph_id' => $message['id'] ?? 'unknown',
                    'subject'  => $message['subject'] ?? '',
                ]);
            } catch (GraphClientException $e) {
                Log::error('[GraphWebhook] Failed to fetch message', [
                    'resource' => $resource,
                    'error'    => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                Log::error('[GraphWebhook] Failed to import message', [
                    'resource' => $resource,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // Always return 202 so Graph doesn't retry
        return response()->json(['status' => 'accepted'], 202);
    }
}
