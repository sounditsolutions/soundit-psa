<?php

namespace App\Services\Cipp;

use App\Support\SafeUrlInspector;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CippMcpClient
{
    private const TOKEN_CACHE_KEY = 'cipp_mcp_oauth_token';

    private const SIGN_IN_FAILED_CACHE_KEY = 'cipp_mcp_sign_in_failed';

    /**
     * #4393: after a failed token request, further sign-ins are not attempted for
     * this many seconds; each read in that window fails over at once instead of
     * POSTing a token request bound to fail. Short and fixed, so a fixed
     * credential or a restored Entra is picked up within a minute.
     */
    public const SIGN_IN_FAILURE_TTL = 60;

    /** Per-request caps; under a read deadline each is min(cap, what is left). */
    private const SIGN_IN_TIMEOUT = 15;

    private const EXEC_MCP_TIMEOUT = 60;

    /** @var callable */
    private $resolver;

    public function __construct(
        private readonly array $config,
        private readonly CacheInterface $cache,
        ?callable $resolver = null,
        private readonly ?CippMcpConnector $connector = null,
    ) {
        $this->resolver = $resolver ?? 'gethostbynamel';
    }

    /**
     * Call one official CIPP ExecMCP tool through JSON-RPC over HTTP.
     *
     * $deadline (CippClient::monotonicNow() seconds) bounds the sign-in AND the
     * ExecMCP call together: each request's timeout and connect_timeout is
     * min(its cap, what is left), and with under 1 ms left nothing is sent. A
     * request the deadline cut short (no budget left to send it, or cURL errno 28
     * while it ran below its cap) throws the #8355 sentence
     * (CippClient::readDeadlineFailure()); the message carries no cURL text, and
     * the ConnectionException is kept only as its previous exception. A request
     * that times out on its full cap is not cut short by the deadline: the app
     * sign-in then throws CippMcpAuthException carrying the cURL text (the
     * delegated refresh, the exception class only), and ExecMCP rethrows the
     * ConnectionException with its cURL text. null keeps the fixed caps (15 s
     * sign-in, 60 s ExecMCP), with connect_timeout left at the HTTP client's 10 s
     * default, for callers outside an interactive read.
     *
     * Outside the deadline: the DNS lookups that pin the ExecMCP host
     * (safeRequestOptions(), and SafeUrlInspector::reject() before it) run
     * before any request and are not bounded by $deadline. The default resolver
     * (gethostbynamel) takes no timeout argument, so the system resolver's own
     * timeout applies to them, not what is left of the read deadline.
     *
     * @return array<int|string, mixed>
     */
    public function callTool(string $toolName, array $arguments = [], ?float $deadline = null): array
    {
        $execMcpUrl = $this->execMcpUrl();
        $safeOptions = $this->safeRequestOptions($execMcpUrl);
        $token = $this->getToken($deadline);
        $timeout = $this->requestTimeout($deadline, self::EXEC_MCP_TIMEOUT);

        try {
            $response = Http::timeout($timeout)
                ->when($deadline !== null, fn ($request) => $request->connectTimeout($timeout))
                ->accept('text/event-stream')
                ->withOptions($safeOptions)
                ->withToken($token)
                ->withQueryParameters(['tools' => $toolName])
                ->post($execMcpUrl, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'tools/call',
                    'params' => [
                        'name' => $toolName,
                        'arguments' => $arguments,
                    ],
                ]);
        } catch (ConnectionException $e) {
            if ($this->deadlineCut($e, $deadline, $timeout, self::EXEC_MCP_TIMEOUT)) {
                throw CippClient::readDeadlineFailure('', 0, $e);
            }

            throw $e;
        }

        if ($response->status() === 401) {
            throw new CippMcpAuthException("CIPP MCP {$toolName} was refused: HTTP 401 ".mb_substr($response->body(), 0, 500));
        }

        if ($response->failed()) {
            throw new CippClientException("CIPP MCP {$toolName} failed: HTTP {$response->status()} ".mb_substr($response->body(), 0, 500));
        }

        return $this->decodeJsonRpcPayload((string) $response->body());
    }

    /**
     * List the official CIPP MCP tools currently advertised by ExecMCP.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTools(): array
    {
        $execMcpUrl = $this->execMcpUrl();

        $response = Http::timeout(60)
            ->acceptJson()
            ->withOptions($this->safeRequestOptions($execMcpUrl))
            ->withToken($this->getToken(null))
            ->post($execMcpUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => new \stdClass,
            ]);

        if ($response->failed()) {
            throw new CippClientException('CIPP MCP tools/list failed: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 500));
        }

        $payload = $this->decodeJsonRpcPayload((string) $response->body());
        $tools = $payload['tools'] ?? [];

        return is_array($tools) ? array_values(array_filter($tools, 'is_array')) : [];
    }

    /**
     * An access token for ExecMCP.
     *
     * With a delegated connector (CIPP v11+; see CippMcpConnector) it is minted from
     * the stored refresh token. Without one this is the pre-v11 app-only
     * client_credentials sign-in, unchanged. Either way every sign-in failure is a
     * CippMcpAuthException, which the curated reads fail over to REST on, and a
     * failed token request is remembered for SIGN_IN_FAILURE_TTL seconds (#4393).
     * It can also throw the read deadline failure below, which is not a sign-in
     * failure, and anything the token cache throws (reading or writing the token
     * or the failure marker) or, on the delegated path, reading the connector
     * generation from the settings store throws; those are not caught. An
     * exception thrown by the logger from the unguarded Log::error on a failure
     * path also escapes, in place of CippMcpAuthException.
     *
     * Under a read deadline the sign-in gets min(15 s, what is left). A sign-in the
     * deadline cut short (no budget to send, or a timeout with less than the full
     * 15 s) is NOT a sign-in failure: it is not remembered, not logged as one, and
     * throws the #8355 deadline failure rather than CippMcpAuthException, so the
     * read does not fail over to REST with nothing left (card Cn86O1kp, C2).
     */
    private function getToken(?float $deadline = null): string
    {
        if ($this->connectorIsConnected()) {
            return $this->getDelegatedToken($deadline);
        }

        $tenantId = (string) ($this->config['tenant_id'] ?? '');
        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            throw new CippMcpAuthException('CIPP MCP client credentials are not configured');
        }

        $cacheKey = $this->tokenCacheKey($tenantId, $clientId);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $failedKey = $this->signInFailedKey($tenantId, $clientId, 'app');
        $this->refuseIfRecentlyFailed($failedKey);

        $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
        $timeout = $this->requestTimeout($deadline, self::SIGN_IN_TIMEOUT);

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->when($deadline !== null, fn ($request) => $request->connectTimeout($timeout))
                ->post($tokenUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => "api://{$clientId}/.default",
                ])
                ->throw();
        } catch (ConnectionException $e) {
            if ($this->deadlineCut($e, $deadline, $timeout, self::SIGN_IN_TIMEOUT)) {
                throw CippClient::readDeadlineFailure('', 0, $e);
            }
            $this->rememberSignInFailure($failedKey);
            Log::error('[CippMcpClient] Token request failed', ['error' => $e->getMessage()]);
            throw new CippMcpAuthException("CIPP MCP OAuth token request failed: {$e->getMessage()}", (int) $e->getCode(), $e);
        } catch (RequestException $e) {
            $this->rememberSignInFailure($failedKey);
            Log::error('[CippMcpClient] Token request failed', ['error' => $e->getMessage()]);
            throw new CippMcpAuthException("CIPP MCP OAuth token request failed: {$e->getMessage()}", $e->getCode(), $e);
        } catch (\Throwable $e) {
            $this->rememberSignInFailure($failedKey);
            Log::error('[CippMcpClient] Token request failed', ['error' => $e->getMessage()]);
            throw new CippMcpAuthException("CIPP MCP OAuth token request failed: {$e->getMessage()}", (int) $e->getCode(), $e);
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            $this->rememberSignInFailure($failedKey);
            throw new CippMcpAuthException('CIPP MCP OAuth response missing access_token');
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        $this->cache->put($cacheKey, $token, max(60, $expiresIn - 300));

        return $token;
    }

    private function tokenCacheKey(string $tenantId, string $clientId): string
    {
        return self::TOKEN_CACHE_KEY.':'.sha1($tenantId.'|'.$clientId);
    }

    private function connectorIsConnected(): bool
    {
        if ($this->connector === null) {
            return false;
        }

        try {
            return $this->connector->isConnected();
        } catch (\Throwable $e) {
            // Settings unreadable: behave as before the connector existed.
            Log::warning('[CippMcpClient] Could not read the CIPP MCP connector state', ['exception' => class_basename($e)]);

            return false;
        }
    }

    /**
     * Delegated sign-in: refresh_token grant for
     * `<backend host>/user_impersonation offline_access`. A rotated refresh token is
     * persisted; a failure is reported to the connector (one alert per episode)
     * and surfaces as CippMcpAuthException carrying the vendor error CODE only.
     *
     * This method returns a token, throws the read deadline failure
     * (CippClient::readDeadlineFailure(): under a deadline, nothing was left to
     * send the refresh, or cURL errno 28 cut it short below its 15 s cap), or
     * throws CippMcpAuthException. Exceptions from the token cache (reading or
     * writing the token or the failure marker) and from reading the connector
     * generation are not caught here and escape as they are, and so does an
     * exception thrown by the logger from the unguarded Log::error on the failure
     * path, in place of CippMcpAuthException. The connector bookkeeping
     * (rotated refresh token, expiry, episode open/close, alert resolve and its ticket note) is best-effort: each
     * step runs in its own guard (bookkeep()), and a step that throws is logged by
     * exception class only and never rethrown. On success the minted token is
     * cached BEFORE any bookkeeping, so a failed settings or alert write can
     * neither lose the token nor force the next read to refresh again.
     *
     * The client secret is read live through CippMcpConnector::clientAuthFields():
     * once an admin removes the stored secret, no refresh sends one.
     */
    private function getDelegatedToken(?float $deadline = null): string
    {
        /** @var CippMcpConnector $connector */
        $connector = $this->connector;
        $tenantId = (string) ($this->config['tenant_id'] ?? '');
        $clientId = (string) ($this->config['client_id'] ?? '');

        if ($tenantId === '' || $clientId === '') {
            throw new CippMcpAuthException('CIPP MCP connector is present but the tenant id or MCP client id is not configured');
        }

        $generation = $connector->generation();
        $cacheKey = self::TOKEN_CACHE_KEY.':delegated:'.sha1($tenantId.'|'.$clientId.'|'.$generation);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $failedKey = $this->signInFailedKey($tenantId, $clientId, 'delegated|'.$generation);
        $this->refuseIfRecentlyFailed($failedKey);

        try {
            $refreshToken = $connector->refreshToken();
            $scope = CippMcpConnector::scope();
        } catch (\Throwable $e) {
            $code = $e instanceof CippMcpAuthException ? 'backend_host_not_configured' : 'stored_token_unreadable';
            $this->rememberSignInFailure($failedKey);
            $this->bookkeep('recordRefreshFailure', fn () => $connector->recordRefreshFailure($code));
            throw new CippMcpAuthException('CIPP MCP connector refresh could not start: '.$code);
        }

        try {
            $fields = CippMcpConnector::clientAuthFields($clientId);
        } catch (\Throwable $e) {
            // The stored secret is unreadable (e.g. APP_KEY rotated): a refresh
            // cannot be authenticated as configured, so this is a sign-in failure.
            $this->rememberSignInFailure($failedKey);
            $this->bookkeep('recordRefreshFailure', fn () => $connector->recordRefreshFailure('stored_secret_unreadable'));
            throw new CippMcpAuthException('CIPP MCP connector refresh could not start: stored_secret_unreadable');
        }

        $timeout = $this->requestTimeout($deadline, self::SIGN_IN_TIMEOUT);

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->when($deadline !== null, fn ($request) => $request->connectTimeout($timeout))
                ->post(CippMcpConnector::tokenUrl($tenantId), $fields + [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => (string) $refreshToken,
                    'scope' => $scope,
                ]);
            $failure = $response->failed() ? CippMcpConnector::errorCode($response) : null;
        } catch (\Throwable $e) {
            if ($e instanceof ConnectionException && $this->deadlineCut($e, $deadline, $timeout, self::SIGN_IN_TIMEOUT)) {
                // Cut short by the read deadline: not a refresh failure (C2).
                throw CippClient::readDeadlineFailure('', 0, $e);
            }
            $response = null;
            $failure = 'unreachable ('.class_basename($e).')';
        }

        $token = $response?->json('access_token');
        if ($failure === null && (! is_string($token) || $token === '')) {
            $failure = 'no_access_token';
        }

        if ($failure !== null) {
            $this->rememberSignInFailure($failedKey);
            $this->bookkeep('recordRefreshFailure', fn () => $connector->recordRefreshFailure($failure));
            Log::error('[CippMcpClient] CIPP MCP connector refresh failed', ['error_code' => $failure]);
            throw new CippMcpAuthException('CIPP MCP connector refresh failed: '.$failure);
        }

        // Cache first: the token is good whatever the bookkeeping below does.
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        $this->cache->put($cacheKey, $token, max(60, $expiresIn - 300));

        $rotated = $response->json('refresh_token');
        $this->bookkeep('storeRotatedRefreshToken', fn () => $connector->storeRotatedRefreshToken($rotated));
        $this->bookkeep('recordAccessExpiry', fn () => $connector->recordAccessExpiry($expiresIn));
        $this->bookkeep('recordRefreshSuccess', fn () => $connector->recordRefreshSuccess());

        return $token;
    }

    /**
     * Run one connector bookkeeping step; never let it escape getToken().
     *
     * The log line carries the step name and the exception CLASS only: never its
     * message, bindings or trace, because a QueryException from
     * Setting::setEncrypted() embeds the SQL bindings, which hold the encrypted
     * refresh token.
     */
    private function bookkeep(string $step, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            try {
                Log::error('[CippMcpClient] CIPP MCP connector bookkeeping failed; continuing', [
                    'step' => $step,
                    'exception' => class_basename($e),
                ]);
            } catch (\Throwable) {
                // A failing logger must not turn bookkeeping into a failed read either.
            }
        }
    }

    /**
     * The timeout for one request: its cap, or under a deadline min(cap, what is
     * left). With under 1 ms left it refuses before sending, because cURL treats a
     * sub-millisecond timeout as unbounded (the CippClient::deadlineOptions() rule).
     */
    private function requestTimeout(?float $deadline, int $cap): float|int
    {
        if ($deadline === null) {
            return $cap;
        }

        $remaining = $deadline - CippClient::monotonicNow();
        if ($remaining < 0.001) {
            throw CippClient::readDeadlineFailure();
        }

        return min($cap, $remaining);
    }

    /**
     * Whether a failed request was cut short by the read deadline: there was one,
     * the request ran with less than its full cap, and it ended on a real timeout
     * (cURL errno 28). DNS failures, refused connections and HTTP errors are not.
     */
    private function deadlineCut(ConnectionException $e, ?float $deadline, float|int $timeout, int $cap): bool
    {
        return $deadline !== null && $timeout < $cap && self::isTimeout($e);
    }

    private static function isTimeout(ConnectionException $e): bool
    {
        $previous = $e->getPrevious();

        return ($previous instanceof \GuzzleHttp\Exception\ConnectException || $previous instanceof \GuzzleHttp\Exception\RequestException)
            && (int) ($previous->getHandlerContext()['errno'] ?? 0) === 28;
    }

    private function signInFailedKey(string $tenantId, string $clientId, string $mode): string
    {
        return self::SIGN_IN_FAILED_CACHE_KEY.':'.sha1($tenantId.'|'.$clientId.'|'.$mode);
    }

    private function refuseIfRecentlyFailed(string $failedKey): void
    {
        if ($this->cache->has($failedKey)) {
            throw new CippMcpAuthException('CIPP MCP sign-in failed within the last '.self::SIGN_IN_FAILURE_TTL.'s; not retrying yet');
        }
    }

    private function rememberSignInFailure(string $failedKey): void
    {
        $this->cache->put($failedKey, true, self::SIGN_IN_FAILURE_TTL);
    }

    private function execMcpUrl(): string
    {
        $apiUrl = (string) ($this->config['api_url'] ?? '');
        if ($apiUrl === '') {
            throw new CippClientException('CIPP API URL is not configured');
        }

        return rtrim($apiUrl, '/').'/api/ExecMCP';
    }

    /**
     * @return array<string, mixed>
     */
    private function safeRequestOptions(string $url): array
    {
        $rejection = SafeUrlInspector::reject($url, $this->resolver);
        if ($rejection !== null) {
            throw new CippClientException(str_replace('Tactical API URL', 'CIPP API URL', $rejection));
        }

        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $options = ['allow_redirects' => false];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $options;
        }

        $resolver = $this->resolver;
        $ips = $resolver($host);
        if ($ips === false || ! is_array($ips) || $ips === []) {
            throw new CippClientException("CIPP API host '{$host}' did not resolve (refused for safety).");
        }

        foreach ($ips as $ip) {
            if (! SafeUrlInspector::ipIsSafe($ip)) {
                throw new CippClientException("CIPP API host '{$host}' resolved to a private or reserved address ({$ip}); refused.");
            }
        }

        $options['curl'] = [CURLOPT_RESOLVE => [$host.':'.$port.':'.implode(',', $ips)]];

        return $options;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decodeJsonRpcPayload(string $body): array
    {
        $payload = $this->firstSseDataPayload($body) ?? $body;
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw new CippClientException('CIPP MCP response was not valid JSON-RPC');
        }

        if (isset($decoded['error'])) {
            $message = is_array($decoded['error'])
                ? (string) ($decoded['error']['message'] ?? json_encode($decoded['error']))
                : (string) $decoded['error'];
            throw new CippClientException('CIPP MCP JSON-RPC error: '.$message);
        }

        $result = $decoded['result'] ?? $decoded;
        if (! is_array($result)) {
            return ['value' => $result];
        }

        return $this->unwrapMcpResult($result);
    }

    private function firstSseDataPayload(string $body): ?string
    {
        $events = preg_split("/\R\R/", trim($body)) ?: [];

        foreach ($events as $event) {
            $data = [];
            foreach (preg_split("/\R/", $event) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(mb_substr($line, 5));
                }
            }

            if ($data === []) {
                continue;
            }

            $payload = implode("\n", $data);
            if ($payload !== '[DONE]') {
                return $payload;
            }
        }

        return null;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function unwrapMcpResult(array $result): array
    {
        $text = null;

        if (isset($result['content']) && is_array($result['content'])) {
            $text = '';
            foreach ($result['content'] as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }

        // CIPP's ExecMCP reports tool failures as HTTP 200 + isError:true with
        // the message as text content. Surface them as exceptions — otherwise
        // the message decodes into pseudo-rows that read as a false-empty
        // result downstream (psa-3twu).
        if (($result['isError'] ?? false) === true) {
            throw new CippClientException('CIPP MCP tool error: '.mb_substr($text !== null && $text !== '' ? $text : 'no error detail provided', 0, 500));
        }

        if ($text !== null) {
            if ($text === '') {
                // Empty tool result: PowerShell's ConvertTo-Json emits nothing
                // for an empty array, so "no rows" arrives as empty text — not
                // a reason to fall back to the envelope, which would fabricate
                // one junk row out of {content, isError}.
                return [];
            }

            $decodedText = json_decode($text, true);
            if (is_array($decodedText)) {
                return $this->unwrapCippEnvelope($decodedText);
            }

            return ['text' => $text];
        }

        return $this->unwrapCippEnvelope($result);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function unwrapCippEnvelope(array $data): array
    {
        // "Still loading" is not "nothing found" — see CippQueueGuard for the
        // vendor shapes and why this must throw rather than unwrap to [].
        CippQueueGuard::assertNotQueueBacked($data);

        foreach (['Results', 'results', 'value', 'Value'] as $key) {
            if (array_key_exists($key, $data) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return $data;
    }
}
