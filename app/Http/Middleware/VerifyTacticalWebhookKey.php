<?php

namespace App\Http\Middleware;

use App\Support\TacticalConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyTacticalWebhookKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = TacticalConfig::get('webhook_key');

        if (! $configuredKey) {
            Log::warning('[Tactical Webhook] Webhook key not configured');

            return response()->json(['error' => 'Webhook not configured'], 401);
        }

        // Accept key via Bearer token or X-Webhook-Key header
        $providedKey = null;

        $authHeader = $request->header('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $providedKey = substr($authHeader, 7);
        }

        if (! $providedKey) {
            $providedKey = $request->header('X-Webhook-Key');
        }

        if (! $providedKey || ! hash_equals($configuredKey, $providedKey)) {
            Log::warning('[Tactical Webhook] Invalid webhook key', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
