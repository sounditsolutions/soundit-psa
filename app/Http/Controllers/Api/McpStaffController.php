<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\McpAuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Chet\OperatorBridgeTextSanitizer;
use App\Services\Chet\OperatorBridgeToolExecutor;
use App\Services\Chet\OperatorBridgeTools;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Support\McpStaffToken;
use App\Support\McpToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MCP server (Streamable HTTP transport, JSON-RPC 2.0) exposing the existing
 * AssistantToolExecutor surface to remote Claude clients via Anthropic's MCP
 * connector beta.
 *
 * Single bot-identity model: every call here is treated as the configured
 * service-account user (no per-Teams-user identity propagates through the
 * MCP connector — see psa-axy notes). Allowlist gating happens on the bot
 * side, not here.
 *
 * Only the three tool-related MCP methods are implemented — connector
 * limitation says only tool calls are supported anyway.
 */
class McpStaffController extends Controller
{
    private const PROTOCOL_VERSION = '2025-03-26';

    private const SERVER_NAME = 'PSA Staff';

    private const SERVER_VERSION = '1.0.0';

    private const CHET_DENIED_WRITE_TOOLS = [
        'create_ticket',
        'propose_close',
        'send_reply',
        'close_ticket',
    ];

    private const NOTE_BODY_AUDIT_PLACEHOLDER = '[note body withheld]';

    public function handle(Request $request): JsonResponse
    {
        $body = $request->json()->all() ?? [];

        // JSON-RPC 2.0 supports batched requests (arrays). MCP via Streamable
        // HTTP can send these. We don't currently use any tool that benefits
        // from batching, but reject cleanly with diagnostics rather than the
        // generic -32600 so the bot side can see what happened.
        if (is_array($body) && array_is_list($body)) {
            Log::warning('[MCP/staff] Batched request received (not supported)', [
                'count' => count($body),
                'first_method' => $body[0]['method'] ?? null,
            ]);

            return $this->error(null, -32600, 'Batched requests are not supported by this server');
        }

        $id = $body['id'] ?? null;
        $method = $body['method'] ?? null;
        $params = $body['params'] ?? [];

        if (! is_array($body) || empty($method)) {
            // Surface the actual incoming shape so we can fix mismatches. Raw
            // body capped at 1KB; sensitive tokens are in the Authorization
            // header, not the body.
            $raw = (string) $request->getContent();
            Log::warning('[MCP/staff] Invalid Request — empty method', [
                'content_type' => $request->header('Content-Type'),
                'has_body' => $raw !== '',
                'body_preview' => mb_substr($raw, 0, 1024),
                'parsed_keys' => is_array($body) ? array_keys($body) : 'non-array',
            ]);

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
            Log::error('[MCP/staff] Unhandled error', [
                'method' => $method,
                'error' => $e->getMessage(),
                'trace' => mb_substr($e->getTraceAsString(), 0, 500),
            ]);

            return $this->error($id, -32603, 'Internal error: '.$e->getMessage());
        }
    }

    private function initialize(mixed $id, array $params): JsonResponse
    {
        // We don't persist client capabilities — the server is stateless and
        // every request includes the JSON-RPC envelope. Just echo a compatible
        // protocol version + advertise the only capability we implement.
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

    /**
     * notifications/initialized has no JSON-RPC id and expects no response,
     * but Laravel needs to return something. Empty 204 keeps the wire clean.
     */
    private function ack(): JsonResponse
    {
        return response()->json(null, 204);
    }

    private function listTools(mixed $id, Request $request, float $start): JsonResponse
    {
        // Expose the full tool surface — both the general (no client context
        // required) and the client-scoped sets, deduped. For client-scoped
        // tools, inject `client_id` into the input schema — the AI picks it
        // up via find_clients() and passes it along on the call. The boundary
        // strips client_id off before dispatch, so the executor doesn't need
        // to know about MCP.
        $generalTools = array_merge(
            AssistantToolDefinitions::getTools(hasClient: false),
            [McpToolRegistry::proposeCloseTool()],
            OperatorBridgeTools::definitions(),
        );
        $generalNames = array_flip(array_column($generalTools, 'name'));

        $clientScopedTools = AssistantToolDefinitions::getTools(hasClient: true);

        // Merge by tool name (general tools win on duplicate names).
        $merged = [];
        foreach (array_merge($clientScopedTools, $generalTools) as $t) {
            $merged[$t['name']] = $t;
        }
        $allTools = array_values($merged);
        $allTools = array_values(array_filter(
            $allTools,
            fn (array $tool): bool => $this->toolAllowed($request, (string) ($tool['name'] ?? '')),
        ));

        // find_persons / find_assets accept an OPTIONAL client_id — they
        // cross-client search when omitted. The wiki tools accept an optional
        // client_id too: omitting it scopes to GLOBAL-only content (spec §6).
        // All other client-scoped tools require it.
        $clientIdOptionalFor = ['find_persons', 'find_assets', 'wiki_list_pages', 'wiki_search', 'wiki_get_page'];

        $translated = array_map(function ($t) use ($generalNames, $clientIdOptionalFor) {
            $schema = $t['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass];
            $isClientScoped = ! isset($generalNames[$t['name']]);

            if ($isClientScoped) {
                $props = (array) ($schema['properties'] ?? []);
                $clientIdRequired = ! in_array($t['name'], $clientIdOptionalFor, true);
                $props['client_id'] = [
                    'type' => 'integer',
                    'description' => $clientIdRequired
                        ? 'PSA client ID (required). Use find_clients(query) to resolve a name to an ID.'
                        : 'PSA client ID (optional). Provide to scope the search to one client; omit to search across all clients.',
                ];
                $schema['properties'] = $props;
                if ($clientIdRequired) {
                    $required = $schema['required'] ?? [];
                    if (! in_array('client_id', $required, true)) {
                        $required[] = 'client_id';
                    }
                    $schema['required'] = $required;
                }
            }

            return [
                'name' => $t['name'],
                'description' => $t['description'] ?? '',
                'inputSchema' => $schema,
            ];
        }, $allTools);

        $this->audit('tools/list', null, null, 'success', null, $start, $request);

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => $translated],
        ]);
    }

    private function callTool(mixed $id, array $params, Request $request, float $start): JsonResponse
    {
        $name = $params['name'] ?? null;
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (! $name) {
            return $this->error($id, -32602, 'Missing tool name');
        }

        if (! $this->toolAllowed($request, (string) $name)) {
            $message = "Tool not allowed for this token: {$name}";
            $this->audit('tools/call', (string) $name, $arguments, 'error', $message, $start, $request);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => $message]],
                    'isError' => true,
                ],
            ]);
        }

        // Extract client_id from the arguments — this is how client-scoped
        // tools get their context in the MCP world. Strip it before dispatch
        // so it doesn't get passed to tools that don't expect it.
        //
        // Isolation control (spec §6): only a positive, numeric client_id is
        // honored. Anything malformed — 0, negative, non-numeric "garbage" —
        // collapses to null (GLOBAL-only scope), never a `client_id = 0` query.
        $clientId = $this->positiveIntegerArgument($arguments['client_id'] ?? null);
        unset($arguments['client_id']);

        if ($this->isChetTicketNoteWrite($request, (string) $name) && $clientId === null) {
            $message = 'client_id is required for Chet ticket-note writes.';
            $this->audit('tools/call', (string) $name, $arguments, 'error', $message, $start, $request);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => $message]],
                    'isError' => true,
                ],
            ]);
        }

        try {
            if (OperatorBridgeTools::handles((string) $name)) {
                $result = app(OperatorBridgeToolExecutor::class)->execute((string) $name, $arguments);
            } else {
                $userId = $this->userIdForToolCall($request, (string) $name);
                $executor = new AssistantToolExecutor(ticket: null, clientId: $clientId, userId: $userId);
                $result = $executor->execute($name, is_array($arguments) ? $arguments : []);
            }
            $isError = is_array($result) && isset($result['error']);

            $this->audit(
                'tools/call', $name, $arguments,
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
            Log::warning('[MCP/staff] Tool execution failed', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);

            $this->audit('tools/call', $name, $arguments, 'error', $e->getMessage(), $start, $request);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => 'Tool execution failed: '.$e->getMessage()]],
                    'isError' => true,
                ],
            ]);
        }
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
                'server_name' => 'staff',
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
            Log::warning('[MCP/staff] Audit log write failed: '.$e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function auditArguments(?string $tool, array $args): array
    {
        $redacted = app(ActionRedactor::class)->redactParams($args);

        if ($tool === 'post_to_operator' && isset($redacted['message']) && is_string($redacted['message'])) {
            $redacted['message'] = app(OperatorBridgeTextSanitizer::class)
                ->sanitizeForPrompt($redacted['message'], '[message detail withheld - unsafe content]');
        }

        if ($tool === 'add_ticket_note') {
            return $this->auditTicketNoteArguments($redacted);
        }

        return $redacted;
    }

    /** @return array<string, mixed> */
    private function auditTicketNoteArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['client_id', 'ticket_id'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'body') {
                $safe['body'] = self::NOTE_BODY_AUDIT_PLACEHOLDER;
            }
        }

        return $safe;
    }

    private function positiveIntegerArgument(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function toolAllowed(Request $request, string $toolName): bool
    {
        $token = $request->attributes->get('mcp_staff_token');
        if (! $token instanceof McpStaffToken) {
            return false;
        }

        if ($this->isChetToken($request) && in_array($toolName, self::CHET_DENIED_WRITE_TOOLS, true)) {
            return false;
        }

        if (OperatorBridgeTools::handles($toolName)) {
            return $token->allowedTools !== null
                && $token->allows($toolName);
        }

        return $token->allows($toolName);
    }

    private function userIdForToolCall(Request $request, string $toolName): ?int
    {
        if ($this->isChetTicketNoteWrite($request, $toolName)) {
            return $this->requiredChetAiActorUserId();
        }

        // Existing service-account identity for non-Chet MCP calls.
        return \App\Support\TriageConfig::systemUserId();
    }

    private function isChetToken(Request $request): bool
    {
        $token = $request->attributes->get('mcp_staff_token');

        return $token instanceof McpStaffToken
            && mb_strtolower((string) $token->label) === 'chet';
    }

    private function isChetTicketNoteWrite(Request $request, string $toolName): bool
    {
        return $toolName === 'add_ticket_note' && $this->isChetToken($request);
    }

    private function requiredChetAiActorUserId(): int
    {
        $configured = Setting::getValue('triage_system_user_id');

        if (! is_numeric($configured) || (int) $configured <= 0) {
            throw new \RuntimeException('AI actor user is not configured for Chet ticket-note writes.');
        }

        $actorId = (int) $configured;
        if (! User::whereKey($actorId)->exists()) {
            throw new \RuntimeException('Configured AI actor user does not exist for Chet ticket-note writes.');
        }

        return $actorId;
    }

    private function actorLabel(Request $request): string
    {
        $token = $request->attributes->get('mcp_staff_token');

        return $token instanceof McpStaffToken ? $token->actorLabel() : 'teams-bot';
    }
}
