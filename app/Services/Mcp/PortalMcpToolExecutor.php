<?php

namespace App\Services\Mcp;

use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Enums\WhoType;
use App\Models\Asset;
use App\Models\Person;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Tool executor for the portal MCP server. Client-locked, on behalf of one
 * authenticated portal Person.
 *
 * SECURITY MODEL — mirrors App\Services\Portal\PortalChatbotToolExecutor:
 *   - Scope (client_id, company-wide access, contact id) is bound at
 *     construction from the resolved Person and can NEVER come from tool input.
 *   - Every read is filtered by the Person's client_id; by-id lookups re-verify
 *     against the same scope and return a not-found error rather than another
 *     client's data. Tickets honour company-wide access (own tickets only
 *     otherwise), exactly like the portal UI.
 *   - Writes force the actor: new tickets get contact_id = this Person and
 *     source = Portal; replies go through TicketService::addPortalReply (author
 *     recorded as the end user). The caller cannot target another contact.
 *
 * Unlike the chatbot executor this one exposes two write tools (create_ticket,
 * add_my_ticket_reply); both are constrained to the same scope.
 */
class PortalMcpToolExecutor
{
    private readonly int $clientId;

    private readonly bool $companyWideAccess;

    public function __construct(
        private readonly Person $person,
    ) {
        $this->clientId = (int) $person->client_id;
        $this->companyWideAccess = (bool) $person->company_wide_access;

        if ($this->clientId <= 0) {
            throw new \RuntimeException('PortalMcpToolExecutor requires a Person with a client scope.');
        }
    }

    /**
     * Dispatch a tool call. Returns a JSON-able array. An `error` key marks a
     * failed call (surfaced to the model as isError); it never throws to the
     * model, so internals are not leaked.
     *
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $input): array
    {
        try {
            return match ($toolName) {
                'list_my_open_tickets' => $this->listMyOpenTickets($input),
                'get_my_ticket' => $this->getMyTicket($input),
                'search_my_tickets' => $this->searchMyTickets($input),
                'create_ticket' => $this->createTicket($input),
                'add_my_ticket_reply' => $this->addMyTicketReply($input),
                'list_my_assets' => $this->listMyAssets($input),
                default => ['error' => "Unknown tool: {$toolName}"],
            };
        } catch (\Throwable $e) {
            Log::warning('[MCP/portal] Tool execution failed', [
                'tool' => $toolName,
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'That request could not be completed.'];
        }
    }

    /** @return array<string, mixed> */
    private function listMyOpenTickets(array $input): array
    {
        $limit = $this->clampLimit($input['limit'] ?? 25, 50);

        $tickets = $this->ticketScope()
            ->open()
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return [
            'count' => $tickets->count(),
            'tickets' => $tickets->map(fn (Ticket $t) => $this->ticketSummary($t))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function getMyTicket(array $input): array
    {
        $id = $this->positiveInt($input['ticket_id'] ?? null);
        if ($id === null) {
            return ['error' => 'A ticket_id is required.'];
        }

        $ticket = $this->ticketScope()->whereKey($id)->first();
        if (! $ticket) {
            return ['error' => 'Ticket not found.'];
        }

        $notes = $ticket->notes()
            ->portalVisible()
            ->orderBy('noted_at')
            ->limit(50)
            ->get()
            ->map(fn ($note) => [
                'from' => $this->noteAuthorLabel($note->who_type),
                'date' => optional($note->noted_at)->toDateTimeString(),
                'message' => $this->clean($note->body, 2000),
            ])
            ->all();

        return array_merge($this->ticketSummary($ticket), [
            'description' => $this->clean($ticket->description, 3000),
            'notes' => $notes,
        ]);
    }

    /** @return array<string, mixed> */
    private function searchMyTickets(array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'A query is required.'];
        }

        $limit = $this->clampLimit($input['limit'] ?? 25, 50);

        $tickets = $this->ticketScope()
            ->search($query)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return [
            'count' => $tickets->count(),
            'tickets' => $tickets->map(fn (Ticket $t) => $this->ticketSummary($t))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function createTicket(array $input): array
    {
        $subject = trim((string) ($input['subject'] ?? ''));
        if ($subject === '') {
            return ['error' => 'A subject is required.'];
        }

        $body = trim((string) ($input['body'] ?? ''));
        if ($body === '') {
            return ['error' => 'A body is required.'];
        }

        $urgency = strtolower(trim((string) ($input['urgency'] ?? 'normal')));
        // Two urgency levels, mirroring the portal UI: normal → P3, urgent → P2.
        $priority = $urgency === 'urgent' ? TicketPriority::P2 : TicketPriority::P3;

        $ticket = app(TicketService::class)->createTicket([
            'client_id' => $this->clientId,
            'contact_id' => $this->person->id,
            'subject' => $subject,
            'description' => $body,
            'source' => TicketSource::Portal,
            'type' => TicketType::ServiceRequest,
            'priority' => $priority->value,
            'status' => TicketStatus::New,
            'opened_at' => now(),
        ], null);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'status' => $ticket->status?->label(),
            'priority' => $ticket->priority?->label(),
            'message' => 'Ticket created.',
        ];
    }

    /** @return array<string, mixed> */
    private function addMyTicketReply(array $input): array
    {
        $id = $this->positiveInt($input['ticket_id'] ?? null);
        if ($id === null) {
            return ['error' => 'A ticket_id is required.'];
        }

        $body = trim((string) ($input['body'] ?? ''));
        if ($body === '') {
            return ['error' => 'A body is required.'];
        }

        $ticket = $this->ticketScope()->whereKey($id)->first();
        if (! $ticket) {
            return ['error' => 'Ticket not found.'];
        }

        $note = app(TicketService::class)->addPortalReply($ticket, $this->person, $body);

        return [
            'success' => true,
            'ticket_id' => $ticket->id,
            'ticket_display_id' => $ticket->display_id,
            'note_id' => $note->id,
            'status' => $ticket->fresh()?->status?->label(),
            'message' => 'Reply added.',
        ];
    }

    /** @return array<string, mixed> */
    private function listMyAssets(array $input): array
    {
        $limit = $this->clampLimit($input['limit'] ?? 50, 100);

        $assets = Asset::where('client_id', $this->clientId)
            ->where('is_active', true)
            ->orderBy('hostname')
            ->limit($limit)
            ->get();

        return [
            'count' => $assets->count(),
            'devices' => $assets->map(fn (Asset $a) => [
                'name' => $a->name,
                'hostname' => $a->hostname,
                'type' => $a->asset_type,
                'operating_system' => $a->os,
                'serial_number' => $a->serial_number,
            ])->all(),
        ];
    }

    // ── Helpers ──

    /**
     * Base ticket query, scoped to the client and — unless this contact has
     * company-wide access — to the tickets they are the contact on. Mirrors
     * PortalChatbotToolExecutor::ticketScope() and the portal controllers.
     *
     * @return Builder<Ticket>
     */
    private function ticketScope(): Builder
    {
        $query = Ticket::query()->where('client_id', $this->clientId);

        if (! $this->companyWideAccess) {
            // A null contact id with no company-wide access matches nothing (safe).
            $query->where('contact_id', $this->person->id);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function ticketSummary(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'display_id' => $ticket->display_id,
            'subject' => $this->clean($ticket->subject, 300),
            'status' => $ticket->status?->label(),
            'priority' => $ticket->priority?->label(),
            'opened' => optional($ticket->created_at)->toDateString(),
            'last_updated' => optional($ticket->updated_at)->toDateString(),
        ];
    }

    private function noteAuthorLabel(?WhoType $who): string
    {
        return match ($who) {
            WhoType::EndUser => 'Client',
            WhoType::Agent => 'Support',
            default => 'System',
        };
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $n = (int) $value;

            return $n > 0 ? $n : null;
        }

        return null;
    }

    private function clampLimit(mixed $value, int $max): int
    {
        $n = (int) $value;
        if ($n < 1) {
            $n = 1;
        }

        return min($n, $max);
    }

    /**
     * Neutralise a free-text field before it reaches the model: strip HTML and
     * collapse whitespace, then cap the length. The data is the caller's own
     * client scope, so we do not credential-redact (the scope lock bounds the
     * blast radius). Mirrors PortalChatbotToolExecutor::clean().
     */
    private function clean(?string $text, int $cap): string
    {
        if ($text === null) {
            return '';
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');

        return mb_strlen($text) > $cap
            ? mb_substr($text, 0, $cap).'…'
            : $text;
    }
}
