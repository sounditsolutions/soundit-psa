<?php

namespace App\Services\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Asset;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;

/**
 * Read-only, client-locked tool executor for the client-portal AI chatbot
 * (psa-2ab).
 *
 * SECURITY MODEL — mirrors App\Services\Triage\TriageToolExecutor:
 *   - The client scope is bound at construction from the authenticated portal
 *     Person's client_id and can NEVER be overridden by tool input.
 *   - Every query is filtered by `client_id = $this->clientId`. By-id lookups
 *     are re-verified against the client and return a not-found error rather
 *     than another client's data.
 *   - Only the data a portal contact can already see in the portal UI is
 *     exposed, and with the same visibility rules:
 *       * Tickets — gated by company-wide access (own tickets only otherwise).
 *       * Invoices — Posted / Synced / Paid only; never cost or margin.
 *       * Devices — active assets only.
 *       * Agreements — active contracts only; prepay balance only for
 *         company-wide contacts on hours-based contracts.
 *   - There are NO write tools. The chatbot cannot mutate anything.
 */
class PortalChatbotToolExecutor
{
    /** Invoice statuses a portal contact may see (mirrors PortalInvoiceController). */
    private const PORTAL_INVOICE_STATUSES = [
        InvoiceStatus::Posted,
        InvoiceStatus::Synced,
        InvoiceStatus::Paid,
    ];

    public function __construct(
        private readonly int $clientId,
        private readonly bool $companyWideAccess = false,
        private readonly ?int $personId = null,
    ) {
        if ($this->clientId <= 0) {
            throw new \RuntimeException('PortalChatbotToolExecutor requires a client scope.');
        }
    }

    /**
     * Dispatch a tool call. Returns a JSON-able array (the AiClient tool loop
     * encodes it). Never throws to the model — failures return a generic error
     * so internals are not leaked.
     */
    public function execute(string $toolName, array $input): mixed
    {
        try {
            return match ($toolName) {
                'get_account_summary' => $this->getAccountSummary(),
                'list_tickets' => $this->listTickets($input),
                'get_ticket' => $this->getTicket($input),
                'list_invoices' => $this->listInvoices($input),
                'list_devices' => $this->listDevices($input),
                'list_agreements' => $this->listAgreements(),
                default => ['error' => "Unknown tool: {$toolName}"],
            };
        } catch (\Throwable $e) {
            Log::warning('[PortalChatbot] Tool execution failed', [
                'tool' => $toolName,
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);

            return ['error' => 'That lookup could not be completed.'];
        }
    }

    private function getAccountSummary(): array
    {
        return [
            'open_tickets' => $this->ticketScope()->open()->count(),
            'unpaid_invoices' => Invoice::where('client_id', $this->clientId)
                ->whereIn('status', [InvoiceStatus::Posted, InvoiceStatus::Synced])
                ->count(),
            'devices' => Asset::where('client_id', $this->clientId)->where('is_active', true)->count(),
            'active_agreements' => Contract::where('client_id', $this->clientId)->active()->count(),
        ];
    }

    private function listTickets(array $input): array
    {
        $limit = $this->clampLimit($input['limit'] ?? 15, 30);
        $status = strtolower((string) ($input['status'] ?? 'open'));

        $query = $this->ticketScope();

        if ($status === 'open') {
            $query->open();
        } elseif (in_array($status, ['closed', 'resolved'], true)) {
            $query->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed]);
        }
        // 'all' (or anything else) → no status filter.

        $tickets = $query->orderByDesc('updated_at')->limit($limit)->get();

        return [
            'count' => $tickets->count(),
            'tickets' => $tickets->map(fn (Ticket $t) => $this->ticketSummary($t))->all(),
        ];
    }

    private function getTicket(array $input): array
    {
        $id = (int) ($input['ticket_id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'A ticket_id is required.'];
        }

        $ticket = $this->ticketScope()->where('id', $id)->first();
        if (! $ticket) {
            return ['error' => 'Ticket not found.'];
        }

        $notes = $ticket->notes()
            ->portalVisible()
            ->orderBy('noted_at')
            ->limit(30)
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

    private function listInvoices(array $input): array
    {
        $limit = $this->clampLimit($input['limit'] ?? 15, 30);

        $invoices = Invoice::where('client_id', $this->clientId)
            ->whereIn('status', self::PORTAL_INVOICE_STATUSES)
            ->orderByDesc('invoice_date')
            ->limit($limit)
            ->get();

        return [
            'count' => $invoices->count(),
            'invoices' => $invoices->map(fn (Invoice $inv) => [
                'invoice_number' => $inv->invoice_number,
                'date' => optional($inv->invoice_date)->toDateString(),
                'due_date' => optional($inv->due_date)->toDateString(),
                'total' => number_format((float) $inv->total, 2),
                'status' => $inv->status->label(),
                'overdue' => $inv->status !== InvoiceStatus::Paid
                    && $inv->due_date !== null
                    && $inv->due_date->isPast(),
            ])->all(),
        ];
    }

    private function listDevices(array $input): array
    {
        $limit = $this->clampLimit($input['limit'] ?? 25, 50);
        $search = trim((string) ($input['search'] ?? ''));

        $query = Asset::where('client_id', $this->clientId)->where('is_active', true);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('hostname', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%");
            });
        }

        $assets = $query->orderBy('hostname')->limit($limit)->get();

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

    private function listAgreements(): array
    {
        $contracts = Contract::where('client_id', $this->clientId)
            ->active()
            ->orderBy('name')
            ->get();

        return [
            'count' => $contracts->count(),
            'agreements' => $contracts->map(function (Contract $c) {
                $row = [
                    'name' => $c->name,
                    'type' => $c->type?->label(),
                    'status' => $c->status?->label(),
                    'start_date' => optional($c->start_date)->toDateString(),
                    'end_date' => optional($c->end_date)->toDateString(),
                ];

                // Prepay balance mirrors PortalContractController: only company-wide
                // contacts see it, and only for hours-based (non-dollar) prepay.
                if ($this->companyWideAccess && $c->has_prepay && ! $c->prepay_as_amount) {
                    $row['prepaid_hours_remaining'] = $c->prepay_balance_formatted;
                }

                return $row;
            })->all(),
        ];
    }

    // ── Helpers ──

    /**
     * Base ticket query, scoped to the client and — unless this contact has
     * company-wide access — to the tickets they are the contact on.
     */
    private function ticketScope()
    {
        $query = Ticket::where('client_id', $this->clientId);

        if (! $this->companyWideAccess) {
            // Null personId with no company-wide access matches nothing (safe).
            $query->where('contact_id', $this->personId);
        }

        return $query;
    }

    private function ticketSummary(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
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

    private function clampLimit(mixed $value, int $max): int
    {
        $n = (int) $value;
        if ($n < 1) {
            $n = 1;
        }

        return min($n, $max);
    }

    /**
     * Neutralise a free-text field before it reaches the model: strip any HTML
     * and collapse whitespace, then cap the length. The data belongs to the
     * client's own account, so we deliberately do not credential-redact (that
     * would mangle legitimate content) — the scope lock keeps the blast radius
     * to this one client's own data.
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
