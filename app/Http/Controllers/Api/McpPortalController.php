<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\McpAuditLog;
use App\Models\Person;
use App\Services\Mcp\PortalMcpToolDefinitions;
use App\Services\Mcp\PortalMcpToolExecutor;
use App\Support\McpInputSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Portal MCP server (Streamable HTTP transport, JSON-RPC 2.0) — the
 * client-facing sibling of {@see McpStaffController}.
 *
 * Every call acts on behalf of ONE authenticated portal Person, resolved from
 * the Teams sender's Entra Object ID by {@see \App\Http\Middleware\VerifyMcpPortalToken}
 * and stashed on the request as `mcp_portal_person`. The tool surface is fixed
 * (no per-token grants) and client-locked: scope comes from the Person, never
 * from tool arguments.
 *
 * Only the tool-related MCP methods are implemented, matching the staff server
 * and the Anthropic MCP connector's tool-only capability.
 */
class McpPortalController extends Controller
{
    private const PROTOCOL_VERSION = '2025-03-26';

    private const SERVER_NAME = 'PSA Portal';

    private const SERVER_VERSION = '1.0.0';

    /** Tools whose free-text bodies must not be written verbatim to the audit log. */
    private const BODY_REDACT_TOOLS = [
        'create_ticket',
        'add_my_ticket_reply',
    ];

    public function handle(Request $request): JsonResponse
    {
        $body = $request->json()->all() ?? [];

        // JSON-RPC batches (lists) are not supported — reject with diagnostics.
        if (is_array($body) && array_is_list($body)) {
            Log::warning('[MCP/portal] Batched request received (not supported)', [
                'count' => count($body),
                'first_method' => $body[0]['method'] ?? null,
            ]);

            return $this->error(null, -32600, 'Batched requests are not supported by this server');
        }

        $id = $body['id'] ?? null;
        $method = $body['method'] ?? null;
        $params = $body['params'] ?? [];

        if (! is_array($body) || empty($method)) {
            return $this->error($id, -32600, 'Invalid Request');
        }

        $start = microtime(true);

        try {
            return match ($method) {
                'initialize' => $this->initialize($id, $params),
                'notifications/initialized' => $this->ack(),
                'tools/list' => $this->listTools($id, $request, $start),
                'tools/call' => $this->callTool($id, $params, $request, $start),
                default => $this->error($id, -32601, "Method not found: {$method}"),
            };
        } catch (\Throwable $e) {
            Log::error('[MCP/portal] Unhandled error', [
                'method' => $method,
                'error' => $e->getMessage(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 500),
            ]);

            return $this->error($id, -32603, 'Internal error: '.$e->getMessage());
        }
    }

    private function initialize(mixed $id, array $params): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => $params['protocolVersion'] ?? self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => new \stdClass],
                'serverInfo' => [
                    'name' => self::SERVER_NAME,
                    'version' => self::SERVER_VERSION,
                ],
            ],
        ]);
    }

    /** notifications/initialized carries no id and expects no body. */
    private function ack(): JsonResponse
    {
        return response()->json(null, 204);
    }

    private function listTools(mixed $id, Request $request, float $start): JsonResponse
    {
        $tools = [];
        foreach (PortalMcpToolDefinitions::tools() as $t) {
            $schema = $t['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass];

            $errors = McpInputSchema::validationErrors($schema);
            if ($errors !== []) {
                Log::warning('[MCP/portal] Dropping invalid MCP tool schema', [
                    'tool' => $t['name'] ?? '(unknown)',
                    'errors' => array_slice($errors, 0, 10),
                ]);

                continue;
            }

            $tools[] = [
                'name' => $t['name'],
                'description' => $t['description'],
                'inputSchema' => $schema,
            ];
        }

        $this->audit('tools/list', null, null, 'success', null, $start, $request);

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => $tools],
        ]);
    }

    private function callTool(mixed $id, array $params, Request $request, float $start): JsonResponse
    {
        $name = $params['name'] ?? null;
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (! $name) {
            return $this->error($id, -32602, 'Missing tool name');
        }

        if (! PortalMcpToolDefinitions::handles((string) $name)) {
            $message = "Unknown tool: {$name}";
            $this->audit('tools/call', (string) $name, $arguments, 'error', $message, $start, $request);

            return $this->toolError($id, $message);
        }

        // Fail closed when no portal user could be resolved for this request —
        // every tool acts on behalf of a specific person.
        $person = $request->attributes->get('mcp_portal_person');
        if (! $person instanceof Person) {
            $message = 'No active portal user could be resolved for this request.';
            $this->audit('tools/call', (string) $name, $arguments, 'error', $message, $start, $request);

            return $this->toolError($id, $message);
        }

        try {
            $result = (new PortalMcpToolExecutor($person))->execute((string) $name, $arguments);
            $isError = isset($result['error']);

            $this->audit(
                'tools/call', (string) $name, $arguments,
                $isError ? 'error' : 'success',
                $isError ? (string) $result['error'] : null,
                $start, $request,
            );

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                    ],
                    'isError' => $isError,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[MCP/portal] Tool execution failed', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);

            $this->audit('tools/call', (string) $name, $arguments, 'error', $e->getMessage(), $start, $request);

            return $this->toolError($id, 'Tool execution failed: '.$e->getMessage());
        }
    }

    private function toolError(mixed $id, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [['type' => 'text', 'text' => $message]],
                'isError' => true,
            ],
        ]);
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }

    private function audit(string $method, ?string $tool, mixed $args, string $status, ?string $error, float $start, Request $request): void
    {
        try {
            McpAuditLog::create([
                'server_name' => 'portal',
                'method' => $method,
                'tool_name' => $tool,
                'arguments' => is_array($args) ? $this->auditArguments($tool, $args) : null,
                'status' => $status,
                'error_message' => $error ? mb_substr($error, 0, 1000) : null,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'actor_label' => $this->actorLabel($request),
                'source_ip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[MCP/portal] Audit log write failed: '.$e->getMessage());
        }
    }

    /**
     * Redact free-text bodies from the audit record — keep the shape and a
     * length indicator, not the customer's words.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function auditArguments(?string $tool, array $args): array
    {
        if (! in_array((string) $tool, self::BODY_REDACT_TOOLS, true)) {
            return $args;
        }

        foreach (['subject', 'body'] as $field) {
            if (array_key_exists($field, $args)) {
                $args[$field] = '['.$field.' withheld: '.mb_strlen((string) $args[$field]).' chars]';
            }
        }

        return $args;
    }

    private function actorLabel(Request $request): string
    {
        $person = $request->attributes->get('mcp_portal_person');

        return $person instanceof Person ? 'portal:'.$person->id : 'portal:unresolved';
    }
}
