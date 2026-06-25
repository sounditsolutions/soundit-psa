<?php

namespace App\Services\Technician\Emergency;

use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Support\TechnicianConfig;

/**
 * The deterministic, relied-on emergency detector (spec §8). Rules fire regardless
 * of AI classification; severity = max(rule signals, AI-raised). Client/AI text can
 * never LOWER severity. Pure (no side effects).
 */
class EmergencyDetector
{
    public function assess(Ticket $ticket, int $aiSeverity = 0): EmergencyAssessment
    {
        // CO-12: clamp the AI-raised severity to a sane band BEFORE it can influence
        // anything. Guards the future AI-raise path against injected severity
        // inflation (or a negative underflow); rule signals still floor on top.
        $aiSeverity = max(0, min(5, $aiSeverity));

        $reasons = [];
        $severity = $aiSeverity;

        // Age: open + not yet responded to, older than the per-priority floor.
        $opened = $ticket->opened_at ?? $ticket->created_at;
        $ageMin = TechnicianConfig::emergencyAgeMinutes($this->priorityOf($ticket));
        if ($ticket->responded_at === null && $opened !== null && $opened->lt(now()->subMinutes($ageMin))) {
            $reasons[] = 'age';
            $severity = max($severity, 2);
        }

        // Keyword: any configured keyword in subject/description (case-insensitive).
        $haystack = strtolower(trim(($ticket->subject ?? '').' '.($ticket->description ?? '')));
        foreach (TechnicianConfig::emergencyKeywords() as $kw) {
            if ($kw !== '' && str_contains($haystack, strtolower($kw))) {
                $reasons[] = 'keyword';
                $severity = max($severity, 3);
                break;
            }
        }

        // SLA breach (contract-derived due_at / response_due_at; existing helper).
        // CO-12: Ticket::isSlaBreach() always exists, so call it directly (the old
        // method_exists guard was dead code). Only fires when due dates are set.
        if ($ticket->isSlaBreach()) {
            $reasons[] = 'sla';
            $severity = max($severity, 3);
        }

        $isEmergency = $reasons !== [] || $severity >= 2;

        $normSubject = preg_replace('/\s+/', ' ', strtolower(trim($ticket->subject ?? '')));
        $signature = sha1(($ticket->client_id ?? 0).':'.$normSubject);

        return new EmergencyAssessment($isEmergency, $severity, array_values(array_unique($reasons)), $signature);
    }

    /**
     * Resolve the ticket's priority to a TicketPriority enum for the age floor.
     * The column is cast to the enum, but defend against a null/unknown value so
     * the age signal degrades to the P3 floor instead of throwing — a relied-on
     * detector must never crash on dirty data.
     */
    private function priorityOf(Ticket $ticket): TicketPriority
    {
        $priority = $ticket->priority;

        if ($priority instanceof TicketPriority) {
            return $priority;
        }

        return TicketPriority::tryFrom((string) $priority) ?? TicketPriority::P3;
    }
}
