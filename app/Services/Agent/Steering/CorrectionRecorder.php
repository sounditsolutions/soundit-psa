<?php

namespace App\Services\Agent\Steering;

use App\Models\AssistantConversation;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;

class CorrectionRecorder
{
    /**
     * Persist an operator correction as a transcript message inside a
     * daily ticket_correction AssistantConversation.
     *
     * Uses createOrFirst (relies on the unique index on external_key) for
     * race-safety — the same pattern the Teams bridge uses for its daily
     * channel conversations.
     */
    public function record(
        Ticket $ticket,
        User $operator,
        string $correction,
        ?TechnicianRun $correctedRun = null,
    ): AssistantConversation {
        $key = "ticket_correction:{$ticket->id}:".now()->format('Y-m-d');

        $conversation = AssistantConversation::createOrFirst(
            ['external_key' => $key],
            [
                'user_id' => $operator->id,
                'context_type' => 'ticket_correction',
                'context_id' => $ticket->id,
                'title' => "Correction for ticket #{$ticket->id}",
            ]
        );

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $correction,
        ]);

        if ($correctedRun !== null) {
            $correctedRun->update([
                'proposed_meta' => array_merge(
                    $correctedRun->proposed_meta ?? [],
                    ['corrected_by_conversation_id' => $conversation->id]
                ),
            ]);
        }

        return $conversation;
    }
}
