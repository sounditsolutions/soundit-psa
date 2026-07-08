<?php

namespace App\Http\Middleware;

use App\Support\LevelConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyLevelWebhookSignature
{
    /**
     * Verify the HMAC-SHA-256 signature sent by Level RMM.
     *
     * Level signs the request body with the shared secret using SHA-256.
     * The signature is sent as "sha256=<hex_digest>" in X-Level-Signature.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = LevelConfig::get('webhook_secret');

        // If no secret configured, allow all requests (dev mode)
        if (! $secret) {
            return $next($request);
        }

        $signature = $request->header('X-Level-Signature');

        if (! $signature) {
            Log::warning('[Level Webhook] Missing X-Level-Signature header');
            abort(403, 'Missing webhook signature');
        }

        $body = $request->getContent();
        $expected = 'sha256='.hash_hmac('sha256', $body, $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('[Level Webhook] Signature mismatch', [
                'received' => $signature,
                'expected' => $expected,
            ]);
            abort(403, 'Invalid webhook signature');
        }

        return $next($request);
    }
}
