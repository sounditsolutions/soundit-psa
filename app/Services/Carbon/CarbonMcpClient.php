<?php

namespace App\Services\Carbon;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * JSON-RPC over MCP Streamable HTTP to Carbon Connect (card PEYvh4NL):
 * initialize (+ notifications/initialized, carrying any Mcp-Session-Id the
 * server issues), tools/list following nextCursor, and tools/call.
 *
 * Error rule (C-56, the card's "customer/company data never in logs"): every
 * exception message here is PSA-authored and carries an HTTP status, a JSON-RPC
 * error code or an exception class. No response body, JSON-RPC error message or
 * tool output is ever put into an exception, so nothing upstream can reach a log
 * line or the audit table through this class.
 */
class CarbonMcpClient
{
    public const PROTOCOL_VERSION = '2025-06-18';

    /** A catalogue longer than this many pages is refused rather than truncated. */
    public const MAX_LIST_PAGES = 50;

    private ?string $sessionId = null;

    private bool $initialized = false;

    private int $nextId = 1;

    public function __construct(private readonly CarbonMcpConnector $connector) {}

    /**
     * Every tool Carbon advertises, all pages. A page cap that is reached throws:
     * a partial list would read as "these tools disappeared" and deactivate them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTools(): array
    {
        $tools = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_LIST_PAGES; $page++) {
            $result = $this->request('tools/list', $cursor !== null ? ['cursor' => $cursor] : new \stdClass, 'tools/list');
            $pageTools = $result['tools'] ?? null;
            if (! is_array($pageTools)) {
                throw new CarbonMcpException('Carbon MCP tools/list answered without a tools array');
            }
            foreach ($pageTools as $tool) {
                if (is_array($tool)) {
                    $tools[] = $tool;
                }
            }

            $next = $result['nextCursor'] ?? null;
            if (! is_string($next) || $next === '') {
                return $tools;
            }
            $cursor = $next;
        }

        throw new CarbonMcpException('Carbon MCP tools/list did not finish within '.self::MAX_LIST_PAGES.' pages; catalogue not changed');
    }

    /**
     * Call one Carbon tool. Returns the MCP CallToolResult as Carbon sent it
     * (content, structuredContent, isError). A tool-level error (isError: true)
     * is returned, not thrown: its text is the agent's to read, never a log's.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function callTool(string $upstreamName, array $arguments): array
    {
        return $this->request('tools/call', [
            'name' => $upstreamName,
            'arguments' => $arguments === [] ? new \stdClass : $arguments,
        ], 'tools/call');
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $result = $this->post([
            'jsonrpc' => '2.0',
            'id' => $this->nextId++,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new \stdClass,
                'clientInfo' => ['name' => 'SoundPSA', 'version' => '1'],
            ],
        ], 'initialize', captureSession: true);

        if (! is_array($result)) {
            throw new CarbonMcpException('Carbon MCP initialize answered without a result');
        }

        $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], 'notifications/initialized', notification: true);
        $this->initialized = true;
    }

    /** @return array<string, mixed> */
    private function request(string $method, array|\stdClass $params, string $label): array
    {
        $this->ensureInitialized();

        $result = $this->post([
            'jsonrpc' => '2.0',
            'id' => $this->nextId++,
            'method' => $method,
            'params' => $params,
        ], $label);

        if (! is_array($result)) {
            throw new CarbonMcpException("Carbon MCP {$label} answered without a result object");
        }

        return $result;
    }

    /** @return array<string, mixed>|null the JSON-RPC result, or null for a notification */
    private function post(array $payload, string $label, bool $captureSession = false, bool $notification = false, bool $retried = false): ?array
    {
        $headers = ['MCP-Protocol-Version' => self::PROTOCOL_VERSION];
        if ($this->sessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        try {
            $response = Http::timeout(60)
                ->accept('application/json, text/event-stream')
                ->withOptions(['allow_redirects' => false])
                ->withHeaders($headers)
                ->withToken($this->connector->accessToken())
                ->post(CarbonMcpConnector::mcpUrl(), $payload);
        } catch (CarbonMcpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CarbonMcpException("Carbon MCP {$label} could not reach Carbon (".class_basename($e).')');
        }

        if ($response->status() === 401) {
            // One retry with a freshly minted token; a second 401 is a sign-in problem.
            $this->connector->forgetAccessToken();
            if (! $retried) {
                return $this->post($payload, $label, $captureSession, $notification, true);
            }
            throw new CarbonMcpAuthException("Carbon MCP {$label} was refused: HTTP 401");
        }

        if ($response->failed()) {
            throw new CarbonMcpException("Carbon MCP {$label} failed: HTTP {$response->status()}");
        }

        if ($captureSession) {
            $session = $response->header('Mcp-Session-Id');
            $this->sessionId = is_string($session) && $session !== '' ? $session : null;
        }

        if ($notification) {
            return null;
        }

        return $this->decode($response, $label);
    }

    /** @return array<string, mixed>|null */
    private function decode(Response $response, string $label): ?array
    {
        $body = (string) $response->body();
        $payload = str_contains((string) $response->header('Content-Type'), 'text/event-stream')
            ? $this->lastSseMessage($body)
            : $body;

        $decoded = json_decode((string) $payload, true);
        if (! is_array($decoded)) {
            throw new CarbonMcpException("Carbon MCP {$label} answered with a body that is not JSON-RPC");
        }

        if (isset($decoded['error'])) {
            $code = is_array($decoded['error']) && is_int($decoded['error']['code'] ?? null) ? $decoded['error']['code'] : 'unknown';
            throw new CarbonMcpException("Carbon MCP {$label} returned JSON-RPC error {$code}");
        }

        return is_array($decoded['result'] ?? null) ? $decoded['result'] : null;
    }

    /** The last SSE `data:` message that is a JSON-RPC response (has an id). */
    private function lastSseMessage(string $body): ?string
    {
        $found = null;
        foreach (preg_split("/\R\R/", trim($body)) ?: [] as $event) {
            $data = [];
            foreach (preg_split("/\R/", $event) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5));
                }
            }
            if ($data === []) {
                continue;
            }
            $message = implode("\n", $data);
            $decoded = json_decode($message, true);
            if (is_array($decoded) && array_key_exists('id', $decoded)) {
                $found = $message;
            }
        }

        return $found;
    }
}
