<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SigningResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('sign/*')) {
            return $next($request);
        }
        try {
            $response = $next($request);
        } catch (Throwable $error) {
            $status = method_exists($error, 'getStatusCode') ? $error->getStatusCode() : 500;
            $response = response()->view('signing.neutral', [], $status);
        }
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
