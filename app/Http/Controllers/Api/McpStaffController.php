<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CippMcpTool;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\Ticket;
use App\Services\Agent\RequestToolTool;
use App\Services\Agent\SendReplyTool;
use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Chet\ChetDataSurfaceToolExecutor;
use App\Services\Chet\ChetDataSurfaceTools;
use App\Services\Chet\OperatorBridgeTextSanitizer;
use App\Services\Chet\OperatorBridgeToolExecutor;
use App\Services\Chet\OperatorBridgeTools;
use App\Services\Cipp\CippMcpDynamicToolExecutor;
use App\Services\Mcp\StaffPsaActionToolExecutor;
use App\Services\Mcp\StaffTacticalActionToolExecutor;
use App\Services\Mcp\StaffTacticalAdminToolExecutor;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Support\McpInputSchema;
use App\Support\McpStaffToken;
use App\Support\McpToolInstructions;
use App\Support\McpToolRegistry;
use App\Support\TacticalConfig;
use App\Support\TechnicianConfig;
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

    private const WHOAMI_TOOL = 'whoami';

    /** Write tools that can be forced to carry an explicit client_id scope. */
    private const EXPLICIT_CLIENT_SCOPE_WRITE_TOOLS = [
        'add_ticket_note',
        'propose_close',
        'send_reply',
    ];

    private const NOTE_BODY_AUDIT_PLACEHOLDER = '[note body withheld]';

    private const TICKET_DESCRIPTION_AUDIT_PLACEHOLDER = '[ticket description withheld]';

    private const WIKI_FACT_STATEMENT_AUDIT_PLACEHOLDER = '[wiki fact statement withheld]';

    private const WIKI_PAGE_TITLE_AUDIT_PLACEHOLDER = '[wiki page title withheld]';

    private const WIKI_PAGE_BODY_AUDIT_PLACEHOLDER = '[wiki page body withheld]';

    private const TACTICAL_CUSTOM_FIELD_AUDIT_PLACEHOLDER = '[custom field value withheld]';

    private const WIKI_WRITE_TOOLS = [
        'wiki_add_fact',
        'wiki_create_page',
        'wiki_update_page',
    ];

    private const WIKI_PAGE_AUTHORING_TOOLS = [
        'wiki_create_page',
        'wiki_update_page',
    ];

    private const PSA_ACTION_TOOLS = [
        'create_ticket',
        'send_email',
        'stage_email',
        'write_public_note',
        'stage_public_note',
        'propose_merge',
    ];

    private const BODY_LENGTH_AUDIT_TOOLS = [
        'send_email',
        'stage_email',
        'write_public_note',
        'stage_public_note',
    ];

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
            [$this->whoamiToolDefinition()],
            AssistantToolDefinitions::getTools(hasClient: false),
            [
                McpToolRegistry::proposeCloseTool(),
                McpToolRegistry::sendReplyTool(),
                McpToolRegistry::requestToolTool(),
                McpToolRegistry::wikiAddFactTool(),
                McpToolRegistry::wikiCreatePageTool(),
                McpToolRegistry::wikiUpdatePageTool(),
            ],
            McpToolRegistry::psaActionTools(),
            TacticalConfig::isConfigured() ? McpToolRegistry::tacticalAdminTools() : [],
            ChetDataSurfaceTools::generalTools(),
            OperatorBridgeTools::definitions(),
        );
        $generalNames = array_flip(array_column($generalTools, 'name'));

        $clientScopedTools = array_merge(
            AssistantToolDefinitions::getTools(hasClient: true),
            ChetDataSurfaceTools::clientTools(),
            McpToolRegistry::dynamicCippReadTools(),
            McpToolRegistry::dynamicCippWriteTools(),
            TacticalConfig::isConfigured() ? McpToolRegistry::tacticalActionTools() : [],
        );

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

        $toolInstructions = McpToolInstructions::all();
        $translated = array_values(array_filter(array_map(function ($t) use ($request, $generalNames, $clientIdOptionalFor, $toolInstructions): ?array {
            $schema = $t['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass];
            $requiresExplicitClientScope = $this->requiresExplicitClientScope($request, (string) $t['name']);
            $isPsaActionTool = $this->isPsaActionTool((string) $t['name']);
            $isTacticalActionTool = $this->isTacticalActionTool((string) $t['name']);
            $isTacticalAdminTool = $this->isTacticalAdminTool((string) $t['name']);
            $requiresTacticalAdminClientScope = $isTacticalAdminTool && StaffTacticalAdminToolExecutor::requiresClient((string) $t['name']);
            $isClientScoped = ! isset($generalNames[$t['name']]) || $requiresExplicitClientScope || $isPsaActionTool || $isTacticalActionTool || $requiresTacticalAdminClientScope;

            if ($isClientScoped) {
                $props = (array) ($schema['properties'] ?? []);
                $clientIdRequired = $requiresExplicitClientScope || $isPsaActionTool || $isTacticalActionTool || $requiresTacticalAdminClientScope || ! in_array($t['name'], $clientIdOptionalFor, true);
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

            return $this->publishableTool([
                'name' => $t['name'],
                'description' => McpToolInstructions::appendToDescription((string) $t['name'], (string) ($t['description'] ?? ''), $toolInstructions),
                'inputSchema' => $schema,
            ]);
        }, $allTools), static fn (?array $tool): bool => $tool !== null));

        $this->audit('tools/list', null, null, 'success', null, $start, $request);

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => $translated],
        ]);
    }

    /** @param  array{name?: mixed, inputSchema?: mixed}  $tool */
    private function publishableTool(array $tool): ?array
    {
        $toolName = is_string($tool['name'] ?? null) && $tool['name'] !== ''
            ? $tool['name']
            : '(unknown)';
        $errors = McpInputSchema::validationErrors($tool['inputSchema'] ?? null);

        if ($errors !== []) {
            Log::warning('[MCP/staff] Dropping invalid MCP tool schema', [
                'tool' => $toolName,
                'errors' => array_slice($errors, 0, 10),
            ]);

            return null;
        }

        return $tool;
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
        $hasClientIdArgument = array_key_exists('client_id', $arguments);
        $clientId = $this->positiveIntegerArgument($arguments['client_id'] ?? null);
        unset($arguments['client_id']);
        $auditArguments = $arguments;
        if ((string) $name === 'create_ticket' && $clientId !== null) {
            $auditArguments['client_id'] = $clientId;
        }

        if ($this->isWikiGlobalScopeWrite((string) $name, $arguments) && $hasClientIdArgument) {
            $message = 'client_id must be omitted for wiki_add_fact global-scope writes.';
            $this->audit('tools/call', (string) $name, $auditArguments, 'error', $message, $start, $request);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => $message]],
                    'isError' => true,
                ],
            ]);
        }

        if ($this->isWikiPageAuthoringWrite((string) $name) && $hasClientIdArgument) {
            $message = 'client_id must be omitted for wiki page authoring writes.';
            $this->audit('tools/call', (string) $name, $auditArguments, 'error', $message, $start, $request);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => $message]],
                    'isError' => true,
                ],
            ]);
        }

        if ($this->isWikiClientScopeWrite((string) $name, $arguments) && $clientId === null) {
            $message = 'client_id is required for wiki_add_fact client-scope writes.';
            $this->audit('tools/call', (string) $name, $auditArguments, 'error', $message, $start, $request);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => $message]],
                    'isError' => true,
                ],
            ]);
        }

        if ($this->requiresExplicitClientScope($request, (string) $name) && $clientId === null) {
            $message = "client_id is required for {$name} when explicit client scope is required.";
            $this->audit('tools/call', (string) $name, $auditArguments, 'error', $message, $start, $request);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => $message]],
                    'isError' => true,
                ],
            ]);
        }

        if ($this->isPsaActionTool((string) $name) && $clientId === null) {
            $message = "client_id is required for {$name}.";
            $this->audit('tools/call', (string) $name, $auditArguments, 'error', $message, $start, $request);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => $message]],
                    'isError' => true,
                ],
            ]);
        }

        if ($this->isTacticalActionTool((string) $name) && $clientId === null) {
            $message = "client_id is required for {$name}.";
            $this->audit('tools/call', (string) $name, $auditArguments, 'error', $message, $start, $request);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => $message]],
                    'isError' => true,
                ],
            ]);
        }

        if ($this->isTacticalAdminTool((string) $name) && StaffTacticalAdminToolExecutor::requiresClient((string) $name) && $clientId === null) {
            $message = "client_id is required for {$name}.";
            $this->audit('tools/call', (string) $name, $auditArguments, 'error', $message, $start, $request);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => $message]],
                    'isError' => true,
                ],
            ]);
        }

        if (CippMcpTool::handles((string) $name) && $clientId === null) {
            $message = "client_id is required for {$name}.";
            $this->audit('tools/call', (string) $name, $auditArguments, 'error', $message, $start, $request);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => $message]],
                    'isError' => true,
                ],
            ]);
        }

        // Held ticket actions validate a supplied client scope before execution.
        // Tokens without explicit-scope enforcement may still use the legacy
        // staff-trust path that derives client context from the ticket.
        if ($this->requiresExplicitClientScope($request, (string) $name)
            && $this->isHeldTicketAction((string) $name)
            && $clientId !== null
        ) {
            if (! $this->ticketBelongsToClient($arguments, $clientId)) {
                $message = 'Ticket not found or belongs to a different client';
                $this->audit('tools/call', (string) $name, $auditArguments, 'error', $message, $start, $request);

                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'content' => [['type' => 'text', 'text' => $message]],
                        'isError' => true,
                    ],
                ]);
            }
        }

        if (ChetDataSurfaceTools::requiresClient((string) $name) && $clientId === null) {
            $message = "client_id is required for {$name}.";
            $this->audit('tools/call', (string) $name, $auditArguments, 'error', $message, $start, $request);

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
            if ($name === self::WHOAMI_TOOL) {
                $result = $this->whoami($request);
            } elseif (OperatorBridgeTools::handles((string) $name)) {
                $token = $request->attributes->get('mcp_staff_token');
                $result = app(OperatorBridgeToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    $token instanceof McpStaffToken ? $token->label : null,
                );
            } elseif (ChetDataSurfaceTools::handles((string) $name)) {
                $result = app(ChetDataSurfaceToolExecutor::class)->execute((string) $name, $arguments, $clientId);
            } elseif (CippMcpTool::handles((string) $name)) {
                $result = app(CippMcpDynamicToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    $clientId !== null ? Client::find($clientId) : null,
                    $clientId,
                );
            } elseif ($this->isTacticalActionTool((string) $name)) {
                $result = app(StaffTacticalActionToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    (int) $clientId,
                    $this->actorLabel($request),
                );
            } elseif ($this->isTacticalAdminTool((string) $name)) {
                $result = app(StaffTacticalAdminToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    $clientId,
                    $this->actorLabel($request),
                );
            } elseif ($this->isPsaActionTool((string) $name)) {
                $result = app(StaffPsaActionToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    (int) $clientId,
                    $this->actorLabel($request),
                );
            } elseif ((string) $name === 'send_reply') {
                $result = $this->sendReply($arguments, $request);
            } elseif ((string) $name === 'request_tool') {
                $result = $this->requestTool($arguments);
            } else {
                $userId = $this->userIdForToolCall($request, (string) $name);
                $executor = new AssistantToolExecutor(ticket: null, clientId: $clientId, userId: $userId);
                $result = $executor->execute($name, is_array($arguments) ? $arguments : []);
            }
            $isError = is_array($result) && isset($result['error']);

            $this->audit(
                'tools/call', $name, $auditArguments,
                $isError ? 'error' : 'success',
                $isError ? (string) $result['error'] : null,
                $start, $request,
            );

            $response = response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                    ],
                    'isError' => $isError,
                ],
            ]);

            if (in_array((string) $name, ['tactical_open_remote_control', 'tactical_get_or_create_installer', 'tactical_generate_installer'], true) && ! $isError) {
                $response->headers->set('Cache-Control', 'no-store');
            }

            return $response;
        } catch (\Throwable $e) {
            Log::warning('[MCP/staff] Tool execution failed', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);

            $this->audit('tools/call', $name, $auditArguments, 'error', $e->getMessage(), $start, $request);

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

    /** @return array<string, mixed> */
    private function sendReply(array $arguments, Request $request): array
    {
        $ticketId = $this->positiveIntegerArgument($arguments['ticket_id'] ?? null);
        if ($ticketId === null) {
            return ['error' => 'ticket_id is required'];
        }

        $reason = trim((string) ($arguments['reason'] ?? ''));
        if ($reason === '') {
            return ['error' => 'reason is required'];
        }

        $ticket = Ticket::find($ticketId);
        if (! $ticket) {
            return ['error' => 'Ticket not found'];
        }

        $input = [
            'reason' => $reason,
        ];

        if (array_key_exists('body', $arguments) && is_string($arguments['body']) && trim($arguments['body']) !== '') {
            $input['body'] = $arguments['body'];
        }

        $message = app(SendReplyTool::class)->executeHeld($ticket, $input, $this->actorLabel($request));

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'message' => $message,
        ];
    }

    /** @return array<string, mixed> */
    private function requestTool(array $arguments): array
    {
        $ticketId = $this->positiveIntegerArgument($arguments['ticket_id'] ?? null);
        if ($ticketId === null) {
            return ['error' => 'ticket_id is required'];
        }

        $ticket = Ticket::find($ticketId);
        if (! $ticket) {
            return ['error' => 'Ticket not found'];
        }

        $message = app(RequestToolTool::class)->execute($ticket, $arguments);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'message' => $message,
        ];
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
        if ($tool === 'send_reply') {
            return $this->auditSendReplyArguments($args);
        }

        if ($tool === 'create_ticket') {
            return $this->auditCreateTicketArguments($args);
        }

        if (in_array((string) $tool, self::BODY_LENGTH_AUDIT_TOOLS, true)) {
            return $this->auditBodyLengthArguments($args);
        }

        if ($tool === 'propose_merge') {
            return $this->auditProposeMergeArguments($args);
        }

        $redacted = app(ActionRedactor::class)->redactParams($args);

        if ($tool === 'post_to_operator' && isset($redacted['message']) && is_string($redacted['message'])) {
            $redacted['message'] = app(OperatorBridgeTextSanitizer::class)
                ->sanitizeForPrompt($redacted['message'], '[message detail withheld - unsafe content]');
        }

        if ($tool === 'add_ticket_note') {
            return $this->auditTicketNoteArguments($redacted);
        }

        if ($tool === 'wiki_add_fact') {
            return $this->auditWikiAddFactArguments($redacted);
        }

        if ($this->isWikiPageAuthoringWrite((string) $tool)) {
            return $this->auditWikiPageAuthoringArguments($redacted);
        }

        if ($this->isTacticalAdminTool((string) $tool)) {
            return $this->auditTacticalAdminArguments($redacted);
        }

        return $redacted;
    }

    /** @return array<string, mixed> */
    private function auditCreateTicketArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['client_id', 'subject', 'priority', 'reason'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'description') {
                $safe['description'] = self::TICKET_DESCRIPTION_AUDIT_PLACEHOLDER;
                $safe['description_length'] = is_string($value) ? mb_strlen($value) : 0;
            }
        }

        return $safe;
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

    /** @return array<string, mixed> */
    private function auditSendReplyArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['client_id', 'ticket_id', 'reason'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'body') {
                $safe['body_length'] = is_string($value) ? mb_strlen($value) : 0;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditBodyLengthArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['client_id', 'ticket_id', 'reason'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'body') {
                $safe['body_length'] = is_string($value) ? mb_strlen($value) : 0;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditProposeMergeArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['client_id', 'primary_ticket_id', 'secondary_ticket_id'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'reason') {
                $safe['reason_length'] = is_string($value) ? mb_strlen($value) : 0;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditWikiAddFactArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['client_id', 'scope', 'page_slug', 'section_anchor', 'subject_key'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'statement') {
                $safe['statement'] = self::WIKI_FACT_STATEMENT_AUDIT_PLACEHOLDER;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditWikiPageAuthoringArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['slug', 'change_summary'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'title') {
                $safe['title'] = self::WIKI_PAGE_TITLE_AUDIT_PLACEHOLDER;
            }

            if ($normalized === 'body_md') {
                $safe['body_md'] = self::WIKI_PAGE_BODY_AUDIT_PLACEHOLDER;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditTacticalAdminArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['asset_id', 'hostname', 'field_key', 'platform', 'reason', 'workstation_policy_id', 'server_policy_id'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'value') {
                $safe['value'] = self::TACTICAL_CUSTOM_FIELD_AUDIT_PLACEHOLDER;
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

        if ($toolName === self::WHOAMI_TOOL) {
            return true;
        }

        if ($token->allowedTools !== null && ! in_array($toolName, McpToolRegistry::allToolNames(), true)) {
            return false;
        }

        if (in_array($toolName, self::WIKI_WRITE_TOOLS, true)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if ($this->isPsaActionTool($toolName)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if ($this->isTacticalActionTool($toolName)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if ($this->isTacticalAdminTool($toolName)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if (CippMcpTool::handles($toolName)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if (OperatorBridgeTools::handles($toolName) || ChetDataSurfaceTools::handles($toolName)) {
            return $token->allowedTools !== null
                && $token->allows($toolName);
        }

        if (str_starts_with($toolName, 'tactical_')) {
            return false;
        }

        return $token->allows($toolName);
    }

    private function userIdForToolCall(Request $request, string $toolName): ?int
    {
        if ($this->usesAiActorForWrite($request, $toolName)) {
            return TechnicianConfig::requiredAiActorUserId();
        }

        // Existing service-account identity for MCP calls without ai_actor.
        return \App\Support\TriageConfig::systemUserId();
    }

    private function usesAiActorForWrite(Request $request, string $toolName): bool
    {
        $token = $request->attributes->get('mcp_staff_token');

        return $token instanceof McpStaffToken
            && $token->aiActor
            && (in_array($toolName, ['add_ticket_note'], true) || in_array($toolName, self::WIKI_WRITE_TOOLS, true));
    }

    private function requiresExplicitClientScope(Request $request, string $toolName): bool
    {
        $token = $request->attributes->get('mcp_staff_token');

        return $token instanceof McpStaffToken
            && $token->requireExplicitClientScope
            && in_array($toolName, self::EXPLICIT_CLIENT_SCOPE_WRITE_TOOLS, true);
    }

    private function isHeldTicketAction(string $toolName): bool
    {
        return in_array($toolName, ['propose_close', 'send_reply'], true);
    }

    private function ticketBelongsToClient(array $arguments, ?int $clientId): bool
    {
        if ($clientId === null) {
            return false;
        }

        $ticketClientId = Ticket::whereKey((int) ($arguments['ticket_id'] ?? 0))->value('client_id');

        return $ticketClientId !== null && (int) $ticketClientId === $clientId;
    }

    private function isWikiClientScopeWrite(string $toolName, array $arguments): bool
    {
        return $toolName === 'wiki_add_fact'
            && mb_strtolower(trim((string) ($arguments['scope'] ?? ''))) === 'client';
    }

    private function isWikiGlobalScopeWrite(string $toolName, array $arguments): bool
    {
        return $toolName === 'wiki_add_fact'
            && mb_strtolower(trim((string) ($arguments['scope'] ?? ''))) === 'global';
    }

    private function isWikiPageAuthoringWrite(string $toolName): bool
    {
        return in_array($toolName, self::WIKI_PAGE_AUTHORING_TOOLS, true);
    }

    private function isPsaActionTool(string $toolName): bool
    {
        return in_array($toolName, self::PSA_ACTION_TOOLS, true);
    }

    private function isTacticalActionTool(string $toolName): bool
    {
        return StaffTacticalActionToolExecutor::handles($toolName);
    }

    private function isTacticalAdminTool(string $toolName): bool
    {
        return StaffTacticalAdminToolExecutor::handles($toolName);
    }

    /** @return array<string, mixed> */
    private function whoamiToolDefinition(): array
    {
        return [
            'name' => self::WHOAMI_TOOL,
            'description' => 'Return this MCP token label, directive, and effective allowed tools.',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) [],
                'required' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function whoami(Request $request): array
    {
        $token = $request->attributes->get('mcp_staff_token');

        return [
            'label' => $token instanceof McpStaffToken && $token->label !== null ? $token->label : McpStaffToken::LEGACY_ACTOR_LABEL,
            'directive' => $token instanceof McpStaffToken ? $token->directiveOrDefault() : McpToken::defaultDirective(),
            'allowed_tools' => $token instanceof McpStaffToken && $token->allowedTools !== null
                ? array_values(array_unique(array_merge([self::WHOAMI_TOOL], $token->allowedTools)))
                : null,
        ];
    }

    private function actorLabel(Request $request): string
    {
        $token = $request->attributes->get('mcp_staff_token');

        return $token instanceof McpStaffToken ? $token->actorLabel() : McpStaffToken::LEGACY_ACTOR_LABEL;
    }
}
