<?php

namespace App\Http\Middleware;

use App\Support\CwAuthHelper;
use App\Support\HuntressConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyHuntressApiKey
{
    /**
     * Validate ConnectWise Manage Basic auth format used by Huntress incident webhooks.
     *
     * Huntress sends CW-format auth: Basic base64("CompanyId+PublicKey:PrivateKey")
     * We only validate the PrivateKey portion against our stored key.
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::debug('[Huntress CW] Incoming request', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
        ]);

        $apiKey = HuntressConfig::get('cw_api_key');

        if (! $apiKey) {
            Log::warning('[Huntress CW] CW API key not configured');

            return $this->unauthorized('API not configured');
        }

        $privateKey = CwAuthHelper::extractPrivateKey($request->header('Authorization', ''));

        if ($privateKey === null) {
            Log::warning('[Huntress CW] Missing or invalid Authorization header', [
                'ip' => $request->ip(),
            ]);

            return $this->unauthorized('Missing authorization');
        }

        if (! hash_equals($apiKey, $privateKey)) {
            Log::warning('[Huntress CW] API key mismatch', [
                'ip' => $request->ip(),
            ]);

            return $this->unauthorized('Invalid API key');
        }

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(
            ['code' => 'Unauthorized', 'message' => $message],
            401,
            ['WWW-Authenticate' => 'Basic realm="ConnectWise API"'],
        );
    }
}
