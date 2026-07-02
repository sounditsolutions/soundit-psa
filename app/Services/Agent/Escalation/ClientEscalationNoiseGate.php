<?php

namespace App\Services\Agent\Escalation;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use Illuminate\Support\Carbon;

/**
 * Deterministic client-level brake for owner-noise from duplicate escalations.
 *
 * This does NOT decide whether a flag_attention run is recorded. It only decides
 * whether a just-recorded flag should fire another immediate Teams/email ping.
 */
class ClientEscalationNoiseGate
{
    private const HUMAN_NOTE_DAYS = 7;

    /** @return array<string, mixed>|null */
    public function suppressionFor(Ticket $ticket, TechnicianRun $currentRun): ?array
    {
        $clientId = $ticket->client_id;
        if ($clientId === null) {
            return null;
        }

        return $this->openClientFlag($clientId, $ticket, $currentRun)
            ?? $this->humanEngagedSibling($clientId, $ticket);
    }

    public function lockKey(Ticket $ticket): ?string
    {
        return $ticket->client_id !== null
            ? "agent:client-escalation-noise:{$ticket->client_id}"
            : null;
    }

    /** @return array<string, mixed>|null */
    private function openClientFlag(int $clientId, Ticket $current, TechnicianRun $currentRun): ?array
    {
        $candidates = TechnicianRun::query()
            ->where('action_type', 'flag_attention')
            ->where('state', TechnicianRunState::Flagged->value)
            ->whereKeyNot($currentRun->id)
            ->where('ticket_id', '!=', $current->id)
            ->where(function ($q) use ($clientId): void {
                // Most new runs carry client_id, but older rows can be nullable;
                // the ticket join keeps those legacy flags in the client-level view.
                $q->where('client_id', $clientId)
                    ->orWhereHas('ticket', fn ($t) => $t->where('client_id', $clientId));
            })
            ->whereHas('ticket', fn ($q) => $q->open()->where('client_id', $clientId))
            ->with('ticket:id,client_id,status')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $run) {
            if (($run->proposed_meta['escalation']['status'] ?? null) === 'suppressed') {
                continue;
            }

            if (! $this->hasDeliveryMarker($run)) {
                continue;
            }

            return [
                'status' => 'suppressed',
                'noise_to_owner' => 'duplicate_client_escalation',
                'suppression_kind' => 'open_client_flag',
                'suppression_reason' => "Same client already has an open human-attention flag on ticket #{$run->ticket_id}.",
                'linked_run_id' => $run->id,
                'linked_ticket_id' => $run->ticket_id,
            ];
        }

        return null;
    }

    private function hasDeliveryMarker(TechnicianRun $run): bool
    {
        $notifiedAt = $run->proposed_meta['escalation']['notified_at'] ?? null;
        if (! is_string($notifiedAt) || trim($notifiedAt) === '') {
            return false;
        }

        try {
            Carbon::parse($notifiedAt);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function humanEngagedSibling(int $clientId, Ticket $current): ?array
    {
        $assigned = Ticket::forClient($clientId)
            ->open()
            ->where('id', '!=', $current->id)
            ->whereNotNull('assignee_id')
            ->orderByDesc('updated_at')
            ->first(['id', 'assignee_id']);

        if ($assigned !== null) {
            return [
                'status' => 'suppressed',
                'noise_to_owner' => 'duplicate_client_escalation',
                'suppression_kind' => 'human_engaged_sibling_assigned',
                'suppression_reason' => "Same client already has open ticket #{$assigned->id} assigned to a human.",
                'linked_ticket_id' => $assigned->id,
            ];
        }

        $systemTypes = array_map(fn (NoteType $t) => $t->value, NoteType::systemGenerated());

        $note = TicketNote::query()
            ->whereHas('ticket', fn ($q) => $q
                ->forClient($clientId)
                ->open()
                ->where('id', '!=', $current->id))
            ->where('who_type', WhoType::Agent->value)
            ->where('ai_authored', false)
            ->whereNotIn('note_type', $systemTypes)
            ->where('noted_at', '>=', now()->subDays(self::HUMAN_NOTE_DAYS))
            ->orderByDesc('noted_at')
            ->first(['id', 'ticket_id', 'noted_at']);

        if ($note !== null) {
            return [
                'status' => 'suppressed',
                'noise_to_owner' => 'duplicate_client_escalation',
                'suppression_kind' => 'human_engaged_sibling_note',
                'suppression_reason' => "Same client already has recent human staff activity on ticket #{$note->ticket_id}.",
                'linked_ticket_id' => $note->ticket_id,
                'linked_note_id' => $note->id,
            ];
        }

        return null;
    }
}
