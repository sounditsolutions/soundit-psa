<?php

namespace App\Services\Huntress;

use App\Enums\TicketPriority;

class HuntressFieldMapper
{
    /**
     * Map Huntress incident severity string to a TicketPriority.
     *
     * Huntress titles follow: "(CRITICAL|HIGH|LOW) - Incident on $agent ($org)"
     * CRITICAL → P1, HIGH → P2, LOW → P3 (not P4 — security findings need 24h SLA).
     */
    public static function severityToTicketPriority(string $severity): TicketPriority
    {
        return match (strtoupper(trim($severity))) {
            'CRITICAL' => TicketPriority::P1,
            'HIGH' => TicketPriority::P2,
            'LOW' => TicketPriority::P3,
            default => TicketPriority::P2, // Default to P2 for unknown severity
        };
    }
}
