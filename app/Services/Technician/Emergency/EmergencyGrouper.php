<?php

namespace App\Services\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Models\TechnicianEmergency;
use App\Models\Ticket;
use App\Support\TechnicianConfig;

/**
 * Storm grouping (spec §8): same client + alert signature within ~15 min → one
 * emergency/one escalation, not N. Otherwise create a new open emergency.
 */
class EmergencyGrouper
{
    public function groupOrCreate(Ticket $ticket, EmergencyAssessment $a): TechnicianEmergency
    {
        $windowStart = now()->subMinutes(TechnicianConfig::stormWindowMinutes());

        $existing = TechnicianEmergency::query()->open()
            ->where('signature', $a->signature)
            ->where('client_id', $ticket->client_id)
            ->where('alerted_at', '>=', $windowStart)
            ->orderByDesc('alerted_at')
            ->first();

        if ($existing !== null) {
            $ids = $existing->ticket_ids ?? [];
            if (! in_array($ticket->id, $ids, true)) {
                $ids[] = $ticket->id;
            }
            $existing->update([
                'ticket_ids' => $ids,
                'severity' => max($existing->severity, $a->severity),
                'reasons' => array_values(array_unique(array_merge($existing->reasons ?? [], $a->reasons))),
            ]);

            return $existing;
        }

        return TechnicianEmergency::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'signature' => $a->signature,
            'severity' => $a->severity,
            'reasons' => $a->reasons,
            'detected_by' => $a->reasons !== [] ? 'rules' : 'ai',
            'state' => EmergencyState::Open,
            'escalation_step' => 0,
            'ticket_ids' => [$ticket->id],
            'alerted_at' => now(),
        ]);
    }
}
