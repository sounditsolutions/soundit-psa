<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Carbon\CarbonMcpAuthException;
use App\Services\Carbon\CarbonMcpCatalogSyncService;
use App\Services\Carbon\CarbonMcpConnector;
use App\Services\Carbon\CarbonMcpException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "Connect Carbon": the admin-only authorization-code + PKCE sign-in that gives
 * the PSA its Carbon Connect MCP connection (card PEYvh4NL), shaped like
 * CippMcpOAuthController: a random state and the PKCE verifier are held in the
 * session; the callback refuses a missing or mismatched state before anything
 * else. Discovery and dynamic client registration run on the redirect leg.
 * No token, code, verifier or client secret is logged or flashed.
 */
class CarbonMcpOAuthController extends Controller
{
    private const STATE_KEY = 'carbon_mcp_oauth_state';

    private const VERIFIER_KEY = 'carbon_mcp_oauth_verifier';

    public function redirect(CarbonMcpConnector $connector)
    {
        $callback = route('auth.carbon-mcp.callback');

        try {
            $metadata = $connector->discover();
            $clientId = $connector->ensureRegistered($metadata, $callback);
        } catch (CarbonMcpAuthException $e) {
            // PSA-authored text carrying a status / code / class only.
            Log::warning('[Carbon MCP OAuth] Could not start the sign-in', ['error' => $e->getMessage()]);

            return redirect()->route('settings.integrations')
                ->with('error', 'Could not start the Carbon sign-in: '.$e->getMessage());
        }

        $state = Str::random(40);
        $verifier = CarbonMcpConnector::newCodeVerifier();
        session([self::STATE_KEY => $state, self::VERIFIER_KEY => $verifier]);

        return redirect(CarbonMcpConnector::authorizeUrl($metadata, $clientId, $callback, $state, $verifier));
    }

    public function callback(Request $request, CarbonMcpConnector $connector, CarbonMcpCatalogSyncService $catalog)
    {
        $expectedState = session()->pull(self::STATE_KEY);
        $verifier = session()->pull(self::VERIFIER_KEY);
        $receivedState = $request->query('state');

        if (! is_string($expectedState) || $expectedState === ''
            || ! is_string($receivedState) || ! hash_equals($expectedState, $receivedState)) {
            Log::warning('[Carbon MCP OAuth] State mismatch', [
                'expected_present' => is_string($expectedState) && $expectedState !== '',
                'received_present' => is_string($receivedState) && $receivedState !== '',
            ]);
            abort(400, 'Invalid OAuth state parameter.');
        }

        if (! is_string($verifier) || $verifier === '') {
            Log::warning('[Carbon MCP OAuth] PKCE code_verifier missing from the session');
            abort(400, 'Missing PKCE verifier; start the connection again.');
        }

        if ($request->query('error')) {
            $code = CarbonMcpConnector::sanitizeCode((string) $request->query('error'));
            Log::warning('[Carbon MCP OAuth] Authorization error', ['error' => $code]);

            return redirect()->route('settings.integrations')
                ->with('error', "Carbon sign-in failed (error code: {$code}). Click Connect Carbon to try again.");
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return redirect()->route('settings.integrations')
                ->with('error', 'Carbon sign-in was cancelled or failed.');
        }

        try {
            $connector->exchangeCode($code, $verifier, route('auth.carbon-mcp.callback'), $request->user()?->name);
        } catch (CarbonMcpAuthException $e) {
            Log::warning('[Carbon MCP OAuth] Code exchange failed', ['error' => $e->getMessage()]);

            return redirect()->route('settings.integrations')
                ->with('error', 'Could not connect Carbon: '.$e->getMessage());
        }

        Log::info('[Carbon MCP OAuth] Connected', ['user_id' => $request->user()?->id]);

        // Load the tool catalogue now, in this request, so the tools are grantable the
        // moment the admin lands back on Settings (Charlie, card PEYvh4NL 2026-10-29).
        // Same request rather than a job: the redirect then reports the real outcome.
        // A failed sync leaves the connection standing; Sync tools now is the retry.
        try {
            $result = $catalog->sync();
        } catch (\Throwable $e) {
            // CarbonMcpException text is PSA-authored (status, code or class, and on a
            // catalogue collision the Carbon tool names; never results or client data);
            // anything else is reported by class, never by its message.
            $reason = $e instanceof CarbonMcpException ? $e->getMessage() : class_basename($e);
            Log::warning('[Carbon MCP OAuth] Catalogue sync after connect failed', ['error' => $reason]);

            return redirect()->route('settings.integrations')
                ->with('warning', "Carbon connected, but loading the Carbon tools failed ({$reason}). Click Sync tools now to try again.");
        }

        if ($result->active === 0) {
            Log::warning('[Carbon MCP OAuth] Catalogue sync after connect loaded no tools', ['seen' => $result->seen]);

            return redirect()->route('settings.integrations')
                ->with('warning', 'Carbon connected, but Carbon listed no tools. Click Sync tools now to try again.');
        }

        $n = $result->active;

        return redirect()->route('settings.integrations')
            ->with('success', "Carbon connected. {$n} ".Str::plural('tool', $n).' loaded.');
    }

    /**
     * Sign out: revoke at Carbon when a refresh token is stored, then clear the
     * local connection either way. "Revoked at Carbon" is said only when /revoke
     * was called and answered 2xx.
     */
    public function disconnect(CarbonMcpConnector $connector)
    {
        $outcome = $connector->disconnect();
        Log::info('[Carbon MCP OAuth] Signed out', [
            'revocation_sent' => $outcome['sent'],
            'revoked' => $outcome['revoked'],
            'reason' => $outcome['reason'],
        ]);

        if ($outcome['revoked']) {
            return redirect()->route('settings.integrations')
                ->with('success', 'Carbon signed out. The token was revoked at Carbon and the local connection cleared.');
        }

        if (! $outcome['sent']) {
            // No stored token: nothing to revoke. A stored token that could not be
            // read or sent is still valid at Carbon, so that case is a warning.
            return redirect()->route('settings.integrations')
                ->with($outcome['reason'] === 'no_stored_token' ? 'success' : 'warning', "Carbon signed out locally. The local connection was cleared and nothing was sent to Carbon ({$outcome['reason']}).");
        }

        return redirect()->route('settings.integrations')
            ->with('warning', "Carbon signed out locally. Carbon did not confirm the revocation ({$outcome['reason']}); the stored token has been deleted from PSA.");
    }
}
