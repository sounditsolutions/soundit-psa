<?php

namespace App\Services\Cipp;

use App\Support\SafeUrlInspector;
use Illuminate\Contracts\Cache\Repository as CacheInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CippMcpClient
{
    private const TOKEN_CACHE_KEY = 'cipp_mcp_oauth_token';

    /** @var callable */
    private $resolver;

    public function __construct(
        private readonly array $config,
        private readonly CacheInterface $cache,
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver ?? 'gethostbynamel';
    }

    /**
     * Call one official CIPP ExecMCP tool through JSON-RPC over HTTP.
     *
     * @return array<int|string, mixed>
     */
    public function callTool(string $toolName, array $arguments = []): array
    {
        $execMcpUrl = $this->execMcpUrl();

        $response = Http::timeout(60)
            ->accept('text/event-stream')
            ->withOptions($this->safeRequestOptions($execMcpUrl))
            ->withToken($this->getToken())
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
            ->withToken($this->getToken())
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

    private function getToken(): string
    {
        $tenantId = (string) ($this->config['tenant_id'] ?? '');
        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');

        if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
            throw new CippClientException('CIPP MCP client credentials are not configured');
        }

        $cacheKey = $this->tokenCacheKey($tenantId, $clientId);
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post($tokenUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => "api://{$clientId}/.default",
                ])
                ->throw();
        } catch (RequestException $e) {
            Log::error('[CippMcpClient] Token request failed', ['error' => $e->getMessage()]);
            throw new CippClientException("CIPP MCP OAuth token request failed: {$e->getMessage()}", $e->getCode(), $e);
        } catch (\Throwable $e) {
            Log::error('[CippMcpClient] Token request failed', ['error' => $e->getMessage()]);
            throw new CippClientException("CIPP MCP OAuth token request failed: {$e->getMessage()}", (int) $e->getCode(), $e);
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new CippClientException('CIPP MCP OAuth response missing access_token');
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        $this->cache->put($cacheKey, $token, max(60, $expiresIn - 300));

        return $token;
    }

    private function tokenCacheKey(string $tenantId, string $clientId): string
    {
        return self::TOKEN_CACHE_KEY.':'.sha1($tenantId.'|'.$clientId);
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
        if (isset($result['content']) && is_array($result['content'])) {
            $text = '';
            foreach ($result['content'] as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }

            if ($text !== '') {
                $decodedText = json_decode($text, true);
                if (is_array($decodedText)) {
                    return $this->unwrapCippEnvelope($decodedText);
                }

                return ['text' => $text];
            }
        }

        return $this->unwrapCippEnvelope($result);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function unwrapCippEnvelope(array $data): array
    {
        foreach (['Results', 'results', 'value', 'Value'] as $key) {
            if (array_key_exists($key, $data) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return $data;
    }
}
