<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\AppRiver\AppRiverClient;
use App\Services\AppRiver\AppRiverClientException;
use App\Support\AppRiverConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AppRiverOAuthController extends Controller
{
    public function redirect()
    {
        if (! AppRiverConfig::isConfigured()) {
            return redirect()->route('settings.integrations')
                ->with('error', 'AppRiver credentials not configured. Save Client ID and Client Secret first.');
        }

        $state = Str::random(40);
        session(['appriver_oauth_state' => $state]);

        $client = new AppRiverClient([
            'client_id' => AppRiverConfig::get('client_id'),
            'client_secret' => AppRiverConfig::get('client_secret'),
            'base_url' => AppRiverConfig::get('base_url'),
        ]);

        return redirect($client->getAuthorizationUrl($state));
    }

    public function callback(Request $request)
    {
        // Validate CSRF state parameter
        $expectedState = session('appriver_oauth_state');
        $receivedState = $request->query('state');

        if (! $expectedState || $expectedState !== $receivedState) {
            Log::warning('[AppRiver OAuth] State mismatch', [
                'expected' => $expectedState,
                'received' => $receivedState,
            ]);
            abort(400, 'Invalid OAuth state parameter.');
        }

        session()->forget('appriver_oauth_state');

        // Handle error responses
        if ($request->query('error')) {
            $error = $request->query('error');
            $description = $request->query('error_description', 'Unknown error');
            Log::warning('[AppRiver OAuth] Authorization error', [
                'error' => $error,
                'description' => $description,
            ]);

            return redirect()->route('settings.integrations')
                ->with('error', "AppRiver authorization failed: {$description} ({$error}).");
        }

        $code = $request->query('code');

        if (! $code) {
            return redirect()->route('settings.integrations')
                ->with('error', 'AppRiver authorization was cancelled or failed.');
        }

        try {
            $client = new AppRiverClient([
                'client_id' => AppRiverConfig::get('client_id'),
                'client_secret' => AppRiverConfig::get('client_secret'),
                'base_url' => AppRiverConfig::get('base_url'),
            ]);
            $client->exchangeCode($code);
        } catch (AppRiverClientException $e) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Failed to connect to AppRiver: ' . $e->getMessage());
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'Connected to AppRiver successfully!');
    }
}
