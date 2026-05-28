<?php

namespace App\Http\Middleware;

use App\Support\PlivoConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPlivoWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = PlivoConfig::get('webhook_secret');

        // If no secret is configured, allow all requests (dev mode)
        if (!$secret) {
            return $next($request);
        }

        if ($request->route('secret') !== $secret) {
            return response()->json(['error' => 'Invalid webhook secret'], 403);
        }

        return $next($request);
    }
}
