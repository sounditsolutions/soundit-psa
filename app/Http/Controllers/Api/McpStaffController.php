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
use App\Services\Cipp\CippMcpToolRelay;
use App\Services\Mcp\StaffCalendarToolExecutor;
use App\Services\Mcp\StaffCippAdminToolExecutor;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use App\Services\Mcp\StaffControlDOnboardingToolExecutor;
use App\Services\Mcp\StaffHuntressActionToolExecutor;
use App\Services\Mcp\StaffMeshAdminToolExecutor;
use App\Services\Mcp\StaffPsaActionToolExecutor;
use App\Services\Mcp\StaffPsaTaxonomyToolExecutor;
use App\Services\Mcp\StaffTacticalActionToolExecutor;
use App\Services\Mcp\StaffTacticalAdminToolExecutor;
use App\Services\Signals\SignalNudgeNotice;
use App\Services\Tactical\Actions\ActionRedactor;
use App\Services\Technician\Scheduled\ExecuteAt;
use App\Services\Technician\Scheduled\ScheduledClock;
use App\Support\CippConfig;
use App\Support\McpInputSchema;
use App\Support\McpStaffToken;
use App\Support\McpToolInstructions;
use App\Support\McpToolModes;
use App\Support\McpToolRegistry;
use App\Support\McpToolSurface;
use App\Support\TechnicianConfig;
use App\Support\UnlinkedTicketScope;
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

    private const SEARCH_TOOLS_TOOL = 'search_tools';

    /**
     * What "not in this catalog" MEANS, stated wherever the catalog is the
     * answer (list_tool_surface, every search_tools response): absence is
     * nonexistence, and the remedy is a request_tool build request — so an
     * empty search can never read as a quiet all-clear.
     */
    private const CATALOG_ABSENT_MEANS = 'A capability not in this catalog does not exist on this server — request_tool records it as a build request.';

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

    /**
     * Reads that serve RAW FILE BYTES into agent context: explicit grant only,
     * never auto-inherited by the legacy full-surface token (psa-688). Same
     * posture as CIPP_EXPLICIT_GRANT_READ_TOOLS above — NOT a blocklist,
     * everything here stays fully grantable; it just cannot arrive by default.
     *
     * The bar for adding a name here is the RETURN SHAPE, not sensitivity:
     * the tool hands back file content (bytes, base64, raw documents) rather
     * than records — content whose nature nobody controls, because it is
     * whatever a client or operator ever attached. A tool that returns
     * sensitive FIELDS does not belong here; the per-token grant covers it.
     *
     * The names live in {@see McpToolRegistry::RAW_FILE_CONTENT_TOOLS} so this gate and
     * the OPERATOR GRANT CATALOG cannot disagree: the registry marks exactly these tools
     * sensitive and gives them their own tier, because a decision this branch says must
     * be made BY NAME must not also be makeable by a bulk "Grant shown" click on a tier
     * labelled "Read" (psa-lulgh — a mislabelled tier is how an operator grants a
     * capability believing it is an ordinary read).
     */
    private const RAW_FILE_CONTENT_EXPLICIT_GRANT_TOOLS = McpToolRegistry::RAW_FILE_CONTENT_TOOLS;

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
        // merge_ticket/merge_asset are the canonical stageable names; propose_merge /
        // propose_asset_merge are their staged twins (the mode gate rewrites the
        // canonical name to them), so all four must route to StaffPsaActionToolExecutor
        // — mirrors send_email/stage_email and close_ticket/stage_close_ticket.
        'merge_ticket',
        'propose_merge',
        'merge_asset',
        'propose_asset_merge',
        'update_ticket',
        'set_ticket_status',
        // psa-d9ayt: close_ticket is the only terminal transition; stage_close_ticket
        // is its staged twin (the mode gate rewrites the canonical name to it), so BOTH
        // must route to StaffPsaActionToolExecutor — mirrors send_email/stage_email.
        'close_ticket',
        'stage_close_ticket',
        'assign_ticket',
        'assign_asset',
        'unassign_asset',
        'set_ticket_contact',
        'move_ticket_to_client',
        // Card XUiMXNEH: explicit grant only, like every PSA action. Its scope is the
        // run's drafting token, not a client, so it is also PSA_CLIENTLESS_ACTION_TOOLS.
        'withdraw_staged_action',
    ];

    /**
     * PSA actions whose scope is not a client (card XUiMXNEH). A supplied client_id
     * is refused rather than required or ignored: withdraw_staged_action acts only on
     * a run the calling token drafted, and a caller who believes a client argument
     * narrowed that has been misled.
     */
    private const PSA_CLIENTLESS_ACTION_TOOLS = [
        'withdraw_staged_action',
    ];

    private const PSA_TICKET_SCOPED_TOOLS = [
        'update_ticket',
        'set_ticket_status',
        // Both close_ticket and its staged dispatch name derive their client scope from
        // ticket_id (never an ambient client_id argument).
        'close_ticket',
        'stage_close_ticket',
        'assign_ticket',
        'assign_asset',
        'unassign_asset',
        'set_ticket_contact',
        'move_ticket_to_client',
    ];

    /** PSA records write-surface (P2a/P2b/P2c) — native client + contact + asset CRUD, dispatched through StaffPsaActionToolExecutor. */
    private const PSA_RECORDS_TOOLS = [
        'portal_get_or_create_install_link',
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
        'rebind_tactical_asset',
        'update_asset',
        'retire_asset',
        'restore_asset',
        'link_asset_user',
        'unlink_asset_user',
        'set_primary_asset_user',
    ];

    /** psa_records tools that act on an existing client — client_id is the required target, not ambient scope. */
    private const PSA_RECORDS_CLIENT_SCOPED_TOOLS = [
        'portal_get_or_create_install_link',
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
        'rebind_tactical_asset',
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
        'get_ticket_tool_history',
        'get_ticket_timeline',
        'list_client_contracts',
        'get_contract',
        // Card 3vhEBCDG: one contract's prepay ledger, read-only. Staff-class like the
        // invoice reads: client_id is an optional fence that must own the contract.
        // Explicit-grant only like every entry in this list.
        'list_prepay_transactions',
        'list_email_items',
        'get_email_item',
        'list_phone_calls',
        'get_phone_call',
        // psa-ij59: invoicing reads. Cross-client like the email/call pairs above,
        // with client_id an optional filter. Unlike the contract reads, these DO
        // expose internal cost/margin — Charlie's explicit ruling, 2026-07-20.
        'list_invoices',
        'get_invoice',
        // Recurring billing profile reads (Chet, 2026-08-31). Cross-client like the
        // invoice pair above, client_id an optional filter. get_recurring_profile
        // exposes unit_cost_override and the pricing_tiers rate cards — internal
        // cost data, same boundary as list_invoices/get_invoice and the opposite of
        // the contract reads. preview_recurring_invoice runs the generator's own
        // pricing path READ-ONLY: it persists nothing and advances no run date.
        'list_recurring_profiles',
        'get_recurring_profile',
        'preview_recurring_invoice',
        // psa-gq7by: staged/held action visibility. Cross-client like the pairs
        // above, client_id an optional filter. Metadata only — never the drafted
        // body, which stays in the cockpit approval UI.
        'get_staged_action_status',
        // READ-ONLY cross-client mislink sweep (Chet archaeology relief). Cross-client
        // like the pairs above, client_id an optional filter; explicit-grant only so
        // the legacy full-surface token never inherits a fleet-wide cross-client read.
        'list_mislinked_assets',
        // #3982: the commit this instance serves. Takes no arguments and reads no
        // client data; explicit-grant only like every entry in this list.
        'psa_version',
        // Card revwQxh4: BenjiPays auto-processing forecast for ONE invoice,
        // read-only and redacted (no customer, amount or payment details).
        // Explicit-grant only like every entry in this list.
        'benjipays_autopay_forecast',
        // Card 6abec4f9 stage 1: read-only BenjiPays reads for ONE PSA client,
        // fenced through its QuickBooks mapping and redacted (method type and
        // last four only). GET only. Explicit-grant only like every entry here.
        'benjipays_list_transactions',
        'benjipays_get_invoice',
        'benjipays_get_customer_payment_methods',
        // Card 6abec4f9 stage 2: sent-email history for ONE PSA client (same
        // fence; recipients masked to j***@domain), and the merchant
        // organization's own settings (org-wide, no client fence; non-secret
        // flags only). GET only. Explicit-grant only like every entry here.
        'benjipays_list_sent_emails',
        'benjipays_get_settings',
    ];

    /**
     * Tools whose ABSENT client_id means "sweep the whole fleet", so collapsing a
     * malformed client_id to null hands the caller the WIDEST read the tool can
     * perform — cross-tenant rows for a typo'd id. For these, a client_id that is
     * PRESENT but does not parse as a positive integer is REFUSED, never silently
     * dropped.
     *
     * Found by Chet 2026-07-31; scope of the fix ruled by Jeeves the same day.
     * Note what is NOT being done here: an omitted client_id still means a
     * fleet-wide sweep. Requiring an explicit opt-in for that was considered and
     * declined — it is the documented shape of every sibling cross-client read,
     * the breadth is already gated by an explicit token grant, and the finder
     * builds its contradiction maps fleet-wide regardless of scope, so the
     * opt-in would have gated the output shape rather than the query. The hazard
     * worth closing is SUPPLYING a scope and silently getting the fleet.
     *
     * Deliberately narrow: list_email_items / list_phone_calls / list_invoices
     * carry the same optional-filter shape and belong here too, but widening the
     * boundary contract for tools outside this branch is a separate scope call.
     * Tracked, not smuggled in here.
     */
    private const SCOPE_WIDENING_READ_TOOLS = [
        'list_mislinked_assets',

        // The recurring-profile reads (2026-08-31). Same fleet-capable shape:
        // client_id is an OPTIONAL filter, so a collapsed-to-null malformed value
        // widens rather than narrows. get_recurring_profile is here too even
        // though it publishes no client_id in its schema — client_id is lifted
        // out of the arguments regardless of schema, and the executor honours it
        // as a real fence, so a malformed one silently drops that fence.
        //
        // Adding these does NOT widen the boundary contract the comment above
        // reserves for a separate scope call: these three are new in this branch
        // and have no callers to change, and the entry only ever ADDS a refusal.
        'list_recurring_profiles',
        'get_recurring_profile',
        'preview_recurring_invoice',

        // #4254: the by-id intake reads. Like get_recurring_profile, neither
        // publishes client_id in its schema, but the controller lifts it out of the
        // arguments regardless and the executor now honours it as a real fence
        // (AssistantToolExecutor::getEmailItem/getPhoneCall). A malformed one would
        // collapse to null and hand back another client's body_text/transcription.
        // The entry only ADDS a refusal; an omitted client_id keeps the unscoped read.
        'get_email_item',
        'get_phone_call',

        // Card tIP4JoIG: the Huntress reads gained an OPTIONAL client_id that resolves
        // the org through clients.huntress_organization_id. Omitted, the list tools
        // read every mapped org (organizations: the whole account), so a malformed
        // client_id collapsing to null would widen exactly as above. Only ADDS a refusal.
        'huntress_list_incident_reports',
        'huntress_list_escalations',
        'huntress_list_organizations',
        'huntress_get_organization',

        // Card 6abdcac2: tactical_list_clients_sites gained an OPTIONAL client_id that
        // resolves through clients.tactical_site_id. Omitted, it lists every client's
        // mapping, so a malformed client_id collapsing to null would widen exactly as
        // above. The device reads already require client_id. Only ADDS a refusal.
        'tactical_list_clients_sites',

        // Card 3vhEBCDG: list_prepay_transactions honours client_id as an ownership
        // fence on the contract. A malformed one collapsing to null would silently
        // drop that fence, exactly as get_recurring_profile above. Only ADDS a refusal.
        'list_prepay_transactions',
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
        // ROUTING ONLY for the email-resolution pair (#1293): membership here sends
        // them to StaffPsaActionToolExecutor and keeps the legacy full-surface token
        // out. It confers NOTHING — the explicit staged-mode gate in toolAllowed() runs
        // above every family branch, so an intake-tier grant (bare names, e.g. the
        // catalog's bulk "Grant shown" click, which normalizes to the immediate mode)
        // cannot reach them.
        'resolve_email_item',
        'stage_resolve_email_item',
        'resolve_phone_call',
        'stage_resolve_phone_call',
        'dismiss_email_item',
        'link_call_to_ticket',
        'create_ticket_from_call',
        // Call-log writes (card 6aac3226dfebbc36fd7ff4f9). ROUTING ONLY, exactly
        // as the note above says: membership sends them to
        // StaffPsaActionToolExecutor and keeps the legacy full-surface token out
        // (the intake-manage branch in toolAllowed() requires allowedTools !==
        // null AND an explicit grant). For the three money/phone-line verbs the
        // mode gate then holds a bare or :staged grant for cockpit approval.
        'set_call_billable',
        'stage_set_call_billable',
        // Prepay move between contracts (card I3EvQKUV PR 2): routing only, same
        // held grant contract as set_call_billable.
        'move_time_entry_contract',
        'stage_move_time_entry_contract',
        'block_caller',
        'stage_block_caller',
        'allow_caller',
        'stage_allow_caller',
        'mark_call_followed_up',
        'retry_call_transcription',
    ];

    /**
     * Call-log write tools whose audited arguments are reduced to ids plus a
     * reason LENGTH. A `reason` is operator prose and a caller `label` is very
     * often a person's name, so neither belongs in the MCP audit log; the
     * caller's phone number is never in the arguments at all (the call id
     * carries it).
     */
    private const CALL_ACTION_ID_ONLY_AUDIT_TOOLS = [
        'set_call_billable',
        'stage_set_call_billable',
        'block_caller',
        'stage_block_caller',
        'allow_caller',
        'stage_allow_caller',
        'mark_call_followed_up',
        'retry_call_transcription',
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
            [$this->whoamiToolDefinition(), $this->toolSurfaceToolDefinition(), $this->searchToolsToolDefinition()],
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
            fn (array $tool): bool => $this->toolPublished($request, (string) ($tool['name'] ?? ''), $liveLookup),
        ));

        // find_persons / find_assets accept an OPTIONAL client_id — they
        // cross-client search when omitted. The wiki tools accept an optional
        // client_id too: omitting it scopes to GLOBAL-only content (spec §6).
        // All other client-scoped tools require it.
        $clientIdOptionalFor = ['find_persons', 'find_assets', 'wiki_list_pages', 'wiki_search', 'wiki_get_page'];

        $toolInstructions = McpToolInstructions::all();
        $translated = array_values(array_filter(array_map(function ($t) use ($generalNames, $clientIdOptionalFor, $toolInstructions): ?array {
            $schema = $t['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass];
            $isPsaActionTool = $this->isPsaActionTool((string) $t['name']);
            $isPsaTicketScopedTool = $this->isPsaTicketScopedTool((string) $t['name']);
            $isCippWriteTool = $this->isCippWriteTool((string) $t['name']);
            $isTacticalActionTool = $this->isTacticalActionTool((string) $t['name']);
            $isTacticalAdminTool = $this->isTacticalAdminTool((string) $t['name']);
            $requiresTacticalAdminClientScope = $isTacticalAdminTool && StaffTacticalAdminToolExecutor::requiresClient((string) $t['name']);
            $requiresCippAdminClientScope = $this->isCippAdminTool((string) $t['name']) && StaffCippAdminToolExecutor::requiresClient((string) $t['name']);
            $isClientScoped = $t['name'] === 'add_ticket_note' || ! isset($generalNames[$t['name']]) || $isPsaActionTool || $isCippWriteTool || $isTacticalActionTool || $requiresTacticalAdminClientScope || $requiresCippAdminClientScope;

            if ($isClientScoped) {
                $props = (array) ($schema['properties'] ?? []);
                $clientIdRequired = ($isPsaActionTool && ! $isPsaTicketScopedTool) || $isCippWriteTool || $isTacticalActionTool || $requiresTacticalAdminClientScope || $requiresCippAdminClientScope || ! in_array($t['name'], $clientIdOptionalFor, true);
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

        $offboarding = in_array($name, ['cipp_offboard_user', 'cipp_stage_offboard_user'], true);
        if ($offboarding && (($arguments['staged'] ?? null) !== true || ! is_int($arguments['client_id'] ?? null) || $arguments['client_id'] < 1)) {
            return $this->error($id, -32602, 'Offboarding requires staged=true and an explicit positive integer client_id; immediate calls are refused, not downgraded.');
        }

        if (in_array($name, ['resolve_email_item', 'stage_resolve_email_item'], true)
            && (($arguments['staged'] ?? null) !== true || ! is_int($arguments['client_id'] ?? null) || $arguments['client_id'] < 1)) {
            return $this->error($id, -32602, 'Email resolution requires staged=true and an explicit positive integer client_id; immediate execution is unavailable.');
        }

        if (in_array($name, ['resolve_phone_call', 'stage_resolve_phone_call'], true)
            && (! is_int($arguments['client_id'] ?? null) || $arguments['client_id'] < 1
                || (array_key_exists('staged', $arguments) && ! is_bool($arguments['staged'])))) {
            return $this->error($id, -32602, 'Caller resolution requires a positive integer client_id and boolean staged when supplied.');
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
        // Scheduled execution (ruled design points 1/2/3): an optional `execute_at` on a
        // capability with a scheduled adapter. It is stripped from the arguments HERE,
        // before any executor sees them, and threaded explicitly; on every other tool
        // it is a named refusal, never a silently-ignored key that runs now.
        //
        // Two lanes, decided by the SAME per-tool grant that decides staged=false:
        //  - the token holds `<tool>:immediate` → the DIRECT lane. It may already run this
        //    tool now without approval, so deferring it needs no new human in the loop; the
        //    row is queued straight into scheduled_authorizations with approver_user_id
        //    NULL and no cockpit proposal. The grant must STILL be held at fire time.
        //  - the token holds only `<tool>:staged` → the cockpit lane, and the call carries
        //    the ordinary downgraded_to_staged signal exactly as it would without
        //    execute_at.
        // The two notices are mutually exclusive by construction: $executeAtDirect and
        // $downgradedToStaged are the two halves of one boolean.
        $executeAt = null;
        $executeAtDirect = false;
        if (array_key_exists('execute_at', $arguments)) {
            $executeAtValue = $arguments['execute_at'];
            unset($arguments['execute_at']);
            $dispatchName = $stageable ? (string) McpToolModes::stagedInternalFor((string) $name) : (string) $name;
            if (! $stageable || ! ExecuteAt::supportsStaged($dispatchName)) {
                $message = ExecuteAt::refusalFor($dispatchName);
                $this->audit('tools/call', $requestedName, $arguments, 'error', $message, $start, $request);

                return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'content' => [['type' => 'text', 'text' => json_encode(['error' => $message], JSON_THROW_ON_ERROR)]], 'isError' => true,
                ]]);
            }
            try {
                $executeAt = ExecuteAt::parse($executeAtValue, app(ScheduledClock::class)->now());
            } catch (\InvalidArgumentException $e) {
                $this->audit('tools/call', $requestedName, $arguments, 'error', $e->getMessage(), $start, $request);

                return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                    'content' => [['type' => 'text', 'text' => json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR)]], 'isError' => true,
                ]]);
            }
            if (! $staged) {
                $executeAtDirect = $this->allowsImmediateExecution($request, (string) $name);
                // Compute the downgrade BEFORE forcing staged (#2093): a staged-only token
                // that asked for immediate execution must still get the unmistakable
                // downgraded_to_staged signal and its explanatory text, exactly as it would
                // without execute_at. An AI client branching on that documented flag would
                // otherwise conclude it had executed under its own authority.
                $downgradedToStaged = ! $executeAtDirect;
                // Staged is forced either way: on the cockpit lane because a human must
                // approve, and on the direct lane because the DIRECT LANE IS BUILT ON
                // STAGING — the executor stages the proposal (building the content hash,
                // encrypted payload, provenance and sealed inputs that admission reads) and
                // then admits it itself under the token's own grant. Nothing executes now on
                // either lane.
                $staged = true;
                if ($executeAtDirect) {
                    $executeAt = $executeAt->withDirectAdmission();
                }
            }
        }
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
        // collapses to null, never a `client_id = 0` query.
        //
        // That collapse is a SAFETY property only because, for almost every tool
        // here, null means GLOBAL-only scope — strictly less reach than the
        // client scope the caller fumbled. Silently narrowing is a fine way to
        // fail.
        //
        // It inverts for a fleet-capable cross-client read
        // (SCOPE_WIDENING_READ_TOOLS): there null means FLEET-WIDE, strictly
        // MORE reach. Same rule, opposite consequence — a caller who typo'd
        // `client_id: "07"` asking about one client would be handed every
        // client's rows, and for those tools a silently widened read IS a
        // cross-tenant read. So a client_id that is PRESENT but malformed is
        // refused below, naming the rejected value, rather than collapsed.
        // An ABSENT client_id still means a deliberate fleet-wide sweep.
        $hasClientIdArgument = array_key_exists('client_id', $arguments);
        $rawClientIdArgument = $arguments['client_id'] ?? null;
        $ticketScopedPsaTool = $this->isPsaTicketScopedTool((string) $name);
        $ticketScope = null;
        $explicitClientArgument = $arguments['client_id'] ?? null;
        $clientId = $this->positiveIntegerArgument($explicitClientArgument);
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
        if (\App\Services\Mcp\AssetWatchTool::handles((string) $name) && $clientId !== null) {
            $auditArguments['client_id'] = $clientId;
        }

        // A present-but-malformed scope on a fleet-capable read is a caller error,
        // and the failure mode of guessing is cross-tenant disclosure. Refuse and
        // name the rejected value rather than returning the whole fleet.
        if ($this->isScopeWideningRead((string) $name) && $hasClientIdArgument && $clientId === null) {
            $message = "client_id {$this->renderRejectedArgument($rawClientIdArgument)} is not a positive integer. "
                ."{$name} refuses a malformed scope rather than widening the sweep fleet-wide. "
                .'Pass a positive integer client_id to sweep one client, or OMIT client_id entirely '
                .'to sweep the fleet on purpose.';
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

        // Notes need client context at the executor. Held actions may derive it
        // from the ticket only on omission, never from a malformed supplied ID.
        if ($clientId === null && ($name === 'add_ticket_note' || ($this->isHeldTicketAction((string) $name) && $hasClientIdArgument))) {
            $message = "A positive integer client_id is required for {$name} when supplying client scope.";
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

            $ticketId = $this->positiveIntegerArgument($arguments['ticket_id'] ?? null);
            $ticket = $ticketId === null ? null : Ticket::find($ticketId);
            $clientId = $ticket?->client_id;
            $message = null;
            if ($ticket && $clientId === null) {
                $ticketScope = UnlinkedTicketScope::Unlinked;
                // Server-derived, retained even if linking changes the ticket.
                $request->attributes->set('mcp_ticket_scope', $ticketScope);
                // Ordinary verb/mode grants admit triage; other writes require linking first.
                if (! UnlinkedTicketScope::admits((string) $name)) {
                    $message = "ticket {$ticketId} is not linked to a client; link it with move_ticket_to_client (confirm_client_name required)";
                }
            }
            if (! $ticket) {
                $message = 'ticket_id is required and must resolve to an existing ticket.';
            }
            if ($message !== null) {
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

        $clientlessPsaTool = in_array((string) $name, self::PSA_CLIENTLESS_ACTION_TOOLS, true);
        if ($clientlessPsaTool && $hasClientIdArgument) {
            $message = "client_id must be omitted for {$name}; it acts only on runs this token drafted.";
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

        if ($this->isPsaActionTool((string) $name) && ! $ticketScopedPsaTool && ! $clientlessPsaTool && $clientId === null) {
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

        if ($this->isMeshAdminTool((string) $name) && $clientId === null) {
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

        if (($this->isHuntressActionTool((string) $name) || $this->isControlDOnboardingTool((string) $name)) && $clientId === null) {
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

        if ($this->isCippAdminTool((string) $name) && StaffCippAdminToolExecutor::requiresClient((string) $name) && $clientId === null) {
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

        // A supplied client must match the ticket, independent of retired flags.
        // Omission keeps the ticket-derived scope used by held ticket actions.
        if ($this->isHeldTicketAction((string) $name)
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

        // UNKNOWN-ARGUMENT REFUSAL (card Z4PdzEZ1, PSA ticket T-22846).
        //
        // Until now an argument the tool does not declare was simply carried
        // into the executor, which read the keys it knew and ignored the rest.
        // On a SEARCH tool that is the worst possible shape: the unrecognised
        // filter is dropped, the search runs UNFILTERED, and what comes back is
        // well-formed data rather than an error. A caller mistake is then
        // indistinguishable from a real result - it reads as "the filter is
        // broken" or, far worse, as "no matching records found", which is an
        // absence claim nothing ever tested.
        //
        // This is the same rule execute_at already follows a few hundred lines
        // above ("on every other tool it is a named refusal, never a silently-
        // ignored key that runs now") and the same rule the CIPP relay applies
        // with "Unsupported CIPP MCP argument(s): ...". It generalises that
        // treatment to every tool on this boundary instead of the handful whose
        // executors happened to hand-roll an allow-list.
        //
        // It runs HERE, after the boundary has consumed its own keys and
        // before dispatch, so no executor ever sees a key its schema did not
        // advertise. client_id is consumed on every tool and stays acceptable;
        // execute_at carries its own named refusal further up. `staged` is NOT
        // unconditionally consumed - callTool() unsets it on the alias and
        // stageable paths and nowhere else - so on a non-stageable tool it is
        // a genuinely undeclared key and is refused like any other. Accepting
        // it there would be this defect on its highest-stakes argument: a
        // caller told it held a proposal for approval, by a call that ran.
        //
        // The comparison is against the PUBLISHED schema - the exact text a
        // caller was handed by tools/list - so the contract being enforced is
        // the one the caller could actually read. An EMPTY properties map is a
        // contract ("this tool declares no arguments") and is enforced as one;
        // only a tool whose schema cannot be resolved at all is left alone.
        // This refuses a key known to be undeclared, never a key we merely
        // failed to look up. Fail open on ignorance, closed on knowledge.
        if ($this->selfValidatingTool((string) $name, $stageable)) {
            // Left to its own validator on purpose - see selfValidatingTool().
        } elseif (($unknown = $this->undeclaredArguments((string) $name, $arguments)) !== []) {
            $declared = $this->declaredArgumentNames((string) $name);
            $message = 'Unsupported MCP argument(s): '.implode(', ', $unknown).'. '
                .$name.' accepts '.($declared === []
                    ? 'no arguments.'
                    : 'only: '.implode(', ', $declared).'.')
                .' The argument was REFUSED, not ignored - this call ran nothing. '
                .'Re-read the tool schema and retry with a declared argument; do not '
                .'treat this as a result.';
            $this->audit('tools/call', $requestedName, $auditArguments, 'error', $message, $start, $request);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => $message]],
                    'isError' => true,
                ],
            ]);
        }

        $activity = new \App\Services\Mcp\TicketToolActivityContext;
        $request->attributes->set(\App\Services\Mcp\TicketToolActivityContext::class, $activity);
        try {
            if (in_array($name, ['get_ticket_tool_history', 'get_ticket_timeline'], true)) {
                $tool = $name === 'get_ticket_timeline' ? \App\Services\Mcp\TicketTimelineTool::class : \App\Services\Mcp\TicketToolHistoryTool::class;
                $result = app($tool)->execute($arguments, $clientId);
            } elseif ($name === \App\Services\Mcp\HdbReportTool::NAME) {
                // Card c5JaSetu: client_id was lifted out above; the tool fences the
                // ticket to it before the report client is called.
                $result = app(\App\Services\Mcp\HdbReportTool::class)->execute($arguments, $clientId);
            } elseif (\App\Services\Mcp\AssetWatchTool::handles((string) $name)) {
                // Card K3VEcxtw: the owner is the authenticated token's bare label,
                // never input; client_id was lifted out above and is passed with
                // whether one was supplied, so a malformed id is refused.
                $result = app(\App\Services\Mcp\AssetWatchTool::class)->execute(
                    (string) $name,
                    $arguments,
                    $clientId,
                    $hasClientIdArgument,
                    $this->tokenLabel($request),
                    $this->actorLabel($request),
                );
            } elseif ($name === \App\Services\Mcp\PsaVersionTool::NAME) {
                $result = app(\App\Services\Mcp\PsaVersionTool::class)->execute();
            } elseif ($name === \App\Services\Mcp\BenjiPaysForecastTool::NAME) {
                $result = app(\App\Services\Mcp\BenjiPaysForecastTool::class)->execute($arguments);
            } elseif ($name === \App\Services\Mcp\BenjiPaysSettingsTool::NAME) {
                // Organization-wide settings: a client_id would suggest a per-client
                // answer this read cannot give, so one is refused rather than ignored.
                $result = $hasClientIdArgument
                    ? ['error' => 'benjipays_get_settings reads the merchant organization\'s settings, not a client\'s; omit client_id.']
                    : app(\App\Services\Mcp\BenjiPaysSettingsTool::class)->execute();
            } elseif (in_array($name, [\App\Services\Mcp\BenjiPaysTransactionsTool::NAME, \App\Services\Mcp\BenjiPaysInvoiceTool::NAME, \App\Services\Mcp\BenjiPaysPaymentMethodsTool::NAME, \App\Services\Mcp\BenjiPaysSentEmailsTool::NAME], true)) {
                // client_id was lifted out of $arguments above; hand the fence both the
                // parsed id and whether one was supplied, so a malformed id is refused
                // rather than collapsing to "no client".
                $tool = match ($name) {
                    \App\Services\Mcp\BenjiPaysTransactionsTool::NAME => \App\Services\Mcp\BenjiPaysTransactionsTool::class,
                    \App\Services\Mcp\BenjiPaysInvoiceTool::NAME => \App\Services\Mcp\BenjiPaysInvoiceTool::class,
                    \App\Services\Mcp\BenjiPaysSentEmailsTool::NAME => \App\Services\Mcp\BenjiPaysSentEmailsTool::class,
                    default => \App\Services\Mcp\BenjiPaysPaymentMethodsTool::class,
                };
                $result = app($tool)->execute($arguments, $clientId, $hasClientIdArgument);
            } elseif ($name === self::WHOAMI_TOOL) {
                $result = $this->whoami($request);
            } elseif ($name === self::TOOL_SURFACE_TOOL) {
                $result = $this->listToolSurface($request, $arguments);
            } elseif ($name === self::SEARCH_TOOLS_TOOL) {
                $result = $this->searchTools($request, $arguments);
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
                if ($offboarding) {
                    if ($explicitClientArgument !== (int) $clientId) {
                        throw new \RuntimeException('Offboarding requires matching explicit client scope.');
                    }
                    $arguments['client_id'] = $explicitClientArgument;
                    $arguments['staged'] = true;
                }
                $staffToken = $request->attributes->get('mcp_staff_token');
                $result = app(StaffCippWriteToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    (int) $clientId,
                    $this->actorLabel($request),
                    $staffToken instanceof McpStaffToken ? $staffToken->id : null,
                    $executeAt,
                    $this->tokenLabel($request),
                );
            } elseif ($this->isCippAdminTool((string) $name)) {
                $result = app(StaffCippAdminToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    (int) $clientId,
                    $this->actorLabel($request),
                );
            } elseif (\App\Models\CarbonMcpTool::handles((string) $name)) {
                // Carbon Connect MCP passthrough (card PEYvh4NL): the MSP's own Carbon
                // account, so a supplied client_id is refused by the executor, not used.
                $result = app(\App\Services\Carbon\CarbonMcpToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    $hasClientIdArgument,
                );
            } elseif (CippMcpTool::handles((string) $name)) {
                $result = app(CippMcpDynamicToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    $clientId !== null ? Client::find($clientId) : null,
                    $clientId,
                );
            } elseif ($this->isTacticalActionTool((string) $name)) {
                $staffToken = $request->attributes->get('mcp_staff_token');
                $result = app(StaffTacticalActionToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    (int) $clientId,
                    $this->actorLabel($request),
                    $staffToken instanceof McpStaffToken ? $staffToken->id : null,
                    $executeAt,
                    $this->tokenLabel($request),
                );
            } elseif ($this->isHuntressActionTool((string) $name)) {
                $result = app(StaffHuntressActionToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    (int) $clientId,
                    $this->actorLabel($request),
                    $this->tokenLabel($request),
                );
            } elseif ($this->isControlDOnboardingTool((string) $name)) {
                // B4.1 (#2043): the executor binds the staging token (id + ai_actor) into the
                // held proposal; a non-ai_actor token is refused there. Legacy token → null → refused.
                $staffToken = $request->attributes->get('mcp_staff_token');
                $result = app(StaffControlDOnboardingToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    (int) $clientId,
                    $this->actorLabel($request),
                    $staffToken instanceof McpStaffToken ? $staffToken : null,
                );
            } elseif ($this->isMeshAdminTool((string) $name)) {
                // Card XUiMXNEH: the bare tokenLabel is threaded so a staged Mesh run
                // records proposed_meta.drafted_by_token, which withdraw_staged_action
                // matches (as stage_email, send_reply and the calendar stagings do).
                $result = app(StaffMeshAdminToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    (int) $clientId,
                    $this->actorLabel($request),
                    $this->tokenLabel($request),
                );
            } elseif ($this->isTacticalAdminTool((string) $name)) {
                $result = app(StaffTacticalAdminToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    $clientId,
                    $this->actorLabel($request),
                    $this->tokenLabel($request),
                );
            } elseif ($this->isPsaActionTool((string) $name)) {
                $result = app(StaffPsaActionToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    $ticketScope ?? (int) $clientId,
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
                // Identity resolvers require explicit client_id; the other intake
                // handlers derive their scope from the targeted record IDs.
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
            } elseif ($this->isCalendarTool((string) $name)) {
                // psa-abl0i: UPN-scoped, never client-scoped ($clientId stays null → 0,
                // ignored by the executor). The executor's guardOwnerUpn() enforces the
                // server-side owner allowlist BEFORE any Graph call. The tokenLabel is threaded
                // (psa-lulgh) so a staged calendar write records its drafter for the approval card.
                $result = app(StaffCalendarToolExecutor::class)->execute(
                    (string) $name,
                    $arguments,
                    (int) $clientId,
                    $this->actorLabel($request),
                    $this->tokenLabel($request),
                );
            } elseif ((string) $name === 'send_reply') {
                $result = $this->sendReply($arguments, $request);
            } elseif ((string) $name === 'request_tool') {
                $result = $this->requestTool($arguments, $request);
            } else {
                $userId = $this->userIdForToolCall($request, (string) $name);
                // card tY39CHiq: the drafter labels let a held propose_close record drafted_by_token,
                // so the drafting token can withdraw it.
                $executor = new AssistantToolExecutor(ticket: null, clientId: $clientId, userId: $userId, actorLabel: $this->actorLabel($request), tokenLabel: $this->tokenLabel($request));
                $result = $executor->execute($name, is_array($arguments) ? $arguments : []);
            }
            // The direct lane's own message is written by ScheduledDirectAdmission (it names
            // the authorization and says no approval is required); nothing is appended here,
            // and `downgraded_to_staged` is never set on it — the token was not downgraded,
            // it exercised the grant it holds.
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

            $imageBlock = $this->attachmentImageBlock((string) $name, $result);
            if ($imageBlock !== null) {
                // The bytes travel once, in the image block; the text says where they are.
                unset($result['data_base64']);
                $result['image_block'] = true;
            }

            $content = [
                ['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            ];
            if ($imageBlock !== null) {
                $content[] = $imageBlock;
            }

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

            if (in_array((string) $name, ['portal_get_or_create_install_link', 'tactical_open_remote_control', 'tactical_get_or_create_installer', 'tactical_generate_installer', 'cipp_reset_user_password', 'cipp_create_user'], true) && ! $isError) {
                $response->headers->set('Cache-Control', 'no-store');
            }

            return $response;
        } catch (\Throwable $e) {
            Log::warning('[MCP/staff] Tool execution failed', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);

            $activity->finish(['error' => true]);
            $this->audit('tools/call', $name, $auditArguments, 'error', $e->getMessage(), $start, $request);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [['type' => 'text', 'text' => 'Tool execution failed: '.$e->getMessage()]],
                    'isError' => true,
                ],
            ]);
        } finally {
            $request->attributes->remove(\App\Services\Mcp\TicketToolActivityContext::class);
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

    /**
     * Card 6ad8e6a1: an image returned by get_ticket_attachment or
     * get_teams_message_attachment as a native MCP image content block, so a
     * client renders it as a picture instead of a base64 string in text. Null
     * for any other tool, an error, a non-image file, an image/* type outside
     * the vision set (is_image false), or a result without image bytes and an
     * image/* media type.
     *
     * @return array{type: 'image', data: string, mimeType: string}|null
     */
    private function attachmentImageBlock(string $name, mixed $result): ?array
    {
        if (! in_array($name, ['get_ticket_attachment', 'get_teams_message_attachment'], true)
            || ! is_array($result) || isset($result['error']) || ($result['is_image'] ?? null) !== true) {
            return null;
        }

        $data = $result['data_base64'] ?? null;
        $mimeType = $result['media_type'] ?? null;
        if (! is_string($data) || $data === '' || ! is_string($mimeType) || ! str_starts_with($mimeType, 'image/')) {
            return null;
        }

        return ['type' => 'image', 'data' => $data, 'mimeType' => $mimeType];
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
        $activity = $request->attributes->get(\App\Services\Mcp\TicketToolActivityContext::class);
        if ($activity?->ticketId === null) {
            $activity = null;
        }
        try {
            McpAuditLog::create([
                'ticket_id' => $activity?->ticketId,
                'client_id' => $activity?->clientId,
                'action_log_id' => $activity?->actionLogId,
                'correlation_id' => $activity?->correlationId,
                'activity_kind' => $activity?->kind,
                'result_summary' => $activity?->summary,
                'server_name' => 'staff',
                'method' => $method,
                'tool_name' => $tool,
                'arguments' => is_array($args) ? array_merge(
                    $this->auditArguments($tool, $args),
                    $request->attributes->get('mcp_ticket_scope') === UnlinkedTicketScope::Unlinked
                        ? ['ticket_scope' => 'unlinked', 'client_id' => null] : [],
                ) : null,
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
        // Carbon Connect MCP (card PEYvh4NL): arguments can carry Carbon company and
        // customer data, so the audit row keeps the argument NAMES only.
        if (str_starts_with((string) $tool, \App\Models\CarbonMcpTool::NAME_PREFIX)) {
            return ['argument_keys' => array_values(array_map('strval', array_keys($args)))];
        }

        if (in_array($tool, ['resolve_email_item', 'stage_resolve_email_item'], true)) {
            $safe = [];
            foreach (['email_id', 'client_id'] as $key) {
                if (is_int($args[$key] ?? null)) {
                    $safe[$key] = $args[$key];
                }
            }
            $safe['reason_length'] = is_string($args['reason'] ?? null) ? mb_strlen($args['reason']) : 0;

            return $safe;
        }

        if (in_array($tool, ['resolve_phone_call', 'stage_resolve_phone_call'], true)) {
            $safe = [];
            foreach (['phone_call_id', 'client_id', 'contact_id'] as $key) {
                if (is_int($args[$key] ?? null)) {
                    $safe[$key] = $args[$key];
                }
            }
            $safe['reason_length'] = is_string($args['reason'] ?? null) ? mb_strlen($args['reason']) : 0;

            return $safe;
        }

        if (in_array((string) $tool, self::CALL_ACTION_ID_ONLY_AUDIT_TOOLS, true)) {
            $safe = [];
            if (is_int($args['phone_call_id'] ?? null)) {
                $safe['phone_call_id'] = $args['phone_call_id'];
            }
            if (is_bool($args['billable'] ?? null)) {
                $safe['billable'] = $args['billable'];
            }
            if (array_key_exists('staged', $args)) {
                $safe['staged'] = (bool) $args['staged'];
            }
            $safe['reason_length'] = is_string($args['reason'] ?? null) ? mb_strlen($args['reason']) : 0;

            return $safe;
        }

        if ($tool === 'send_reply') {
            return $this->auditSendReplyArguments($args);
        }

        if ($tool === 'create_ticket') {
            return $this->auditCreateTicketArguments($args);
        }

        if (in_array((string) $tool, self::BODY_LENGTH_AUDIT_TOOLS, true)) {
            return $this->auditBodyLengthArguments($args);
        }

        if ($tool === 'merge_ticket' || $tool === 'propose_merge') {
            return $this->auditProposeMergeArguments($args);
        }

        if ($tool === 'merge_asset' || $tool === 'propose_asset_merge') {
            return $this->auditProposeAssetMergeArguments($args);
        }

        if ($tool === 'update_ticket') {
            return $this->auditUpdateTicketArguments($args);
        }

        if ($tool === 'set_ticket_status') {
            return $this->auditSetTicketStatusArguments($args);
        }

        if ($tool === 'close_ticket' || $tool === 'stage_close_ticket') {
            return $this->auditCloseTicketArguments($tool, $args);
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

        if (\App\Services\Mcp\AssetWatchTool::handles((string) $tool)) {
            // Ids and flags only; reason is operator prose, so only its length.
            $safe = array_intersect_key($args, array_flip(['client_id', 'asset_id', 'watch_id', 'state', 'repeat', 'expires_at']));
            if (array_key_exists('reason', $args)) {
                $safe['reason_length'] = is_string($args['reason']) ? mb_strlen($args['reason']) : 0;
            }

            return $safe;
        }

        if ($tool === 'rebind_tactical_asset') {
            return array_intersect_key($args, array_flip(['asset_id', 'target_asset_id']));
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

        if ($this->isCalendarTool((string) $tool)) {
            return $this->auditCalendarArguments($args);
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

    private function auditProposeAssetMergeArguments(array $arguments): array
    {
        $safe = [];

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['client_id', 'survivor_asset_id', 'duplicate_asset_id', 'ticket_id'], true)) {
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

            if (in_array($normalized, ['ticket_id', 'status'], true)) {
                $safe[$normalized] = $value;
            }

            if (in_array($normalized, ['reason', 'note'], true)) {
                $safe[$normalized.'_length'] = is_string($value) ? mb_strlen($value) : 0;
            }
        }

        return $safe;
    }

    /**
     * close_ticket / stage_close_ticket audit shape: keep the scalar decision fields
     * (ticket_id, status, confidence) and reduce the free-text resolution_summary / reason
     * to lengths only — they can carry client detail and never belong in the audit body.
     *
     * Keys the tool's registry schema does not declare are recorded by NAME under
     * unknown_keys (card crRnwaQJ). Without that, the row for a call refused over a misnamed
     * key (`resolution` for resolution_summary) showed no sign of the key. Only names are
     * kept, never values, since a value can carry client text. At most 10 names are kept,
     * each cut to 64 characters, and unknown_key_count holds the full count. The match is
     * exact, the same as the executor's allow-list. staged, client_id and execute_at are not
     * counted: callTool() consumes them itself and unsets them before dispatch, but a row it
     * refuses before those unsets (Tool not allowed, an execute_at refusal) can still carry
     * client_id or execute_at. So on a dispatched call the audit names the same keys the
     * refusal names.
     *
     * @return array<string, mixed>
     */
    private function auditCloseTicketArguments(string $tool, array $arguments): array
    {
        $safe = [];

        $definition = $tool === 'stage_close_ticket'
            ? McpToolRegistry::stageCloseTicketTool()
            : McpToolRegistry::closeTicketTool();
        $declared = [
            ...array_keys((array) ($definition['input_schema']['properties'] ?? [])),
            'staged', 'client_id', 'execute_at',
        ];
        $unknown = [];
        foreach (array_keys($arguments) as $key) {
            if (! in_array((string) $key, $declared, true)) {
                $unknown[] = (string) $key;
            }
        }
        if ($unknown !== []) {
            sort($unknown);
            $safe['unknown_keys'] = array_map(
                static fn (string $key): string => mb_substr($key, 0, 64),
                array_slice($unknown, 0, 10),
            );
            $safe['unknown_key_count'] = count($unknown);
        }

        foreach ($arguments as $key => $value) {
            $normalized = mb_strtolower((string) $key);

            if (in_array($normalized, ['ticket_id', 'status', 'confidence'], true)) {
                $safe[$normalized] = $value;
            }

            if (in_array($normalized, ['resolution_summary', 'reason'], true)) {
                $safe[$normalized.'_length'] = is_string($value) ? mb_strlen($value) : 0;
            }
        }

        return $safe;
    }

    /**
     * Calendar read audit shape (psa-abl0i): safelist the scalar decision fields — user_upn (the
     * allowlisted owner mailbox), event_id, the start/end window, interval — and the schedules
     * list. A safelist (not a passthrough) so a field added by a later slice never lands in the
     * audit body unreviewed.
     *
     * The audit records REJECTED calls too, so the values here are UNTRUSTED — the guard may not
     * have proven them (psa-abl0i.2 audit-debt). Shape-bound before persistence: scalars are
     * length-capped, and schedules keeps only string entries with a bounded count + length, so a
     * hostile/oversized argument on a refused call cannot bloat or poison the audit log.
     *
     * @return array<string, mixed>
     */
    private function auditCalendarArguments(array $arguments): array
    {
        $maxLen = 320;       // a UPN / event id / ISO timestamp is short; cap defensively
        $maxSchedules = 50;  // bound an untrusted (possibly rejected-call) list

        $safe = [];

        // Structural/enum scalars are safe to keep verbatim (short, non-content): the mailbox, the
        // event id, the window, the ticket the write traces to, the response verb, and the toggles.
        foreach (['user_upn', 'event_id', 'start', 'end', 'interval', 'ticket_id', 'response', 'teams_meeting', 'send_response', 'tz'] as $key) {
            if (array_key_exists($key, $arguments) && is_scalar($arguments[$key])) {
                $safe[$key] = is_string($arguments[$key]) ? mb_substr($arguments[$key], 0, $maxLen) : $arguments[$key];
            }
        }

        // Free-text write fields (Slice B) are reduced to LENGTH only — never the content. subject /
        // body / comment / reason / location can carry client-identifying detail and must not land
        // raw in the audit (the close_ticket / CIPP redaction idiom); the human-readable trace is
        // the private ticket back-link note, not this transport log.
        foreach (['subject', 'body', 'comment', 'reason', 'location'] as $key) {
            if (isset($arguments[$key]) && is_string($arguments[$key])) {
                $safe[$key.'_length'] = mb_strlen($arguments[$key]);
            }
        }

        // attendees is a caller-controlled list of (possibly external) emails — record only the
        // count, never the addresses.
        if (isset($arguments['attendees']) && is_array($arguments['attendees'])) {
            $safe['attendee_count'] = count($arguments['attendees']);
        }

        if (isset($arguments['schedules']) && is_array($arguments['schedules'])) {
            $schedules = [];
            foreach ($arguments['schedules'] as $entry) {
                if (count($schedules) >= $maxSchedules) {
                    $schedules[] = '…(truncated)';
                    break;
                }
                if (is_string($entry)) {
                    $schedules[] = mb_substr($entry, 0, $maxLen);
                }
            }
            $safe['schedules'] = $schedules;
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

    /**
     * Does this tool already refuse an undeclared argument in its own executor?
     *
     * The write, action and admin families hand-roll per-tool allow-lists and
     * refuse with messages that teach the SEMANTICS of the mistake - "upstream
     * CIPP identifiers are not accepted", "for a PSA-mapped person use
     * cipp_assign_user_license". Those are strictly more useful than a generic
     * "unsupported argument" and, on a mutating tool, they are also a safety
     * surface with their own controls. Pre-empting them would REPLACE a good
     * message with a worse one and move a refusal those tests pin.
     *
     * So the generic guard deliberately covers the READ surface only, which is
     * exactly where the defect this fixes was found: a search tool that drops
     * an unrecognised filter and answers unfiltered. Reads also carry no
     * side-effect risk, so refusing one late is free.
     *
     * A tool that gains its own allow-list later does not need this list
     * updated: the worst case is a generic refusal instead of a specific one,
     * never a silently-ignored argument.
     *
     * Membership is a claim about the EXECUTOR, not about a family name, and
     * the operator bridge never met it: none of OperatorBridgeToolExecutor's
     * five bodies carries an allow-list, so find_staff read `query`/`limit`
     * and dropped everything else - including an `is_active` filter its own
     * description invites by advertising that field in the result. Exempting
     * that family preserved THIS defect, on a read, inside the change that
     * exists to close it. The question to ask of any arm added here is the one
     * that arm failed: name the refusal it defers to, and name the state in
     * which that refusal actually runs.
     *
     * ACCEPT_AND_IGNORE_TOOLS are excluded for a DIFFERENT and stronger
     * reason than the write families: for them, dropping an undeclared key IS
     * the security contract. send_reply deliberately does not declare `to`,
     * and ChetSendReplyTest pins BOTH halves - the schema must not advertise
     * it, AND a caller who supplies it must be ignored rather than obeyed, so
     * a prompt-injected recipient cannot redirect a client-facing reply.
     * Refusing there would turn a contained injection attempt into a failed
     * call: louder, but it changes a behaviour a control asserts, and the
     * containment is the point. These tools drop the key BY DESIGN; the read
     * surface dropped it BY ACCIDENT, and only the accident is in scope.
     */
    private const ACCEPT_AND_IGNORE_TOOLS = [
        'send_reply',
        'add_ticket_note',
        'propose_close',
        'request_tool',
    ];

    private function selfValidatingTool(string $name, bool $stageable): bool
    {
        return $stageable
            || McpToolModes::isStageable($name)
            || $this->isPsaActionTool($name)
            || $this->isCippWriteTool($name)
            || $this->isCippAdminTool($name)
            || $this->isTacticalActionTool($name)
            || $this->isTacticalAdminTool($name)
            || $this->isTaxonomyTool($name)
            || in_array($name, self::ACCEPT_AND_IGNORE_TOOLS, true)
            // App\Models\CippMcpTool::handles() - the DB-backed dynamic tools,
            // present only while cipp_mcp_enabled is set.
            || CippMcpTool::handles($name)
            // ...and the RELAY's own static map, which is a DIFFERENT class and
            // a different set. CippMcpToolRelay::unknownArguments() already
            // answers "Unsupported CIPP MCP argument(s): ..." naming the keys,
            // so the generic guard must defer or it DISPLACES the better
            // message - the regression CippMcpRelayTest pins. The two CIPP
            // predicates read alike and are not interchangeable: the model asks
            // the registry, the relay asks its own TOOL_MAP.
            //
            // Gated on the predicate that decides whether the relay RUNS, not
            // on its map alone. handles() is static and carries no config, but
            // the refusal it defers to is reachable ONLY through
            // AssistantToolExecutor::cippMcpRelay(), which returns null unless
            // isMcpRelayEnabled(). With cipp_enabled on and cipp_mcp_enabled
            // off - the documented fallback (psa-dbrw, psa-idii) - these static
            // CIPP reads are still published under the WIDER REST predicate
            // (isEnabled() && isConfigured()) and dispatch straight to
            // CippClient, which inspects no keys at all. Exempting them there
            // deferred to a validator that does not run and reopened the silent
            // drop on a read. Publish and dispatch must answer ONE question
            // (psa-wzjzz); so must exempt and refuse.
            //
            // ORDERED map-first and GUARDED - see cippMcpRelayIsLive(). The
            // relay's handles() is a pure static map; the predicate gating it
            // is I/O that can THROW, and this method runs on the pre-dispatch
            // path of every call.
            || (CippMcpToolRelay::handles($name) && $this->cippMcpRelayIsLive());
    }

    /**
     * isMcpRelayEnabled(), read the way every other consumer of these settings
     * reads it: guarded, and only for a name that could possibly care.
     *
     * The predicate is two uncached Setting reads and, once cipp_mcp_enabled
     * is set, a Crypt::decryptString of cipp_mcp_client_secret - so a rotated
     * APP_KEY, or a settings table restored from another environment, makes it
     * THROW rather than return false. selfValidatingTool() is evaluated before
     * dispatch and OUTSIDE callTool()'s try, so an escape there is a -32603 on
     * the call, not a CIPP degradation.
     *
     * ORDER carries the first half of that. Gated behind the static map, the
     * read happens only for a relay-mapped name; every other tool never
     * touches it - including whoami / list_tool_surface / search_tools, which
     * toolAllowed() passes BEFORE the liveness check precisely so a caller can
     * still ask why something was refused. Those three are the names for which
     * this arm was the first config read on the path at all.
     *
     * The CATCH carries the second. On a relay-mapped name a config fault
     * means the relay is not going to run, so there is no better refusal to
     * defer TO and the generic guard must apply - the same answer the
     * cipp_mcp_enabled='0' arm gives, for the same reason. Degrade CIPP, never
     * the boundary: CippMcpTool::handles() already catches \Throwable here,
     * as do TriageToolDefinitions::isCippAvailable() and
     * Setting::settingOrConfig(); this arm was the one that did not.
     */
    private function cippMcpRelayIsLive(): bool
    {
        try {
            return CippConfig::isMcpRelayEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Argument names the tool's own schema declares, as published to callers.
     *
     * Resolved from the SAME assemblies listTools() publishes, so the contract
     * enforced on dispatch is the contract the caller was handed. Returns null
     * - distinct from an empty array - only when no assembly here knows the
     * tool, or it carries no properties block at all; the caller treats null
     * as "do not judge this call" and an empty array as "this tool declares no
     * arguments". An empty MAP - `(object) []`, which is how the definitions
     * render {} - is the second of those, not the first.
     *
     * The boundary's own keys are added deliberately, but only where they are
     * genuinely the boundary's. client_id is consumed on every tool, and
     * execute_at is refused by name further up, so both stay acceptable here.
     * `staged` is accepted ONLY on a stageable tool: callTool() unsets it on
     * the alias and stageable paths and NOWHERE else, so on a non-stageable
     * tool it reaches this guard as a live, undeclared key. Whitelisting it
     * there would silently drop the one argument that means "hold this for a
     * human", and the caller would record an approval request for work that
     * already ran - this defect, on the key that can least afford it.
     *
     * @return list<string>|null
     */
    private function declaredArgumentNamesOrNull(string $name): ?array
    {
        if (($declared = $this->publishedPropertyNamesOrNull($name)) === null) {
            return null;
        }

        $boundaryKeys = McpToolModes::isStageable($name)
            ? ['client_id', 'staged', 'execute_at']
            : ['client_id', 'execute_at'];
        foreach ($boundaryKeys as $boundaryKey) {
            if (! in_array($boundaryKey, $declared, true)) {
                $declared[] = $boundaryKey;
            }
        }
        sort($declared);

        return $declared;
    }

    /**
     * The property names the tool's OWN schema carries - exactly the keys
     * tools/list published for it, before any boundary key is added.
     *
     * Split out from the acceptance set above because the MESSAGE has to tell
     * the two apart. A boundary key is the BOUNDARY'S only where the tool did
     * not declare it itself: the general PSA reads declare client_id in their
     * own properties, so subtracting it by name quoted a contract the caller
     * was never handed.
     *
     * Returns null on the same terms as the caller above - an unresolved name
     * or no properties block at all - and an empty array for an empty map.
     *
     * @return list<string>|null
     */
    private function publishedPropertyNamesOrNull(string $name): ?array
    {
        static $cache = [];

        if (array_key_exists($name, $cache)) {
            return $cache[$name];
        }

        $schema = null;
        foreach (array_merge(
            McpToolSurface::liveGeneralToolDefinitions(),
            McpToolSurface::liveClientScopedToolDefinitions(),
            [$this->whoamiToolDefinition(), $this->toolSurfaceToolDefinition(), $this->searchToolsToolDefinition()],
        ) as $tool) {
            if (($tool['name'] ?? null) === $name) {
                $schema = $tool['input_schema'] ?? null;
                break;
            }
        }

        if (! is_array($schema)) {
            return $cache[$name] = null;
        }

        // The definitions render a properties map as a stdClass so it encodes
        // as {} rather than []; listTools() normalises with `(array)` before
        // injecting client_id, and this guard reads the SAME definitions, so
        // it must normalise identically. Read raw, the cast failed is_array
        // and the tool dropped out of the guard over an encoding artifact
        // rather than a decision - fail-open by ACCIDENT, on exactly the read
        // surface this exists to protect.
        $properties = $schema['properties'] ?? null;
        if ($properties instanceof \stdClass) {
            $properties = (array) $properties;
        }

        if (! is_array($properties)) {
            return $cache[$name] = null;
        }

        return $cache[$name] = array_values(array_filter(array_keys($properties), 'is_string'));
    }

    /**
     * The names to QUOTE back to the caller: what tools/list actually
     * published for this tool, and nothing else.
     *
     * This is the operator-facing half and it must not over-promise. The
     * acceptance set above carries the boundary's own keys so a caller is
     * never REFUSED for one; naming them here would do the opposite of this
     * change's purpose, because it reads as an invitation. A caller that
     * retries with client_id on a general tool lands straight back in the
     * silent-drop shape - the boundary unsets it, the executor never declared
     * it, the tool answers, and nothing says the key did nothing. So the
     * message names the published properties, plus client_id only where it is
     * published. Empty - a real "accepts no arguments" - when none are.
     *
     * @return list<string>
     */
    private function declaredArgumentNames(string $name): array
    {
        $declared = $this->declaredArgumentNamesOrNull($name) ?? [];
        $ownProperties = $this->publishedPropertyNamesOrNull($name) ?? [];

        // Subtract client_id only where it is genuinely the BOUNDARY'S key.
        // tools/list injects it into client-scoped schemas, but the general
        // PSA reads declare it in their own properties - REQUIRED on
        // get_contract and list_client_contracts, an optional filter on the
        // fleet-capable reads (list_mislinked_assets, list_invoices) where an
        // ABSENT client_id means "every client". Subtracting it by name there
        // hid a published argument and pointed the retry straight off a
        // cross-client scope fence: the message must never under-promise the
        // published contract any more than it may over-promise it.
        $unpublished = ['staged', 'execute_at'];
        if (! $this->publishesClientId($name) && ! in_array('client_id', $ownProperties, true)) {
            $unpublished[] = 'client_id';
        }

        return array_values(array_diff($declared, $unpublished));
    }

    /**
     * Does tools/list inject client_id into this tool's published schema?
     *
     * Mirrors listTools(): the general assembly wins on a duplicate name, and
     * a PSA ticket-scoped tool is scoped by its ticket rather than by an
     * injected client_id. listTools() also treats the action, write and
     * add_ticket_note families as client-scoped; every one of those is
     * self-validating or accept-and-ignore, so none can reach this message and
     * none is re-derived here. Governs the MESSAGE only, never acceptance.
     */
    private function publishesClientId(string $name): bool
    {
        foreach (array_merge(
            [$this->whoamiToolDefinition(), $this->toolSurfaceToolDefinition(), $this->searchToolsToolDefinition()],
            McpToolSurface::liveGeneralToolDefinitions(),
        ) as $tool) {
            if (($tool['name'] ?? null) === $name) {
                return false;
            }
        }

        foreach (McpToolSurface::liveClientScopedToolDefinitions() as $tool) {
            if (($tool['name'] ?? null) === $name) {
                return ! $this->isPsaTicketScopedTool($name);
            }
        }

        return false;
    }

    /**
     * Supplied argument names the tool does not declare.
     *
     * Empty when the schema could not be resolved: an unrecognised argument is
     * only refusable against a contract we can actually read.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    private function undeclaredArguments(string $name, array $arguments): array
    {
        if ($arguments === [] || ($declared = $this->declaredArgumentNamesOrNull($name)) === null) {
            return [];
        }

        $unknown = array_values(array_diff(array_keys($arguments), $declared));
        sort($unknown);

        return array_slice($unknown, 0, 10);
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

    private function isScopeWideningRead(string $toolName): bool
    {
        return in_array($toolName, self::SCOPE_WIDENING_READ_TOOLS, true);
    }

    /**
     * Echo the rejected client_id back so the caller can see WHICH value was
     * refused (a typo is invisible otherwise). Scalars only, quoted, truncated:
     * the value is caller-supplied and the message is rendered as text on
     * surfaces that are not all trusted to treat it as data.
     */
    private function renderRejectedArgument(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) json_encode($value);
        }

        if (is_string($value)) {
            return '"'.mb_substr(preg_replace('/[^\P{C}]|["\\\\]/u', '', $value) ?? '', 0, 40).'"';
        }

        return '('.gettype($value).')';
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
        if ($toolName === self::WHOAMI_TOOL || $toolName === self::TOOL_SURFACE_TOOL || $toolName === self::SEARCH_TOOLS_TOOL) {
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

        // Email resolution (#1293) is DEFAULT-UNGRANTED and held-only, exactly as the
        // tool description promises: the token must grant it in the `staged` MODE. The
        // grant is read the way every other gate reads one — through the resolved token
        // (allows() + modeFor()), never by string-matching a raw `tool:mode` entry:
        // McpConfig resolves grants through McpToolModes::parseGrants(), which strips the
        // suffix into toolModes, so allowedTools holds plain canonical names and a literal
        // `resolve_email_item:staged` comparison matches NO token at all — including the
        // documented `:staged` grant, which is how this gate hid the tool from everyone.
        //
        // A BARE `resolve_email_item` entry — what a bulk tier grant of the intake surface
        // produces, and which normalizeGrantEntries() stores as the `:immediate` mode — is
        // deliberately NOT enough, so intake-manage membership (routing only) can never
        // confer a sender-wide client reassignment the operator did not grant by name.
        // That is also why the immediate mode is refused rather than treated as a superset
        // here: accepting it would let the tier's bulk "Grant shown" click confer this
        // tool, and the capability has no immediate lane to confer anyway (callTool
        // refuses staged !== true). Placed above every family branch so no family default,
        // and no full-surface token, can outrun it.
        if (in_array($toolName, ['resolve_email_item', 'stage_resolve_email_item'], true)) {
            $canonical = McpToolModes::canonicalForAlias($toolName) ?? $toolName;

            return $token->allowedTools !== null
                && $token->allows($canonical)
                && $token->modeFor($canonical) === McpToolModes::MODE_STAGED;
        }

        // High-scope curated CIPP reads: explicit grant only, never auto-inherited by the
        // legacy full-surface token. Placed FIRST among the family branches because it is
        // the only one that gates a CURATED READ — a reader scanning for "why doesn't the
        // legacy token get this?" will look here before the write families.
        if (in_array($toolName, self::CIPP_EXPLICIT_GRANT_READ_TOOLS, true)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        // Raw-file-content reads (psa-688): explicit grant only, never auto-inherited
        // by the legacy full-surface token. Attachment bytes flow straight into agent
        // context, so the operator grants this by name or not at all.
        if (in_array($toolName, self::RAW_FILE_CONTENT_EXPLICIT_GRANT_TOOLS, true)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if (in_array($toolName, self::WIKI_WRITE_TOOLS, true)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        // Asset watch alerts (card K3VEcxtw): EXPLICIT-GRANT-ONLY, never inherited by
        // the legacy full-surface token, which also has no label to own a watch.
        if (\App\Services\Mcp\AssetWatchTool::handles($toolName)) {
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

        // Calendar/scheduling (psa-abl0i): EXPLICIT-GRANT-ONLY. The legacy full-surface token
        // must never inherit tenant-wide staff-mailbox reads — the server-side UPN allowlist is
        // the only remaining constraint on which mailboxes the tenant-wide Graph token touches,
        // and a token that grants everything by construction would bypass the operator's
        // per-tool decision. Mirrors the Chet data-surface and psa_read gates.
        if ($this->isCalendarTool($toolName)) {
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

        // Huntress escalation actions: EXPLICIT-GRANT-ONLY, never inherited by
        // the legacy full-surface token — resolving a SOC escalation is a
        // security record change and rides a write credential the operator
        // added deliberately; the per-tool grant is the operator's decision
        // point. Mirrors the CIPP-write and calendar gates.
        if ($this->isHuntressActionTool($toolName)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        // Mesh allow-list writes: EXPLICIT-GRANT-ONLY, never inherited by the
        // legacy full-surface token. An allow rule weakens a customer's mail
        // filtering and there is no immediate lane at all, so the operator's
        // per-tool grant is the decision point. Mirrors the Huntress gate.
        if ($this->isMeshAdminTool($toolName)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        // Control D client onboarding (B4): EXPLICIT-GRANT-ONLY, never inherited by
        // the legacy full-surface token. It creates vendor organizations and
        // provisioning codes; the operator's per-tool grant is the decision point
        // and is Charlie's to give on prod. Mirrors the Huntress and Mesh gates.
        if ($this->isControlDOnboardingTool($toolName)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        if (CippMcpTool::handles($toolName)) {
            return $token->allowedTools !== null && $token->allows($toolName);
        }

        // Carbon Connect MCP catalogue tools (card PEYvh4NL): EXPLICIT-GRANT-ONLY, as the
        // dynamic CIPP rows above. The legacy full-surface token never inherits them; the
        // operator's per-token grant is the only approval (Charlie's ruling, 10/21).
        if (\App\Models\CarbonMcpTool::handles($toolName)) {
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
     * PUBLICATION predicate for the ADVERTISED surface (tools/list): toolAllowed()
     * AND "this token could actually succeed". Today that adds one conjunct: Control D
     * client onboarding is staged only by an ai_actor token (B4.1, #2043 — the executor
     * refuses any other with an audited reason), so a granted non-ai_actor token is not
     * shown the verb either (B4.2, #2056 diff:4/contract:4 — publish/dispatch parity).
     * Deliberately NOT folded into toolAllowed(): tools/call keeps B4.1's refusal in the
     * executor, which names ai_actor, points at the client-page button and writes the
     * `rejected` action-log row — the grant gate's generic "Tool not allowed" would lose
     * all three.
     *
     * NOT the predicate list_tool_surface / search_tools classify with (B4.2, #2056 diff:5).
     * Those report a `grant_state` per catalog entry, and both publication answers are wrong
     * for a granted non-ai_actor token: classifying by publication calls the verb
     * `available_ungranted` ("not in this token's allowlist — an operator token grant enables
     * it"), which sends the operator to re-grant a grant it already holds, and dropping the
     * entry instead puts it under `absent_means` ("does not exist on this server — request_tool
     * records it as a build request"). So the catalog keeps classifying by the GRANT, which is
     * what `grant_state` names, and the caller learns about the lane where B4.1 says it: the
     * executor's refusal, which names ai_actor and points at the client-page button.
     *
     * @param  array<string, true>|null  $liveLookup  see toolAllowed()
     */
    private function toolPublished(Request $request, string $toolName, ?array $liveLookup = null): bool
    {
        if (! $this->toolAllowed($request, $toolName, $liveLookup)) {
            return false;
        }

        if ($this->isControlDOnboardingTool($toolName)) {
            $token = $request->attributes->get('mcp_staff_token');

            return $token instanceof McpStaffToken && $token->aiActor;
        }

        return true;
    }

    /**
     * Whether staged=false may execute a stageable tool now. Delegates to
     * McpStaffToken::allowsImmediate(), which resolves the mode through
     * McpToolModes::effectiveMode() — the same single resolution point
     * tools/list advertises from, so this gate can never grant a lane the
     * published surface says is approval-gated. A capability whose immediate
     * lane is new (merge_ticket / merge_asset) needs the explicit per-tool
     * `:immediate` grant; every other stageable tool keeps its legacy default.
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

    /** psa-abl0i calendar/scheduling reads (Slice A). UPN-scoped — never client/ticket-scoped. */
    private function isCalendarTool(string $toolName): bool
    {
        return StaffCalendarToolExecutor::handles($toolName);
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

    private function isCippAdminTool(string $toolName): bool
    {
        return StaffCippAdminToolExecutor::handles($toolName);
    }

    private function isTacticalActionTool(string $toolName): bool
    {
        return StaffTacticalActionToolExecutor::handles($toolName);
    }

    private function isHuntressActionTool(string $toolName): bool
    {
        return StaffHuntressActionToolExecutor::handles($toolName);
    }

    private function isMeshAdminTool(string $toolName): bool
    {
        return StaffMeshAdminToolExecutor::handles($toolName);
    }

    private function isControlDOnboardingTool(string $toolName): bool
    {
        return StaffControlDOnboardingToolExecutor::handles($toolName);
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
                ? array_values(array_unique(array_merge([self::WHOAMI_TOOL, self::TOOL_SURFACE_TOOL, self::SEARCH_TOOLS_TOOL], $token->allowedTools)))
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

        // The GRANT predicate, not toolPublished(): `grant_state` names the grant, so a
        // publication verdict here would report a granted non-ai_actor token's
        // controld_onboard_client as `available_ungranted` (B4.2, #2056 diff:5). tools/list
        // still withholds the verb; the executor still refuses it, naming ai_actor.
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
            'absent_means' => self::CATALOG_ABSENT_MEANS,
            'counts' => $counts,
            'categories' => $categories,
            'tools' => $filtered,
        ];
    }

    /** @return array<string, mixed> */
    private function searchToolsToolDefinition(): array
    {
        return [
            'name' => self::SEARCH_TOOLS_TOOL,
            'description' => 'Search this server\'s full tool catalog by keyword before asking for a capability: each match carries its grant_state — granted (in this token\'s allowlist, callable now), available_ungranted (built and configured but not granted — an operator token grant enables it), or unavailable_config (built but its integration is switched off or not configured on this instance). Case-insensitive substring match on tool names, categories, and one-line descriptions. An empty result means only that this keyword matched nothing — not that the capability is absent (an equivalent tool may use different wording): try other keywords and/or list_tool_surface (the full catalog) first, and reserve a request_tool build request for capabilities confirmed absent from the full surface. Same disclosure as list_tool_surface: names and one-line descriptions only — no data, no secrets.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Keyword to match (case-insensitive substring), e.g. "unifi", "backup", "mailbox".',
                    ],
                ],
                'required' => ['query'],
            ],
        ];
    }

    /**
     * The search_tools handler (psa-cplo3.1): the READ verb over this
     * caller's own tool surface — "do I already have something for X?" —
     * answered from the SAME per-caller classification list_tool_surface
     * returns, narrowed to the query. Disclosure parity with that tool by
     * construction ({@see McpToolSurface::search()} matches only over the
     * fields it returns): same caller, same catalog, same states, same
     * one-line descriptions; never schemas, data, or configuration values.
     *
     * An empty or missing query and a query with no matches both return an
     * EMPTY result with explanatory copy — not an error (nothing is wrong),
     * and never a bare [] that could read as a clean all-clear.
     *
     * @return array<string, mixed>
     */
    private function searchTools(Request $request, array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        $matches = array_map(
            static fn (array $entry): array => [
                'name' => $entry['name'],
                'category' => $entry['category'],
                'grant_state' => $entry['state'],
                'description' => $entry['description'],
            ],
            // The same GRANT predicate list_tool_surface classifies with — state parity by
            // construction, and no publication verdict inside `grant_state` (diff:5).
            McpToolSurface::search($query, fn (string $tool): bool => $this->toolAllowed($request, $tool)),
        );

        $payload = [
            'query' => $query,
            'match_count' => count($matches),
            'states' => McpToolSurface::states(),
            'absent_means' => self::CATALOG_ABSENT_MEANS,
            'matches' => $matches,
        ];

        if ($query === '') {
            $payload['note'] = 'Empty query — nothing was searched. Provide a keyword; list_tool_surface lists the full catalog.';
        } elseif ($matches === []) {
            $payload['note'] = 'No tool name, category, or description contains "'.$query.'" — a keyword miss, not proof the capability is absent (an equivalent tool may use different wording). Try other keywords and/or list_tool_surface (the full catalog) before request_tool; reserve a build request for capabilities confirmed absent from the full surface.';
        }

        return $payload;
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
