<?php

namespace App\Http\Middleware;

use App\Support\PandaDocConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify the HMAC-SHA-256 signature PandaDoc attaches to webhook requests.
 *
 * PandaDoc signs the raw request body with the shared key configured on the
 * webhook subscription and passes the result as a hex digest in the
 * `signature` query parameter.
 *
 * @see https://developers.pandadoc.com/reference/getting-started-with-webhooks
 */
class VerifyPandaDocWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $sharedKey = PandaDocConfig::webhookSecret();

        if (! $sharedKey) {
            Log::warning('[PandaDoc Webhook] No shared key configured, rejecting.');
            abort(401, 'Webhook not configured');
        }

        $signature = $request->query('signature');

        if (! $signature || ! is_string($signature)) {
            abort(401, 'Missing signature');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $sharedKey);

        if (! hash_equals($expected, $signature)) {
            Log::warning('[PandaDoc Webhook] Signature mismatch');
            abort(401, 'Invalid signature');
        }

        return $next($request);
    }
}
