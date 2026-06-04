<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Qbo\QboClient;
use App\Services\Qbo\QboClientException;
use App\Services\Qbo\QboSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QboController extends Controller
{
    public function redirect(QboClient $qboClient)
    {
        $state = Str::random(40);
        session(['qbo_oauth_state' => $state]);

        return redirect($qboClient->getAuthorizationUrl($state));
    }

    public function callback(Request $request, QboClient $qboClient)
    {
        // Validate CSRF state parameter
        $expectedState = session('qbo_oauth_state');
        $receivedState = $request->query('state');

        if (! $expectedState || $expectedState !== $receivedState) {
            \Illuminate\Support\Facades\Log::warning('[QBO OAuth] State mismatch', [
                'expected' => $expectedState,
                'received' => $receivedState,
            ]);
            abort(400, 'Invalid OAuth state parameter.');
        }

        session()->forget('qbo_oauth_state');

        // Handle error responses from Intuit (e.g. invalid_scope)
        if ($request->query('error')) {
            $error = $request->query('error');
            $description = $request->query('error_description', 'Unknown error');
            \Illuminate\Support\Facades\Log::warning('[QBO OAuth] Authorization error', [
                'error' => $error,
                'description' => $description,
            ]);

            return redirect()->route('settings.integrations')
                ->with('error', "QuickBooks authorization failed: {$description} ({$error}). Check your Intuit app configuration.");
        }

        $code = $request->query('code');
        $realmId = $request->query('realmId');

        if (! $code || ! $realmId) {
            return redirect()->route('settings.integrations')
                ->with('error', 'QuickBooks authorization was cancelled or failed.');
        }

        try {
            $qboClient->exchangeCode($code, $realmId);
        } catch (QboClientException $e) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Failed to connect to QuickBooks: '.$e->getMessage());
        }

        return redirect()->route('settings.integrations')
            ->with('success', 'Connected to QuickBooks Online successfully!');
    }

    public function disconnect(QboClient $qboClient)
    {
        $qboClient->disconnect();

        return redirect()->route('settings.integrations')
            ->with('success', 'Disconnected from QuickBooks Online.');
    }

    /**
     * Landing page when a user disconnects from within QBO.
     * Intuit redirects here — clears tokens if the user is authenticated.
     */
    public function disconnected(QboClient $qboClient)
    {
        if (auth()->check()) {
            $qboClient->disconnect();
        }

        return view('legal.qbo-disconnected');
    }

    public function customers(QboSyncService $syncService)
    {
        try {
            $customers = $syncService->fetchQboCustomers();

            return response()->json($customers);
        } catch (QboClientException) {
            return response()->json([], 503);
        }
    }
}
