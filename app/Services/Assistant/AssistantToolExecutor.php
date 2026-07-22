<?php

namespace App\Services\Assistant;

use App\Enums\CallDirection;
use App\Enums\ContractStatus;
use App\Enums\EmailDirection;
use App\Enums\InvoiceStatus;
use App\Enums\NoteType;
use App\Enums\ToolEffect;
use App\Enums\TranscriptionStatus;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Email;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Agent\ProposeCloseTool;
use App\Services\Cipp\CippMcpToolRelay;
use App\Services\Cipp\HandlesCippTools;
use App\Services\Level\LevelClient;
use App\Services\Mesh\MeshClient;
use App\Services\Ninja\NinjaClient;
use App\Services\TicketService;
use App\Services\Wiki\HandlesWikiTools;
use App\Services\Wiki\WikiAddFactTool;
use App\Services\Wiki\WikiPageAuthoringTool;
use App\Support\CippConfig;
use Illuminate\Support\Facades\Log;

/**
 * Executes tool calls from the AI assistant.
 * All queries scoped to client_id.
 */
class AssistantToolExecutor
{
    use HandlesCippTools;
    use HandlesWikiTools;

    private ?Ticket $ticket;

    // Read by HandlesWikiTools; nullable here means global-only wiki scope (spec §6).
    protected ?int $clientId;

    private ?Client $client;

    private ?int $userId;

    public function __construct(?Ticket $ticket = null, ?int $clientId = null, ?int $userId = null)
    {
        $this->ticket = $ticket;
        $this->clientId = $ticket?->client_id ?? $clientId;
        $this->userId = $userId;

        if ($this->clientId) {
            $this->client = $ticket?->client ?? Client::find($this->clientId);
        } else {
            $this->client = null;
        }
    }

    public function execute(string $toolName, array $input): mixed
    {
        $logInput = $input;
        if ($toolName === 'wiki_add_fact' && array_key_exists('statement', $logInput)) {
            $logInput['statement'] = '[wiki fact statement withheld]';
        }
        if (in_array($toolName, ['wiki_create_page', 'wiki_update_page'], true) && array_key_exists('body_md', $logInput)) {
            $logInput['body_md'] = '[wiki page body withheld]';
        }

        Log::debug('[Assistant] Tool call', [
            'tool' => $toolName,
            'client_id' => $this->clientId,
            'input' => $logInput,
        ]);

        $table = self::dispatchTable();

        if (! array_key_exists($toolName, $table)) {
            return ['error' => "Unknown tool: {$toolName}"];
        }

        [, $handler] = self::entry($toolName, $table[$toolName]);

        return $handler($this, $input);
    }

    /**
     * EVERY tool this executor can dispatch, each registered as a
     * [ToolEffect, handler] PAIR. This table is both the dispatch table and the
     * mutability classification — deliberately one thing, not two.
     *
     * *** DO NOT SPLIT THE EFFECT OUT INTO A SEPARATE LIST. *** That split is
     * the defect this shape exists to prevent, and it recurred four times on
     * psa-uw2o. The last shape kept a WRITE_TOOLS constant beside a match
     * statement and asserted the two agreed; two reviewers independently added a
     * mutating arm next to wiki_create_page without touching the constant, and
     * the ReadOnly Teams bot persisted a real WikiPage row while the guard suite
     * stayed green. A list beside a dispatch table can disagree with it. A list
     * that IS the dispatch table cannot: a tool that is not registered here is
     * not dispatchable at all, and it cannot be registered here without stating
     * whether it reads or writes.
     *
     * What this does NOT enforce: that the stated effect is the TRUE one. A
     * handler that writes can still be registered as Read. That is a deliberate
     * mistyping rather than an omission, and it is only partly covered:
     * TeamsReadOnlyWriteGuardTest pins the six known writers by name (so
     * reclassifying one of THEM fails) and executes every Read-classified tool
     * to catch one that writes on trivial input. A new writer mistyped as Read
     * that needs valid arguments before it mutates would pass both — verified,
     * not assumed. Classify honestly; nothing below will do it for you.
     *
     * Handlers are static closures taking the executor explicitly, so the table
     * can be read for classification (readTools()/writeTools()) without an
     * instance, and so building it resolves no container services.
     *
     * @return array<string, array{0: ToolEffect, 1: callable(self, array<string, mixed>): mixed}>
     */
    private static function dispatchTable(): array
    {
        return [
            // General tools (no client scope required)
            'search_all_tickets' => [ToolEffect::Read, static fn (self $x, array $in) => $x->searchAllTickets($in)],
            'list_my_tickets' => [ToolEffect::Read, static fn (self $x, array $in) => $x->listMyTickets($in)],
            'list_open_tickets' => [ToolEffect::Read, static fn (self $x, array $in) => $x->listOpenTickets($in)],
            'get_ticket_detail' => [ToolEffect::Read, static fn (self $x, array $in) => $x->getTicketDetail($in)],
            // Writes: files a held close proposal against the ticket.
            'propose_close' => [ToolEffect::Write, static fn (self $x, array $in) => $x->proposeClose($in)],
            'get_ticket_calls' => [ToolEffect::Read, static fn (self $x, array $in) => $x->getTicketCalls($in)],
            'get_queue_stats' => [ToolEffect::Read, static fn (self $x, array $in) => $x->getQueueStats()],

            // PSA tools (client-scoped)
            'search_tickets' => [ToolEffect::Read, static fn (self $x, array $in) => $x->searchTickets($in)],
            'get_ticket_notes' => [ToolEffect::Read, static fn (self $x, array $in) => $x->getTicketNotes($in)],
            'create_ticket' => [ToolEffect::Write, static fn (self $x, array $in) => $x->createTicket($in)],
            'add_ticket_note' => [ToolEffect::Write, static fn (self $x, array $in) => $x->addTicketNote($in)],
            'get_client' => [ToolEffect::Read, static fn (self $x, array $in) => $x->getClient()],
            'get_person' => [ToolEffect::Read, static fn (self $x, array $in) => $x->getPerson($in)],
            'get_asset' => [ToolEffect::Read, static fn (self $x, array $in) => $x->getAsset($in)],
            'find_clients' => [ToolEffect::Read, static fn (self $x, array $in) => $x->findClients($in)],
            'find_persons' => [ToolEffect::Read, static fn (self $x, array $in) => $x->findPersons($in)],
            'find_assets' => [ToolEffect::Read, static fn (self $x, array $in) => $x->findAssets($in)],
            'list_client_contracts' => [ToolEffect::Read, static fn (self $x, array $in) => $x->listClientContracts($in)],
            'get_contract' => [ToolEffect::Read, static fn (self $x, array $in) => $x->getContract($in)],

            // Cross-client staff-class reads (client_id optional filter, never required)
            'list_email_items' => [ToolEffect::Read, static fn (self $x, array $in) => $x->listEmailItems($in)],
            'get_email_item' => [ToolEffect::Read, static fn (self $x, array $in) => $x->getEmailItem($in)],
            'list_phone_calls' => [ToolEffect::Read, static fn (self $x, array $in) => $x->listPhoneCalls($in)],
            'get_phone_call' => [ToolEffect::Read, static fn (self $x, array $in) => $x->getPhoneCall($in)],
            'list_invoices' => [ToolEffect::Read, static fn (self $x, array $in) => $x->listInvoices($in)],
            'get_invoice' => [ToolEffect::Read, static fn (self $x, array $in) => $x->getInvoice($in)],

            // NinjaRMM tools
            'ninja_search_devices' => [ToolEffect::Read, static fn (self $x, array $in) => $x->ninjaSearchDevices($in)],
            'ninja_get_device' => [ToolEffect::Read, static fn (self $x, array $in) => $x->ninjaGetDevice($in)],
            'ninja_get_device_volumes' => [ToolEffect::Read, static fn (self $x, array $in) => $x->ninjaGetDeviceSub($in, 'volumes')],
            'ninja_get_device_alerts' => [ToolEffect::Read, static fn (self $x, array $in) => $x->ninjaGetDeviceSub($in, 'alerts')],
            'ninja_get_device_os_patches' => [ToolEffect::Read, static fn (self $x, array $in) => $x->ninjaGetDeviceSub($in, 'os-patches')],
            'ninja_get_device_software' => [ToolEffect::Read, static fn (self $x, array $in) => $x->ninjaGetDeviceSub($in, 'software')],
            'ninja_get_device_processors' => [ToolEffect::Read, static fn (self $x, array $in) => $x->ninjaGetDeviceSub($in, 'processors')],
            'ninja_get_device_disk_drives' => [ToolEffect::Read, static fn (self $x, array $in) => $x->ninjaGetDeviceSub($in, 'disks')],
            'ninja_get_device_network_interfaces' => [ToolEffect::Read, static fn (self $x, array $in) => $x->ninjaGetDeviceSub($in, 'network-interfaces')],
            'ninja_get_device_windows_services' => [ToolEffect::Read, static fn (self $x, array $in) => $x->ninjaGetDeviceSub($in, 'windows-services')],
            'ninja_get_device_last_user' => [ToolEffect::Read, static fn (self $x, array $in) => $x->ninjaGetDeviceSub($in, 'last-logged-on-user')],

            // Level RMM tools
            'level_get_device' => [ToolEffect::Read, static fn (self $x, array $in) => $x->levelGetDevice($in)],

            // Mesh tools
            'mesh_search_email_logs' => [ToolEffect::Read, static fn (self $x, array $in) => $x->meshSearchLogs($in)],
            'mesh_get_email_events' => [ToolEffect::Read, static fn (self $x, array $in) => $x->meshGetEvents($in)],

            // CIPP tools (dispatch + bodies shared via HandlesCippTools)
            'cipp_list_users' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippQuery('cipp_list_users', $in, 'api/ListUsers')],
            'cipp_list_mailboxes' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippQuery('cipp_list_mailboxes', $in, 'api/ListMailboxes')],
            'cipp_list_licenses' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippQuery('cipp_list_licenses', $in, 'api/ListLicenses')],
            'cipp_list_devices' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippQuery('cipp_list_devices', $in, 'api/ListDevices')],
            'cipp_list_sign_ins' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippListSignIns($in)],
            'cipp_list_groups' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippQuery('cipp_list_groups', $in, 'api/ListGroups')],
            'cipp_list_user_groups' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippQueryWithUser('cipp_list_user_groups', $in, 'api/ListUserGroups')],
            'cipp_list_mailbox_permissions' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippQueryWithUser('cipp_list_mailbox_permissions', $in, 'api/ListmailboxPermissions')],
            // ListUserMailboxRules, NOT ListMailboxRules: the latter takes no user
            // parameter and returns EVERY mailbox's rules in the tenant (psa-7lgo.1).
            'cipp_list_mailbox_rules' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippQueryWithUser('cipp_list_mailbox_rules', $in, 'api/ListUserMailboxRules', 'UserID')],
            // Tenant-wide sweep (psa-4k6m). See the twin arm in TriageToolExecutor — this
            // pair is what CippToolDispatchTest exists to keep from drifting apart.
            'cipp_list_tenant_mailbox_rules' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippQuery('cipp_list_tenant_mailbox_rules', $in, 'api/ListMailboxRules')],
            'cipp_list_defender_state' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippQuery('cipp_list_defender_state', $in, 'api/ListDefenderState')],
            'cipp_list_conditional_access_policies' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippQuery('cipp_list_conditional_access_policies', $in, 'api/ListConditionalAccessPolicies')],
            'cipp_list_user_conditional_access' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippQueryWithUser('cipp_list_user_conditional_access', $in, 'api/ListUserConditionalAccessPolicies')],
            'cipp_list_audit_logs' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippListAuditLogs($in)],
            'cipp_list_message_trace' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippListMessageTrace($in)],
            'cipp_list_mail_quarantine' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippListMailQuarantine($in)],
            'cipp_list_user_mfa_methods' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippListUserMfaMethods($in)],
            'cipp_list_oauth_apps' => [ToolEffect::Read, static fn (self $x, array $in) => $x->cippListOauthApps($in)],

            // DNS tools (always available, no client scope required)
            'dns_lookup' => [ToolEffect::Read, static fn (self $x, array $in) => \App\Services\Dns\DnsToolkit::lookup($in['hostname'] ?? '', $in['type'] ?? '')],
            'dns_email_health' => [ToolEffect::Read, static fn (self $x, array $in) => \App\Services\Dns\DnsToolkit::emailHealth($in['domain'] ?? '')],

            // Wiki retrieval tools (§6; null clientId → global-only, not an error)
            'wiki_list_pages' => [ToolEffect::Read, static fn (self $x, array $in) => $x->wikiListPages()],
            'wiki_search' => [ToolEffect::Read, static fn (self $x, array $in) => $x->wikiSearch($in)],
            'wiki_get_page' => [ToolEffect::Read, static fn (self $x, array $in) => $x->wikiGetPage($in)],

            // Wiki authoring. NO definition set offers these, so they never appear
            // in a published schema — but dispatch is BY NAME, which is exactly how
            // they stayed reachable from a surface that had filtered the schema.
            'wiki_add_fact' => [ToolEffect::Write, static fn (self $x, array $in) => app(WikiAddFactTool::class)->execute($in, $x->clientId, $x->userId)],
            'wiki_create_page' => [ToolEffect::Write, static fn (self $x, array $in) => app(WikiPageAuthoringTool::class)->create($in, $x->clientId, $x->userId)],
            'wiki_update_page' => [ToolEffect::Write, static fn (self $x, array $in) => app(WikiPageAuthoringTool::class)->update($in, $x->clientId, $x->userId)],
        ];
    }

    /**
     * Tool names this executor can dispatch that MUTATE state.
     *
     * Derived from the dispatch table, never declared alongside it. A guard that
     * filters this executor must source from here — it is the layer that
     * actually executes (psa-uw2o.10).
     *
     * @return list<string>
     */
    public static function writeTools(): array
    {
        return self::toolsWithEffect(ToolEffect::Write);
    }

    /**
     * Tool names this executor can dispatch that only READ.
     *
     * This is the ALLOWLIST a read-only surface should filter on. Allowlisting
     * reads rather than denylisting writes is what makes the boundary
     * fail-closed: an unrecognised or unclassified name is refused by default,
     * so no future tool can be reachable merely by going unnamed.
     *
     * @return list<string>
     */
    public static function readTools(): array
    {
        return self::toolsWithEffect(ToolEffect::Read);
    }

    /** @return list<string> */
    private static function toolsWithEffect(ToolEffect $effect): array
    {
        $names = [];

        foreach (self::dispatchTable() as $name => $entry) {
            if (self::entry($name, $entry)[0] === $effect) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Validate one dispatch-table registration. Both doors — execution and
     * classification — read entries through here, so a malformed registration
     * fails loudly at whichever it reaches first rather than being silently
     * treated as unclassified (which, on a read-only surface, would mean
     * "allowed").
     *
     * @return array{0: ToolEffect, 1: callable(self, array<string, mixed>): mixed}
     */
    private static function entry(string $name, mixed $entry): array
    {
        $effect = is_array($entry) ? ($entry[0] ?? null) : null;
        $handler = is_array($entry) ? ($entry[1] ?? null) : null;

        if (! $effect instanceof ToolEffect || ! is_callable($handler)) {
            throw new \LogicException(
                "Assistant tool '{$name}' is registered without a [ToolEffect, handler] pair. ".
                'Every dispatchable tool must declare whether it reads or writes.'
            );
        }

        return [$effect, $handler];
    }

    // ── General Tools (no client scope) ──

    private function activeClientTickets()
    {
        return Ticket::query()
            ->whereHas('client', fn ($client) => $client->active());
    }

    private function priorityOrderSql(): string
    {
        return "CASE priority WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 WHEN 'p3' THEN 3 WHEN 'p4' THEN 4 ELSE 5 END";
    }

    private function searchAllTickets(array $input): array
    {
        $query = $input['query'] ?? '';
        $limit = min($input['limit'] ?? 15, 30);

        $tickets = $this->activeClientTickets()
            ->search($query)
            ->when($input['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'subject', 'status', 'priority', 'client_id', 'assignee_id', 'created_at']);

        return $tickets->map(fn (Ticket $t) => [
            'id' => $t->id,
            'display_id' => $t->display_id,
            'subject' => $t->subject,
            'status' => $t->status->value,
            'priority' => $t->priority->value,
            'client' => $t->client?->name,
            'assignee' => $t->assignee?->name,
            'created_at' => $t->created_at?->toDateTimeString(),
            'age' => $t->created_at?->diffForHumans(),
        ])->toArray();
    }

    private function listMyTickets(array $input): array
    {
        if (! $this->userId) {
            return ['error' => 'No user context'];
        }

        $limit = min($input['limit'] ?? 20, 50);
        $openStatuses = ['new', 'in_progress', 'pending_client', 'pending_third_party'];

        $tickets = $this->activeClientTickets()
            ->where('assignee_id', $this->userId)
            ->when(
                $input['status'] ?? null,
                fn ($q, $s) => $q->where('status', $s),
                fn ($q) => $q->whereIn('status', $openStatuses)
            )
            ->orderByRaw($this->priorityOrderSql())
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'subject', 'status', 'priority', 'client_id', 'source', 'created_at']);

        return $tickets->map(fn (Ticket $t) => [
            'id' => $t->id,
            'display_id' => $t->display_id,
            'subject' => $t->subject,
            'status' => $t->status->value,
            'priority' => $t->priority->value,
            'client' => $t->client?->name,
            'source' => $t->source?->value,
            'created_at' => $t->created_at?->toDateTimeString(),
            'age' => $t->created_at?->diffForHumans(),
        ])->toArray();
    }

    private function listOpenTickets(array $input): array
    {
        $limit = min($input['limit'] ?? 20, 50);
        $openStatuses = ['new', 'in_progress', 'pending_client', 'pending_third_party'];

        $query = $this->activeClientTickets()->whereIn('status', $openStatuses);

        if ($input['assignee'] ?? null) {
            $query->whereHas('assignee', fn ($q) => $q->where('name', 'like', '%'.$input['assignee'].'%'));
        }

        if ($input['priority'] ?? null) {
            $query->where('priority', $input['priority']);
        }

        if ($input['source'] ?? null) {
            $query->where('source', $input['source']);
        }

        if ($input['exclude_alerts'] ?? false) {
            $query->where('source', '!=', 'alert');
        }

        // updated_since turns this into a recently-modified feed — the scalable
        // way to catch new client replies landing on EXISTING open tickets
        // (psa-1f35 / triage req 148). When set, order by most-recent touch first
        // rather than the priority-then-age queue order.
        $recentlyModified = false;
        if (($updatedSince = trim((string) ($input['updated_since'] ?? ''))) !== '') {
            try {
                $query->where('updated_at', '>=', \Carbon\Carbon::parse($updatedSince));
            } catch (\Throwable) {
                return ['error' => 'updated_since must be a valid ISO-8601 timestamp'];
            }
            $recentlyModified = true;
        }

        if ($recentlyModified) {
            $query->orderByDesc('updated_at');
        } else {
            $query->orderByRaw($this->priorityOrderSql())->orderBy('created_at');
        }

        $tickets = $query
            ->limit($limit)
            ->get(['id', 'subject', 'status', 'priority', 'client_id', 'assignee_id', 'source', 'created_at', 'updated_at']);

        return $tickets->map(fn (Ticket $t) => [
            'id' => $t->id,
            'display_id' => $t->display_id,
            'subject' => $t->subject,
            'status' => $t->status->value,
            'priority' => $t->priority->value,
            'client' => $t->client?->name,
            'assignee' => $t->assignee?->name,
            'source' => $t->source?->value,
            'created_at' => $t->created_at?->toDateTimeString(),
            'updated_at' => $t->updated_at?->toDateTimeString(),
            'age' => $t->created_at?->diffForHumans(),
        ])->toArray();
    }

    private function getTicketDetail(array $input): array
    {
        $ticketId = $input['ticket_id'] ?? null;
        if (! $ticketId) {
            return ['error' => 'ticket_id is required'];
        }

        $ticket = Ticket::with(['client:id,name,stage', 'assignee:id,name', 'contact'])->find($ticketId);
        if (! $ticket) {
            return ['error' => 'Ticket not found'];
        }

        $notes = TicketNote::where('ticket_id', $ticketId)
            ->orderByDesc('noted_at')
            ->limit(10)
            ->get();

        $calls = PhoneCall::where('ticket_id', $ticketId)
            ->orderBy('started_at')
            ->limit(20)
            ->get();

        // Last-activity: the most recent MEANINGFUL touch — last reply to the
        // client, last note, or last call. Deliberately NOT updated_at, which
        // an AI/system write bumps to "now" and would mask staleness (psa-m7re).
        // The close decision hinges on "when did anyone last actually touch this".
        // Calls use a direct max — the display collection is ASC-limited to 20,
        // so on a >20-call ticket its max would miss the latest call.
        $lastActivityAt = collect([
            $ticket->responded_at,
            $notes->max('noted_at'),
            PhoneCall::where('ticket_id', $ticketId)->max('started_at'),
        ])->filter()->map(fn ($t) => $t instanceof \Carbon\CarbonInterface ? $t : \Illuminate\Support\Carbon::parse($t))->max() ?? $ticket->created_at;

        return [
            'id' => $ticket->id,
            'display_id' => $ticket->display_id,
            'subject' => $ticket->subject,
            'description' => $ticket->description ? mb_substr(strip_tags($ticket->description), 0, 2000) : null,
            'status' => $ticket->status->value,
            'priority' => $ticket->priority->value,
            'client' => $ticket->client?->name,
            'contact' => $ticket->contact?->fullName,
            'assignee' => $ticket->assignee?->name,
            'source' => $ticket->source?->value,
            'type' => $ticket->type?->value,
            'created_at' => $ticket->created_at?->toDateTimeString(),
            'responded_at' => $ticket->responded_at?->toDateTimeString(),
            'last_activity_at' => $lastActivityAt?->toDateTimeString(),
            'resolved_at' => $ticket->resolved_at?->toDateTimeString(),
            'resolution' => $ticket->resolution,
            'recent_notes' => $notes->map(fn (TicketNote $n) => [
                'type' => $n->note_type?->value,
                'author' => $n->author?->name ?? $n->author_name ?? 'System',
                'body' => mb_substr(strip_tags($n->body ?? ''), 0, 2000),
                'date' => $n->noted_at?->toDateTimeString(),
            ])->toArray(),
            // Compact call summary; full transcripts via get_ticket_calls.
            'calls' => $calls->map(fn (PhoneCall $c) => [
                'id' => $c->id,
                'direction' => $c->direction?->value,
                'status' => $c->status?->value,
                'date' => $c->started_at?->toDateTimeString(),
                'duration_seconds' => $c->duration,
                'is_billable' => $c->is_billable,
                'charge_classification' => $c->charge_classification?->value,
                'sentiment_score' => $c->sentiment_score,
                'summary' => $c->call_summary
                    ? mb_substr($c->call_summary, 0, 500)
                    : ($c->transcription_summary ? mb_substr($c->transcription_summary, 0, 500) : null),
                'has_transcript' => $c->isTranscribed() && ($c->cleaned_transcript || $c->transcription),
            ])->toArray(),
        ];
    }

    private function proposeClose(array $input): array
    {
        $ticketId = $input['ticket_id'] ?? null;
        if (! is_numeric($ticketId) || (int) $ticketId <= 0) {
            return ['error' => 'ticket_id is required'];
        }

        $reason = trim((string) ($input['reason'] ?? $input['evidence'] ?? ''));
        if ($reason === '') {
            return ['error' => 'reason is required'];
        }

        $confidence = $input['confidence'] ?? null;
        if (! is_numeric($confidence)) {
            return ['error' => 'confidence must be a number between 0 and 1'];
        }
        $confidence = (float) $confidence;
        if ($confidence < 0.0 || $confidence > 1.0) {
            return ['error' => 'confidence must be a number between 0 and 1'];
        }

        $ticket = Ticket::with('client')->find((int) $ticketId);
        if (! $ticket) {
            return ['error' => 'Ticket not found'];
        }

        if (! $ticket->client_id || ! $ticket->client) {
            return ['error' => 'Ticket has no valid client'];
        }

        $message = app(ProposeCloseTool::class)->executeHeld($ticket, [
            'reason' => $reason,
            'confidence' => $confidence,
        ]);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'message' => $message,
        ];
    }

    private function getTicketCalls(array $input): array
    {
        $ticketId = $input['ticket_id'] ?? null;
        if (! $ticketId) {
            return ['error' => 'ticket_id is required'];
        }

        $ticket = Ticket::with('client:id,stage')->find($ticketId);
        if (! $ticket) {
            return ['error' => 'Ticket not found'];
        }

        $calls = PhoneCall::where('ticket_id', $ticketId)
            ->with('person')
            ->orderBy('started_at')
            ->limit(20)
            ->get();

        return [
            'ticket_id' => (int) $ticketId,
            'display_id' => $ticket->display_id,
            'call_count' => $calls->count(),
            'calls' => $calls->map(fn (PhoneCall $c) => [
                'id' => $c->id,
                'direction' => $c->direction?->value,
                'status' => $c->status?->value,
                'from' => $c->from_number,
                'to' => $c->to_number,
                'contact' => $c->person?->fullName,
                'date' => $c->started_at?->toDateTimeString(),
                'duration_seconds' => $c->duration,
                'is_billable' => $c->is_billable,
                'charge_classification' => $c->charge_classification?->value,
                'sentiment_score' => $c->sentiment_score,
                'call_summary' => $c->call_summary,
                'next_steps' => $c->next_steps,
                'coaching_notes' => $c->coaching_notes,
                'transcript' => ($t = $c->cleaned_transcript ?: $c->transcription)
                    ? mb_substr($t, 0, 10000)
                    : null,
            ])->toArray(),
        ];
    }

    private function getQueueStats(): array
    {
        $openStatuses = ['new', 'in_progress', 'pending_client', 'pending_third_party'];

        $openTickets = $this->activeClientTickets()->whereIn('status', $openStatuses);

        $byStatus = (clone $openTickets)->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $byPriority = (clone $openTickets)->selectRaw('priority, count(*) as count')
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();

        $unassigned = (clone $openTickets)->whereNull('assignee_id')->count();

        $oldest = (clone $openTickets)->orderBy('created_at')->first(['id', 'subject', 'created_at', 'priority']);

        $alertCount = (clone $openTickets)->where('source', 'alert')->count();
        $nonAlertCount = (clone $openTickets)->where('source', '!=', 'alert')->count();

        return [
            'total_open' => array_sum($byStatus),
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'unassigned' => $unassigned,
            'alert_tickets' => $alertCount,
            'non_alert_tickets' => $nonAlertCount,
            'oldest_ticket' => $oldest ? [
                'id' => $oldest->id,
                'subject' => $oldest->subject,
                'priority' => $oldest->priority->value,
                'age' => $oldest->created_at?->diffForHumans(),
            ] : null,
        ];
    }

    // ── PSA Tools (client-scoped) ──

    private function searchTickets(array $input): array
    {
        if (! $this->clientId) {
            return ['error' => 'No client context — cannot search tickets'];
        }

        $query = $input['query'] ?? '';
        $limit = min($input['limit'] ?? 10, 20);

        $tickets = Ticket::where('client_id', $this->clientId)
            ->search($query)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'subject', 'status', 'priority', 'resolution', 'created_at', 'resolved_at']);

        return $tickets->map(fn (Ticket $t) => [
            'id' => $t->id,
            'display_id' => $t->display_id,
            'subject' => $t->subject,
            'status' => $t->status->value,
            'priority' => $t->priority->value,
            'resolution' => $t->resolution ? mb_substr($t->resolution, 0, 500) : null,
            'created_at' => $t->created_at?->toDateTimeString(),
            'resolved_at' => $t->resolved_at?->toDateTimeString(),
        ])->toArray();
    }

    private function getTicketNotes(array $input): array
    {
        if (! $this->clientId) {
            return ['error' => 'No client context — cannot access ticket notes'];
        }

        $ticketId = $input['ticket_id'] ?? null;
        if (! $ticketId || (! is_int($ticketId) && ! is_string($ticketId))) {
            return ['error' => 'ticket_id is required'];
        }

        // CLIENT-SCOPED: verify the ticket belongs to this client. Resolve
        // display ids too ("#8351" / bare synced number) — MCP callers echo
        // back the number they see, which diverges from the internal id on
        // externally-synced tickets (psa-gq0f).
        $ticket = Ticket::resolveReference($ticketId, $this->clientId);

        if (! $ticket) {
            return ['error' => 'Ticket not found or belongs to a different client'];
        }

        // Latest notes, not oldest: fetch the newest 20 then present them
        // chronologically. The old ASC+limit dropped the tail on busy tickets,
        // so "what did the client say last" could be absent entirely (psa-m7re).
        $notes = TicketNote::where('ticket_id', $ticket->id)
            ->orderByDesc('noted_at')
            ->limit(20)
            ->get()
            ->sortBy('noted_at')
            ->values();

        return $notes->map(fn (TicketNote $n) => [
            'type' => $n->note_type?->value,
            'author' => $n->author?->name ?? $n->author_name ?? 'System',
            // Generous cap: a full final client message must not be clipped
            // mid-sentence when a close decision hinges on it.
            'body' => mb_substr(strip_tags($n->body ?? ''), 0, 4000),
            'date' => $n->noted_at?->toDateTimeString(),
            'is_private' => $n->is_private,
        ])->toArray();
    }

    private function createTicket(array $input): array
    {
        try {
            $ticket = app(AssistantTicketCreator::class)->create($this->clientId, $input, $this->userId);

            Log::info('[Assistant] AI created ticket', [
                'ticket_id' => $ticket->id,
                'client_id' => $this->clientId,
                'subject' => $ticket->subject,
            ]);

            return [
                'success' => true,
                'ticket_id' => $ticket->id,
                'display_id' => $ticket->display_id,
                'url' => route('tickets.show', $ticket),
            ];
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::warning('[Assistant] Ticket creation failed', ['error' => $e->getMessage()]);

            return ['error' => 'Failed to create ticket: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function addTicketNote(array $input): array
    {
        if (! $this->clientId) {
            return ['error' => 'No client context — cannot add note'];
        }

        $ticketId = $input['ticket_id'] ?? null;
        $body = $input['body'] ?? null;
        if (! $ticketId || ! $body) {
            return ['error' => 'ticket_id and body are required'];
        }

        // CLIENT-SCOPED: verify the ticket belongs to this client
        $ticket = Ticket::where('id', $ticketId)
            ->where('client_id', $this->clientId)
            ->first();

        if (! $ticket) {
            return ['error' => 'Ticket not found or belongs to a different client'];
        }

        if (! $this->userId) {
            return ['error' => 'No user context — cannot attribute note'];
        }

        try {
            // aiAuthored keeps Technician human-touch signals from treating
            // assistant-originated notes as human engagement.
            $note = app(TicketService::class)->addNote(
                $ticket, $body, NoteType::Note, true, $this->userId, aiAuthored: true
            );

            Log::info('[Assistant] AI added note to ticket', [
                'ticket_id' => $ticket->id,
                'note_id' => $note->id,
            ]);

            return [
                'success' => true,
                'note_id' => $note->id,
                'ticket_display_id' => $ticket->display_id,
            ];
        } catch (\Throwable $e) {
            Log::warning('[Assistant] Note creation failed', ['exception' => $e::class]);

            return ['error' => 'Failed to add note.'];
        }
    }

    private function getClient(): array
    {
        if (! $this->client) {
            return ['error' => 'No client context'];
        }

        return [
            'id' => $this->client->id,
            'name' => $this->client->name,
            'email' => $this->client->email,
            'phone' => $this->client->phone,
            'website' => $this->client->website,
            'address' => trim(implode(', ', array_filter([
                $this->client->address_line1,
                $this->client->address_line2,
                $this->client->city,
                $this->client->state,
                $this->client->postcode,
            ]))) ?: null,
            'notes' => $this->client->notes,
            'is_active' => $this->client->is_active,
        ];
    }

    private function getPerson(array $input): array
    {
        if (! $this->clientId) {
            return ['error' => 'No client context — cannot look up person'];
        }

        $query = Person::where('client_id', $this->clientId);

        if (! empty($input['person_id'])) {
            $query->where('id', (int) $input['person_id']);
        } elseif (! empty($input['email'])) {
            $query->where('email', $input['email']);
        } elseif (! empty($input['name'])) {
            $name = $input['name'];
            $query->where(fn ($q) => $q
                ->where('first_name', 'like', "%{$name}%")
                ->orWhere('last_name', 'like', "%{$name}%"));
        } else {
            return ['error' => 'Provide one of: person_id, email, or name'];
        }

        $person = $query->first();
        if (! $person) {
            return ['error' => 'Person not found at this client'];
        }

        return [
            'id' => $person->id,
            'name' => $person->full_name,
            'email' => $person->email,
            'phone' => $person->phone,
            'mobile' => $person->mobile,
            'job_title' => $person->job_title,
            'department' => $person->department,
            'office_location' => $person->office_location,
            'person_type' => $person->person_type?->value,
            'is_primary' => $person->is_primary,
            'is_active' => $person->is_active,
            'mfa_enabled' => $person->mfa_enabled,
            'm365_user_type' => $person->m365_user_type,
            'notes' => $person->notes,
        ];
    }

    private function getAsset(array $input): array
    {
        if (! $this->clientId) {
            return ['error' => 'No client context — cannot look up asset'];
        }

        $query = Asset::where('client_id', $this->clientId);

        if (! empty($input['asset_id'])) {
            $query->where('id', (int) $input['asset_id']);
        } elseif (! empty($input['hostname'])) {
            $query->whereRaw('LOWER(hostname) = ?', [strtolower($input['hostname'])]);
        } else {
            return ['error' => 'Provide one of: asset_id or hostname'];
        }

        $asset = $query->first();
        if (! $asset) {
            return ['error' => 'Asset not found at this client'];
        }

        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'hostname' => $asset->hostname,
            'asset_type' => $asset->asset_type,
            'serial_number' => $asset->serial_number,
            'os' => $asset->os,
            'cpu' => $asset->cpu,
            'ram_gb' => $asset->ram_gb,
            'ip_address' => $asset->ip_address,
            'last_user' => $asset->last_user,
            'warranty_start' => $asset->warranty_start?->toDateString(),
            'warranty_end' => $asset->warranty_end?->toDateString(),
            'last_boot_at' => $asset->last_boot_at?->toDateTimeString(),
            'needs_reboot' => $asset->needs_reboot,
            'is_active' => $asset->is_active,
            'ninja_id' => $asset->ninja_id,
            'level_id' => $asset->level_id,
            'tactical_asset_id' => $asset->tactical_asset_id,
            'notes' => $asset->notes,
        ];
    }

    private function findClients(array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'query is required'];
        }

        $limit = min((int) ($input['limit'] ?? 10), 25);

        $clients = Client::active()
            ->where('name', 'like', "%{$query}%")
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'is_active', 'phone', 'website']);

        return [
            'count' => $clients->count(),
            'clients' => $clients->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'is_active' => $c->is_active,
                'phone' => $c->phone,
                'website' => $c->website,
            ])->toArray(),
        ];
    }

    private function findPersons(array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'query is required'];
        }

        $limit = min((int) ($input['limit'] ?? 10), 25);

        $q = Person::query()
            ->with('client:id,name')
            ->whereHas('client', fn ($client) => $client->active())
            ->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$query}%")
                ->orWhere('last_name', 'like', "%{$query}%")
                ->orWhere('email', 'like', "%{$query}%"));

        if ($this->clientId) {
            $q->where('client_id', $this->clientId);
        }

        $persons = $q->orderByDesc('is_active')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit($limit)
            ->get(['id', 'client_id', 'first_name', 'last_name', 'email', 'job_title', 'is_active']);

        return [
            'count' => $persons->count(),
            'scope' => $this->clientId ? "client_id={$this->clientId}" : 'cross-client (no client_id provided)',
            'persons' => $persons->map(fn ($p) => [
                'id' => $p->id,
                'client_id' => $p->client_id,
                'client_name' => $p->client?->name,
                'name' => trim("{$p->first_name} {$p->last_name}"),
                'email' => $p->email,
                'job_title' => $p->job_title,
                'is_active' => $p->is_active,
            ])->toArray(),
        ];
    }

    private function findAssets(array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'query is required'];
        }

        $limit = min((int) ($input['limit'] ?? 10), 25);

        $q = Asset::query()
            ->with('client:id,name')
            ->whereHas('client', fn ($client) => $client->active())
            ->where(fn ($w) => $w
                ->where('name', 'like', "%{$query}%")
                ->orWhere('hostname', 'like', "%{$query}%")
                ->orWhere('serial_number', 'like', "%{$query}%"));

        if ($this->clientId) {
            $q->where('client_id', $this->clientId);
        }

        $assets = $q->orderByDesc('is_active')
            ->orderBy('hostname')
            ->limit($limit)
            ->get(['id', 'client_id', 'name', 'hostname', 'asset_type', 'serial_number', 'os', 'last_user', 'is_active']);

        return [
            'count' => $assets->count(),
            'scope' => $this->clientId ? "client_id={$this->clientId}" : 'cross-client (no client_id provided)',
            'assets' => $assets->map(fn ($a) => [
                'id' => $a->id,
                'client_id' => $a->client_id,
                'client_name' => $a->client?->name,
                'name' => $a->name,
                'hostname' => $a->hostname,
                'asset_type' => $a->asset_type,
                'serial_number' => $a->serial_number,
                'os' => $a->os,
                'last_user' => $a->last_user,
                'is_active' => $a->is_active,
            ])->toArray(),
        ];
    }

    /**
     * list_client_contracts — hard client-scoped read (mirrors get_person/search_tickets).
     * Coverage/SLA summary rows only; pricing/financial fields are never exposed.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listClientContracts(array $input): array
    {
        if (! $this->clientId) {
            return ['error' => 'No client context — cannot list contracts'];
        }

        // max(1, …) guards the lower bound: Laravel's limit() treats a negative
        // value as "no limit", which would return the whole (client-scoped) set.
        $limit = max(1, min((int) ($input['limit'] ?? 25), 50));

        $query = Contract::where('client_id', $this->clientId)
            ->withCount(['assets', 'people', 'licenses']);

        $status = trim((string) ($input['status'] ?? ''));
        if ($status !== '' && ($case = ContractStatus::tryFrom($status)) !== null) {
            $query->where('status', $case);
        }

        $contracts = $query->orderByDesc('start_date')
            ->limit($limit)
            ->get();

        return [
            'count' => $contracts->count(),
            'client_id' => $this->clientId,
            'contracts' => $contracts->map(fn (Contract $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type?->value,
                'type_label' => $c->type?->label(),
                'status' => $c->status?->value,
                'start_date' => $c->start_date?->toDateString(),
                'end_date' => $c->end_date?->toDateString(),
                'auto_renew' => (bool) $c->auto_renew,
                'has_sla' => $c->hasSla(),
                'assets_count' => $c->assets_count,
                'people_count' => $c->people_count,
                'licenses_count' => $c->licenses_count,
            ])->toArray(),
        ];
    }

    /**
     * get_contract — one contract's coverage detail, scoped to the client. Pricing/
     * financial fields are never exposed (see the PR design notes for the held list).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getContract(array $input): array
    {
        if (! $this->clientId) {
            return ['error' => 'No client context — cannot look up contract'];
        }

        $contractId = (int) ($input['contract_id'] ?? 0);
        if ($contractId <= 0) {
            return ['error' => 'contract_id is required'];
        }

        $contract = Contract::where('client_id', $this->clientId)
            ->withCount(['assets', 'people', 'licenses', 'profiles', 'documents'])
            ->find($contractId);

        if (! $contract) {
            return ['error' => 'Contract not found at this client'];
        }

        return [
            'id' => $contract->id,
            'client_id' => $contract->client_id,
            'name' => $contract->name,
            'type' => $contract->type?->value,
            'type_label' => $contract->type?->label(),
            'status' => $contract->status?->value,
            'status_label' => $contract->status?->label(),
            'start_date' => $contract->start_date?->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'term_length_months' => $contract->term_length_months,
            'auto_renew' => (bool) $contract->auto_renew,
            'is_expired' => $contract->is_expired,
            'cancelled_at' => $contract->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $contract->cancellation_reason,
            'has_sla' => $contract->hasSla(),
            'sla_terms' => $contract->sla_terms,
            'notes' => $contract->notes !== null ? mb_substr((string) $contract->notes, 0, 2000) : null,
            'assets_count' => $contract->assets_count,
            'people_count' => $contract->people_count,
            'licenses_count' => $contract->licenses_count,
            'profiles_count' => $contract->profiles_count,
            'documents_count' => $contract->documents_count,
        ];
    }

    /**
     * list_email_items — cross-client staff-class read (mirrors find_persons/
     * find_assets: client_id is an OPTIONAL filter resolved from $this->clientId,
     * never required). Metadata + body_preview only; full body_text is only
     * available via get_email_item. A woken Chet must be able to list
     * unlinked/unresolved items that have no client yet, so this deliberately
     * does not gate on $this->clientId the way the client-scoped tools above do.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listEmailItems(array $input): array
    {
        $limit = max(1, min((int) ($input['limit'] ?? 25), 50));

        $query = Email::query()->orderByDesc('received_at');

        if (($d = trim((string) ($input['direction'] ?? ''))) !== '' && ($case = EmailDirection::tryFrom($d)) !== null) {
            $query->where('direction', $case);
        }

        if (! empty($input['unlinked'])) {
            $query->whereNull('ticket_id');
        }

        // client_id comes from the constructor-derived scope, not $input — the
        // MCP boundary strips client_id out of the raw arguments before dispatch
        // (see McpStaffController::callTool), so this must mirror findPersons()/
        // findAssets() rather than read $input['client_id'] directly.
        if ($this->clientId) {
            $query->where('client_id', $this->clientId);
        }

        if (($since = trim((string) ($input['since'] ?? ''))) !== '') {
            try {
                $query->where('received_at', '>=', \Carbon\Carbon::parse($since));
            } catch (\Throwable) {
                return ['error' => 'since must be a valid ISO-8601 timestamp'];
            }
        }

        $items = $query->limit($limit)->get();

        return [
            'count' => $items->count(),
            'email_items' => $items->map(fn (Email $e) => [
                'id' => $e->id,
                'direction' => $e->direction->value,
                'from_address' => $e->from_address,
                'subject' => $e->subject,
                'received_at' => $e->received_at?->toIso8601String(),
                'client_id' => $e->client_id,
                'ticket_id' => $e->ticket_id,
                'dismissed_at' => $e->dismissed_at?->toIso8601String(),
                'body_preview' => $e->body_preview,
            ])->toArray(),
        ];
    }

    /**
     * get_email_item — full email detail by id, cross-client (mirrors
     * getTicketDetail's by-id-with-no-client-gating precedent). Includes the
     * full body_text; only the by-id read exposes it, never the list.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getEmailItem(array $input): array
    {
        $id = (int) ($input['email_id'] ?? 0);
        $email = $id > 0 ? Email::find($id) : null;
        if (! $email) {
            return ['error' => 'Email item not found'];
        }

        return ['email_item' => [
            'id' => $email->id,
            'direction' => $email->direction->value,
            'from_address' => $email->from_address,
            'from_name' => $email->from_name,
            'subject' => $email->subject,
            'received_at' => $email->received_at?->toIso8601String(),
            'client_id' => $email->client_id,
            'person_id' => $email->person_id,
            'ticket_id' => $email->ticket_id,
            'dismissed_at' => $email->dismissed_at?->toIso8601String(),
            'body_text' => $email->body_text,
        ]];
    }

    /**
     * list_phone_calls — cross-client staff-class read, same shape as
     * listEmailItems(). Metadata only; the transcript is only available via
     * get_phone_call.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listPhoneCalls(array $input): array
    {
        $limit = max(1, min((int) ($input['limit'] ?? 25), 50));

        $query = PhoneCall::query()->orderByDesc('started_at');

        if (($d = trim((string) ($input['direction'] ?? ''))) !== '' && ($case = CallDirection::tryFrom($d)) !== null) {
            $query->where('direction', $case);
        }

        if (! empty($input['unlinked'])) {
            // "Unlinked" means "an unhandled intake item", not merely
            // "ticket_id is null". A call a technician followed up on — in
            // particular one marked spam, which stamps followed_up_at AND
            // blocks the caller — is resolved, so it must not surface here as
            // a ticket candidate. This borrows the followed_up_at half of
            // PhoneCall::scopeUnfollowedUp() but deliberately NOT its
            // status IN (Missed, Voicemail) predicate: a completed unknown
            // call with no ticket is still an intake candidate here.
            $query->whereNull('ticket_id')->whereNull('followed_up_at');
        }

        // See listEmailItems() — client_id is the constructor-derived scope,
        // not a raw $input argument.
        if ($this->clientId) {
            $query->where('client_id', $this->clientId);
        }

        if (($ts = trim((string) ($input['transcription_status'] ?? ''))) !== '' && ($case = TranscriptionStatus::tryFrom($ts)) !== null) {
            $query->where('transcription_status', $case);
        }

        if (($since = trim((string) ($input['since'] ?? ''))) !== '') {
            try {
                $query->where('started_at', '>=', \Carbon\Carbon::parse($since));
            } catch (\Throwable) {
                return ['error' => 'since must be a valid ISO-8601 timestamp'];
            }
        }

        $calls = $query->limit($limit)->get();

        return [
            'count' => $calls->count(),
            'phone_calls' => $calls->map(fn (PhoneCall $c) => [
                'id' => $c->id,
                'direction' => $c->direction?->value,
                'from_number' => $c->from_number,
                'to_number' => $c->to_number,
                'status' => $c->status?->value,
                'started_at' => $c->started_at?->toIso8601String(),
                'duration' => $c->duration,
                'client_id' => $c->client_id,
                'ticket_id' => $c->ticket_id,
                // The spam-dismissal / follow-up marker. Returned so the agent
                // can SEE that a technician already resolved this call instead
                // of being structurally blind to it (see create_ticket_from_call,
                // which refuses a followed-up call).
                'followed_up_at' => $c->followed_up_at?->toIso8601String(),
                'transcription_status' => $c->transcription_status?->value,
            ])->toArray(),
        ];
    }

    /**
     * get_phone_call — full call detail by id, cross-client. Includes the
     * transcription; only the by-id read exposes it, never the list.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getPhoneCall(array $input): array
    {
        $id = (int) ($input['phone_call_id'] ?? 0);
        $call = $id > 0 ? PhoneCall::find($id) : null;
        if (! $call) {
            return ['error' => 'Phone call not found'];
        }

        return ['phone_call' => [
            'id' => $call->id,
            'direction' => $call->direction?->value,
            'from_number' => $call->from_number,
            'to_number' => $call->to_number,
            'status' => $call->status?->value,
            'started_at' => $call->started_at?->toIso8601String(),
            'duration' => $call->duration,
            'client_id' => $call->client_id,
            'person_id' => $call->person_id,
            'ticket_id' => $call->ticket_id,
            'transcription_status' => $call->transcription_status?->value,
            'transcription' => $call->transcription,
        ]];
    }

    // ── Invoicing reads (psa-ij59) ──

    /**
     * list_invoices — cross-client staff-class read, mirroring listEmailItems().
     *
     * Scope note: client_id is NOT read from $input. The MCP boundary lifts it out of
     * the raw arguments and into this executor's constructor scope before dispatch
     * (McpStaffController::callTool), so reading $input['client_id'] here would be
     * both dead and a scope-bypass shape. Omitted client => cross-client, which is
     * Charlie's ruling for this tool ("staff-wide, per-token grant").
     *
     * Exposes internal cost/margin, unlike its psa_read contract-tool neighbours.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function listInvoices(array $input): array
    {
        // max(1, …) guards the lower bound: Laravel's limit() treats a negative value
        // as "no limit", which would dump the whole cross-client invoice table.
        $limit = max(1, min((int) ($input['limit'] ?? 25), 50));

        $query = Invoice::query()->with(['client:id,name', 'contract:id,name']);

        if ($this->clientId) {
            $query->where('client_id', $this->clientId);
        }

        // psa-ij59.5: same failure class as the status bug — a malformed contract_id
        // was silently DROPPED, so a caller intending to scope to one contract got
        // every invoice the token can see, cost and margin included. On a cross-client
        // financial read a bad filter must error, never fall through to a wider result.
        if (array_key_exists('contract_id', $input) && $input['contract_id'] !== null && $input['contract_id'] !== '') {
            $raw = $input['contract_id'];
            $contractId = is_int($raw) ? $raw : (is_string($raw) && preg_match('/^[1-9][0-9]*$/', $raw) === 1 ? (int) $raw : 0);
            if ($contractId <= 0) {
                return ['error' => 'contract_id must be a positive integer'];
            }
            $query->where('contract_id', $contractId);
        }

        // psa-ij59.2 / .4: an unrecognised status must ERROR, never be silently
        // dropped. Ignoring it would widen a cross-client financial read to every
        // client's invoices — cost and margin included — while the caller believes
        // it filtered. 'overdue'/'outstanding' are REAL domain filters the web list
        // uses (InvoiceStatus::filterOptions, Invoice::scopeStatusFilter), so they
        // are plausible caller values and are supported here for web parity rather
        // than rejected.
        if (($status = trim((string) ($input['status'] ?? ''))) !== '') {
            $derived = ['outstanding', 'overdue'];
            if (! in_array($status, $derived, true) && InvoiceStatus::tryFrom($status) === null) {
                $valid = implode(', ', array_merge($derived, array_column(InvoiceStatus::cases(), 'value')));

                return ['error' => "status must be one of: {$valid}"];
            }
            $query->statusFilter($status);
        }

        // A malformed date must ERROR, never fall through to an unfiltered read.
        // Answering "not-a-date" with rows would silently ignore the caller's filter;
        // answering it with [] would read as "no invoices in that range" — both are
        // the confident-wrong-answer failure CLAUDE.md rule 3 exists to prevent.
        foreach ([['from', '>='], ['to', '<=']] as [$key, $op]) {
            $raw = trim((string) ($input[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            try {
                // Plain where(), not whereDate(): invoice_date is a DATE column with
                // an index (psa-ij59.2), and this mirrors the web invoice list.
                $query->where('invoice_date', $op, \Carbon\Carbon::parse($raw)->toDateString());
            } catch (\Throwable) {
                return ['error' => "{$key} must be a valid date (YYYY-MM-DD)"];
            }
        }

        $invoices = $query->orderByDesc('invoice_date')->orderByDesc('id')->limit($limit)->get();

        return [
            'count' => $invoices->count(),
            'client_id' => $this->clientId,
            'invoices' => $invoices->map(fn (Invoice $inv) => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'client_id' => $inv->client_id,
                'client_name' => $inv->client?->name,
                'contract_id' => $inv->contract_id,
                'contract_name' => $inv->contract?->name,
                'invoice_date' => $inv->invoice_date?->toDateString(),
                'due_date' => $inv->due_date?->toDateString(),
                'status' => $inv->status?->value,
                // Voided invoices carry zeroed live money (InvoiceVoidService) with
                // the originals in pre_void_*. Flagging it here — not just on
                // get_invoice — so a $0 row in a LIST cannot be read as "this
                // invoice was for nothing" either. original_total gives the billed
                // figure via the model's own display accessor.
                'is_void_with_snapshot' => $inv->isVoidWithSnapshot(),
                'reportable_subtotal' => $inv->subtotal,
                'reportable_tax' => $inv->tax,
                'reportable_total' => $inv->total,
                'original_total' => $inv->display_total,
                // Internal — never client-visible. Named in the tool description.
                'reportable_total_cost' => $inv->total_cost,
                'reportable_margin' => $inv->margin,
            ])->toArray(),
        ];
    }

    /**
     * get_invoice — one invoice with every line, in billing order.
     *
     * *** quantity_source IS THE AUDIT RECORD and must never be dropped. *** A
     * graduated line is expanded by BillingService into ONE LINE PER CONSUMED BAND,
     * so several lines legitimately share a description at different unit_prices;
     * quantity_source is what names the rate card that priced each one
     * ("[graduated: N bands]" / "[volume tier rate $X/GB]"). Order matters too — the
     * QBO push pairs lines by sort_order POSITION — so lines() is left in its own
     * ordering rather than re-sorted here.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function getInvoice(array $input): array
    {
        $id = (int) ($input['invoice_id'] ?? 0);
        $invoice = $id > 0
            ? Invoice::with(['client:id,name', 'contract:id,name', 'lines'])->find($id)
            : null;

        if (! $invoice) {
            return ['error' => 'Invoice not found'];
        }

        if ($this->clientId && $invoice->client_id !== $this->clientId) {
            return ['error' => 'Invoice not found'];
        }

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'client_id' => $invoice->client_id,
            'client_name' => $invoice->client?->name,
            'contract_id' => $invoice->contract_id,
            'contract_name' => $invoice->contract?->name,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'status' => $invoice->status?->value,
            // psa-ij59.3 — VOIDED INVOICES. InvoiceVoidService zeroes the live money
            // fields on void and snapshots the originals into pre_void_*, so that
            // aggregates exclude voided invoices structurally. Returning ONLY the
            // zeroed values would answer "what was on this invoice?" with a
            // detailed-looking $0 and let an agent misreport a real historical bill.
            // So we mirror the staff web detail exactly: reportable_* is the live
            // (zeroed) value that counts toward revenue, original_* is what was
            // billed, and is_void_with_snapshot says which reading applies. The
            // original_* values come from the model's own display_* accessors rather
            // than being re-derived here.
            'is_void_with_snapshot' => $invoice->isVoidWithSnapshot(),
            'reportable_subtotal' => $invoice->subtotal,
            'reportable_tax' => $invoice->tax,
            'reportable_total' => $invoice->total,
            'reportable_total_cost' => $invoice->total_cost,
            'reportable_margin' => $invoice->margin,
            'original_subtotal' => $invoice->display_subtotal,
            'original_tax' => $invoice->display_tax,
            'original_total' => $invoice->display_total,
            'original_total_cost' => $invoice->display_total_cost,
            'original_margin' => $invoice->display_margin,
            'lines' => $invoice->lines->map(fn (InvoiceLine $line) => [
                'id' => $line->id,
                'sort_order' => $line->sort_order,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'reportable_amount' => $line->amount,
                'reportable_cost_amount' => $line->cost_amount,
                'original_amount' => $line->display_amount,
                'original_cost_amount' => $line->display_cost_amount,
                'unit_cost' => $line->unit_cost,
                'is_taxable' => (bool) $line->is_taxable,
                'prepaid_time_minutes' => $line->prepaid_time_minutes,
                'quantity_source' => $line->quantity_source,
            ])->toArray(),
        ];
    }

    // ── NinjaRMM Tools ──

    private function ninjaSearchDevices(array $input): array
    {
        if (! $this->clientId) {
            return ['error' => 'No client context — cannot search devices'];
        }

        $query = $input['query'] ?? '';
        $limit = min($input['limit'] ?? 20, 50);

        if (! $query) {
            return ['error' => 'query is required'];
        }

        $ninjaOrgId = $this->client?->ninja_org_id;
        if (! $ninjaOrgId) {
            return ['error' => 'Client has no NinjaRMM organization mapping'];
        }

        try {
            $results = app(NinjaClient::class)->get('/v2/devices/search', ['q' => $query, 'limit' => $limit]);
        } catch (\Throwable $e) {
            Log::warning('[Assistant] NinjaRMM search failed', ['error' => $e->getMessage()]);

            return ['error' => 'NinjaRMM search failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // Search endpoint wraps results: {"query": "...", "devices": [...]}
        $deviceList = $results['devices'] ?? $results;

        // CLIENT-SCOPED: filter to devices belonging to this client's Ninja org
        $devices = array_values(array_filter($deviceList, function ($device) use ($ninjaOrgId) {
            return ($device['organizationId'] ?? null) == $ninjaOrgId;
        }));

        return array_slice($devices, 0, $limit);
    }

    private function ninjaGetDevice(array $input): array
    {
        $deviceId = $input['device_id'] ?? null;
        if (! $deviceId) {
            return ['error' => 'device_id is required'];
        }

        if (! $this->verifyNinjaDeviceScope($deviceId)) {
            return ['error' => 'Device not found or belongs to a different client'];
        }

        try {
            return app(NinjaClient::class)->getDeviceDetail($deviceId);
        } catch (\Throwable $e) {
            Log::warning('[Assistant] NinjaRMM device query failed', ['device_id' => $deviceId, 'error' => $e->getMessage()]);

            return ['error' => 'NinjaRMM query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function ninjaGetDeviceSub(array $input, string $subResource): array
    {
        $deviceId = $input['device_id'] ?? null;
        if (! $deviceId) {
            return ['error' => 'device_id is required'];
        }

        if (! $this->verifyNinjaDeviceScope($deviceId)) {
            return ['error' => 'Device not found or belongs to a different client'];
        }

        try {
            return app(NinjaClient::class)->get("/v2/device/{$deviceId}/{$subResource}");
        } catch (\Throwable $e) {
            Log::warning('[Assistant] NinjaRMM sub-resource query failed', ['device_id' => $deviceId, 'sub' => $subResource, 'error' => $e->getMessage()]);

            return ['error' => 'NinjaRMM query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function verifyNinjaDeviceScope(int $ninjaDeviceId): bool
    {
        if (! $this->clientId) {
            return false;
        }

        return Asset::where('client_id', $this->clientId)
            ->where('ninja_id', $ninjaDeviceId)
            ->exists();
    }

    // ── Level RMM Tools ──

    private function levelGetDevice(array $input): array
    {
        $deviceId = $input['device_id'] ?? null;
        if (! $deviceId) {
            return ['error' => 'device_id is required'];
        }

        if (! $this->verifyLevelDeviceScope($deviceId)) {
            return ['error' => 'Device not found or belongs to a different client'];
        }

        try {
            return app(LevelClient::class)->getDevice($deviceId);
        } catch (\Throwable $e) {
            Log::warning('[Assistant] Level device query failed', ['device_id' => $deviceId, 'error' => $e->getMessage()]);

            return ['error' => 'Level query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function verifyLevelDeviceScope(string $levelDeviceId): bool
    {
        if (! $this->clientId) {
            return false;
        }

        return Asset::where('client_id', $this->clientId)
            ->where('level_id', $levelDeviceId)
            ->exists();
    }

    // ── Mesh Tools ──

    private function meshSearchLogs(array $input): array
    {
        $size = min($input['size'] ?? 20, 50);

        $meshCustomerId = $this->client?->mesh_customer_id;
        if (! $meshCustomerId) {
            return ['error' => 'Client has no Mesh customer mapping'];
        }

        // Date range is required — Mesh returns empty without it
        $end = gmdate('Y-m-d\TH:i:s');
        $start = gmdate('Y-m-d\TH:i:s', strtotime('-7 days'));

        $params = [
            '_from' => 0,
            '_size' => $size,
            'start' => $start,
            'end' => $end,
        ];

        foreach (['from', 'to', 'subject', 'status'] as $field) {
            if (! empty($input[$field])) {
                $params[$field] = $input[$field];
            }
        }

        try {
            $result = app(MeshClient::class)->get('api/emaillogs/', $params);
        } catch (\Throwable $e) {
            Log::warning('[Assistant] Mesh log search failed', ['error' => $e->getMessage()]);

            return ['error' => 'Mesh query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        // Filter to this client's customer ID (API returns all customers)
        $logs = $result['list'] ?? [];
        $logs = array_values(array_filter($logs, function ($entry) use ($meshCustomerId) {
            $ids = $entry['Customer-ID'] ?? [$entry['Customer Id'] ?? null];

            return in_array($meshCustomerId, (array) $ids);
        }));

        return [
            'total' => count($logs),
            'emails' => array_slice($logs, 0, $size),
        ];
    }

    private function meshGetEvents(array $input): array
    {
        $queueId = $input['queue_id'] ?? null;
        if (! $queueId) {
            return ['error' => 'queue_id is required'];
        }

        try {
            return app(MeshClient::class)->get('api/emaillogs/events', [
                'queue_id' => $queueId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Assistant] Mesh events query failed', ['queue_id' => $queueId, 'error' => $e->getMessage()]);

            return ['error' => 'Mesh query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    // ── CIPP Tools ──
    //
    // The CIPP tool bodies live in the shared App\Services\Cipp\HandlesCippTools
    // trait. The Assistant sources the tenant filter from $this->client, tags its
    // CIPP failure logs [Assistant], and overrides cippMcpRelay() below to route
    // through the CIPP MCP relay before falling back to the direct CippClient path.

    protected function cippTenantDomain(): ?string
    {
        return $this->client?->cipp_tenant_domain;
    }

    protected function cippLogPrefix(): string
    {
        return '[Assistant]';
    }

    protected function cippMcpRelay(string $toolName, array $input): ?array
    {
        if (! CippConfig::isMcpRelayEnabled()) {
            return null;
        }

        return app(CippMcpToolRelay::class)->execute($toolName, $input, $this->client, $this->clientId);
    }
}
