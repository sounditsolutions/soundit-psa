<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\NoteType;
use App\Enums\TicketStatus;
use App\Models\ContractActivity;
use App\Models\Email;
use App\Models\Invoice;
use App\Models\PhoneCall;
use App\Models\TicketNote;
use App\Models\TriageRun;
use App\Support\ActivityItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ActivityStreamService
{
    private const SOURCE_MAP = [
        'ticket' => 'queryTicketNotes',
        'call' => 'queryPhoneCalls',
        'email' => 'queryEmails',
        'contract' => 'queryContractActivities',
        'triage' => 'queryTriageRuns',
        'invoice' => 'queryInvoices',
    ];

    private const PERSON_SOURCES = ['ticket', 'call', 'email'];

    /**
     * Get a merged, sorted activity stream (dashboard — unscoped).
     */
    public function getStream(?Carbon $before = null, int $limit = 30, array $types = []): Collection
    {
        return $this->buildStream($before, $limit, $types);
    }

    /**
     * Get activity stream scoped to a single client.
     */
    public function getClientStream(int $clientId, ?Carbon $before = null, int $limit = 30, array $types = []): Collection
    {
        return $this->buildStream($before, $limit, $types, clientId: $clientId);
    }

    /**
     * Get activity stream scoped to a single person (tickets, calls, emails only).
     */
    public function getPersonStream(int $personId, ?Carbon $before = null, int $limit = 30, array $types = []): Collection
    {
        $personTypes = empty($types) ? self::PERSON_SOURCES : array_intersect($types, self::PERSON_SOURCES);

        return $this->buildStream($before, $limit, $personTypes, personId: $personId);
    }

    private function buildStream(?Carbon $before, int $limit, array $types, ?int $clientId = null, ?int $personId = null): Collection
    {
        $activeSources = empty($types)
            ? self::SOURCE_MAP
            : array_intersect_key(self::SOURCE_MAP, array_flip($types));

        $numSources = count($activeSources);
        if ($numSources === 0) {
            return collect();
        }

        $perSource = max(15, (int) ceil($limit * 3 / $numSources));

        $items = collect();
        foreach ($activeSources as $type => $method) {
            $items = $items->concat($this->{$method}($before, $perSource, $clientId, $personId));
        }

        return $items
            ->sortByDesc(fn (ActivityItem $item) => $item->timestamp)
            ->take($limit)
            ->values();
    }

    /**
     * Get items newer than the given timestamp (for polling).
     */
    public function getStreamSince(Carbon $since, array $types = []): Collection
    {
        $activeSources = empty($types)
            ? self::SOURCE_MAP
            : array_intersect_key(self::SOURCE_MAP, array_flip($types));

        $items = collect();
        foreach ($activeSources as $type => $method) {
            $items = $items->concat($this->{$method . 'Since'}($since));
        }

        return $items
            ->sortByDesc(fn (ActivityItem $item) => $item->timestamp)
            ->take(100)
            ->values();
    }

    /**
     * Get stat card data for the dashboard.
     */
    public function getDashboardStats(): array
    {
        return [
            'needs_response' => \App\Models\Ticket::whereIn('status', [
                TicketStatus::New,
                TicketStatus::InProgress,
            ])->count(),
            'missed_calls' => PhoneCall::unfollowedUp()->count(),
            'outstanding_invoices' => (float) Invoice::whereIn('status', [InvoiceStatus::Posted, InvoiceStatus::Synced])->sum('total'),
        ];
    }

    // ── Scoping helper ──

    private function applyScope($query, string $sourceType, ?int $clientId, ?int $personId)
    {
        if ($clientId) {
            return match ($sourceType) {
                'ticket', 'triage' => $query->whereHas('ticket', fn ($q) => $q->where('client_id', $clientId)),
                'call', 'email', 'invoice' => $query->where('client_id', $clientId),
                'contract' => $query->whereHas('contract', fn ($q) => $q->where('client_id', $clientId)),
            };
        }

        if ($personId) {
            return match ($sourceType) {
                'ticket' => $query->whereHas('ticket', fn ($q) => $q->where('contact_id', $personId)),
                'call' => $query->where('person_id', $personId),
                'email' => $query->where('person_id', $personId),
                default => $query,
            };
        }

        return $query;
    }

    // ── Private query methods ──

    private function queryTicketNotes(?Carbon $before, int $limit, ?int $clientId = null, ?int $personId = null): Collection
    {
        $query = TicketNote::with(['ticket.client', 'author'])
            ->whereNotIn('note_type', NoteType::systemGenerated())
            ->whereNull('email_id');

        // Dashboard: public notes only. Client/person scoped: include private notes for staff context.
        if (!$clientId && !$personId) {
            $query->where('is_private', false);
        }

        $this->applyScope($query, 'ticket', $clientId, $personId);

        return $query
            ->when($before, fn ($q) => $q->where('noted_at', '<', $before))
            ->orderByDesc('noted_at')
            ->limit($limit)
            ->get()
            ->map(fn ($note) => $this->wrapItem($note, 'ticket'));
    }

    private function queryTicketNotesSince(Carbon $since): Collection
    {
        return TicketNote::with(['ticket.client', 'author'])
            ->where('is_private', false)
            ->whereNotIn('note_type', NoteType::systemGenerated())
            ->whereNull('email_id')
            ->where('noted_at', '>', $since)
            ->orderByDesc('noted_at')
            ->limit(100)
            ->get()
            ->map(fn ($note) => $this->wrapItem($note, 'ticket'));
    }

    private function queryPhoneCalls(?Carbon $before, int $limit, ?int $clientId = null, ?int $personId = null): Collection
    {
        $query = PhoneCall::with(['client', 'answeredBy', 'person', 'ticket']);
        $this->applyScope($query, 'call', $clientId, $personId);

        return $query
            ->when($before, fn ($q) => $q->where('started_at', '<', $before))
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn ($call) => $this->wrapItem($call, 'call'));
    }

    private function queryPhoneCallsSince(Carbon $since): Collection
    {
        return PhoneCall::with(['client', 'answeredBy', 'person', 'ticket'])
            ->where('started_at', '>', $since)
            ->orderByDesc('started_at')
            ->limit(100)
            ->get()
            ->map(fn ($call) => $this->wrapItem($call, 'call'));
    }

    private function queryEmails(?Carbon $before, int $limit, ?int $clientId = null, ?int $personId = null): Collection
    {
        $query = Email::with(['client', 'person', 'ticket']);
        $this->applyScope($query, 'email', $clientId, $personId);

        return $query
            ->when($before, fn ($q) => $q->where('received_at', '<', $before))
            ->orderByDesc('received_at')
            ->limit($limit)
            ->get()
            ->map(fn ($email) => $this->wrapItem($email, 'email'));
    }

    private function queryEmailsSince(Carbon $since): Collection
    {
        return Email::with(['client', 'person', 'ticket'])
            ->where('received_at', '>', $since)
            ->orderByDesc('received_at')
            ->limit(100)
            ->get()
            ->map(fn ($email) => $this->wrapItem($email, 'email'));
    }

    private function queryContractActivities(?Carbon $before, int $limit, ?int $clientId = null, ?int $personId = null): Collection
    {
        $query = ContractActivity::with(['contract.client', 'user']);
        $this->applyScope($query, 'contract', $clientId, $personId);

        return $query
            ->when($before, fn ($q) => $q->where('created_at', '<', $before))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($activity) => $this->wrapItem($activity, 'contract'));
    }

    private function queryContractActivitiesSince(Carbon $since): Collection
    {
        return ContractActivity::with(['contract.client', 'user'])
            ->where('created_at', '>', $since)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($activity) => $this->wrapItem($activity, 'contract'));
    }

    private function queryTriageRuns(?Carbon $before, int $limit, ?int $clientId = null, ?int $personId = null): Collection
    {
        $query = TriageRun::with(['ticket.client'])
            ->whereIn('status', ['completed', 'failed']);
        $this->applyScope($query, 'triage', $clientId, $personId);

        return $query
            ->when($before, fn ($q) => $q->where('started_at', '<', $before))
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn ($run) => $this->wrapItem($run, 'triage'));
    }

    private function queryTriageRunsSince(Carbon $since): Collection
    {
        return TriageRun::with(['ticket.client'])
            ->whereIn('status', ['completed', 'failed'])
            ->where('started_at', '>', $since)
            ->orderByDesc('started_at')
            ->limit(100)
            ->get()
            ->map(fn ($run) => $this->wrapItem($run, 'triage'));
    }

    private function queryInvoices(?Carbon $before, int $limit, ?int $clientId = null, ?int $personId = null): Collection
    {
        $query = Invoice::with(['client', 'contract']);
        $this->applyScope($query, 'invoice', $clientId, $personId);

        return $query
            ->when($before, fn ($q) => $q->where('created_at', '<', $before))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($invoice) => $this->wrapItem($invoice, 'invoice'));
    }

    private function queryInvoicesSince(Carbon $since): Collection
    {
        return Invoice::with(['client', 'contract'])
            ->where('created_at', '>', $since)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($invoice) => $this->wrapItem($invoice, 'invoice'));
    }

    // ── Item wrapping ──

    private function wrapItem(object $model, string $type): ActivityItem
    {
        return new ActivityItem(
            model: $model,
            type: $type,
            timestamp: $this->resolveTimestamp($model),
            url: $this->resolveUrl($model, $type),
        );
    }

    private function resolveTimestamp(object $model): Carbon
    {
        return match (true) {
            $model instanceof TicketNote => $model->noted_at,
            $model instanceof PhoneCall => $model->started_at,
            $model instanceof Email => $model->received_at,
            $model instanceof ContractActivity => $model->created_at,
            $model instanceof TriageRun => $model->started_at,
            $model instanceof Invoice => $model->created_at,
            default => $model->created_at,
        };
    }

    private function resolveUrl(object $model, string $type): string
    {
        return match ($type) {
            'ticket' => $model->ticket ? route('tickets.show', $model->ticket) : '#',
            'call' => route('calls.show', $model),
            'email' => route('emails.show', $model),
            'contract' => $model->contract ? route('contracts.show', $model->contract) : '#',
            'triage' => $model->ticket ? route('tickets.show', $model->ticket) : '#',
            'invoice' => route('invoices.show', $model),
            default => '#',
        };
    }
}
