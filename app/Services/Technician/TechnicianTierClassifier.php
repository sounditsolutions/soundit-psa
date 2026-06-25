<?php

namespace App\Services\Technician;

use App\Enums\TechnicianTier;
use App\Models\Ticket;
use App\Services\Agent\CloseAutoEligibility;
use App\Support\AgentConfig;
use App\Support\TechnicianConfig;

/**
 * Classifies a RESOLVED action type to a tier, server-side, default-deny
 * (spec §4.3/§7). The model's self-reported tier is never consulted — only the
 * config tier map. Anything not explicitly mapped to 'auto' is ≥Approve.
 *
 * EXCEPTION — propose_close (the AI Technician): its Auto decision is owned ENTIRELY
 * by the operator-set confidence threshold + a deterministic backstop, NOT the
 * tier map (CO-14). The $confidence/$ticket params feed only that branch; every
 * other action type ignores them and follows the unchanged legacy map path.
 */
class TechnicianTierClassifier
{
    public function classify(string $actionType, ?float $confidence = null, ?Ticket $ticket = null): TechnicianTier
    {
        // ── propose_close: confidence (and ONLY confidence) owns Auto (CO-14) ──
        // An operator writing {"propose_close":"auto"} (the only "make it auto" UI
        // they know) must NOT auto-close at arbitrary confidence. The threshold —
        // gated by the deterministic CloseAutoEligibility backstop (CO-19) — is the
        // sole source of Auto here. The map may only DOWNGRADE (kill to Block); it
        // can never be a source of Auto for propose_close.
        if ($actionType === 'propose_close') {
            if ((TechnicianConfig::tierMap()['propose_close'] ?? null) === TechnicianTier::Block->value) {
                return TechnicianTier::Block; // explicit operator denylist — honored as a kill only
            }

            $threshold = AgentConfig::proposeCloseAutoThreshold(); // ?float; null = never auto

            $auto = $threshold !== null
                && $confidence !== null
                && $confidence >= $threshold
                && $ticket !== null
                && CloseAutoEligibility::eligible($ticket); // deterministic, model-independent backstop

            return $auto ? TechnicianTier::Auto : TechnicianTier::Approve;
        }

        // ── every other action type: unchanged legacy default-deny path ──
        $mapped = TechnicianConfig::tierMap()[$actionType] ?? null;

        return match ($mapped) {
            TechnicianTier::Auto->value => TechnicianTier::Auto,
            TechnicianTier::Block->value => TechnicianTier::Block,
            default => TechnicianTier::Approve, // default-deny
        };
    }
}
