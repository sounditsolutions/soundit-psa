<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\CippMcpTool;
use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\McpToken;
use App\Models\Person;
use App\Models\Ticket;
use App\Services\Agent\RequestToolTool;
use App\Services\Agent\SendReplyTool;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Chet\ChetDataSurfaceToolExecutor;
use App\Services\Chet\ChetDataSurfaceTools;
use App\Services\Chet\OperatorBridgeTextSanitizer;
use App\Services\Chet\OperatorBridgeToolExecutor;
use App\Services\Chet\OperatorBridgeTools;
use App\Services\Cipp\CippMcpDynamicToolExecutor;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use App\Services\Mcp\StaffPsaActionToolExecutor;
use App\Services\Mcp\StaffPsaTaxonomyToolExecutor;
use App\Services\Mcp\StaffTacticalActionToolExecutor;
use App\Services\Mcp\StaffTacticalAdminToolExecutor;
use App\Services\Signals\SignalNudgeNotice;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Support\McpInputSchema;
use App\Support\McpStaffToken;
use App\Support\McpToolInstructions;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use App\Support\McpToolSurface;
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

    private const TOOL_SURFACE_TOOL = 'list_tool_surface';

    /**
     * Appended to every grant-check denial. A denied call is the failure that
     * doubles as a refresh signal: the token's allowed-tool surface may have
     * drifted from the tools/list snapshot the client cached at startup (a grant
     * added or revoked since — {@see toolAllowed()} re-checks live DB grants on
     * every call, so the client list is a cache, not truth). Per the wake-spec
     * authority/trigger separation, this hint stays instruction-free: it states
     * the fact and points at whoami (the live set) and the token directive (the
     * authority), never issuing an imperative. A pointer that carries no
     * authority is inert on any pipe — a forged copy cannot instruct.
     */
    private const TOOL_SURFACE_DRIFT_HINT = "The token's allowed-tool surface may have changed since this client cached tools/list; whoami returns the current allowed tools, list_tool_surface classifies the full tool surface (granted / available_ungranted / unavailable_config), and your token directive governs how to proceed.";

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

    private const TACTICAL_SCRIPT_BODY_AUDIT_PLACEHOLDER = '[script body withheld]';

    /**
     * Curated CIPP READS whose blast radius is large enough that an operator must grant
     * them by name — they are never auto-inherited by the legacy full-surface token
     * (psa-4k6m, caught by the psa-4k6m.2 security lane).
     *
     * *** THIS IS NOT A BLOCKLIST AND MUST NOT BECOME ONE. *** Charlie's standing
     * directive is "wire the tools, make sure they work correctly and let me/the MSP
     * decide which ones to allow." A tool the legacy token inherits SILENTLY is a tool
     * the operator never got to decide about — so requiring an explicit grant is that
     * directive, enforced, not an exception to it. Everything here stays fully grantable;
     * it just cannot arrive by default.
     *
     * The bar for adding a name: the read is CATEGORICALLY wider than its siblings, not
     * merely sensitive. cipp_list_tenant_mailbox_rules returns EVERY mailbox's inbox
     * rules in a tenant, where every other curated CIPP read is either one user's data
     * or a single-purpose list. If a future read is only "a bit sensitive", it does not
     * belong here — the per-token grant already covers it.
     */
    private const CIPP_EXPLICIT_GRANT_READ_TOOLS = [
        'cipp_list_tenant_mailbox_rules',
    ];

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
        'update_ticket',
        'set_ticket_status',
        'assign_ticket',
        'assign_asset',
        'unassign_asset',
        'set_ticket_contact',
        'move_ticket_to_client',
    ];

    private const PSA_TICKET_SCOPED_TOOLS = [
        'update_ticket',
        'set_ticket_status',
        'assign_ticket',
        'assign_asset',
        'unassign_asset',
        'set_ticket_contact',
        'move_ticket_to_client',
    ];

    /** PSA records write-surface (P2a/P2b/P2c) — native client + contact + asset CRUD, dispatched through StaffPsaActionToolExecutor. */
    private const PSA_RECORDS_TOOLS = [
        'create_client',
        'update_client',
        'update_client_site_notes',
        'delete_client',
        'create_contact',
        'update_contact',
        'set_primary_contact',
        'move_contact_to_client',
        'delete_contact',
        'create_asset',
        'update_asset',
        'retire_asset',
        'restore_asset',
        'link_asset_user',
        'unlink_asset_user',
        'set_primary_asset_user',
    ];

    /** psa_records tools that act on an existing client — client_id is the required target, not ambient scope. */
    private const PSA_RECORDS_CLIENT_SCOPED_TOOLS = [
        'update_client',
        'update_client_site_notes',
        'delete_client',
    ];

    /**
     * psa_records tools that act on an existing contact — contact_id is the entity
     * key; client_id is derived server-side from the contact and a supplied
     * client_id is rejected (mirrors PSA_TICKET_SCOPED_TOOLS' derive-from-ticket_id).
     */
    private const PSA_RECORDS_CONTACT_SCOPED_TOOLS = [
        'update_contact',
        'set_primary_contact',
        'move_contact_to_client',
        'delete_contact',
    ];

    /**
     * psa_records tools that act on an existing asset — asset_id is the entity
     * key; client_id is derived server-side (withTrashed, since restore_asset
     * targets a soft-deleted asset) and a supplied client_id is rejected.
     */
    private const PSA_RECORDS_ASSET_SCOPED_TOOLS = [
        'update_asset',
        'retire_asset',
        'restore_asset',
        'link_asset_user',
        'unlink_asset_user',
        'set_primary_asset_user',
    ];

    /**
     * PSA read surface (P3+) — grant-gated reads executed via AssistantToolExecutor.
     * list_client_contracts/get_contract are client-scoped; the email-item and
     * phone-call tools are cross-client, with client_id as an optional filter.
     */
    private const PSA_READ_TOOLS = [
        'list_client_contracts',
        'get_contract',
        'list_email_items',
        'get_email_item',
        'list_phone_calls',
        'get_phone_call',
        // psa-ij59: invoicing reads. Cross-client like the email/call pairs above,
        // with client_id an optional filter. Unlike the contract reads, these DO
        // expose internal cost/margin — Charlie's explicit ruling, 2026-07-20.
        'list_invoices',
        'get_invoice',
    ];

    /**
     * Intake MANAGE surface (W2 Task 2/3) — the 5 front-door verbs that act on
     * unresolved email/call intake items, dispatched through
     * StaffPsaActionToolExecutor. None carry client_id; scope lives on the
     * targeted email/call/ticket ids themselves.
     */
    private const INTAKE_MANAGE_TOOLS = [
        'link_email_to_ticket',
        'create_ticket_from_email',
        'dismiss_email_item',
        'link_call_to_ticket',
        'create_ticket_from_call',
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
        // required) and the client-scoped sets, deduped. The config-gated
        // assemblies live in McpToolSurface so list_tool_surface classifies
        // the same surface this method publishes. For client-scoped tools,
        // inject `client_id` into the input schema — the AI picks it up via
        // find_clients() and passes it along on the call. The boundary strips
        // client_id off before dispatch, so the executor doesn't need to know
        // about MCP.
        $generalTools = array_merge(
            [$this->whoamiToolDefinition(), $this->toolSurfaceToolDefinition()],
            McpToolSurface::liveGeneralToolDefinitions(),
        );
        $generalNames = array_flip(array_column($generalTools, 'name'));

        $clientScopedTools = McpToolSurface::liveClientScopedToolDefinitions();

        // Merge by tool name (general tools win on duplicate names).
        $merged = [];
        foreach (array_merge($clientScopedTools, $generalTools) as $t) {
            $merged[$t['name']] = $t;
        }
        $allTools = array_values($merged);

        // Unified staged/immediate surface: retire the paired stage_* names
        // from the advertised list and fold each into its canonical tool as a
        // `staged` parameter, with the schema shaped by this token's granted
        // mode (staged-only tokens see the staged variant's requirements).
        $staffToken = $request->attributes->get('mcp_staff_token');
        $allTools = McpToolModes::unifyDefinitionsForList(
            $allTools,
            $staffToken instanceof McpStaffToken ? $staffToken : null,
        );
        // Resolved once for the whole filter: toolAllowed()'s liveness conjunct would
        // otherwise re-assemble the live surface for every tool in the list.
        $liveLookup = $this->liveToolNameLookup();

        $allTools = array_values(array_filter(
            $allTools,
            fn (array $tool): bool => $this->toolAllowed($request, (string) ($tool['name'] ?? ''), $liveLookup),
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
            $isPsaTicketScopedTool = $this->isPsaTicketScopedTool((string) $t['name']);
            $isCippWriteTool = $this->isCippWriteTool((string) $t['name']);
            $isTacticalActionTool = $this->isTacticalActionTool((string) $t['name']);
            $isTacticalAdminTool = $this->isTacticalAdminTool((string) $t['name']);
            $requiresTacticalAdminClientScope = $isTacticalAdminTool && StaffTacticalAdminToolExecutor::requiresClient((string) $t['name']);
            $isClientScoped = ! isset($generalNames[$t['name']]) || $requiresExplicitClientScope || $isPsaActionTool || $isCippWriteTool || $isTacticalActionTool || $requiresTacticalAdminClientScope;

            if ($isClientScoped) {
                $props = (array) ($schema['properties'] ?? []);
                $clientIdRequired = $requiresExplicitClientScope || ($isPsaActionTool && ! $isPsaTicketScopedTool) || $isCippWriteTool || $isTacticalActionTool || $requiresTacticalAdminClientScope || ! in_array($t['name'], $clientIdOptionalFor, true);
                if (! $isPsaTicketScopedTool) {
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

        // Unified staged/immediate boundary. Retired stage_* names remain
        // callable as thin aliases that force staged=true on their canonical
        // tool; stageable canonicals carry a `staged` argument instead. The
        // grant gate and mode gate both run on the canonical name, then the
        // call is dispatched under the internal (staged or immediate) name so
        // every executor path, cooldown key, and audit action_type is
        // identical to the legacy paired call.
        $requestedName = (string) $name;
        $stageable = false;
        $staged = false;
        if (($aliasCanonical = McpToolModes::canonicalForAlias($requestedName)) !== null) {
            $name = $aliasCanonical;
            $stageable = true;
            $staged = true;
            unset($arguments['staged']);
        } elseif (McpToolModes::isStageable($requestedName)) {
            $stageable = true;
            $staged = filter_var($arguments['staged'] ?? false, FILTER_VALIDATE_BOOLEAN);
            unset($arguments['staged']);
        }

        if (! $this->toolAllowed($request, (string) $name)) {
            $message = "Tool not allowed for this token: {$requestedName}";
            $this->audit('tools/call', $requestedName, $arguments, 'error', $message, $start, $request);

            // The denial itself is the refresh signal: surface the drift hint to
            // the model so a stale cached tools/list self-heals. The audit above
            // keeps the bare reason; only the client-facing text carries the hint.
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => $message.' '.self::TOOL_SURFACE_DRIFT_HINT]],
                    'isError' => true,
                ],
            ]);
        }

        // Mode gate: staged=false (immediate execution) needs the per-tool
        // immediate mode grant; a staged-only grant auto-downgrades the call
        // to a staged proposal rather than failing it — staging is strictly
        // the safer path and keeps the agent's workflow moving while a human
        // still approves the action. Any grant of a stageable tool permits
        // staged=true. After the gate, rewrite to the internal dispatch name.
        $downgradedToStaged = false;
        if ($stageable) {
            if (! $staged && ! $this->allowsImmediateExecution($request, (string) $name)) {
                $staged = true;
                $downgradedToStaged = true;
            }
            if ($staged) {
                $name = (string) McpToolModes::stagedInternalFor((string) $name);
            }
        }

        // Extract client_id from the arguments — this is how client-scoped
        // tools get their context in the MCP world. Strip it before dispatch
        // so it doesn't get passed to tools that don't expect it.
        //
        // Isolation control (spec §6): only a positive, numeric client_id is
        // honored. Anything malformed — 0, negative, non-numeric "garbage" —
        // collapses to null (GLOBAL-only scope), never a `client_id = 0` query.
        $hasClientIdArgument = array_key_exists('client_id', $arguments);
        $ticketScopedPsaTool = $this->isPsaTicketScopedTool((string) $name);
        $clientId = $this->positiveIntegerArgument($arguments['client_id'] ?? null);
        unset($arguments['client_id']);
        $auditArguments = $arguments;
        if ((string) $name === 'create_ticket' && $clientId !== null) {
            $auditArguments['client_id'] = $clientId;
        }
        if ($this->isCippWriteTool((string) $name) && $clientId !== null) {
            $auditArguments['client_id'] = $clientId;
        }
        if ($this->psaRecordsRequiresClientId((string) $name) && $clientId !== null) {
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

        // The ticket taxonomy is global (so-0ftg). Every taxonomy tool — reads
        // included — rejects a supplied client_id rather than silently dropping
        // it: a caller that believes the listing it got back was client-scoped
        // has been misled, which is worse than an error (psa-mgok).
        if ($this->isTaxonomyTool((string) $name) && $hasClientIdArgument) {
            $message = "client_id must be omitted for {$name}; the ticket taxonomy is global.";
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

        if ($ticketScopedPsaTool) {
            if ($hasClientIdArgument) {
                $message = "client_id must be omitted for {$name}; the server derives scope from ticket_id.";
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

            $clientId = $this->ticketClientIdForArguments($arguments);
            if ($clientId === null) {
                $message = 'ticket_id is required and must resolve to an existing ticket.';
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

        if ($this->isPsaActionTool((string) $name) && ! $ticketScopedPsaTool && $clientId === null) {
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

        // create_client is a GLOBAL psa_records write (a new client has no parent
        // scope) — reject a supplied client_id, mirroring the wiki global writes.
        if ((string) $name === 'create_client' && $hasClientIdArgument) {
            $message = 'client_id must be omitted for create_client; it is a global write.';
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

        // Client-entity targets (update/delete client) + the create_contact parent
        // scope require an explicit client_id argument.
        if ($this->psaRecordsRequiresClientId((string) $name) && $clientId === null) {
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

        // Contact-scoped psa_records tools (update/set_primary/move/delete_contact)
        // reject a supplied client_id and derive scope from contact_id, mirroring
        // the ticket-scoped tools' derive-from-ticket_id pattern.
        if ($this->isPsaRecordsContactScopedTool((string) $name)) {
            if ($hasClientIdArgument) {
                $message = "client_id must be omitted for {$name}; the server derives scope from contact_id.";
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

            $clientId = $this->contactClientIdForArguments($arguments);
            if ($clientId === null) {
                $message = 'contact_id is required and must resolve to an existing contact.';
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

        // Asset-scoped psa_records tools (update/retire/restore/link/unlink/set_primary_asset_user)
        // reject a supplied client_id and derive scope from asset_id (withTrashed, since
        // restore targets a soft-deleted asset). client_id may be null for a client-less asset.
        if ($this->isPsaRecordsAssetScopedTool((string) $name)) {
            if ($hasClientIdArgument) {
                $message = "client_id must be omitted for {$name}; the server derives scope from asset_id.";
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

            $assetId = $this->positiveIntegerArgument($arguments['asset_id'] ?? null);
            if ($assetId === null || ! Asset::withTrashed()->whereKey($assetId)->exists()) {
                $message = 'asset_id is required and must resolve to an existing asset.';
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

            $clientId = $this->assetClientIdForArguments($arguments);
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

        if ($this->isCippWriteTool((string) $name) && $clientId === null) {
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
            } elseif ($name === self::TOOL_SURFACE_TOOL) {
                $result = $this->listToolSurface($request, $arguments);
            } elseif (OperatorBridgeTools::handles((string) $name)) {
                $token = $request->attributes->get('mcp_staff_token');
                $result = app(OperatorBridgeToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    $token instanceof McpStaffToken ? $token->label : null,
                );
            } elseif (ChetDataSurfaceTools::handles((string) $name)) {
                $result = app(ChetDataSurfaceToolExecutor::class)->execute((string) $name, $arguments, $clientId);
            } elseif ($this->isCippWriteTool((string) $name)) {
                $result = app(StaffCippWriteToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    (int) $clientId,
                    $this->actorLabel($request),
                );
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
                    $this->tokenLabel($request),
                );
            } elseif ($this->isPsaRecordsTool((string) $name)) {
                // create_client is global ($clientId null → 0, ignored by the
                // handler); the other three carry client_id as the target.
                $result = app(StaffPsaActionToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    (int) $clientId,
                    $this->actorLabel($request),
                    $this->tokenLabel($request),
                );
            } elseif ($this->isIntakeManageTool((string) $name)) {
                // None of these carry client_id — scope lives on the targeted
                // email/call/ticket ids themselves ($clientId is always null → 0,
                // ignored by the handlers, mirroring create_client above).
                $result = app(StaffPsaActionToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    (int) $clientId,
                    $this->actorLabel($request),
                    $this->tokenLabel($request),
                );
            } elseif ($this->isTaxonomyTool((string) $name)) {
                // Global surface — no client scope at all (a supplied client_id
                // was already rejected above).
                $result = app(StaffPsaTaxonomyToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    $this->actorLabel($request),
                );
            } elseif ((string) $name === 'send_reply') {
                $result = $this->sendReply($arguments, $request);
            } elseif ((string) $name === 'request_tool') {
                $result = $this->requestTool($arguments, $request);
            } else {
                $userId = $this->userIdForToolCall($request, (string) $name);
                $executor = new AssistantToolExecutor(ticket: null, clientId: $clientId, userId: $userId);
                $result = $executor->execute($name, is_array($arguments) ? $arguments : []);
            }
            // Make an auto-downgrade unmistakable to the caller: it asked for
            // immediate execution but got a held proposal instead.
            if ($downgradedToStaged && is_array($result)) {
                $result['downgraded_to_staged'] = true;
                if (isset($result['error'])) {
                    $result['error'] = 'Immediate execution is not granted for this token; the call was downgraded to a staged proposal. '.$result['error'];
                } else {
                    $result['message'] = trim('Immediate execution is not granted for this token; the call was downgraded to a staged proposal. '.(string) ($result['message'] ?? ''));
                }
            }

            $isError = is_array($result) && isset($result['error']);

            $this->audit(
                'tools/call', $name, $auditArguments,
                $isError ? 'error' : 'success',
                $isError ? (string) $result['error'] : null,
                $start, $request,
            );

            $content = [
                ['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            ];

            // Alerts-Hub payload piggyback (psa-0j6i): if this token has unread nudge-flagged
            // alerts, ride a short awareness notice on the NORMAL response of this tool call so
            // an active agent learns of them without a GC-specific wake. Skip poll_signals — that
            // call IS the drain. Defensive: a notice failure must never break the tool response.
            if ((string) $name !== 'poll_signals') {
                try {
                    $staffToken = $request->attributes->get('mcp_staff_token');
                    $nudgeNotice = app(SignalNudgeNotice::class)->pendingNoticeFor(
                        $staffToken instanceof McpStaffToken ? $staffToken->label : null,
                    );
                    if ($nudgeNotice !== null) {
                        $content[] = ['type' => 'text', 'text' => $nudgeNotice];
                    }
                } catch (\Throwable $e) {
                    Log::warning('[MCP/staff] Nudge notice computation failed: '.$e->getMessage());
                }
            }

            $response = response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => $content,
                    'isError' => $isError,
                ],
            ]);

            if (in_array((string) $name, ['tactical_open_remote_control', 'tactical_get_or_create_installer', 'tactical_generate_installer', 'cipp_reset_user_password', 'cipp_create_user'], true) && ! $isError) {
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

        $message = app(SendReplyTool::class)->executeHeld($ticket, $input, $this->actorLabel($request), $this->tokenLabel($request));

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'message' => $message,
        ];
    }

    /** @return array<string, mixed> */
    private function requestTool(array $arguments, Request $request): array
    {
        $ticketId = $this->positiveIntegerArgument($arguments['ticket_id'] ?? null);
        if ($ticketId === null) {
            return ['error' => 'ticket_id is required'];
        }

        $ticket = Ticket::find($ticketId);
        if (! $ticket) {
            return ['error' => 'Ticket not found'];
        }

        // The caller's live grant check lets auto-classification distinguish
        // "already granted" from "built but ungranted" for this token.
        $message = app(RequestToolTool::class)->execute(
            $ticket,
            $arguments,
            fn (string $tool): bool => $this->toolAllowed($request, $tool),
        );

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

        if ($tool === 'update_ticket') {
            return $this->auditUpdateTicketArguments($args);
        }

        if ($tool === 'set_ticket_status') {
            return $this->auditSetTicketStatusArguments($args);
        }

        if ($tool === 'assign_ticket') {
            return $this->auditAssignTicketArguments($args);
        }

        if (in_array((string) $tool, ['assign_asset', 'unassign_asset'], true)) {
            return $this->auditAssetAssignmentArguments($args);
        }

        if ($tool === 'set_ticket_contact') {
            return $this->auditSetTicketContactArguments($args);
        }

        if ($tool === 'move_ticket_to_client') {
            return $this->auditMoveTicketArguments($args);
        }

        if ($tool === 'create_client' || $tool === 'update_client') {
            return $this->auditClientWriteArguments($args);
        }

        if ($tool === 'update_client_site_notes') {
            return $this->auditClientSiteNotesArguments($args);
        }

        if ($tool === 'delete_client') {
            return $this->auditDeleteClientArguments($args);
        }

        if ($tool === 'create_contact' || $tool === 'update_contact') {
            return $this->auditContactWriteArguments($args);
        }

        if ($tool === 'set_primary_contact') {
            return $this->auditSetPrimaryContactArguments($args);
        }

        if ($tool === 'move_contact_to_client') {
            return $this->auditMoveContactArguments($args);
        }

        if ($tool === 'delete_contact') {
            return $this->auditDeleteContactArguments($args);
        }

        if ($tool === 'create_asset' || $tool === 'update_asset') {
            return $this->auditAssetWriteArguments($args);
        }

        if ($tool === 'retire_asset') {
            return $this->auditRetireAssetArguments($args);
        }

        if (in_array((string) $tool, ['restore_asset', 'link_asset_user', 'unlink_asset_user', 'set_primary_asset_user'], true)) {
            return $this->auditAssetScopedIdArguments($args);
        }

        if ($this->isTaxonomyTool((string) $tool)) {
            return $this->auditTaxonomyArguments($args);
        }

        $redacted = app(ActionRedactor::class)->redactParams($args);

        if ($tool === 'post_to_operator' && isset($redacted['message']) && is_string($redacted['message'])) {
            $redacted['message'] = app(OperatorBridgeTextSanitizer::class)
                ->sanitizeForPrompt($redacted['message'], '[message detail withheld - unsafe content]');
        }

        if ($tool === 'add_ticket_note') {
            return $this->auditTicketNoteArguments($redacted);
        }

        if ($this->isCippWriteTool((string) $tool)) {
            return $this->auditCippWriteArguments($redacted);
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

            if (in_array($normalized, ['client_id', 'ticket_id'], true)) {
                $safe[$normalized] = $value;
            }

            // psa-kt82: reason is free text and may name an address — redact before it
            // is persisted, matching the redaction applied to the action-log summary.
            if ($normalized === 'reason') {
                $safe['reason'] = is_string($value) ? \App\Support\EmailRedactor::redact($value) : $value;
            }

            if ($normalized === 'body') {
                $safe['body_length'] = is_string($value) ? mb_strlen($value) : 0;
            }

            // psa-kt82: record recipient counts only — never the raw to/cc addresses.
            if (in_array($normalized, ['to', 'cc'], true) && is_array($value)) {
                $safe[$normalized.'_count'] = count($value);
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
    private function auditUpdateTicketArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['ticket_id', 'subject', 'priority', 'type', 'reason'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'description') {
                $safe['description_length'] = is_string($value) ? mb_strlen($value) : 0;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditSetTicketStatusArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['ticket_id', 'status', 'confirm_status'], true)) {
                $safe[$normalized] = $value;
            }

            if (in_array($normalized, ['reason', 'note', 'resolution'], true)) {
                $safe[$normalized.'_length'] = is_string($value) ? mb_strlen($value) : 0;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditAssignTicketArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['ticket_id', 'user_id', 'reason'], true)) {
                $safe[$normalized] = $value;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditAssetAssignmentArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['ticket_id', 'asset_id', 'is_primary', 'reason'], true)) {
                $safe[$normalized] = $value;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditSetTicketContactArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['ticket_id', 'contact_id', 'reason'], true)) {
                $safe[$normalized] = $value;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditMoveTicketArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['ticket_id', 'new_client_id', 'new_contact_id', 'confirm_client_name'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'reason') {
                $safe['reason_length'] = is_string($value) ? mb_strlen($value) : 0;
            }
        }

        return $safe;
    }

    /**
     * Redaction for create_client / update_client: safelist the client fields,
     * reduce the free-text notes body to a length only.
     *
     * @return array<string, mixed>
     */
    private function auditClientWriteArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['client_id', 'name', 'phone', 'email', 'website', 'address_line1', 'address_line2', 'city', 'state', 'postcode', 'is_active', 'primary_tech_id', 'reseller_id'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'notes') {
                $safe['notes_length'] = is_string($value) ? mb_strlen($value) : 0;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditClientSiteNotesArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['client_id', 'expected_updated_at'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'site_notes') {
                $safe['site_notes_length'] = is_string($value) ? mb_strlen($value) : 0;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditDeleteClientArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['client_id', 'confirm_client_name', 'reason'], true)) {
                $safe[$normalized] = $value;
            }
        }

        return $safe;
    }

    /**
     * Redaction for create_contact / update_contact: safelist the contact fields,
     * reduce free-text notes to a length and additional_emails to a count.
     *
     * @return array<string, mixed>
     */
    private function auditContactWriteArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['client_id', 'contact_id', 'first_name', 'last_name', 'email', 'phone', 'mobile', 'job_title', 'person_type', 'is_primary', 'is_active'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'notes') {
                $safe['notes_length'] = is_string($value) ? mb_strlen($value) : 0;
            }

            if ($normalized === 'additional_emails') {
                $safe['additional_emails_count'] = is_array($value) ? count($value) : 0;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditSetPrimaryContactArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            if (mb_strtolower((string) $key) === 'contact_id') {
                $safe['contact_id'] = $value;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditMoveContactArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['contact_id', 'new_client_id', 'confirm_client_name', 'reason'], true)) {
                $safe[$normalized] = $value;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditDeleteContactArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['contact_id', 'confirm_contact_name', 'reason'], true)) {
                $safe[$normalized] = $value;
            }
        }

        return $safe;
    }

    /**
     * Redaction for create_asset / update_asset: safelist the asset fields,
     * reduce free-text notes to a length only.
     *
     * @return array<string, mixed>
     */
    private function auditAssetWriteArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['client_id', 'asset_id', 'name', 'asset_type', 'serial_number', 'hostname', 'os', 'ip_address', 'is_active'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'notes') {
                $safe['notes_length'] = is_string($value) ? mb_strlen($value) : 0;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditRetireAssetArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['asset_id', 'confirm_asset_name', 'reason'], true)) {
                $safe[$normalized] = $value;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditAssetScopedIdArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['asset_id', 'person_id'], true)) {
                $safe[$normalized] = $value;
            }
        }

        return $safe;
    }

    /**
     * One projection for all six taxonomy tools: scalar identifiers, filters and
     * hints pass through; the SOP markdown and description bodies are reduced to
     * lengths (mirrors site notes / wiki page authoring).
     *
     * @return array<string, mixed>
     */
    private function auditTaxonomyArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['category_id', 'parent_id', 'name', 'sop_status', 'record_type_hint', 'sort_order', 'is_active', 'source_runbook_slug', 'confirm_category_name', 'reason', 'expected_updated_at', 'search', 'stale_days', 'include_inactive', 'limit'], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'sop_text') {
                $safe['sop_text_length'] = is_string($value) ? mb_strlen($value) : 0;
            }

            if ($normalized === 'description') {
                $safe['description_length'] = is_string($value) ? mb_strlen($value) : 0;
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

            if (in_array($normalized, [
                'asset_id',
                'hostname',
                'field_key',
                'platform',
                'reason',
                'ticket_id',
                'workstation_policy_id',
                'server_policy_id',
                'policy_id',
                'copy_id',
                'confirm_policy_name',
                'task_id',
                'confirm_task_name',
                'confirm_hostname',
                'confirm_run_all',
                'task_type',
                'run_time_date',
                'expire_date',
                'daily_interval',
                'weekly_interval',
                'run_time_bit_weekdays',
                'monthly_months_of_year',
                'monthly_days_of_month',
                'monthly_weeks_of_month',
                'task_repetition_duration',
                'task_repetition_interval',
                'stop_task_at_duration_end',
                'random_task_delay',
                'remove_if_not_scheduled',
                'run_asap_after_missed',
                'task_instance_policy',
                'task_supported_platforms',
                'assigned_check',
                'continue_on_error',
                'alert_severity',
                'email_alert',
                'text_alert',
                'dashboard_alert',
                'collector_all_output',
                'check_type',
                'fails_b4_alert',
                'timeout',
                'run_interval',
                'success_return_codes',
                'info_return_codes',
                'warning_return_codes',
                'target_type',
                'policy_kind',
                'block_policy_inheritance',
                'active',
                'enforced',
                'script_id',
                'script_name',
                'confirm_script_name',
                'show_community',
                'show_hidden',
                'with_snippets',
                'name',
                'description',
                'shell',
                'category',
                'favorite',
                'default_timeout',
                'syntax',
                'filename',
                'hidden',
                'supported_platforms',
                'run_as_user',
            ], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'value') {
                $safe['value'] = self::TACTICAL_CUSTOM_FIELD_AUDIT_PLACEHOLDER;
            }

            if ($normalized === 'script_body') {
                $safe['script_body'] = self::TACTICAL_SCRIPT_BODY_AUDIT_PLACEHOLDER;
                $safe['script_body_length'] = is_string($value) ? mb_strlen($value) : 0;
            }

            if ($normalized === 'desc') {
                $safe['desc'] = '[policy description withheld]';
                $safe['desc_length'] = is_string($value) ? mb_strlen($value) : 0;
            }

            if ($normalized === 'args') {
                $safe['args'] = app(ActionRedactor::class)->redactParams(is_array($value) ? $value : []);
            }

            if ($normalized === 'script_args') {
                $safe['script_args_count'] = is_array($value) ? count($value) : 0;
            }

            if ($normalized === 'env_vars') {
                $safe['env_vars_count'] = is_array($value) ? count($value) : 0;
            }

            if ($normalized === 'actions') {
                $safe['actions_count'] = is_array($value) ? count($value) : 0;
                $safe['action_types'] = is_array($value)
                    ? array_values(array_filter(array_map(
                        fn (mixed $action): ?string => is_array($action) && is_scalar($action['type'] ?? null) ? (string) $action['type'] : null,
                        $value,
                    )))
                    : [];
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function auditCippWriteArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, [
                'client_id',
                'person_id',
                'license_type_id',
                'ticket_id',
                'state',
                'mailbox_type',
                'mode',
                'target_person_id',
                'keep_copy',
                'hidden',
                'must_change',
                'start_time',
                'end_time',
                'timezone',
            ], true)) {
                $safe[$normalized] = $value;
            }

            if ($normalized === 'confirm_upn') {
                $safe['confirm_upn'] = '[withheld]';
            }

            if ($normalized === 'reason' && is_scalar($value)) {
                $safe['reason'] = $this->safeCippWriteReasonForAudit((string) $value, $arguments);
            }

            if ($normalized === 'external_smtp' && is_scalar($value)) {
                $domain = mb_strtolower((string) substr(strrchr((string) $value, '@') ?: '', 1));
                $safe['external_target_type'] = 'smtp';
                $safe['external_target_domain'] = $domain !== '' ? $domain : '[invalid]';
            }

            if ($normalized === 'internal_message') {
                $safe['internal_message_length'] = is_string($value) ? mb_strlen($value) : 0;
            }

            if ($normalized === 'external_message') {
                $safe['external_message_length'] = is_string($value) ? mb_strlen($value) : 0;
            }
        }

        return $safe;
    }

    private function safeCippWriteReasonForAudit(string $reason, array $arguments): string
    {
        $safe = $reason;
        if (isset($arguments['external_smtp']) && is_scalar($arguments['external_smtp'])) {
            $safe = str_replace((string) $arguments['external_smtp'], '[external address withheld]', $safe);
        }

        if (mb_strtolower((string) ($arguments['mode'] ?? '')) === 'external') {
            $safe = \App\Support\EmailRedactor::redact($safe);
        }

        foreach (['internal_message', 'external_message'] as $key) {
            if (isset($arguments[$key]) && is_scalar($arguments[$key])) {
                $value = trim((string) $arguments[$key]);
                if ($value !== '') {
                    $safe = str_replace($value, "[{$key} withheld]", $safe);
                }
            }
        }

        return mb_substr($safe, 0, 1000);
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

    private function isPsaTicketScopedTool(string $toolName): bool
    {
        return in_array($toolName, self::PSA_TICKET_SCOPED_TOOLS, true);
    }

    private function ticketClientIdForArguments(array $arguments): ?int
    {
        $ticketId = $this->positiveIntegerArgument($arguments['ticket_id'] ?? null);
        if ($ticketId === null) {
            return null;
        }

        $clientId = Ticket::whereKey($ticketId)->value('client_id');

        return is_numeric($clientId) ? (int) $clientId : null;
    }

    /**
     * Live-name lookup for a liveness check. tools/list calls toolAllowed() once per tool
     * and McpToolSurface::liveToolNames() re-assembles both surfaces on every call, so the
     * list path passes ONE precomputed lookup down instead of paying N full assemblies.
     *
     * *** DELIBERATELY NOT CACHED ON THE INSTANCE OR IN A STATIC. THIS WAS TRIED AND IT WAS
     * WRONG. *** A `private ?array $liveToolNameLookup` memo looked request-scoped — a
     * controller is resolved per request, so it "could not" outlive one — and
     * McpToolSurfaceDiscoveryTest caught it going STALE ACROSS CALLS anyway: configure an
     * integration between two calls and the second still answered from the first's snapshot,
     * classifying a now-live granted tool as merely available_ungranted.
     *
     * That is the whole defect class this conjunct exists to close, reintroduced by the
     * optimisation meant to support it: a cached answer to "is this tool live" lets a
     * switched-off integration stay callable, and a switched-ON one stay refused. The scope
     * of the memo is therefore the CALL that needs it and nothing wider.
     *
     * @return array<string, true>
     */
    private function liveToolNameLookup(): array
    {
        return array_fill_keys(McpToolSurface::liveToolNames(), true);
    }

    /**
     * @param  array<string, true>|null  $liveLookup  precomputed live-name lookup; pass it
     *                                                when calling this in a loop, omit it
     *                                                for a single check so the answer is
     *                                                always resolved fresh.
     */
    private function toolAllowed(Request $request, string $toolName, ?array $liveLookup = null): bool
    {
        $token = $request->attributes->get('mcp_staff_token');
        if (! $token instanceof McpStaffToken) {
            return false;
        }

        // Transport built-ins: identity and surface discovery are always
        // callable — a token that cannot see its own scope cannot self-heal.
        // They are assembled outside the grant catalog, so they are also
        // legitimately absent from the live surface and must be exempted from
        // the liveness check below or a caller loses the very tools it would
        // use to find out why something was refused.
        if ($toolName === self::WHOAMI_TOOL || $toolName === self::TOOL_SURFACE_TOOL) {
            return true;
        }

        // LIVENESS (psa-vydpz). A grant says the OPERATOR permitted this tool; it does not
        // say the tool EXISTS on this deployment. Without this conjunct the two were
        // conflated, and a tool that was granted but never published stayed callable by
        // name — the advertised surface understating what a token would actually do. At
        // filing: 137 of a 208-tool catalog were grantable but never published, 52 of those
        // reached real work, and three reached live outbound vendor HTTP.
        //
        // *** THIS IS WHAT MAKES "OFF" MEAN OFF ON MCP. *** psa-wzjzz takes a switched-off
        // integration's tools out of the live surface; gating publication alone only HIDES
        // them, because tools/list and tools/call were reading different sources. Refusing a
        // not-live name here is what actually disables it.
        //
        // Deliberately placed BEFORE the family branches: it is a property of the
        // deployment, not of the token's scope, so no grant of any shape may outrun it —
        // including the legacy full-surface token, which grants everything by construction
        // and for which liveness is the only remaining constraint.
        //
        // liveToolNames() is the UNION of the general and client-scoped surfaces, so this
        // flat check cannot refuse a client-scoped tool on a general call. The executors'
        // own self-gates stay exactly as they are, as defence in depth.
        if (! isset(($liveLookup ?? $this->liveToolNameLookup())[$toolName])) {
            return false;
        }

        if ($token->allowedTools !== null && ! in_array($toolName, McpToolRegistry::allToolNames(), true)) {
            return false;
        }

        // High-scope curated CIPP reads: explicit grant only, never auto-inherited by the
        // legacy full-surface token. Placed FIRST among the family branches because it is
        // the only one that gates a CURATED READ — a reader scanning for "why doesn't the
        // legacy token get this?" will look here before the write families.
        if (in_array($toolName, self::CIPP_EXPLICIT_GRANT_READ_TOOLS, true)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if (in_array($toolName, self::WIKI_WRITE_TOOLS, true)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if ($this->isPsaActionTool($toolName)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if ($this->isPsaRecordsTool($toolName)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if ($this->isPsaReadTool($toolName)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if ($this->isIntakeManageTool($toolName)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if ($this->isTaxonomyTool($toolName)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if ($this->isCippWriteTool($toolName)) {
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

    /**
     * Whether staged=false may execute a stageable tool now. The legacy
     * full-surface token retains full trust; scoped tokens need the per-tool
     * immediate mode grant (see McpStaffToken::allowsImmediate()).
     */
    private function allowsImmediateExecution(Request $request, string $toolName): bool
    {
        $token = $request->attributes->get('mcp_staff_token');

        return $token instanceof McpStaffToken && $token->allowsImmediate($toolName);
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

    private function isPsaRecordsTool(string $toolName): bool
    {
        return in_array($toolName, self::PSA_RECORDS_TOOLS, true);
    }

    private function isPsaRecordsClientScopedTool(string $toolName): bool
    {
        return in_array($toolName, self::PSA_RECORDS_CLIENT_SCOPED_TOOLS, true);
    }

    private function isPsaRecordsContactScopedTool(string $toolName): bool
    {
        return in_array($toolName, self::PSA_RECORDS_CONTACT_SCOPED_TOOLS, true);
    }

    private function isPsaRecordsAssetScopedTool(string $toolName): bool
    {
        return in_array($toolName, self::PSA_RECORDS_ASSET_SCOPED_TOOLS, true);
    }

    private function isPsaReadTool(string $toolName): bool
    {
        return in_array($toolName, self::PSA_READ_TOOLS, true);
    }

    private function isIntakeManageTool(string $toolName): bool
    {
        return in_array($toolName, self::INTAKE_MANAGE_TOOLS, true);
    }

    private function isTaxonomyTool(string $toolName): bool
    {
        return StaffPsaTaxonomyToolExecutor::handles($toolName);
    }

    /** psa_records tools that carry an explicit client_id argument: client-entity targets + the create_contact / create_asset parent scope. */
    private function psaRecordsRequiresClientId(string $toolName): bool
    {
        return $this->isPsaRecordsClientScopedTool($toolName)
            || in_array($toolName, ['create_contact', 'create_asset'], true);
    }

    private function contactClientIdForArguments(array $arguments): ?int
    {
        $contactId = $this->positiveIntegerArgument($arguments['contact_id'] ?? null);
        if ($contactId === null) {
            return null;
        }

        $clientId = Person::whereKey($contactId)->value('client_id');

        return is_numeric($clientId) ? (int) $clientId : null;
    }

    private function assetClientIdForArguments(array $arguments): ?int
    {
        $assetId = $this->positiveIntegerArgument($arguments['asset_id'] ?? null);
        if ($assetId === null) {
            return null;
        }

        // withTrashed: restore_asset resolves scope from a soft-deleted asset.
        $clientId = Asset::withTrashed()->whereKey($assetId)->value('client_id');

        return is_numeric($clientId) ? (int) $clientId : null;
    }

    private function isCippWriteTool(string $toolName): bool
    {
        return StaffCippWriteToolExecutor::handles($toolName);
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

        $payload = [
            'label' => $token instanceof McpStaffToken && $token->label !== null ? $token->label : McpStaffToken::LEGACY_ACTOR_LABEL,
            'directive' => $token instanceof McpStaffToken ? $token->directiveOrDefault() : McpToken::defaultDirective(),
            'allowed_tools' => $token instanceof McpStaffToken && $token->allowedTools !== null
                ? array_values(array_unique(array_merge([self::WHOAMI_TOOL, self::TOOL_SURFACE_TOOL], $token->allowedTools)))
                : null,
        ];

        // Per-tool execution mode for granted stageable capabilities:
        // 'staged' = every call is held for approval, 'immediate' = staged
        // and immediate both allowed. Omitted entirely when no stageable
        // tool is granted (and for the legacy full-surface token).
        if ($token instanceof McpStaffToken && $token->toolModes !== []) {
            $payload['tool_modes'] = $token->toolModes;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function toolSurfaceToolDefinition(): array
    {
        return [
            'name' => self::TOOL_SURFACE_TOOL,
            'description' => 'List the full tool catalog of this server with a per-tool state: granted (in this token\'s allowlist, callable now), available_ungranted (built and configured but not granted — an operator token grant enables it), or unavailable_config (built but its integration is switched off or not configured on this instance — the remedy is re-enabling it in Settings > Integrations or adding its credentials). Tools absent from this catalog do not exist; request_tool records a build request. Names and one-line descriptions only — no data, no secrets.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'state' => [
                        'type' => 'string',
                        'enum' => [
                            McpToolSurface::STATE_GRANTED,
                            McpToolSurface::STATE_AVAILABLE_UNGRANTED,
                            McpToolSurface::STATE_UNAVAILABLE_CONFIG,
                        ],
                        'description' => 'Optional: only return tools in this state. Counts always cover the full surface.',
                    ],
                    'category' => [
                        'type' => 'string',
                        'description' => 'Optional: only return tools in this catalog category (e.g. general, integration, psa_action). The response lists valid categories.',
                    ],
                ],
                'required' => [],
            ],
        ];
    }

    /**
     * The list_tool_surface handler: classify the full grant catalog for this
     * caller's token. Capability names, categories, and one-line descriptions
     * only — never data or configuration values.
     *
     * @return array<string, mixed>
     */
    private function listToolSurface(Request $request, array $arguments): array
    {
        $states = McpToolSurface::states();

        $stateFilter = trim((string) ($arguments['state'] ?? ''));
        if ($stateFilter !== '' && ! isset($states[$stateFilter])) {
            return ['error' => 'Unknown state: '.$stateFilter.'. Valid states: '.implode(', ', array_keys($states)).'.'];
        }

        $categories = [];
        foreach (McpToolRegistry::groups() as $categoryKey => $group) {
            $categories[$categoryKey] = $group['label'];
        }

        $categoryFilter = trim((string) ($arguments['category'] ?? ''));
        if ($categoryFilter !== '' && ! isset($categories[$categoryFilter])) {
            return ['error' => 'Unknown category: '.$categoryFilter.'. Valid categories: '.implode(', ', array_keys($categories)).'.'];
        }

        $entries = McpToolSurface::classify(fn (string $tool): bool => $this->toolAllowed($request, $tool));

        $counts = array_fill_keys(array_keys($states), 0);
        foreach ($entries as $entry) {
            $counts[$entry['state']]++;
        }
        $counts['total'] = count($entries);

        $filtered = array_values(array_filter(
            $entries,
            fn (array $entry): bool => ($stateFilter === '' || $entry['state'] === $stateFilter)
                && ($categoryFilter === '' || $entry['category'] === $categoryFilter),
        ));

        return [
            'states' => $states,
            'absent_means' => 'A capability not in this catalog does not exist on this server — request_tool records it as a build request.',
            'counts' => $counts,
            'categories' => $categories,
            'tools' => $filtered,
        ];
    }

    private function actorLabel(Request $request): string
    {
        $token = $request->attributes->get('mcp_staff_token');

        return $token instanceof McpStaffToken ? $token->actorLabel() : McpStaffToken::LEGACY_ACTOR_LABEL;
    }

    /**
     * The authenticated caller's BARE McpToken.label — what TeamsPersona.mcp_token_label
     * matches, and so the only form that resolves a persona for the client-facing
     * tagline (psa-u51h). Deliberately NOT actorLabel(), which is the prefixed audit
     * form "mcp-staff:{label}" and matches no persona. Null for a legacy (unlabelled)
     * token, which correctly resolves none. Mirrors what the OperatorBridge branch
     * already passes.
     */
    private function tokenLabel(Request $request): ?string
    {
        $token = $request->attributes->get('mcp_staff_token');

        return $token instanceof McpStaffToken ? $token->label : null;
    }
}
