<?php

namespace App\Services\Technician;

use App\Enums\TechnicianTier;
use App\Support\TechnicianConfig;

/**
 * Classifies a RESOLVED action type to a tier, server-side, default-deny
 * (spec §4.3/§7). The model's self-reported tier is never consulted — only the
 * config tier map. Anything not explicitly mapped to 'auto' is ≥Approve.
 */
class TechnicianTierClassifier
{
    public function classify(string $actionType): TechnicianTier
    {
        $mapped = TechnicianConfig::tierMap()[$actionType] ?? null;

        return match ($mapped) {
            TechnicianTier::Auto->value => TechnicianTier::Auto,
            TechnicianTier::Block->value => TechnicianTier::Block,
            default => TechnicianTier::Approve, // default-deny
        };
    }
}
