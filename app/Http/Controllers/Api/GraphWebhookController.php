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
            if (! $resource) {
                continue;
            }

            // #5829 / G-14: the fetch and the import are caught separately, so each record names
            // the step that failed. Before, one try covered both, and a GraphClientException
            // thrown inside the import was recorded as 'Failed to fetch message'.
            try {
                // Fetch the full message from Graph API
                $message = $this->graphClient->get($resource, [
                    '$select' => 'id,internetMessageId,conversationId,from,toRecipients,ccRecipients,subject,bodyPreview,body,hasAttachments,importance,receivedDateTime,internetMessageHeaders',
                ]);
            } catch (GraphClientException $e) {
                // #5731 / C-56: the HTTP status and the exception class only. The resource
                // (users/{mailbox}/messages/{id}) and the exception message stay out of the record.
                Log::error('[GraphWebhook] Failed to fetch message', [
                    'status' => $e->getHttpStatus(),
                    'exception' => $e::class,
                ]);

                continue;
            } catch (\Throwable $e) {
                // #5731 / C-56: the exception class only; its message can carry any text.
                Log::error('[GraphWebhook] Failed to fetch message', [
                    'exception' => $e::class,
                ]);

                continue;
            }

            try {
                $this->emailService->importSingleMessage($message);

                Log::info('[GraphWebhook] Email imported', [
                    'graph_id' => $message['id'] ?? 'unknown',
                    'subject' => $message['subject'] ?? '',
                ]);
            } catch (GraphClientException $e) {
                // #5829: a Graph call made by the import (an attachment read, a token refresh)
                // failed. Its status and class only, as on the fetch arm.
                Log::error('[GraphWebhook] Failed to import message', [
                    'status' => $e->getHttpStatus(),
                    'exception' => $e::class,
                ]);
            } catch (\Throwable $e) {
                // #5731 / C-56: the exception class only; an import or database failure's message
                // can carry any text, and it is not logged.
                Log::error('[GraphWebhook] Failed to import message', [
                    'exception' => $e::class,
                ]);
            }
        }

        // Always return 202 so Graph doesn't retry
        return response()->json(['status' => 'accepted'], 202);
    }
}
