<?php

namespace App\Http\Controllers\Web;

use App\Enums\CabApproval;
use App\Enums\ChangeType;
use App\Enums\RiskLevel;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Http\Controllers\Controller;
use App\Http\Requests\TicketStoreRequest;
use App\Http\Requests\TicketUpdateRequest;
use App\Jobs\RunTriagePipeline;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Email;
use App\Models\Ticket;
use App\Models\TriageRun;
use App\Models\User;
use App\Services\RateLimitException;
use App\Services\ReplyDraftService;
use App\Services\TicketResolutionDrafter;
use App\Services\TicketService;
use App\Support\AiConfig;
use App\Support\TriageConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    public function __construct(
        private readonly TicketService $ticketService,
    ) {}

    public function index(Request $request)
    {
        $filters = [
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'type' => $request->query('type'),
            'source' => $request->query('source'),
            'client_id' => $request->query('client_id'),
            'assignee_id' => $request->query('assignee_id', auth()->id()),
            'search' => $request->query('search'),
            'show_closed' => $request->boolean('show_closed'),
            'overdue' => $request->boolean('overdue'),
            'sort' => $request->query('sort', 'priority'),
            'direction' => $request->query('direction', 'asc'),
        ];

        $tickets = $this->ticketService->getTicketList($filters);
        $unassignedCount = Ticket::open()->whereNull('assignee_id')->count();

        return view('tickets.index', [
            'tickets' => $tickets,
            'filters' => $filters,
            // active(), not operational(): ticket filters should include prospect intake tickets.
            'clients' => Client::active()->orderBy('name')->get(['id', 'name']),
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'statuses' => TicketStatus::cases(),
            'priorities' => TicketPriority::cases(),
            'types' => TicketType::cases(),
            'sources' => TicketSource::cases(),
            'unassignedCount' => $unassignedCount,
        ]);
    }

    public function create()
    {
        return view('tickets.create', [
            // active(), not operational(): staff can create ticket-as-opportunity records for prospects.
            'clients' => Client::active()->orderBy('name')->get(['id', 'name']),
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'types' => TicketType::cases(),
            'priorities' => TicketPriority::cases(),
            'categories' => config('tickets.categories', []),
            'changeTypes' => ChangeType::cases(),
            'riskLevels' => RiskLevel::cases(),
            'cabApprovals' => CabApproval::cases(),
        ]);
    }

    public function store(TicketStoreRequest $request)
    {
        $ticket = $this->ticketService->createTicket(
            $request->validated(),
            auth()->id(),
        );

        return redirect()->route('tickets.show', $ticket)
            ->with('success', "Ticket {$ticket->display_id} created.");
    }

    public function show(Ticket $ticket)
    {
        $ticket->load([
            'notes.author', 'notes.contract', 'notes.attachments', 'notes.email',
            'attachments',
            'client.contracts',
            'contact',
            'assets.tacticalAsset',
            'contract',
            'assignee',
            'createdBy',
            'parentTicket',
            'childTickets',
            'phoneCalls.answeredBy',
            'phoneCalls.person',
        ])->loadSum('notes', 'time_minutes');

        // Client assets available for linking (exclude already-linked)
        $clientAssets = collect();
        if ($ticket->client) {
            $linkedAssetIds = $ticket->assets->pluck('id');
            $clientAssets = Asset::where('client_id', $ticket->client_id)
                ->where('is_active', true)
                ->whereNotIn('id', $linkedAssetIds)
                ->orderBy('name')
                ->get(['id', 'name', 'asset_type']);
        }

        // Build merged timeline: notes + phone calls + AI conversations, sorted newest-first
        $conversations = \App\Models\AssistantConversation::where('context_type', 'ticket')
            ->where('context_id', $ticket->id)
            ->with(['user:id,name', 'messages'])
            ->get();

        $timeline = $ticket->notes
            ->concat($ticket->phoneCalls)
            ->concat($conversations)
            ->sortByDesc(function ($item) {
                if ($item instanceof \App\Models\PhoneCall) {
                    return $item->started_at;
                }
                if ($item instanceof \App\Models\AssistantConversation) {
                    return $item->created_at;
                }

                return $item->noted_at;
            })
            ->values();

        // Contacts for the ticket's client (for contact reassignment dropdown)
        $clientContacts = $ticket->client
            ? $ticket->client->people()->active()->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            : collect();

        \App\Support\RecentItems::track(auth()->id(), 'ticket', $ticket->id, $ticket->display_id.' '.\Illuminate\Support\Str::limit($ticket->subject, 30), route('tickets.show', $ticket));

        $defaultBillable = app(TicketService::class)->defaultBillable($ticket);

        return view('tickets.show', [
            'ticket' => $ticket,
            'timeline' => $timeline,
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'statuses' => TicketStatus::cases(),
            'priorities' => TicketPriority::cases(),
            'categories' => config('tickets.categories', []),
            'changeTypes' => ChangeType::cases(),
            'riskLevels' => RiskLevel::cases(),
            'cabApprovals' => CabApproval::cases(),
            'clientAssets' => $clientAssets,
            'clientContacts' => $clientContacts,
            'defaultBillable' => $defaultBillable,
        ]);
    }

    public function update(TicketUpdateRequest $request, Ticket $ticket)
    {
        $this->ticketService->updateTicket($ticket, $request->validated());

        return redirect()->route('tickets.show', $ticket)
            ->with('success', 'Ticket updated.');
    }

    public function updateStatus(Request $request, Ticket $ticket)
    {
        $request->validate([
            'status' => ['required', 'string'],
            'note' => ['nullable', 'string'],
            'resolution' => ['nullable', 'string'],
        ]);

        $newStatus = TicketStatus::from($request->input('status'));

        try {
            $this->ticketService->changeStatus(
                $ticket,
                $newStatus,
                auth()->id(),
                $request->input('note'),
                $request->input('resolution'),
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('tickets.show', $ticket)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('tickets.show', $ticket)
            ->with('success', "Status changed to {$newStatus->label()}.");
    }

    public function linkAsset(Request $request, Ticket $ticket)
    {
        $request->validate([
            'asset_id' => ['required', 'exists:assets,id'],
        ]);

        $asset = Asset::findOrFail($request->input('asset_id'));

        // Ensure asset belongs to the ticket's client
        if ($ticket->client_id && $asset->client_id !== $ticket->client_id) {
            return redirect()->route('tickets.show', $ticket)
                ->with('error', 'Asset does not belong to this client.');
        }

        $ticket->assets()->syncWithoutDetaching([
            $asset->id => ['is_primary' => false],
        ]);

        return redirect()->route('tickets.show', $ticket)
            ->with('success', "Asset {$asset->name} linked.");
    }

    public function unlinkAsset(Ticket $ticket, Asset $asset)
    {
        $ticket->assets()->detach($asset->id);

        return redirect()->route('tickets.show', $ticket)
            ->with('success', "Asset {$asset->name} unlinked.");
    }

    public function move(Request $request, Ticket $ticket)
    {
        $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'contact_id' => ['nullable', 'exists:people,id'],
        ]);

        try {
            $this->ticketService->moveToClient(
                $ticket,
                (int) $request->input('client_id'),
                $request->filled('contact_id') ? (int) $request->input('contact_id') : null,
                auth()->id(),
            );
        } catch (\RuntimeException $e) {
            return redirect()->route('tickets.show', $ticket)
                ->with('error', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('tickets.show', $ticket)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('tickets.show', $ticket)
            ->with('success', 'Ticket reassigned successfully.');
    }

    public function merge(Request $request, Ticket $ticket)
    {
        $request->validate([
            'secondary_ticket_id' => ['required', 'exists:tickets,id'],
        ]);

        $secondary = Ticket::findOrFail($request->input('secondary_ticket_id'));

        try {
            $this->ticketService->mergeTickets($ticket, $secondary, auth()->id());
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return redirect()->route('tickets.show', $ticket)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('tickets.show', $ticket)
            ->with('success', "Ticket {$secondary->display_id} merged successfully.");
    }

    public function apiSearch(Request $request)
    {
        $query = Ticket::query()
            ->whereNull('parent_ticket_id')
            ->whereDoesntHave('childTickets');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->input('client_id'));
        }

        if ($request->filled('exclude')) {
            $query->where('id', '!=', $request->input('exclude'));
        }

        if ($request->filled('q')) {
            $query->search($request->input('q'));
        }

        $tickets = $query
            ->withCount(['notes', 'phoneCalls', 'assets'])
            ->orderBy('updated_at', 'desc')
            ->limit(15)
            ->get(['id', 'subject', 'status', 'priority', 'client_id']);

        // Batch-fetch email counts
        $emailCounts = Email::whereIn('ticket_id', $tickets->pluck('id'))
            ->selectRaw('ticket_id, COUNT(*) as cnt')
            ->groupBy('ticket_id')
            ->pluck('cnt', 'ticket_id');

        return response()->json($tickets->map(fn ($t) => [
            'id' => $t->id,
            'display_id' => $t->display_id,
            'subject' => $t->subject,
            'status' => $t->status->label(),
            'priority' => $t->priority->label(),
            'priority_class' => $t->priority->badgeClass(),
            'notes_count' => $t->notes_count,
            'calls_count' => $t->phone_calls_count,
            'emails_count' => $emailCounts[$t->id] ?? 0,
            'assets_count' => $t->assets_count,
        ]));
    }

    public function bulkAction(Request $request)
    {
        $request->validate([
            'action' => ['required', 'string', 'in:close,reassign,priority,triage,review'],
            'assignee_id' => ['required_if:action,reassign', 'nullable', 'integer', 'exists:users,id'],
            'priority' => ['required_if:action,priority', 'nullable', 'string'],
        ]);

        // Resolve ticket IDs — either from filter or explicit list
        if ($request->boolean('select_all_filter')) {
            $filters = [
                'status' => $request->input('filter_status'),
                'priority' => $request->input('filter_priority'),
                'type' => $request->input('filter_type'),
                'source' => $request->input('filter_source'),
                'client_id' => $request->input('filter_client_id'),
                'assignee_id' => $request->input('filter_assignee_id'),
                'search' => $request->input('filter_search'),
                'show_closed' => $request->boolean('filter_show_closed'),
                'overdue' => $request->boolean('filter_overdue'),
            ];

            $ticketIds = $this->ticketService->getFilteredTicketIds($filters);

            if (empty($ticketIds)) {
                return redirect()->route('tickets.index')
                    ->with('error', 'No tickets match the current filter.');
            }
        } else {
            $validated = $request->validate([
                'ticket_ids' => ['required', 'array', 'min:1'],
                'ticket_ids.*' => ['required', 'integer', 'exists:tickets,id'],
            ]);

            $ticketIds = $validated['ticket_ids'];
        }

        $action = $request->input('action');
        $count = count($ticketIds);

        switch ($action) {
            case 'close':
                $affected = $this->ticketService->bulkClose($ticketIds, auth()->id());
                $message = "{$affected} ticket(s) closed.";
                break;

            case 'reassign':
                $affected = $this->ticketService->bulkReassign($ticketIds, (int) $request->input('assignee_id'), auth()->id());
                $assignee = User::find($request->input('assignee_id'));
                $message = "{$affected} ticket(s) reassigned to {$assignee->name}.";
                break;

            case 'priority':
                $priority = TicketPriority::from($request->input('priority'));
                $affected = $this->ticketService->bulkChangePriority($ticketIds, $priority);
                $message = "{$affected} ticket(s) set to {$priority->label()}.";
                break;

            case 'triage':
            case 'review':
                if (! TriageConfig::isEnabled()) {
                    return redirect()->route('tickets.index')
                        ->with('error', 'AI Triage is not enabled.');
                }

                foreach ($ticketIds as $id) {
                    RunTriagePipeline::dispatch($id, $action, auth()->id());
                }
                $label = $action === 'triage' ? 'Triage' : 'Review';
                $message = "AI {$label} queued for {$count} ticket(s).";
                break;
        }

        return redirect()->route('tickets.index')
            ->with('success', $message);
    }

    public function triggerTriage(Ticket $ticket)
    {
        if (! TriageConfig::isEnabled()) {
            return redirect()->route('tickets.show', $ticket)
                ->with('error', 'AI Triage is not enabled.');
        }

        RunTriagePipeline::dispatch($ticket->id, 'triage', auth()->id());

        return redirect()->route('tickets.show', $ticket)
            ->with('success', 'AI Triage started. Results will appear shortly.');
    }

    public function triggerReview(Ticket $ticket)
    {
        if (! TriageConfig::isEnabled()) {
            return redirect()->route('tickets.show', $ticket)
                ->with('error', 'AI Triage is not enabled.');
        }

        RunTriagePipeline::dispatch($ticket->id, 'review', auth()->id());

        return redirect()->route('tickets.show', $ticket)
            ->with('success', 'AI Review started. Results will appear shortly.');
    }

    public function storeFeedback(Request $request, TriageRun $triageRun)
    {
        $validated = $request->validate([
            'feedback_correct' => ['required', 'boolean'],
            'feedback_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $triageRun->update([
            'feedback_correct' => $validated['feedback_correct'],
            'feedback_note' => $validated['feedback_note'] ?? null,
            'feedback_submitted_by' => auth()->id(),
            'feedback_submitted_at' => now(),
        ]);

        return response()->json(['message' => 'Feedback saved.']);
    }

    public function clearFeedback(TriageRun $triageRun)
    {
        $triageRun->update([
            'feedback_correct' => null,
            'feedback_note' => null,
            'feedback_submitted_by' => null,
            'feedback_submitted_at' => null,
        ]);

        return response()->json(['message' => 'Feedback cleared.']);
    }

    public function draftReply(Request $request, Ticket $ticket)
    {
        $request->validate([
            'instructions' => ['nullable', 'string', 'max:500'],
        ]);

        if (! AiConfig::isConfigured()) {
            return response()->json(['error' => 'AI is not configured. Set it up in Settings > Integrations.'], 422);
        }

        try {
            $service = app(ReplyDraftService::class);
            $result = $service->generateDraft(
                $ticket,
                $request->input('instructions'),
                auth()->user()?->name,
            );

            return response()->json([
                'draft' => $result['draft'],
                'to' => $result['to'],
                'cc' => $result['cc'],
                'status' => $result['status'],
                'tokens' => $result['input_tokens'] + $result['output_tokens'],
            ]);
        } catch (RateLimitException $e) {
            return response()->json(['error' => $e->getMessage()], 429);
        } catch (\Throwable $e) {
            Log::warning('[ReplyDraft] Draft generation failed', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            $message = match (true) {
                str_contains($e->getMessage(), '529') => 'AI provider is temporarily overloaded. Try again in a minute or two.',
                str_contains($e->getMessage(), '500 Internal Server Error') => 'AI provider returned a server error. This is usually temporary — try again shortly.',
                str_contains($e->getMessage(), '401') || str_contains($e->getMessage(), '403') => 'AI API key is invalid or expired. Check Settings > Integrations.',
                str_contains($e->getMessage(), '408') || str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'cURL error 28') => 'AI request timed out. The ticket may be too large, or the provider is slow. Try again.',
                default => 'Failed to generate draft. Please try again or type manually.',
            };

            return response()->json(['error' => $message], 422);
        }
    }

    public function draftResolution(Ticket $ticket)
    {
        if (! AiConfig::isConfigured()) {
            return response()->json(['error' => 'AI is not configured. Set it up in Settings > Integrations.'], 422);
        }

        try {
            $result = app(TicketResolutionDrafter::class)->draft($ticket, 'manual');

            if ($result === null) {
                return response()->json([
                    'error' => "Couldn't draft a resolution from this ticket's notes — write one manually.",
                ], 422);
            }

            return response()->json(['resolution' => $result]);
        } catch (RateLimitException $e) {
            return response()->json(['error' => $e->getMessage()], 429);
        } catch (\Throwable $e) {
            Log::warning('[ResolutionDraft] Draft generation failed', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);

            $message = match (true) {
                str_contains($e->getMessage(), '529') => 'AI provider is temporarily overloaded. Try again in a minute or two.',
                str_contains($e->getMessage(), '500 Internal Server Error') => 'AI provider returned a server error. This is usually temporary — try again shortly.',
                str_contains($e->getMessage(), '401') || str_contains($e->getMessage(), '403') => 'AI API key is invalid or expired. Check Settings > Integrations.',
                str_contains($e->getMessage(), '408') || str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'cURL error 28') => 'AI request timed out. The ticket may be too large, or the provider is slow. Try again.',
                default => 'Failed to generate draft. Please try again or type manually.',
            };

            return response()->json(['error' => $message], 422);
        }
    }

    public function runTacticalScript(Request $request, Ticket $ticket)
    {
        $request->validate([
            'asset_id' => ['required', 'exists:assets,id'],
            'script_id' => ['required', 'exists:tactical_scripts,id'],
            'args' => ['nullable', 'string', 'max:1000'],
            'timeout' => ['required', 'integer', 'min:10', 'max:600'],
        ]);

        $asset = Asset::with('tacticalAsset')->findOrFail($request->input('asset_id'));

        // Verify asset is linked to this ticket
        if (! $ticket->assets()->where('assets.id', $asset->id)->exists()) {
            return response()->json(['error' => 'Asset is not linked to this ticket.'], 422);
        }

        // Only the not-linked case is a hard pre-check (M4: don't gate on the
        // daily-stale snapshot; the bus reports `offline` as the source of truth).
        if (! $asset->tacticalAsset || empty($asset->tacticalAsset->agent_id)) {
            return response()->json(['error' => 'Device has no Tactical agent.'], 422);
        }

        $script = \App\Models\TacticalScript::findOrFail($request->input('script_id'));

        // Route execution through the audited action bus, attributing the ticket
        // (m1) so the audit row carries per-incident ITIL history.
        $result = app(\App\Services\Tactical\TacticalActionService::class)->dispatch(
            new \App\Services\Tactical\Actions\RunScriptAction,
            $asset,
            $request->user(),
            [
                'tactical_script_id' => $script->tactical_script_id,
                'args' => (string) $request->input('args', ''),
                'timeout' => (int) $request->input('timeout'),
            ],
            ticketId: $ticket->id,
        );

        if (! $result->isOk()) {
            $status = $result->isOffline() ? 422 : 500;

            return response()->json(['error' => $result->message ?? 'Script execution failed.'], $status);
        }

        $stdout = $result->stdout ?? '';
        $stderr = $result->stderr ?? '';
        $retcode = $result->retcode;

        // m5: the ticket-note side effect STAYS here (post-dispatch, reading the
        // normalized result) — RunScriptAction is side-effect-free w.r.t. PSA models.
        $noteBody = "**Script Executed:** {$script->name}\n"
            .'**Device:** '.($asset->hostname ?? $asset->name)."\n"
            .'**Return Code:** '.($retcode ?? 'unknown')."\n";

        if ($stdout) {
            $noteBody .= "\n**Output:**\n```\n".substr($stdout, 0, 5000)."\n```";
        }
        if ($stderr) {
            $noteBody .= "\n**Errors:**\n```\n".substr($stderr, 0, 2000)."\n```";
        }

        $this->ticketService->addNote(
            $ticket,
            $noteBody,
            \App\Enums\NoteType::System,
            true,
            auth()->id(),
        );

        return response()->json([
            'success' => true,
            'script_name' => $script->name,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'retcode' => $retcode,
        ]);
    }

    /**
     * Run an ad-hoc command on a ticket's asset while working the incident — the
     * ITIL "diagnostic during an incident" flow (amendment G1: ticket surface is
     * cmd ONLY; shutdown/recover/maintenance stay asset-page). The most dangerous
     * capability in the integration (arbitrary RCE), so it carries the SAME A1
     * security spine as AssetController::runTacticalCommand PLUS the run-script
     * membership gate.
     *
     * Spine, in order:
     *   1. membership — the posted asset MUST be attached to this ticket (else 422,
     *      no dispatch — mirror runTacticalScript);
     *   2. resolve the asset's tacticalAsset->agent_id (else 422, not-linked);
     *   3. ONE canonical $params = RunCommandAction::validateParams($request->only
     *      (shell,cmd,timeout)) — invalid input is dispatched RAW (no token) so the
     *      bus audits a `rejected` row (with the ticket id);
     *   4. server-side typed-hostname match (strcasecmp, like reboot);
     *   5. mint a token bound to payloadHash($params) and dispatch THAT SAME array,
     *      passing $ticket->id NON-OPTIONALLY so the audit row carries it.
     * The controller NEVER re-reads cmd/shell/timeout for execution.
     *
     * B3 + DC1 — the ticket-note side effect (written OUTSIDE the bus, so the
     * controller must redact it itself): AFTER the bus returns, and ONLY if the
     * result isOk(), write a note from REDACTED values — `$action->summary($params)`
     * for the command (already secret-redacted), the retcode, and
     * `ActionRedactor::redactOutput()` for stdout/stderr. NEVER raw request input.
     * A blocked/rejected/offline/error cmd writes NO note (the bus audit row covers
     * those).
     */
    public function runTacticalCommand(Request $request, Ticket $ticket)
    {
        $request->validate([
            'asset_id' => ['required', 'exists:assets,id'],
            'hostname' => ['required', 'string', 'max:255'],
        ]);

        $asset = Asset::with('tacticalAsset')->findOrFail($request->input('asset_id'));

        // 1. Membership gate (mirror runTacticalScript): the posted asset must be
        //    attached to THIS ticket — never an arbitrary asset id.
        if (! $ticket->assets()->where('assets.id', $asset->id)->exists()) {
            return response()->json(['error' => 'Asset is not linked to this ticket.'], 422);
        }

        // 2. resolve the agent (not-linked is a hard pre-check; the bus reports
        //    `offline` as the live source of truth for a linked-but-unreachable box).
        if (! $asset->tacticalAsset || empty($asset->tacticalAsset->agent_id)) {
            return response()->json(['error' => 'Device has no Tactical agent.'], 422);
        }

        $agentId = $asset->tacticalAsset->agent_id;
        $actor = $request->user();
        $action = new \App\Services\Tactical\Actions\RunCommandAction;
        $bus = app(\App\Services\Tactical\TacticalActionService::class);

        // 3. A1 step 1: ONE canonical params source. Invalid input is dispatched
        //    RAW (no token) so the bus audits a `rejected` row carrying the ticket.
        try {
            $params = $action->validateParams($request->only('shell', 'cmd', 'timeout'));
        } catch (\App\Services\Tactical\Actions\InvalidActionParams $e) {
            $result = $bus->dispatch($action, $asset, $actor, $request->only('shell', 'cmd', 'timeout'), null, null, $ticket->id);

            return $this->tacticalCommandResponse($result);
        }

        // 4. Typed-hostname gate (server-side, case-insensitive + trimmed — reboot).
        $expected = trim((string) ($asset->tacticalAsset->hostname ?? $asset->hostname ?? ''));
        $typed = trim((string) $request->input('hostname'));
        if ($expected === '' || strcasecmp($expected, $typed) !== 0) {
            return response()->json([
                'error' => 'The typed hostname does not match this device. Command cancelled.',
            ], 422);
        }

        // 5. A1 steps 2 + 4: hash the canonical array, mint a token bound to it,
        //    dispatch THAT SAME array WITH the ticket id (never re-reading input).
        $token = \App\Services\Tactical\TacticalActionConfirmToken::issue(
            $action->key(),
            $agentId,
            $actor?->id,
            $action->payloadHash($params),
        );

        $result = $bus->dispatch($action, $asset, $actor, $params, $token, null, $ticket->id);

        // B3 + DC1: success-gated, redacted ticket note (written outside the bus).
        if ($result->isOk()) {
            $this->writeCommandNote($ticket, $asset, $action, $params, $result);
        }

        return $this->tacticalCommandResponse($result);
    }

    /**
     * B3 — compose the ticket note from REDACTED values only: the command via
     * RunCommandAction::summary() (already routed through
     * ActionRedactor::redactCommandString) and the output via redactOutput().
     * NEVER the raw request input. Mirrors runTacticalScript's note shape.
     */
    private function writeCommandNote(
        Ticket $ticket,
        Asset $asset,
        \App\Services\Tactical\Actions\RunCommandAction $action,
        array $params,
        \App\Services\Tactical\Actions\TacticalActionResult $result,
    ): void {
        $redactor = app(\App\Services\Tactical\Actions\ActionRedactor::class);

        $noteBody = "**Command Executed:** `{$action->summary($params)}`\n"
            .'**Device:** '.($asset->hostname ?? $asset->name)."\n"
            .'**Return Code:** '.($result->retcode ?? 'unknown')."\n";

        $stdout = $redactor->redactOutput($result->stdout);
        $stderr = $redactor->redactOutput($result->stderr);

        if ($stdout !== null && $stdout !== '') {
            $noteBody .= "\n**Output:**\n```\n".$stdout."\n```";
        }
        if ($stderr !== null && $stderr !== '') {
            $noteBody .= "\n**Errors:**\n```\n".$stderr."\n```";
        }

        $this->ticketService->addNote(
            $ticket,
            $noteBody,
            \App\Enums\NoteType::System,
            true,
            auth()->id(),
        );
    }

    /**
     * Map the bus's normalized cmd result to the JSON contract the ticket cmd JS
     * parses: ok -> 200 {success, message}; offline -> 422 {error}; any other
     * non-ok (rejected/blocked/error) -> 500 {error}. No `success` key on failure.
     * (Mirrors AssetController::tacticalActionResponse.)
     */
    private function tacticalCommandResponse(
        \App\Services\Tactical\Actions\TacticalActionResult $result,
    ): \Illuminate\Http\JsonResponse {
        if ($result->isOk()) {
            return response()->json([
                'success' => true,
                'message' => $result->stdout ?: 'Command sent.',
            ]);
        }

        return response()->json([
            'error' => $result->message ?? 'Command failed.',
        ], $result->isOffline() ? 422 : 500);
    }
}
