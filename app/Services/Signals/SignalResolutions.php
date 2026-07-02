<?php

namespace App\Services\Signals;

use App\Enums\TechnicianRunState;
use App\Models\SignalEvent;
use App\Models\TechnicianRun;
use App\Models\Ticket;

class SignalResolutions
{
    public function isResolved(SignalEvent $event): bool
    {
        return match ($event->type_key) {
            'agent.flag_attention' => $this->flagAttentionResolved($event),
            default => true,
        };
    }

    private function flagAttentionResolved(SignalEvent $event): bool
    {
        $ticket = Ticket::query()->find($event->entity_id);
        if ($ticket === null) {
            return true;
        }

        if ($ticket->status->isTerminal()) {
            return true;
        }

        return ! TechnicianRun::query()
            ->where('ticket_id', $ticket->id)
            ->where('action_type', 'flag_attention')
            ->where('state', TechnicianRunState::Flagged->value)
            ->exists();
    }
}
