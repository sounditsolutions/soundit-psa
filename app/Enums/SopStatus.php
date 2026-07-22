<?php

namespace App\Enums;

/**
 * The authoring state of a ticket category's SOP. so-0ftg (Charlie-LOCKED):
 * this is a SOFT HINT only — it is shown to orient the reader but NEVER gates
 * whether the SOP text is served. A category stays correct through in-place
 * correction, not a review gate.
 */
enum SopStatus: string
{
    case None = 'none';       // no procedure authored yet — a coverage gap
    case Draft = 'draft';     // written, not yet vetted (still served)
    case Reviewed = 'reviewed'; // vetted / authoritative

    public function label(): string
    {
        return match ($this) {
            self::None => 'No SOP',
            self::Draft => 'Draft',
            self::Reviewed => 'Reviewed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::None => 'bg-secondary',
            self::Draft => 'bg-warning text-dark',
            self::Reviewed => 'bg-success',
        };
    }
}
