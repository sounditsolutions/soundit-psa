<?php

namespace App\Services\Assistant;

use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Enums\TicketType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\Agent\ProposeCloseTool;
use App\Services\Cipp\CippClient;
use App\Services\Cipp\CippMcpToolRelay;
use App\Services\Level\LevelClient;
use App\Services\Mesh\MeshClient;
use App\Services\Ninja\NinjaClient;
use App\Services\TicketService;
use App\Services\Wiki\HandlesWikiTools;
use App\Services\Wiki\WikiAddFactTool;
use App\Support\CippConfig;
use Illuminate\Support\Facades\Log;

/**
 * Executes tool calls from the AI assistant.
 * All queries scoped to client_id.
 */
class AssistantToolExecutor
{
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

        Log::debug('[Assistant] Tool call', [
            'tool' => $toolName,
            'client_id' => $this->clientId,
            'input' => $logInput,
        ]);

        return match ($toolName) {
            // General tools (no client scope required)
            'search_all_tickets' => $this->searchAllTickets($input),
            'list_my_tickets' => $this->listMyTickets($input),
            'list_open_tickets' => $this->listOpenTickets($input),
            'get_ticket_detail' => $this->getTicketDetail($input),
            'propose_close' => $this->proposeClose($input),
            'get_ticket_calls' => $this->getTicketCalls($input),
            'get_queue_stats' => $this->getQueueStats(),

            // PSA tools (client-scoped)
            'search_tickets' => $this->searchTickets($input),
            'get_ticket_notes' => $this->getTicketNotes($input),
            'create_ticket' => $this->createTicket($input),
            'add_ticket_note' => $this->addTicketNote($input),
            'get_client' => $this->getClient(),
            'get_person' => $this->getPerson($input),
            'get_asset' => $this->getAsset($input),
            'find_clients' => $this->findClients($input),
            'find_persons' => $this->findPersons($input),
            'find_assets' => $this->findAssets($input),

            // NinjaRMM tools
            'ninja_search_devices' => $this->ninjaSearchDevices($input),
            'ninja_get_device' => $this->ninjaGetDevice($input),
            'ninja_get_device_volumes' => $this->ninjaGetDeviceSub($input, 'volumes'),
            'ninja_get_device_alerts' => $this->ninjaGetDeviceSub($input, 'alerts'),
            'ninja_get_device_os_patches' => $this->ninjaGetDeviceSub($input, 'os-patches'),
            'ninja_get_device_software' => $this->ninjaGetDeviceSub($input, 'software'),
            'ninja_get_device_processors' => $this->ninjaGetDeviceSub($input, 'processors'),
            'ninja_get_device_disk_drives' => $this->ninjaGetDeviceSub($input, 'disks'),
            'ninja_get_device_network_interfaces' => $this->ninjaGetDeviceSub($input, 'network-interfaces'),
            'ninja_get_device_windows_services' => $this->ninjaGetDeviceSub($input, 'windows-services'),
            'ninja_get_device_last_user' => $this->ninjaGetDeviceSub($input, 'last-logged-on-user'),

            // Level RMM tools
            'level_get_device' => $this->levelGetDevice($input),

            // Mesh tools
            'mesh_search_email_logs' => $this->meshSearchLogs($input),
            'mesh_get_email_events' => $this->meshGetEvents($input),

            // CIPP tools
            'cipp_list_users' => $this->cippQuery('cipp_list_users', $input, 'api/ListUsers'),
            'cipp_list_mailboxes' => $this->cippQuery('cipp_list_mailboxes', $input, 'api/ListMailboxes'),
            'cipp_list_licenses' => $this->cippQuery('cipp_list_licenses', $input, 'api/ListLicenses'),
            'cipp_list_devices' => $this->cippQuery('cipp_list_devices', $input, 'api/ListDevices'),
            'cipp_list_sign_ins' => $this->cippListSignIns($input),
            'cipp_list_groups' => $this->cippQuery('cipp_list_groups', $input, 'api/ListGroups'),
            'cipp_list_user_groups' => $this->cippQueryWithUser($input, 'cipp_list_user_groups', 'api/ListUserGroups'),
            'cipp_list_mailbox_permissions' => $this->cippQueryWithUser($input, 'cipp_list_mailbox_permissions', 'api/ListmailboxPermissions'),
            'cipp_list_mailbox_rules' => $this->cippQueryWithUser($input, 'cipp_list_mailbox_rules', 'api/ListMailboxRules'),
            'cipp_list_defender_state' => $this->cippQuery('cipp_list_defender_state', $input, 'api/ListDefenderState'),
            'cipp_list_conditional_access_policies' => $this->cippQuery('cipp_list_conditional_access_policies', $input, 'api/ListConditionalAccessPolicies'),
            'cipp_list_user_conditional_access' => $this->cippQueryWithUser($input, 'cipp_list_user_conditional_access', 'api/ListUserConditionalAccessPolicies'),
            'cipp_list_audit_logs' => $this->cippListAuditLogs($input),
            'cipp_list_message_trace' => $this->cippListMessageTrace($input),
            'cipp_list_mail_quarantine' => $this->cippListMailQuarantine($input),
            'cipp_list_user_mfa_methods' => $this->cippListUserMfaMethods($input),
            'cipp_list_oauth_apps' => $this->cippListOauthApps($input),

            // DNS tools (always available, no client scope required)
            'dns_lookup' => \App\Services\Dns\DnsToolkit::lookup($input['hostname'] ?? '', $input['type'] ?? ''),
            'dns_email_health' => \App\Services\Dns\DnsToolkit::emailHealth($input['domain'] ?? ''),

            // Wiki retrieval tools (§6; null clientId → global-only, not an error)
            'wiki_list_pages' => $this->wikiListPages(),
            'wiki_search' => $this->wikiSearch($input),
            'wiki_get_page' => $this->wikiGetPage($input),
            'wiki_add_fact' => app(WikiAddFactTool::class)->execute($input, $this->clientId, $this->userId),

            default => ['error' => "Unknown tool: {$toolName}"],
        };
    }

    // ── General Tools (no client scope) ──

    private function searchAllTickets(array $input): array
    {
        $query = $input['query'] ?? '';
        $limit = min($input['limit'] ?? 15, 30);

        $tickets = Ticket::query()
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
        $openStatuses = ['new', 'in_progress', 'pending_client', 'pending_vendor'];

        $tickets = Ticket::where('assignee_id', $this->userId)
            ->when(
                $input['status'] ?? null,
                fn ($q, $s) => $q->where('status', $s),
                fn ($q) => $q->whereIn('status', $openStatuses)
            )
            ->orderByRaw("FIELD(priority, 'p1', 'p2', 'p3', 'p4')")
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
        $openStatuses = ['new', 'in_progress', 'pending_client', 'pending_vendor'];

        $query = Ticket::whereIn('status', $openStatuses);

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

        $tickets = $query
            ->orderByRaw("FIELD(priority, 'p1', 'p2', 'p3', 'p4')")
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'subject', 'status', 'priority', 'client_id', 'assignee_id', 'source', 'created_at']);

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
            'age' => $t->created_at?->diffForHumans(),
        ])->toArray();
    }

    private function getTicketDetail(array $input): array
    {
        $ticketId = $input['ticket_id'] ?? null;
        if (! $ticketId) {
            return ['error' => 'ticket_id is required'];
        }

        $ticket = Ticket::with(['client:id,name', 'assignee:id,name', 'contact'])->find($ticketId);
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

        $ticket = Ticket::find($ticketId);
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
        $openStatuses = ['new', 'in_progress', 'pending_client', 'pending_vendor'];

        $openTickets = Ticket::whereIn('status', $openStatuses);

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
        if (! $ticketId) {
            return ['error' => 'ticket_id is required'];
        }

        // CLIENT-SCOPED: verify the ticket belongs to this client
        $ticket = Ticket::where('id', $ticketId)
            ->where('client_id', $this->clientId)
            ->first();

        if (! $ticket) {
            return ['error' => 'Ticket not found or belongs to a different client'];
        }

        // Latest notes, not oldest: fetch the newest 20 then present them
        // chronologically. The old ASC+limit dropped the tail on busy tickets,
        // so "what did the client say last" could be absent entirely (psa-m7re).
        $notes = TicketNote::where('ticket_id', $ticketId)
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
        if (! $this->clientId) {
            return ['error' => 'No client context — cannot create ticket'];
        }

        $subject = $input['subject'] ?? null;
        $description = $input['description'] ?? null;
        if (! $subject || ! $description) {
            return ['error' => 'subject and description are required'];
        }

        $priorityLevel = $input['priority'] ?? 3;
        $priorityMap = [1 => 'p1', 2 => 'p2', 3 => 'p3', 4 => 'p4'];
        $priority = $priorityMap[$priorityLevel] ?? 'p3';

        try {
            $ticket = app(TicketService::class)->createTicket([
                'client_id' => $this->clientId,
                'subject' => $subject,
                'description' => $description,
                'priority' => $priority,
                'type' => TicketType::ServiceRequest->value,
                'source' => TicketSource::Assistant->value,
            ], $this->userId);

            Log::info('[Assistant] AI created ticket', [
                'ticket_id' => $ticket->id,
                'client_id' => $this->clientId,
                'subject' => $subject,
            ]);

            return [
                'success' => true,
                'ticket_id' => $ticket->id,
                'display_id' => $ticket->display_id,
                'url' => route('tickets.show', $ticket),
            ];
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
            // aiAuthored: every note through this executor is AI-authored
            // (Chet via MCP, the Teams teammate) — the flag keeps the
            // Technician's human-touch signals from misreading it as a human.
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

        $clients = Client::where('name', 'like', "%{$query}%")
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

    private function cippQuery(string $toolName, array $input, string $endpoint): array
    {
        $relay = $this->cippMcpRelay($toolName, $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        try {
            return app(CippClient::class)->get($endpoint, ['TenantFilter' => $tenantDomain]);
        } catch (\Throwable $e) {
            Log::warning('[Assistant] CIPP query failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function cippQueryWithUser(array $input, string $toolName, string $endpoint): array
    {
        $relay = $this->cippMcpRelay($toolName, $input);
        if ($relay !== null) {
            return $relay;
        }

        $userId = $input['user_id'] ?? null;
        if (! $userId) {
            return ['error' => 'user_id is required'];
        }

        $tenantDomain = $this->client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        try {
            return app(CippClient::class)->get($endpoint, [
                'TenantFilter' => $tenantDomain,
                'userId' => $this->resolveCippUserId($userId),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Assistant] CIPP query failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }

    /**
     * Translate a UPN (email) to the Azure AD object ID expected by CIPP's
     * per-user endpoints. See TriageToolExecutor::resolveCippUserId for rationale.
     */
    private function resolveCippUserId(string $input): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $input)) {
            return $input;
        }

        if (str_contains($input, '@') && $this->clientId) {
            $objectId = Person::where('client_id', $this->clientId)
                ->whereRaw('LOWER(cipp_upn) = ?', [mb_strtolower($input)])
                ->value('cipp_user_id');

            if ($objectId) {
                return $objectId;
            }
        }

        return $input;
    }

    private function cippListSignIns(array $input): array
    {
        $relay = $this->cippMcpRelay('cipp_list_sign_ins', $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $userId = ! empty($input['user_id']) ? trim((string) $input['user_id']) : null;
        $days = isset($input['days']) && is_numeric($input['days'])
            ? (int) min(max(1, $input['days']), 30)
            : null;

        $endpoint = $userId ? 'api/ListUserSigninLogs' : 'api/ListSignIns';
        $params = ['TenantFilter' => $tenantDomain];
        if ($userId) {
            $params['userId'] = $this->resolveCippUserId($userId);
        }

        try {
            $events = app(CippClient::class)->get($endpoint, $params);
        } catch (\Throwable $e) {
            Log::warning('[Assistant] CIPP sign-in query failed', ['endpoint' => $endpoint, 'error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($events)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($events);

        if ($days !== null) {
            $cutoff = now()->subDays($days);
            $events = array_values(array_filter($events, fn ($e) => $this->eventWithinCutoff($e, $cutoff, ['createdDateTime'])));
        }

        return [
            'count' => count(array_slice($events, 0, 50)),
            'endpoint' => $endpoint,
            'filtered_by_user' => $userId,
            'filtered_by_days' => $days,
            'total_returned_by_cipp' => $totalReturned,
            'events' => array_slice($events, 0, 50),
        ];
    }

    private function cippListAuditLogs(array $input): array
    {
        $relay = $this->cippMcpRelay('cipp_list_audit_logs', $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $userId = ! empty($input['user_id']) ? trim((string) $input['user_id']) : null;
        $days = isset($input['days']) && is_numeric($input['days'])
            ? (int) min(max(1, $input['days']), 30)
            : null;

        $params = ['TenantFilter' => $tenantDomain];
        if ($userId) {
            $params['userId'] = $this->resolveCippUserId($userId);
        }

        try {
            $events = app(CippClient::class)->get('api/ListAuditLogs', $params);
        } catch (\Throwable $e) {
            Log::warning('[Assistant] CIPP ListAuditLogs failed', ['error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($events)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($events);

        if ($days !== null) {
            $cutoff = now()->subDays($days);
            $events = array_values(array_filter($events, fn ($e) => $this->eventWithinCutoff($e, $cutoff, ['createdDateTime', 'CreationTime', 'Date'])));
        }

        if ($userId) {
            $resolved = $this->resolveCippUserId($userId);
            $upnNeedle = str_contains($userId, '@') ? mb_strtolower($userId) : null;
            $events = array_values(array_filter($events, function ($e) use ($resolved, $upnNeedle) {
                foreach (['userId', 'UserId', 'userPrincipalName', 'UserPrincipalName', 'initiatedBy'] as $key) {
                    if (isset($e[$key])) {
                        $val = mb_strtolower((string) $e[$key]);
                        if ($val === mb_strtolower($resolved)) {
                            return true;
                        }
                        if ($upnNeedle && $val === $upnNeedle) {
                            return true;
                        }
                    }
                }

                return false;
            }));
        }

        return [
            'count' => count($events),
            'filtered_by_user' => $userId,
            'filtered_by_days' => $days,
            'total_returned_by_cipp' => $totalReturned,
            'events' => array_slice($events, 0, 50),
        ];
    }

    private function cippListMessageTrace(array $input): array
    {
        $relay = $this->cippMcpRelay('cipp_list_message_trace', $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $sender = ! empty($input['sender']) ? trim((string) $input['sender']) : null;
        $recipient = ! empty($input['recipient']) ? trim((string) $input['recipient']) : null;
        $days = isset($input['days']) && is_numeric($input['days'])
            ? (int) min(max(1, $input['days']), 10)
            : 2;

        $params = ['TenantFilter' => $tenantDomain, 'days' => $days];
        if ($sender) {
            $params['sender'] = $sender;
        }
        if ($recipient) {
            $params['recipient'] = $recipient;
        }

        try {
            $messages = app(CippClient::class)->get('api/ListMessageTrace', $params);
        } catch (\Throwable $e) {
            Log::warning('[Assistant] CIPP ListMessageTrace failed', ['error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($messages)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($messages);

        if ($sender) {
            $needle = mb_strtolower($sender);
            $messages = array_values(array_filter($messages, fn ($m) => mb_strtolower((string) ($m['SenderAddress'] ?? $m['senderAddress'] ?? '')) === $needle));
        }
        if ($recipient) {
            $needle = mb_strtolower($recipient);
            $messages = array_values(array_filter($messages, fn ($m) => mb_strtolower((string) ($m['RecipientAddress'] ?? $m['recipientAddress'] ?? '')) === $needle));
        }

        return [
            'count' => count($messages),
            'filtered_by_sender' => $sender,
            'filtered_by_recipient' => $recipient,
            'window_days' => $days,
            'total_returned_by_cipp' => $totalReturned,
            'messages' => array_slice($messages, 0, 50),
        ];
    }

    private function cippListMailQuarantine(array $input): array
    {
        $relay = $this->cippMcpRelay('cipp_list_mail_quarantine', $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $recipient = ! empty($input['recipient']) ? trim((string) $input['recipient']) : null;

        try {
            $entries = app(CippClient::class)->get('api/ListMailQuarantine', ['TenantFilter' => $tenantDomain]);
        } catch (\Throwable $e) {
            Log::warning('[Assistant] CIPP ListMailQuarantine failed', ['error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($entries)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($entries);

        if ($recipient) {
            $needle = mb_strtolower($recipient);
            $entries = array_values(array_filter($entries, function ($e) use ($needle) {
                foreach (['RecipientAddress', 'recipientAddress', 'recipients'] as $key) {
                    $val = $e[$key] ?? null;
                    if (is_string($val) && mb_strtolower($val) === $needle) {
                        return true;
                    }
                    if (is_array($val)) {
                        foreach ($val as $r) {
                            if (is_string($r) && mb_strtolower($r) === $needle) {
                                return true;
                            }
                        }
                    }
                }

                return false;
            }));
        }

        return [
            'count' => count($entries),
            'filtered_by_recipient' => $recipient,
            'total_returned_by_cipp' => $totalReturned,
            'entries' => array_slice($entries, 0, 50),
        ];
    }

    private function cippListUserMfaMethods(array $input): array
    {
        $relay = $this->cippMcpRelay('cipp_list_user_mfa_methods', $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        $userId = $input['user_id'] ?? null;
        if (! $userId) {
            return ['error' => 'user_id is required'];
        }

        // See TriageToolExecutor::cippListUserMfaMethods — ListMFAUsers, not ListPerUserMFA.
        try {
            $rows = app(CippClient::class)->get('api/ListMFAUsers', ['TenantFilter' => $tenantDomain]);
        } catch (\Throwable $e) {
            Log::warning('[Assistant] CIPP ListMFAUsers failed', ['error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($rows)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $objectId = $this->resolveCippUserId($userId);
        $upnNeedle = str_contains($userId, '@') ? mb_strtolower($userId) : null;

        foreach ($rows as $row) {
            $rowUpn = mb_strtolower((string) ($row['UPN'] ?? $row['userPrincipalName'] ?? ''));
            $rowId = (string) ($row['ID'] ?? $row['Id'] ?? $row['userId'] ?? '');
            if ($rowId === $objectId || ($upnNeedle && $rowUpn === $upnNeedle)) {
                return \App\Services\Triage\TriageToolExecutor::summarizeMfaRow($row);
            }
        }

        return [
            'error' => "No MFA record found for {$userId} in this tenant",
            'searched_user_id' => $userId,
            'resolved_object_id' => $objectId,
        ];
    }

    private function cippListOauthApps(array $input): array
    {
        $relay = $this->cippMcpRelay('cipp_list_oauth_apps', $input);
        if ($relay !== null) {
            return $relay;
        }

        $tenantDomain = $this->client?->cipp_tenant_domain;
        if (! $tenantDomain) {
            return ['error' => 'Client has no CIPP tenant mapping'];
        }

        try {
            $apps = app(CippClient::class)->get('api/ListOAuthApps', ['TenantFilter' => $tenantDomain]);
        } catch (\Throwable $e) {
            Log::warning('[Assistant] CIPP ListOAuthApps failed', ['error' => $e->getMessage()]);

            return ['error' => 'CIPP query failed: '.mb_substr($e->getMessage(), 0, 200)];
        }

        if (! is_array($apps)) {
            return ['error' => 'Unexpected CIPP response shape'];
        }

        $totalReturned = count($apps);
        $userId = ! empty($input['user_id']) ? trim((string) $input['user_id']) : null;

        if ($userId) {
            $objectId = $this->resolveCippUserId($userId);
            $upnNeedle = str_contains($userId, '@') ? mb_strtolower($userId) : null;

            $apps = array_values(array_filter($apps, function ($app) use ($objectId, $upnNeedle) {
                foreach (['principalId', 'consentedBy', 'userId', 'userPrincipalName'] as $key) {
                    $val = $app[$key] ?? null;
                    if (is_string($val) && $val !== '') {
                        if ($val === $objectId) {
                            return true;
                        }
                        if ($upnNeedle && mb_strtolower($val) === $upnNeedle) {
                            return true;
                        }
                    }
                }

                return false;
            }));
        }

        return [
            'count' => count($apps),
            'filtered_by_user' => $userId,
            'total_returned_by_cipp' => $totalReturned,
            'apps' => array_slice($apps, 0, 50),
        ];
    }

    private function eventWithinCutoff(array $e, \Illuminate\Support\Carbon $cutoff, array $dateKeys): bool
    {
        foreach ($dateKeys as $key) {
            if (! empty($e[$key])) {
                try {
                    return \Illuminate\Support\Carbon::parse($e[$key])->gte($cutoff);
                } catch (\Throwable) {
                    return false;
                }
            }
        }

        return false;
    }

    private function cippMcpRelay(string $toolName, array $input): ?array
    {
        if (! CippConfig::isMcpRelayEnabled()) {
            return null;
        }

        return app(CippMcpToolRelay::class)->execute($toolName, $input, $this->client, $this->clientId);
    }
}
