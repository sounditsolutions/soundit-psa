<?php

namespace App\Services;

use App\Enums\NoteType;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Helpers\MarkdownRenderer;
use App\Models\Asset;
use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Email;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Support\AppTimezone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator as LengthAwarePaginatorInstance;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TicketService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function createTicket(array $data, ?int $createdByUserId): Ticket
    {
        $priority = TicketPriority::from($data['priority']);

        $data['created_by'] = $createdByUserId;
        $data['source'] = $data['source'] ?? TicketSource::Manual->value;
        $data['status'] = TicketStatus::New->value;
        $data['opened_at'] = now();
        $data['priority_order'] = $priority->sortOrder();

        // Resolve SLA deadlines from contract terms (if any)
        $contract = ! empty($data['contract_id']) ? Contract::find($data['contract_id']) : null;

        if (empty($data['due_at'])) {
            $resolutionHours = $contract?->slaResolutionHours($priority);
            if ($resolutionHours) {
                $data['due_at'] = now()->addHours($resolutionHours);
            }
            // No contract SLA → no automatic due_at
        } elseif (is_string($data['due_at'])) {
            // User supplied a datetime-local string — convert from app timezone to UTC
            $data['due_at'] = Carbon::createFromFormat('Y-m-d\TH:i', $data['due_at'], AppTimezone::get())->utc();
        }

        if (empty($data['response_due_at'])) {
            $responseHours = $contract?->slaResponseHours($priority);
            if ($responseHours) {
                $data['response_due_at'] = now()->addHours($responseHours);
            }
        }

        return Ticket::create($data);
    }

    public function updateTicket(Ticket $ticket, array $data): Ticket
    {
        // Convert due_at from app timezone (datetime-local input) to UTC for storage.
        if (! empty($data['due_at']) && is_string($data['due_at'])) {
            $data['due_at'] = Carbon::createFromFormat('Y-m-d\TH:i', $data['due_at'], AppTimezone::get())->utc();
        }

        $oldAssigneeId = $ticket->getOriginal('assignee_id');
        $oldPriority = $ticket->getOriginal('priority');

        $ticket->update($data);

        // Detect assignment change
        $newAssigneeId = $ticket->assignee_id;
        if ($newAssigneeId && $newAssigneeId != $oldAssigneeId && auth()->id()) {
            $this->notificationService->notifyTicketAssigned($ticket, (int) $newAssigneeId, auth()->id());
        }

        // Detect priority change
        if ($ticket->priority && $oldPriority && $ticket->priority !== $oldPriority && $ticket->assignee_id && auth()->id()) {
            $this->notificationService->notifyPriorityChanged($ticket, $oldPriority, $ticket->priority, auth()->id());
        }

        return $ticket->fresh();
    }

    public function changeStatus(Ticket $ticket, TicketStatus $newStatus, int $changedByUserId, ?string $note = null, ?string $resolution = null): Ticket
    {
        $oldStatus = $ticket->status;

        // Validate transition
        $allowed = $oldStatus->allowedTransitions();
        if (! in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$oldStatus->label()} to {$newStatus->label()}."
            );
        }

        return DB::transaction(function () use ($ticket, $oldStatus, $newStatus, $changedByUserId, $note, $resolution) {
            // Track pending time
            $this->trackPendingTime($ticket, $oldStatus, $newStatus);

            // Set resolved_at on transition TO resolved
            if ($newStatus === TicketStatus::Resolved) {
                $ticket->resolved_at = now();
            }

            // Set closed_at on transition TO closed
            if ($newStatus === TicketStatus::Closed) {
                $ticket->closed_at = now();
                if (! $ticket->resolved_at) {
                    $ticket->resolved_at = now();
                }
            }

            // Clear resolved_at when reopening from resolved
            if ($oldStatus === TicketStatus::Resolved && $newStatus->isOpen()) {
                $ticket->resolved_at = null;
            }

            // Clear closed_at when reopening from closed
            if ($oldStatus === TicketStatus::Closed && $newStatus->isOpen()) {
                $ticket->closed_at = null;
                $ticket->resolved_at = null;
            }

            // Set resolution text; a human-provided resolution always clears the AI-drafted marker
            if ($resolution) {
                $ticket->resolution = $resolution;
                $ticket->resolution_ai_drafted = false;
            }

            $ticket->status = $newStatus;
            $ticket->save();

            // Check SLA breach on resolve/close
            if ($newStatus === TicketStatus::Resolved || $newStatus === TicketStatus::Closed) {
                $this->checkSlaBreach($ticket);
            }

            // Create status_change note
            $noteBody = $note ?? "Status changed from {$oldStatus->label()} to {$newStatus->label()}.";

            TicketNote::create([
                'ticket_id' => $ticket->id,
                'author_id' => $changedByUserId,
                'body' => $noteBody,
                'note_type' => NoteType::StatusChange,
                'is_private' => true,
                'status_from' => $oldStatus,
                'status_to' => $newStatus,
                'noted_at' => now(),
            ]);

            $this->notificationService->notifyStatusChanged($ticket, $oldStatus, $newStatus, $changedByUserId);

            return $ticket;
        });
    }

    public function addNote(Ticket $ticket, string $body, NoteType $type, bool $isPrivate, int $authorUserId, ?int $timeMinutes = null, ?int $emailId = null, ?bool $isBillable = null, ?int $contractId = null, bool $aiAuthored = false): TicketNote
    {
        // Auto-determine billability if time is logged and no explicit override
        if ($timeMinutes && $isBillable === null) {
            $isBillable = $this->defaultBillable($ticket);
        }

        $note = TicketNote::create([
            'ticket_id' => $ticket->id,
            'contract_id' => $contractId,
            'author_id' => $authorUserId,
            'email_id' => $emailId,
            'body' => $body,
            'body_html' => MarkdownRenderer::render($body),
            'note_type' => $type,
            'is_private' => $isPrivate,
            'is_billable' => $isBillable,
            'time_minutes' => $timeMinutes,
            'noted_at' => now(),
            'ai_authored' => $aiAuthored,
        ]);

        // Auto-set responded_at on first public reply
        if (! $ticket->responded_at && $type === NoteType::Reply && ! $isPrivate) {
            $ticket->update(['responded_at' => now()]);
        }

        // Link any uploaded attachments referenced in the body
        app(AttachmentService::class)->linkAttachmentsFromBody(
            $body,
            'App\\Models\\TicketNote',
            $note->id,
            $ticket->id,
        );

        // Touch ticket so updated_at reflects latest activity
        $ticket->touch();

        $this->notificationService->notifyNoteAdded($ticket, $note, $authorUserId);

        return $note;
    }

    /**
     * Add a reply from a portal user (Person, not User).
     * Uses author_id = null since the FK references users, not people.
     */
    public function addPortalReply(Ticket $ticket, Person $person, string $body): TicketNote
    {
        $note = TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'author_name' => $person->full_name,
            'who_type' => \App\Enums\WhoType::EndUser,
            'body' => $body,
            'body_html' => MarkdownRenderer::render($body),
            'note_type' => NoteType::Reply,
            'is_private' => false,
            'noted_at' => now(),
        ]);

        // Link any uploaded attachments referenced in the body
        app(AttachmentService::class)->linkAttachmentsFromBody(
            $body,
            'App\\Models\\TicketNote',
            $note->id,
            $ticket->id,
        );

        // Auto-transition PendingClient → InProgress when client replies via portal
        if ($ticket->status === TicketStatus::PendingClient) {
            $ticket->update(['status' => TicketStatus::InProgress]);

            TicketNote::create([
                'ticket_id' => $ticket->id,
                'author_id' => null,
                'author_name' => 'System',
                'who_type' => \App\Enums\WhoType::System,
                'body' => 'Status changed from Pending Client to In Progress (client replied via portal)',
                'note_type' => NoteType::StatusChange,
                'is_private' => true,
                'status_from' => TicketStatus::PendingClient,
                'status_to' => TicketStatus::InProgress,
                'noted_at' => now(),
            ]);
        }

        // Touch ticket so updated_at reflects latest activity
        $ticket->touch();

        $this->notificationService->notifyPortalReply($ticket, $note, $person);

        // AI Technician (Plan 1B): a client reply re-opens drafting. The pipeline's
        // own substance/idempotency logic (Task 10) decides whether to actually draft.
        if (\App\Support\TechnicianConfig::enabled()) {
            \App\Jobs\RunTechnicianLoop::dispatch($ticket->id);
        }

        return $note;
    }

    public function moveToClient(Ticket $ticket, int $newClientId, ?int $newContactId, int $changedByUserId): void
    {
        $oldClientId = $ticket->client_id;
        $oldContactId = $ticket->contact_id;
        $clientChanging = $oldClientId !== $newClientId;
        $contactChanging = $oldContactId !== $newContactId;

        // No-op guard
        if (! $clientChanging && ! $contactChanging) {
            return;
        }

        // Validate new contact belongs to new client (if provided)
        if ($newContactId) {
            $contact = Person::where('id', $newContactId)->where('client_id', $newClientId)->first();
            if (! $contact) {
                throw new \InvalidArgumentException('Contact does not belong to the selected client.');
            }
        }

        DB::transaction(function () use ($ticket, $newClientId, $newContactId, $oldClientId, $clientChanging, $changedByUserId) {
            $changer = User::find($changedByUserId)?->name ?? 'Unknown';

            if ($clientChanging) {
                $oldClientName = $ticket->client?->name ?? 'None';
                $oldContactName = $ticket->contact?->full_name ?? 'None';
                $oldContractName = $ticket->contract?->name ?? null;
                $newClientName = Client::find($newClientId)?->name ?? 'Unknown';

                // Detach assets belonging to the old client
                $detachedAssets = collect();
                if ($oldClientId) {
                    $oldClientAssetIds = Asset::where('client_id', $oldClientId)->pluck('id');
                    $detachedAssets = $ticket->assets()->whereIn('assets.id', $oldClientAssetIds)->get();
                    $ticket->assets()->detach($oldClientAssetIds);
                }

                // Clear contract and contact, set new client
                $ticket->contract_id = null;
                $ticket->contact_id = $newContactId;
                $ticket->client_id = $newClientId;
                $ticket->save();

                // Build detailed audit note
                $parts = ["Ticket moved from **{$oldClientName}** to **{$newClientName}** by {$changer}."];
                if ($oldContractName) {
                    $parts[] = "Contract cleared: {$oldContractName}.";
                }
                if ($detachedAssets->isNotEmpty()) {
                    $assetNames = $detachedAssets->pluck('name')->join(', ');
                    $parts[] = "Assets detached: {$assetNames}.";
                }
                if ($oldContactName !== 'None') {
                    $parts[] = "Previous contact: {$oldContactName}.";
                }
                if ($newContactId) {
                    $newContactName = Person::find($newContactId)?->full_name ?? 'Unknown';
                    $parts[] = "New contact: {$newContactName}.";
                }

                $noteBody = implode(' ', $parts);
            } else {
                // Contact-only change (same client)
                $oldContactName = $ticket->contact?->full_name ?? 'None';
                $newContactName = $newContactId ? (Person::find($newContactId)?->full_name ?? 'Unknown') : 'None';

                $ticket->contact_id = $newContactId;
                $ticket->save();

                $noteBody = "Contact changed from {$oldContactName} to {$newContactName} by {$changer}.";
            }

            TicketNote::create([
                'ticket_id' => $ticket->id,
                'author_id' => $changedByUserId,
                'body' => $noteBody,
                'body_html' => MarkdownRenderer::render($noteBody),
                'note_type' => NoteType::System,
                'is_private' => true,
                'noted_at' => now(),
            ]);
        });
    }

    public function mergeTickets(Ticket $primary, Ticket $secondary, int $mergedByUserId): void
    {
        // Guards
        if ($primary->id === $secondary->id) {
            throw new \InvalidArgumentException('Cannot merge a ticket into itself.');
        }

        if ($primary->client_id !== $secondary->client_id) {
            throw new \InvalidArgumentException('Cannot merge tickets from different clients.');
        }

        if ($secondary->childTickets()->exists()) {
            throw new \InvalidArgumentException('Cannot merge a ticket that has merged tickets. Merge those first.');
        }

        if ($secondary->parent_ticket_id) {
            throw new \InvalidArgumentException('This ticket has already been merged.');
        }

        DB::transaction(function () use ($primary, $secondary, $mergedByUserId) {
            // Pessimistic lock
            $primary = Ticket::lockForUpdate()->find($primary->id);
            $secondary = Ticket::lockForUpdate()->find($secondary->id);

            // Count entities before moving
            $noteCount = TicketNote::where('ticket_id', $secondary->id)->count();
            $callCount = PhoneCall::where('ticket_id', $secondary->id)->count();
            $emailCount = Email::where('ticket_id', $secondary->id)->count();

            // Move notes
            TicketNote::where('ticket_id', $secondary->id)->update(['ticket_id' => $primary->id]);

            // Move phone calls
            PhoneCall::where('ticket_id', $secondary->id)->update(['ticket_id' => $primary->id]);

            // Move emails
            Email::where('ticket_id', $secondary->id)->update(['ticket_id' => $primary->id]);

            // Move assets (skip dupes)
            $primaryAssetIds = $primary->assets()->pluck('assets.id')->toArray();
            $secondaryAssets = $secondary->assets()->get();
            $movedAssetCount = 0;
            foreach ($secondaryAssets as $asset) {
                if (! in_array($asset->id, $primaryAssetIds)) {
                    $primary->assets()->attach($asset->id, ['is_primary' => false]);
                    $movedAssetCount++;
                }
            }
            $secondary->assets()->detach();

            // Copy contact if primary has none
            if (! $primary->contact_id && $secondary->contact_id) {
                $primary->contact_id = $secondary->contact_id;
                $primary->save();
            }

            // Track pending time before closing
            $this->trackPendingTime($secondary, $secondary->status, TicketStatus::Closed);

            // Close secondary (direct save — intentionally avoids TicketObserver side effects)
            $secondary->parent_ticket_id = $primary->id;
            $secondary->status = TicketStatus::Closed;
            $secondary->closed_at = now();
            if (! $secondary->resolved_at) {
                $secondary->resolved_at = now();
            }
            $secondary->resolution = "Merged into {$primary->display_id}.";
            $secondary->save();

            // Build audit notes with entity counts
            $merger = User::find($mergedByUserId)?->name ?? 'Unknown';

            $movedParts = [];
            if ($noteCount) {
                $movedParts[] = "{$noteCount} ".($noteCount === 1 ? 'note' : 'notes');
            }
            if ($callCount) {
                $movedParts[] = "{$callCount} ".($callCount === 1 ? 'call' : 'calls');
            }
            if ($emailCount) {
                $movedParts[] = "{$emailCount} ".($emailCount === 1 ? 'email' : 'emails');
            }
            $movedSummary = $movedParts ? ' Moved: '.implode(', ', $movedParts).'.' : '';

            $primaryNote = "**Ticket merged:** {$secondary->display_id} — *{$secondary->subject}* merged into this ticket by {$merger}.{$movedSummary}";

            // Carry over the secondary's description so the user's original
            // message stays visible in the primary's timeline (and in the AI
            // context built from notes). Strip tags to avoid mangling markdown
            // rendering when the source was HTML (e.g. emailed-in tickets).
            $secondaryDescription = trim(strip_tags($secondary->description ?? ''));
            if ($secondaryDescription !== '') {
                $maxLen = 8000;
                if (mb_strlen($secondaryDescription) > $maxLen) {
                    $secondaryDescription = mb_substr($secondaryDescription, 0, $maxLen)."\n\n[truncated]";
                }
                $quoted = '> '.str_replace("\n", "\n> ", $secondaryDescription);
                $primaryNote .= "\n\n**Original message from {$secondary->display_id}:**\n\n{$quoted}";
            }

            TicketNote::create([
                'ticket_id' => $primary->id,
                'author_id' => $mergedByUserId,
                'body' => $primaryNote,
                'body_html' => MarkdownRenderer::render($primaryNote),
                'note_type' => NoteType::System,
                'is_private' => true,
                'noted_at' => now(),
            ]);

            $allParts = $movedParts;
            if ($movedAssetCount) {
                $allParts[] = "{$movedAssetCount} ".($movedAssetCount === 1 ? 'asset' : 'assets');
            }
            $allMovedSummary = $allParts ? ' '.implode(', ', $allParts).' moved.' : '';

            $secondaryNote = "**Merged into {$primary->display_id}** by {$merger}.{$allMovedSummary}";
            TicketNote::create([
                'ticket_id' => $secondary->id,
                'author_id' => $mergedByUserId,
                'body' => $secondaryNote,
                'body_html' => MarkdownRenderer::render($secondaryNote),
                'note_type' => NoteType::System,
                'is_private' => true,
                'noted_at' => now(),
            ]);
        });
    }

    public function assignTicket(Ticket $ticket, ?int $userId, int $changedByUserId): Ticket
    {
        $newAssignee = $userId ? User::find($userId)?->name ?? 'Unknown' : 'Unassigned';
        $changer = User::find($changedByUserId)?->name ?? 'Unknown';

        $ticket->update(['assignee_id' => $userId]);

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $changedByUserId,
            'body' => "Assigned to {$newAssignee} by {$changer}.",
            'note_type' => NoteType::StatusChange,
            'is_private' => true,
            'noted_at' => now(),
        ]);

        if ($userId) {
            $this->notificationService->notifyTicketAssigned($ticket, $userId, $changedByUserId);
        }

        return $ticket->fresh();
    }

    public function getTicketList(array $filters): LengthAwarePaginator
    {
        $query = Ticket::query()
            ->with(['client', 'assignee', 'latestTriageRun', 'assets'])
            ->withSum('notes', 'time_minutes')
            ->withCount('triageRuns');

        // Status filter
        if (! empty($filters['show_closed'])) {
            // Show all statuses
        } elseif (($filters['status'] ?? '') === 'needs_action') {
            $query->whereIn('status', [TicketStatus::New, TicketStatus::InProgress]);
        } elseif (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->open();
        }

        // Assignee filter
        if (isset($filters['assignee_id'])) {
            if ($filters['assignee_id'] === 'all') {
                // No filter
            } elseif ($filters['assignee_id'] === 'unassigned') {
                $query->whereNull('assignee_id');
            } else {
                $query->where('assignee_id', $filters['assignee_id']);
            }
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (! empty($filters['contract_id'])) {
            $query->where('contract_id', $filters['contract_id']);
        }

        if (! empty($filters['contact_id'])) {
            $query->where('contact_id', $filters['contact_id']);
        }

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (! empty($filters['overdue'])) {
            $query->overdue();
        }

        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        if (! empty($filters['asset_id'])) {
            $query->whereHas('assets', fn ($q) => $q->where('assets.id', $filters['asset_id']));
        }

        // Configurable sort with allowlisted columns
        $allowedSorts = [
            'priority' => 'priority_order',
            'status' => 'tickets.status',
            'client' => 'clients.name',
            'assignee' => 'users.name',
            'updated_at' => 'tickets.updated_at',
            'opened_at' => 'tickets.opened_at',
            'due_at' => 'tickets.due_at',
            'created_at' => 'tickets.created_at',
        ];

        $sortKey = $filters['sort'] ?? 'priority';
        $sortColumn = $allowedSorts[$sortKey] ?? 'priority_order';
        $sortDirection = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        // LEFT JOIN only when sorting by relation columns
        if ($sortColumn === 'clients.name') {
            $query->select('tickets.*')->leftJoin('clients', 'tickets.client_id', '=', 'clients.id');
        } elseif ($sortColumn === 'users.name') {
            $query->select('tickets.*')->leftJoin('users', 'tickets.assignee_id', '=', 'users.id');
        }

        // Nulls last for due_at sort
        if ($sortColumn === 'tickets.due_at') {
            $query->orderByRaw('tickets.due_at IS NULL');
        }

        $query->orderBy($sortColumn, $sortDirection);

        // Stable secondary sort
        if ($sortColumn !== 'priority_order') {
            $query->orderBy('priority_order', 'asc');
        }
        if ($sortColumn !== 'tickets.due_at') {
            $query->orderByRaw('tickets.due_at IS NULL');
            $query->orderBy('tickets.due_at', 'asc');
        }

        return $query
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Build the ticket activity timeline — notes, phone calls, and AI
     * conversations merged into a single newest-first stream, paginated for
     * display.
     *
     * The three sources live in different tables, so their chronological order
     * has to be resolved in memory. To keep that affordable on long-lived
     * tickets, only the bare rows are pulled up front for sorting; the heavy
     * display relations (note authors/contracts/attachments/emails, call
     * parties, chat messages) are eager-loaded for just the page being
     * rendered — not for every note the ticket has ever accumulated.
     */
    public function buildTimeline(Ticket $ticket, int $perPage = 25): LengthAwarePaginator
    {
        $items = $ticket->notes()->get()
            ->concat($ticket->phoneCalls()->get())
            ->concat(
                AssistantConversation::where('context_type', 'ticket')
                    ->where('context_id', $ticket->id)
                    ->get()
            )
            ->sortByDesc(fn ($item) => $this->timelineSortKey($item))
            ->values();

        $page = Paginator::resolveCurrentPage();

        $paginator = new LengthAwarePaginatorInstance(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );

        $this->loadTimelineRelations($paginator->getCollection());

        return $paginator->withQueryString();
    }

    /**
     * The moment a timeline item sorts by: a call's start, a conversation's
     * creation, or a note's (possibly back-dated) noted_at.
     */
    private function timelineSortKey(object $item): ?Carbon
    {
        if ($item instanceof PhoneCall) {
            return $item->started_at;
        }

        if ($item instanceof AssistantConversation) {
            return $item->created_at;
        }

        return $item->noted_at;
    }

    /**
     * Eager-load display relations for the current timeline page only, grouped
     * by model type so each relation set costs a single query.
     */
    private function loadTimelineRelations(Collection $items): void
    {
        $items->groupBy(fn ($item) => $item::class)
            ->each(function (Collection $group, string $class): void {
                $models = new EloquentCollection($group->all());

                match ($class) {
                    TicketNote::class => $models->load(['author', 'contract', 'attachments', 'email']),
                    PhoneCall::class => $models->load(['answeredBy', 'person']),
                    AssistantConversation::class => $models->load(['user:id,name', 'messages']),
                    default => null,
                };
            });
    }

    /**
     * Parse time input like "1h 30m", "45m", "2h", or bare "45" (= minutes).
     */
    public function parseTimeInput(?string $input): ?int
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $input = trim($input);

        // Bare number = minutes
        if (is_numeric($input)) {
            return max(1, (int) $input);
        }

        $hours = 0;
        $minutes = 0;

        if (preg_match('/(\d+)\s*h/i', $input, $m)) {
            $hours = (int) $m[1];
        }

        if (preg_match('/(\d+)\s*m/i', $input, $m)) {
            $minutes = (int) $m[1];
        }

        $total = ($hours * 60) + $minutes;

        return $total > 0 ? $total : null;
    }

    /**
     * Determine the default billability for time logged on a ticket.
     * Break/fix and unclassified tickets default to billable.
     * Managed services tickets default to non-billable.
     */
    public function defaultBillable(Ticket $ticket): bool
    {
        $triageRun = $ticket->latestTriageRun;

        if (! $triageRun) {
            return true;
        }

        $classification = $triageRun->stageResult('classification');

        if (! $classification) {
            return true;
        }

        $workCovered = $classification['work_covered_by_managed'] ?? false;

        return ! $workCovered;
    }

    private function trackPendingTime(Ticket $ticket, TicketStatus $oldStatus, TicketStatus $newStatus): void
    {
        // Exiting a pending state — accumulate time
        if ($oldStatus->isPending() && ! $newStatus->isPending() && $ticket->pending_since) {
            $pendingMinutes = $ticket->pending_since->diffInMinutes(now());
            $ticket->total_pending_minutes += $pendingMinutes;
            $ticket->pending_since = null;
        }

        // Entering a pending state — start tracking
        if ($newStatus->isPending() && ! $oldStatus->isPending()) {
            $ticket->pending_since = now();
        }
    }

    /**
     * Return all ticket IDs matching the given filters (no pagination).
     */
    public function getFilteredTicketIds(array $filters): array
    {
        $query = Ticket::query();

        if (! empty($filters['show_closed'])) {
            // Show all statuses
        } elseif (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->open();
        }

        if (isset($filters['assignee_id'])) {
            if ($filters['assignee_id'] === 'all') {
                // No filter
            } elseif ($filters['assignee_id'] === 'unassigned') {
                $query->whereNull('assignee_id');
            } else {
                $query->where('assignee_id', $filters['assignee_id']);
            }
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (! empty($filters['contract_id'])) {
            $query->where('contract_id', $filters['contract_id']);
        }

        if (! empty($filters['contact_id'])) {
            $query->where('contact_id', $filters['contact_id']);
        }

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (! empty($filters['overdue'])) {
            $query->overdue();
        }

        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        if (! empty($filters['asset_id'])) {
            $query->whereHas('assets', fn ($q) => $q->where('assets.id', $filters['asset_id']));
        }

        return $query->pluck('id')->all();
    }

    // ── Bulk Actions ──

    public function bulkClose(array $ticketIds, int $userId): int
    {
        $count = 0;
        foreach (Ticket::whereIn('id', $ticketIds)->get() as $ticket) {
            if ($ticket->status === TicketStatus::Closed) {
                continue;
            }
            try {
                $this->changeStatus($ticket, TicketStatus::Closed, $userId);
                $count++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Bulk] Failed to close ticket', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    public function bulkReassign(array $ticketIds, int $assigneeId, int $userId): int
    {
        $count = 0;
        foreach (Ticket::whereIn('id', $ticketIds)->get() as $ticket) {
            try {
                $this->assignTicket($ticket, $assigneeId, $userId);
                $count++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Bulk] Failed to reassign ticket', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    public function bulkChangePriority(array $ticketIds, TicketPriority $priority): int
    {
        return Ticket::whereIn('id', $ticketIds)->update([
            'priority' => $priority,
            'priority_order' => $priority->sortOrder(),
        ]);
    }

    private function checkSlaBreach(Ticket $ticket): void
    {
        // opened_at guarded too: the sign-safe form below calls diffInMinutes ON opened_at,
        // and the codebase treats opened_at as nullable (cf. net_elapsed_minutes) — no SLA
        // window without it.
        if (! $ticket->due_at || ! $ticket->opened_at || $ticket->sla_breach_recorded_at) {
            return;
        }

        $netElapsed = $ticket->net_elapsed_minutes;
        // Sign-safe (psa-lqlu): the SLA window is (due − opened). due_at->diffInMinutes(opened_at)
        // is NEGATIVE in Carbon 3, so `netElapsed > negative` recorded a breach on EVERY check.
        $slaMinutes = (int) $ticket->opened_at->diffInMinutes($ticket->due_at);

        if ($netElapsed > $slaMinutes) {
            $ticket->update(['sla_breach_recorded_at' => now()]);
        }
    }
}
