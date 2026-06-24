<?php

namespace App\Services\Technician\Notify;

use App\Support\PlivoConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fail-soft outbound SMS via the Plivo Messages API (Basic auth, mirroring the
 * voice integration's HTTP pattern). Notify-only: SMS never authorizes an action.
 */
class SmsNotifier
{
    public function send(string $toNumber, string $text): bool
    {
        if ($toNumber === '' || ! PlivoConfig::isConfigured()) {
            return false;
        }

        $authId = (string) PlivoConfig::get('auth_id');
        $authToken = (string) PlivoConfig::get('auth_token');
        $src = (string) PlivoConfig::get('did_number');

        try {
            $response = Http::withBasicAuth($authId, $authToken)
                ->timeout(10)
                ->post("https://api.plivo.com/v1/Account/{$authId}/Message/", [
                    'src' => $src,
                    'dst' => $toNumber,
                    'text' => $text,
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('[Technician] Plivo SMS send failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
