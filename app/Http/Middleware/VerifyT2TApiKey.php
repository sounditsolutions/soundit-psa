<?php

namespace App\Http\Middleware;

use App\Support\CwAuthHelper;
use App\Support\T2TConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyT2TApiKey
{
    /**
     * Validate ConnectWise Manage Basic auth format used by Tier2Tickets.
     *
     * CW format: Authorization: Basic base64("CompanyId+PublicKey:PrivateKey")
     * We only validate the PrivateKey portion (everything after the last colon).
     *
     * Returns a 401 with WWW-Authenticate header to trigger CW-standard auth
     * challenge/response. First request may arrive without auth; T2T retries
     * with credentials after receiving 401 + WWW-Authenticate.
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::debug('[T2T] Incoming request', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => collect($request->headers->all())->map(fn ($v) => $v[0] ?? $v)->except(['cookie'])->all(),
            'ip' => $request->ip(),
        ]);

        $apiKey = T2TConfig::get('api_key');

        if (! $apiKey) {
            Log::warning('[T2T] T2T API key not configured');

            return $this->unauthorized('API not configured');
        }

        $privateKey = CwAuthHelper::extractPrivateKey($request->header('Authorization', ''));

        if ($privateKey === null) {
            Log::warning('[T2T] Missing or invalid Authorization header', [
                'ip' => $request->ip(),
            ]);

            return $this->unauthorized('Missing authorization');
        }

        if (! hash_equals($apiKey, $privateKey)) {
            Log::warning('[T2T] API key mismatch', [
                'ip' => $request->ip(),
            ]);

            return $this->unauthorized('Invalid API key');
        }

        return $next($request);
    }

    /**
     * Return a proper JSON 401 with WWW-Authenticate header.
     *
     * CW-compatible clients (including T2T) expect:
     * - HTTP 401 status
     * - WWW-Authenticate: Basic header to trigger credential retry
     * - Small JSON body (not HTML)
     */
    private function unauthorized(string $message): Response
    {
        return response()->json(
            ['code' => 'Unauthorized', 'message' => $message],
            401,
            ['WWW-Authenticate' => 'Basic realm="ConnectWise API"'],
        );
    }
}
