<?php

namespace App\Services\PandaDoc;

use App\Support\PandaDocConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Guzzle client for the PandaDoc public API (agreements + e-signatures).
 *
 * Auth: `Authorization: API-Key <key>` header. Base URL https://api.pandadoc.com,
 * endpoints under /public/v1. Follows the TacticalClient testable-seam pattern:
 * pass a pre-built Guzzle client (with its own headers) for tests, or leave null
 * to build a config-driven client from the encrypted API key.
 *
 * @see https://developers.pandadoc.com/reference/about
 */
class PandaDocClient
{
    private Client $http;

    private int $maxAttempts = 3;

    /**
     * @param  \GuzzleHttp\Client|null  $http  Injected client (test/bus seam) used
     *                                         AS-IS; when null, a config-driven client is built from the
     *                                         encrypted API key in Settings.
     */
    public function __construct(?Client $http = null)
    {
        if ($http !== null) {
            $this->http = $http;

            return;
        }

        $this->http = new Client([
            'base_uri' => PandaDocConfig::baseUrl(),
            'timeout' => 30,
            'allow_redirects' => false,
            'headers' => [
                'Authorization' => 'API-Key '.(PandaDocConfig::apiKey() ?? ''),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    // ── Health ──

    public function isHealthy(): bool
    {
        try {
            $this->get('/public/v1/templates', ['count' => 1]);

            return true;
        } catch (PandaDocClientException) {
            return false;
        }
    }

    // ── Templates ──

    public function listTemplates(int $count = 100): array
    {
        return $this->get('/public/v1/templates', ['count' => $count]);
    }

    // ── Documents ──

    /**
     * Create a document (typically from a template). Async on PandaDoc's side:
     * the returned document starts in `document.uploaded` and transitions to
     * `document.draft` once processing completes.
     */
    public function createDocument(array $payload): array
    {
        return $this->post('/public/v1/documents', $payload);
    }

    public function getDocument(string $id): array
    {
        return $this->get("/public/v1/documents/{$id}");
    }

    /**
     * Send a draft document to its recipients for signature.
     */
    public function sendDocument(string $id, array $payload = []): array
    {
        return $this->post("/public/v1/documents/{$id}/send", $payload);
    }

    public function voidDocument(string $id, string $message = 'Voided from Sound PSA'): array
    {
        return $this->request('POST', "/public/v1/documents/{$id}/status", [
            'json' => ['status' => 11, 'message' => $message], // 11 = voided
        ]);
    }

    /**
     * Download the (signed) PDF. Returns raw bytes, not JSON.
     */
    public function downloadDocument(string $id): string
    {
        return $this->requestRaw('GET', "/public/v1/documents/{$id}/download");
    }

    // ── Core HTTP ──

    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
    }

    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, ['json' => $data]);
    }

    private function request(string $method, string $endpoint, array $options = []): array
    {
        $body = $this->send($method, $endpoint, $options);

        return json_decode($body, true) ?? [];
    }

    private function requestRaw(string $method, string $endpoint, array $options = []): string
    {
        return $this->send($method, $endpoint, $options);
    }

    private function send(string $method, string $endpoint, array $options): string
    {
        $attempts = 0;

        while (true) {
            $attempts++;

            try {
                $response = $this->http->request($method, $endpoint, $options);

                return (string) $response->getBody();
            } catch (GuzzleException $e) {
                $code = $e->getCode();

                // Rate limited — back off and retry.
                if ($code === 429 && $attempts < $this->maxAttempts) {
                    $retryAfter = 1;
                    if (method_exists($e, 'getResponse') && $e->getResponse()) {
                        $retryAfter = (int) ($e->getResponse()->getHeaderLine('Retry-After') ?: 1);
                    }
                    Log::info("[PandaDoc] Rate limited, retrying in {$retryAfter}s");
                    sleep($retryAfter);

                    continue;
                }

                Log::error("[PandaDoc] {$method} {$endpoint} failed: {$e->getMessage()}");
                throw new PandaDocClientException("PandaDoc API error: {$e->getMessage()}", $code, $e);
            }
        }
    }
}
