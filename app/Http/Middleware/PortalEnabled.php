<?php

namespace App\Http\Middleware;

use App\Support\PortalConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PortalEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! PortalConfig::isEnabled()) {
            abort(404);
        }

        return $next($request);
    }
}
