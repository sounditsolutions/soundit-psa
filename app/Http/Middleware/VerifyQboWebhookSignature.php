<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyQboWebhookSignature
{
    /**
     * Verify the HMAC-SHA-256 signature sent by QuickBooks Online.
     *
     * QBO signs the request body using the verifier token as key.
     * The signature is sent as base64-encoded HMAC-SHA-256 in the intuit-signature header.
     *
     * QBO validates webhook endpoints by sending a signed POST and checking for HTTP 200.
     * There is no separate challenge/response flow — normal HMAC verification handles it.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $verifierToken = Setting::getEncrypted('qbo_webhook_verifier_token');

        if (! $verifierToken) {
            Log::warning('[QBO Webhook] No verifier token configured, rejecting.');
            abort(401, 'Webhook not configured');
        }

        $signature = $request->header('intuit-signature');

        if (! $signature) {
            abort(401, 'Missing signature');
        }

        $payload = $request->getContent();
        $expected = base64_encode(hash_hmac('sha256', $payload, $verifierToken, true));

        if (! hash_equals($expected, $signature)) {
            Log::warning('[QBO Webhook] Signature mismatch');
            abort(401, 'Invalid signature');
        }

        return $next($request);
    }
}
