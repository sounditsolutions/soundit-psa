<?php

namespace App\Http\Middleware;

use App\Support\CometConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyCometWebhookKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $storedKey = CometConfig::get('comet_webhook_key');

        if (!$storedKey) {
            Log::warning('[Comet Webhook] No webhook key configured');
            return response()->json(['error' => 'Webhook not configured'], 401);
        }

        $providedKey = null;

        $authHeader = $request->header('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $providedKey = substr($authHeader, 7);
        }

        if (!$providedKey) {
            $providedKey = $request->header('X-Webhook-Key');
        }

        if (!$providedKey || !hash_equals($storedKey, $providedKey)) {
            Log::warning('[Comet Webhook] Invalid webhook key', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
