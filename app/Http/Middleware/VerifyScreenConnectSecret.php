<?php

namespace App\Http\Middleware;

use App\Support\ScreenConnectConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyScreenConnectSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = ScreenConnectConfig::webhookSecret();

        if (! $secret) {
            abort(403, 'ScreenConnect webhook not configured');
        }

        if ($request->route('secret') !== $secret) {
            abort(403, 'Invalid webhook secret');
        }

        return $next($request);
    }
}
