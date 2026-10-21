<?php

namespace App\Services\Carbon;

use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The Carbon Connect MCP connection (card PEYvh4NL): OAuth 2.1 authorization
 * code + PKCE (S256) with dynamic client registration, the CIPP connector's
 * pattern (CippMcpConnector) applied to an MCP server that publishes its own
 * metadata.
 *
 * Discovery follows the MCP authorization spec: the MCP URL's protected-resource
 * metadata (RFC 9728) names the authorization server, whose metadata (RFC 8414)
 * names /authorize, /token, /revoke and /register. Registration (RFC 7591) runs
 * once, at the first Connect, and its client_id / client_secret are stored
 * (the secret encrypted); Carbon's revocation endpoint accepts only
 * client_secret_post, so the client registers as a confidential client.
 *
 * Secrecy: no token, code, verifier or client secret is logged, flashed or put
 * in an exception message. Vendor failures are reduced to an OAuth error code
 * or an HTTP status before they leave this class; a body is never echoed.
 */
class CarbonMcpConnector
{
    public const DEFAULT_MCP_URL = 'https://api.carbonsys.com/mcp';

    /** Charlie's ruling (card PEYvh4NL, 08:57 PT 10/21): all five scopes. */
    public const SCOPES = [
        'mcp:read',
        'mcp:products:write',
        'mcp:orders:draft',
        'mcp:companies:write',
        'mcp:customers:write',
    ];

    public const MCP_URL_SETTING = 'carbon_mcp_url';

    public const REFRESH_TOKEN_SETTING = 'carbon_mcp_refresh_token';

    public const CLIENT_ID_SETTING = 'carbon_mcp_client_id';

    public const CLIENT_SECRET_SETTING = 'carbon_mcp_client_secret';

    public const REGISTERED_REDIRECT_SETTING = 'carbon_mcp_registered_redirect_uri';

    public const METADATA_SETTING = 'carbon_mcp_as_metadata';

    public const CONNECTED_AT = 'carbon_mcp_connected_at';

    public const CONNECTED_BY = 'carbon_mcp_connected_by';

    public const GRANTED_SCOPES = 'carbon_mcp_granted_scopes';

    public const ACCESS_EXPIRES_AT = 'carbon_mcp_access_expires_at';

    public const FAILED_AT = 'carbon_mcp_refresh_failed_at';

    public const FAILED_CODE = 'carbon_mcp_refresh_error_code';

    public const LAST_SYNC_AT = 'carbon_mcp_last_sync_at';

    public const LAST_SYNC_SUMMARY = 'carbon_mcp_last_sync_summary';

    private const TOKEN_CACHE_KEY = 'carbon_mcp_access_token';

    public static function mcpUrl(): string
    {
        $url = trim((string) Setting::getValue(self::MCP_URL_SETTING, ''));

        return $url !== '' ? $url : self::DEFAULT_MCP_URL;
    }

    public static function scope(): string
    {
        return implode(' ', self::SCOPES);
    }

    public function isConnected(): bool
    {
        return (string) Setting::getValue(self::REFRESH_TOKEN_SETTING, '') !== '';
    }

    /** Changes on every (re)connect, so a cached access token never outlives its sign-in. */
    public function generation(): string
    {
        return (string) Setting::getValue(self::CONNECTED_AT, '');
    }

    // --- PKCE ------------------------------------------------------------------

    /** RFC 7636 S256: BASE64URL(SHA256(ascii(code_verifier))), unpadded. */
    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** 64 characters from the RFC 7636 unreserved set (43..128 allowed). */
    public static function newCodeVerifier(): string
    {
        return Str::random(64);
    }

    // --- discovery ---------------------------------------------------------------

    /**
     * Protected-resource metadata, then authorization-server metadata. Refuses
     * (CarbonMcpAuthException) when the resource does not name the MCP URL,
     * when S256 is not offered, or when a required endpoint is missing or not https.
     *
     * @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, registration_endpoint: string, revocation_endpoint: ?string, resource: string}
     */
    public function discover(): array
    {
        $mcpUrl = self::mcpUrl();
        $parts = parse_url($mcpUrl);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        $prm = $this->getJson($origin.'/.well-known/oauth-protected-resource'.$path, 'protected-resource metadata');
        if (rtrim((string) ($prm['resource'] ?? ''), '/') !== rtrim($mcpUrl, '/')) {
            throw new CarbonMcpAuthException('Carbon MCP protected-resource metadata names a different resource than the configured MCP URL');
        }

        $issuer = (string) (($prm['authorization_servers'] ?? [])[0] ?? '');
        if (! str_starts_with($issuer, 'https://')) {
            throw new CarbonMcpAuthException('Carbon MCP protected-resource metadata names no https authorization server');
        }

        $as = $this->getJson(self::authorizationServerMetadataUrl($issuer), 'authorization-server metadata');
        if (rtrim((string) ($as['issuer'] ?? ''), '/') !== rtrim($issuer, '/')) {
            throw new CarbonMcpAuthException('Carbon MCP authorization-server metadata issuer does not match the protected resource');
        }

        if (! in_array('S256', (array) ($as['code_challenge_methods_supported'] ?? []), true)) {
            throw new CarbonMcpAuthException('Carbon MCP authorization server does not offer PKCE S256; sign-in refused');
        }

        $metadata = ['issuer' => $issuer, 'resource' => $mcpUrl, 'revocation_endpoint' => null];
        foreach (['authorization_endpoint', 'token_endpoint', 'registration_endpoint', 'revocation_endpoint'] as $key) {
            $value = $as[$key] ?? null;
            if ($value === null && $key === 'revocation_endpoint') {
                continue;
            }
            if (! is_string($value) || ! str_starts_with($value, 'https://')) {
                throw new CarbonMcpAuthException("Carbon MCP authorization-server metadata has no https {$key}");
            }
            $metadata[$key] = $value;
        }

        Setting::setValue(self::METADATA_SETTING, json_encode($metadata, JSON_UNESCAPED_SLASHES));

        return $metadata;
    }

    /** RFC 8414 section 3: the well-known segment goes between the host and the issuer's path. */
    public static function authorizationServerMetadataUrl(string $issuer): string
    {
        $parts = parse_url($issuer);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.'/.well-known/oauth-authorization-server'.rtrim((string) ($parts['path'] ?? ''), '/');
    }

    /** @return array<string, mixed>|null the stored discovery result */
    public function metadata(): ?array
    {
        $decoded = json_decode((string) Setting::getValue(self::METADATA_SETTING, ''), true);

        return is_array($decoded) && isset($decoded['token_endpoint']) ? $decoded : null;
    }

    /** @return array<string, mixed> */
    private function getJson(string $url, string $what): array
    {
        try {
            $response = Http::acceptJson()->timeout(15)->withOptions(['allow_redirects' => false])->get($url);
        } catch (\Throwable $e) {
            throw new CarbonMcpAuthException("Carbon MCP {$what} could not be fetched (".class_basename($e).')');
        }

        if ($response->failed() || ! is_array($response->json())) {
            throw new CarbonMcpAuthException("Carbon MCP {$what} could not be read (HTTP {$response->status()})");
        }

        return $response->json();
    }

    // --- dynamic client registration (RFC 7591) ---------------------------------

    /**
     * The registered client_id, registering first when there is none or when the
     * stored registration was made for a different redirect URI.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function ensureRegistered(array $metadata, string $redirectUri): string
    {
        $clientId = (string) Setting::getValue(self::CLIENT_ID_SETTING, '');
        if ($clientId !== '' && Setting::getValue(self::REGISTERED_REDIRECT_SETTING) === $redirectUri) {
            return $clientId;
        }

        try {
            $response = Http::acceptJson()->asJson()->timeout(15)->withOptions(['allow_redirects' => false])
                ->post((string) $metadata['registration_endpoint'], [
                    'client_name' => 'SoundPSA',
                    'redirect_uris' => [$redirectUri],
                    'grant_types' => ['authorization_code', 'refresh_token'],
                    'response_types' => ['code'],
                    'token_endpoint_auth_method' => 'client_secret_post',
                    'scope' => self::scope(),
                ]);
        } catch (\Throwable $e) {
            throw new CarbonMcpAuthException('Carbon MCP client registration got no response from Carbon (may have been delivered): '.class_basename($e));
        }

        $newId = $response->json('client_id');
        if ($response->failed() || ! is_string($newId) || $newId === '') {
            throw new CarbonMcpAuthException('Carbon MCP client registration failed: '.self::errorCode($response));
        }

        Setting::setValue(self::CLIENT_ID_SETTING, $newId);
        $secret = $response->json('client_secret');
        if (is_string($secret) && $secret !== '') {
            Setting::setEncrypted(self::CLIENT_SECRET_SETTING, $secret);
        } else {
            Setting::where('key', self::CLIENT_SECRET_SETTING)->delete();
        }
        Setting::setValue(self::REGISTERED_REDIRECT_SETTING, $redirectUri);

        return $newId;
    }

    /**
     * client_id, plus client_secret when the registration issued one
     * (client_secret_post). Never log the result.
     *
     * @return array<string, string>
     */
    public function clientAuthFields(): array
    {
        $fields = ['client_id' => (string) Setting::getValue(self::CLIENT_ID_SETTING, '')];
        $secret = Setting::getEncrypted(self::CLIENT_SECRET_SETTING);
        if (is_string($secret) && $secret !== '') {
            $fields['client_secret'] = $secret;
        }

        return $fields;
    }

    /** @param  array<string, mixed>  $metadata */
    public static function authorizeUrl(array $metadata, string $clientId, string $redirectUri, string $state, string $verifier): string
    {
        return $metadata['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => self::scope(),
            'state' => $state,
            'code_challenge' => self::codeChallenge($verifier),
            'code_challenge_method' => 'S256',
            'resource' => $metadata['resource'],
        ], '', '&', PHP_QUERY_RFC3986);
    }

    // --- code exchange, refresh, revoke --------------------------------------------

    /** @throws CarbonMcpAuthException carrying an OAuth error code or HTTP status only */
    public function exchangeCode(string $code, string $verifier, string $redirectUri, ?string $connectedBy = null): void
    {
        $metadata = $this->metadata();
        if ($metadata === null) {
            throw new CarbonMcpAuthException('Carbon MCP sign-in has no discovered token endpoint; start the connection again');
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->withOptions(['allow_redirects' => false])
                ->post((string) $metadata['token_endpoint'], $this->clientAuthFields() + [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $verifier,
                    'resource' => $metadata['resource'],
                ]);
        } catch (\Throwable $e) {
            throw new CarbonMcpAuthException('Carbon MCP code exchange got no response from Carbon (may have been delivered): '.class_basename($e));
        }

        if ($response->failed()) {
            throw new CarbonMcpAuthException('Carbon MCP code exchange was refused: '.self::errorCode($response));
        }

        $refresh = $response->json('refresh_token');
        if (! is_string($refresh) || $refresh === '') {
            throw new CarbonMcpAuthException('Carbon MCP code exchange returned no refresh token');
        }

        Setting::setEncrypted(self::REFRESH_TOKEN_SETTING, $refresh);
        Setting::setValue(self::CONNECTED_AT, now()->toIso8601String());
        Setting::setValue(self::CONNECTED_BY, $connectedBy !== null ? mb_substr($connectedBy, 0, 255) : null);
        $granted = $response->json('scope');
        Setting::setValue(self::GRANTED_SCOPES, is_string($granted) && $granted !== '' ? self::sanitizeScopes($granted) : self::scope());
        $this->recordAccessExpiry((int) ($response->json('expires_in') ?? 0));
        Setting::setValue(self::FAILED_AT, null);
        Setting::setValue(self::FAILED_CODE, null);

        $access = $response->json('access_token');
        if (is_string($access) && $access !== '') {
            Cache::put($this->accessCacheKey(), $access, max(60, (int) ($response->json('expires_in') ?? 3600) - 300));
        }
    }

    /**
     * A bearer token for the MCP endpoint: cached, else minted from the stored
     * refresh token (a rotated refresh token is saved). Returns a token or throws
     * CarbonMcpAuthException carrying an error code only.
     */
    public function accessToken(): string
    {
        if (! $this->isConnected()) {
            throw new CarbonMcpAuthException('Carbon MCP is not connected; an admin signs in at Settings > Integrations > Carbon Connect');
        }

        $cached = Cache::get($this->accessCacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $metadata = $this->metadata() ?? $this->discover();

        try {
            $refreshToken = (string) Setting::getEncrypted(self::REFRESH_TOKEN_SETTING);
            $fields = $this->clientAuthFields();
        } catch (\Throwable $e) {
            $this->recordRefreshFailure('stored_token_unreadable');
            throw new CarbonMcpAuthException('Carbon MCP refresh could not start: stored_token_unreadable');
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(15)->withOptions(['allow_redirects' => false])
                ->post((string) $metadata['token_endpoint'], $fields + [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'resource' => $metadata['resource'],
                ]);
            $failure = $response->failed() ? self::errorCode($response) : null;
        } catch (\Throwable $e) {
            $response = null;
            $failure = 'no response ('.class_basename($e).')';
        }

        $token = $response?->json('access_token');
        if ($failure === null && (! is_string($token) || $token === '')) {
            $failure = 'no_access_token';
        }

        if ($failure !== null) {
            $this->recordRefreshFailure($failure);
            Log::error('[CarbonMcpConnector] Carbon MCP refresh failed', ['error_code' => $failure]);
            throw new CarbonMcpAuthException('Carbon MCP refresh failed: '.$failure);
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        Cache::put($this->accessCacheKey(), $token, max(60, $expiresIn - 300));

        $rotated = $response->json('refresh_token');
        if (is_string($rotated) && $rotated !== '' && $rotated !== $refreshToken) {
            Setting::setEncrypted(self::REFRESH_TOKEN_SETTING, $rotated);
        }
        $this->recordAccessExpiry($expiresIn);
        if (Setting::getValue(self::FAILED_AT) !== null) {
            Setting::setValue(self::FAILED_AT, null);
            Setting::setValue(self::FAILED_CODE, null);
        }

        return $token;
    }

    /** Forget the cached access token (after an HTTP 401 from the MCP endpoint). */
    public function forgetAccessToken(): void
    {
        Cache::forget($this->accessCacheKey());
    }

    /**
     * Sign out: revoke the refresh token at Carbon's /revoke (client_secret_post),
     * then clear the local connection whatever Carbon answered.
     *
     * `sent` is true only when a /revoke request was made; `revoked` only when it
     * was made and Carbon answered 2xx. `reason` (status / code / class) says why
     * not, and is null when revoked. With no stored refresh token nothing is sent.
     *
     * @return array{sent: bool, revoked: bool, reason: ?string}
     */
    public function disconnect(): array
    {
        $sent = false;
        $revoked = false;
        $reason = 'no_stored_token';
        $metadata = $this->metadata();
        $refresh = null;
        try {
            $refresh = Setting::getEncrypted(self::REFRESH_TOKEN_SETTING);
        } catch (\Throwable) {
            $reason = 'stored_token_unreadable';
        }

        if (is_string($refresh) && $refresh !== '') {
            if (! is_string($metadata['revocation_endpoint'] ?? null)) {
                $reason = 'no_revocation_endpoint';
            } else {
                $sent = true;
                try {
                    $response = Http::asForm()->acceptJson()->timeout(15)->withOptions(['allow_redirects' => false])
                        ->post($metadata['revocation_endpoint'], $this->clientAuthFields() + [
                            'token' => $refresh,
                            'token_type_hint' => 'refresh_token',
                        ]);
                    $revoked = $response->successful();
                    $reason = $revoked ? null : self::errorCode($response);
                } catch (\Throwable $e) {
                    $reason = 'no response ('.class_basename($e).')';
                }
            }
        }

        $this->forgetAccessToken();
        foreach ([self::REFRESH_TOKEN_SETTING, self::CONNECTED_AT, self::CONNECTED_BY, self::GRANTED_SCOPES, self::ACCESS_EXPIRES_AT, self::FAILED_AT, self::FAILED_CODE] as $key) {
            Setting::where('key', $key)->delete();
        }

        return ['sent' => $sent, 'revoked' => $revoked, 'reason' => $reason];
    }

    // --- bookkeeping / status ---------------------------------------------------------

    public function recordAccessExpiry(int $expiresIn): void
    {
        Setting::setValue(self::ACCESS_EXPIRES_AT, $expiresIn > 0 ? now()->addSeconds($expiresIn)->toIso8601String() : null);
    }

    public function recordRefreshFailure(string $code): void
    {
        try {
            if (Setting::getValue(self::FAILED_AT) === null) {
                Setting::setValue(self::FAILED_AT, now()->toIso8601String());
            }
            Setting::setValue(self::FAILED_CODE, self::sanitizeFailure($code));
        } catch (\Throwable $e) {
            Log::error('[CarbonMcpConnector] Could not record the refresh failure', ['exception' => class_basename($e)]);
        }
    }

    /**
     * Panel status; `state` is not_connected | connected | refresh_failed.
     *
     * @return array{state: string, connected_by: ?string, connected_at: ?string, scopes: array<int, string>, access_expires_at: ?string, failed_at: ?string, error_code: ?string, last_sync_at: ?string, last_sync_summary: ?string}
     */
    public function status(): array
    {
        $connected = $this->isConnected();
        $failedAt = $connected ? Setting::getValue(self::FAILED_AT) : null;
        $scopes = $connected ? array_values(array_filter(explode(' ', (string) Setting::getValue(self::GRANTED_SCOPES, '')))) : [];

        return [
            'state' => ! $connected ? 'not_connected' : ($failedAt !== null ? 'refresh_failed' : 'connected'),
            'connected_by' => $connected ? Setting::getValue(self::CONNECTED_BY) : null,
            'connected_at' => $connected ? Setting::getValue(self::CONNECTED_AT) : null,
            'scopes' => $scopes,
            'access_expires_at' => $connected ? Setting::getValue(self::ACCESS_EXPIRES_AT) : null,
            'failed_at' => $failedAt,
            'error_code' => $failedAt !== null ? Setting::getValue(self::FAILED_CODE) : null,
            'last_sync_at' => Setting::getValue(self::LAST_SYNC_AT),
            'last_sync_summary' => Setting::getValue(self::LAST_SYNC_SUMMARY),
        ];
    }

    private function accessCacheKey(): string
    {
        return self::TOKEN_CACHE_KEY.':'.sha1($this->generation());
    }

    /** The OAuth `error` code, else the HTTP status. Never the body. */
    public static function errorCode(Response $response): string
    {
        $error = $response->json('error');
        if (is_string($error) && $error !== '') {
            return self::sanitizeCode($error).' (HTTP '.$response->status().')';
        }

        return 'HTTP '.$response->status();
    }

    /**
     * A vendor OAuth `error` value, kept only when it has the RFC 6749 shape
     * `[a-z_]{1,40}`; anything else (too long, spaces, markup, other characters)
     * becomes `unknown_error`, so vendor text never reaches a log, flash or audit row.
     */
    public static function sanitizeCode(string $code): string
    {
        return preg_match('/^[a-z_]{1,40}$/', $code) === 1 ? $code : 'unknown_error';
    }

    /**
     * A PSA-composed refresh-failure reason (a sanitizeCode() value plus an HTTP
     * status, or an exception class), trimmed to a short safe character set.
     */
    private static function sanitizeFailure(string $reason): string
    {
        $reason = preg_replace('/[^A-Za-z0-9_ .()\/-]/', '', $reason) ?? '';
        $reason = mb_substr(trim($reason), 0, 80);

        return $reason !== '' ? $reason : 'unknown_error';
    }

    /** Keep only scope-shaped words (mcp:...), so a stored value can carry nothing else. */
    public static function sanitizeScopes(string $scopes): string
    {
        $words = array_filter(preg_split('/\s+/', trim($scopes)) ?: [], fn (string $w): bool => preg_match('/^[a-z][a-z0-9:_.-]{0,63}$/i', $w) === 1);

        return implode(' ', array_slice(array_values($words), 0, 20));
    }
}
