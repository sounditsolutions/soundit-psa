<?php

namespace App\Services;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Enums\TicketType;
use App\Models\Alert;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TriageConfig;
use Illuminate\Support\Facades\Log;

class AlertService
{
    public function __construct(
        private readonly TicketService $ticketService,
    ) {}

    /**
     * Create or update an alert (upsert with dedup).
     */
    public function upsert(
        AlertSource $source,
        string $sourceAlertId,
        array $data,
    ): Alert {
        $existing = Alert::where('source', $source)
            ->where('source_alert_id', $sourceAlertId)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->first();

        if ($existing) {
            // Re-fired — update existing
            $existing->update([
                'message' => $data['message'] ?? $existing->message,
                'fired_at' => $data['fired_at'] ?? $existing->fired_at,
                'refired_count' => $existing->refired_count + 1,
                'metadata' => array_merge($existing->metadata ?? [], $data['metadata'] ?? []),
            ]);

            Log::debug("[Alert] Re-fired {$source->value} alert {$sourceAlertId}", [
                'alert_id' => $existing->id,
                'refired_count' => $existing->refired_count,
            ]);

            return $existing;
        }

        $alert = Alert::create([
            'asset_id' => $data['asset_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'source' => $source,
            'source_alert_id' => $sourceAlertId,
            'severity' => $data['severity'],
            'status' => AlertStatus::Active,
            'title' => $data['title'],
            'message' => $data['message'] ?? null,
            'hostname' => $data['hostname'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'fired_at' => $data['fired_at'] ?? now(),
        ]);

        Log::info("[Alert] Created {$source->value} alert", [
            'alert_id' => $alert->id,
            'severity' => $data['severity']->value ?? $data['severity'],
            'title' => $data['title'],
            'hostname' => $data['hostname'] ?? null,
        ]);

        return $alert;
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledge(Alert $alert, User $user): void
    {
        if ($alert->status === AlertStatus::Resolved) {
            return;
        }

        $alert->update([
            'status' => AlertStatus::Acknowledged,
            'acknowledged_by' => $user->id,
            'acknowledged_at' => now(),
        ]);
    }

    /**
     * Convert an alert to a ticket.
     */
    public function createTicket(Alert $alert, ?int $userId = null): ?Ticket
    {
        if ($alert->ticket_id) {
            return $alert->ticket;
        }

        $priority = $alert->severity->toTicketPriority();
        $subject = "[{$alert->source->label()}] {$alert->severity->label()} — {$alert->title} on {$alert->hostname}";

        // Resolve contact from asset's primary user
        $contactId = null;
        if ($alert->asset) {
            $primaryUser = $alert->asset->primaryUser();
            if ($primaryUser) {
                $contactId = $primaryUser->id;
            }
        }

        // Build description
        $descLines = ["**{$alert->source->label()} Alert**"];
        $descLines[] = "- Device: {$alert->hostname}";
        $descLines[] = "- Severity: {$alert->severity->label()}";
        $descLines[] = "- Alert: {$alert->title}";
        if ($alert->fired_at) {
            $descLines[] = "- Fired: {$alert->fired_at->toDateTimeString()}";
        }
        if ($alert->message) {
            $descLines[] = '';
            $descLines[] = '**Details:**';
            $descLines[] = '```';
            $descLines[] = substr($alert->message, 0, 3000);
            $descLines[] = '```';
        }

        $ticket = $this->ticketService->createTicket([
            'subject' => $subject,
            'description' => implode("\n", $descLines),
            'client_id' => $alert->client_id,
            'contact_id' => $contactId,
            'priority' => $priority->value,
            'type' => TicketType::Incident->value,
            'source' => TicketSource::Alert->value,
            'source_ref' => (string) $alert->id,
        ], $userId);

        if ($ticket && $alert->asset_id) {
            $ticket->assets()->syncWithoutDetaching([$alert->asset_id]);
        }

        $alert->update([
            'status' => AlertStatus::Ticketed,
            'ticket_id' => $ticket?->id,
        ]);

        Log::info('[Alert] Ticket created from alert', [
            'alert_id' => $alert->id,
            'ticket_id' => $ticket?->id,
        ]);

        return $ticket;
    }

    /**
     * Attach an alert to an existing ticket.
     */
    public function attachToTicket(Alert $alert, int $ticketId, ?int $userId = null): void
    {
        $alert->update([
            'status' => AlertStatus::Ticketed,
            'ticket_id' => $ticketId,
        ]);

        // Link asset to ticket if not already linked
        $ticket = Ticket::find($ticketId);
        if ($ticket && $alert->asset_id) {
            $ticket->assets()->syncWithoutDetaching([$alert->asset_id]);
        }

        // Add a system note to the ticket documenting the linkage
        if ($ticket) {
            $authorId = $userId ?? TriageConfig::systemUserId() ?? User::orderBy('id')->value('id');
            if ($authorId) {
                $noteLines = ["**{$alert->source->label()} alert attached to this ticket**"];
                $noteLines[] = "- Alert: {$alert->title}";
                $noteLines[] = "- Severity: {$alert->severity->label()}";
                if ($alert->hostname) {
                    $noteLines[] = "- Device: {$alert->hostname}";
                }
                if ($alert->fired_at) {
                    $noteLines[] = "- Fired: {$alert->fired_at->toAppTz()->format('M j, Y g:ia T')}";
                }
                if ($alert->message) {
                    $noteLines[] = '';
                    $noteLines[] = '**Details:**';
                    $noteLines[] = '```';
                    $noteLines[] = substr($alert->message, 0, 3000);
                    $noteLines[] = '```';
                }

                $this->ticketService->addNote(
                    $ticket,
                    implode("\n", $noteLines),
                    NoteType::System,
                    true,
                    $authorId,
                );
            }
        }

        Log::info('[Alert] Attached to existing ticket', [
            'alert_id' => $alert->id,
            'ticket_id' => $ticketId,
        ]);
    }

    /**
     * Resolve an alert (from RMM or manual).
     */
    public function resolve(Alert $alert, ?string $reason = null): void
    {
        if ($alert->status === AlertStatus::Resolved) {
            return;
        }

        $alert->update([
            'status' => AlertStatus::Resolved,
            'resolved_at' => now(),
        ]);

        // Add note to linked ticket if exists
        if ($alert->ticket_id) {
            $ticket = $alert->ticket;
            if ($ticket && $ticket->status->isOpen()) {
                $systemUserId = TriageConfig::systemUserId() ?? User::orderBy('id')->value('id');
                if ($systemUserId) {
                    $noteBody = $reason ?? "Alert resolved by {$alert->source->label()} monitoring.";
                    $this->ticketService->addNote(
                        $ticket,
                        $noteBody,
                        NoteType::System,
                        true,
                        $systemUserId,
                    );
                }
            }
        }

        Log::info('[Alert] Resolved', [
            'alert_id' => $alert->id,
            'source' => $alert->source->value,
            'title' => $alert->title,
        ]);
    }

    /**
     * Bulk acknowledge alerts.
     */
    public function bulkAcknowledge(array $alertIds, User $user): int
    {
        return Alert::whereIn('id', $alertIds)
            ->where('status', AlertStatus::Active)
            ->update([
                'status' => AlertStatus::Acknowledged,
                'acknowledged_by' => $user->id,
                'acknowledged_at' => now(),
            ]);
    }

    /**
     * Bulk create tickets from alerts.
     */
    public function bulkCreateTickets(array $alertIds, ?int $userId = null): int
    {
        $alerts = Alert::whereIn('id', $alertIds)
            ->whereNull('ticket_id')
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged])
            ->get();

        $count = 0;
        foreach ($alerts as $alert) {
            if ($this->createTicket($alert, $userId)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Bulk resolve alerts.
     */
    public function bulkResolve(array $alertIds): int
    {
        $alerts = Alert::whereIn('id', $alertIds)
            ->whereIn('status', [AlertStatus::Active, AlertStatus::Acknowledged, AlertStatus::Ticketed])
            ->get();

        foreach ($alerts as $alert) {
            $this->resolve($alert, 'Manually resolved.');
        }

        return $alerts->count();
    }
}
