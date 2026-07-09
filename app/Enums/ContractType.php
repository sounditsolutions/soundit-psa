<?php

namespace App\Enums;

enum ContractType: string
{
    case Managed = 'managed';
    case BreakFix = 'breakfix';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Managed => 'Managed Services',
            self::BreakFix => 'Break-Fix',
            self::Custom => 'Custom',
        };
    }

    /**
     * Coverage precedence used when a client holds several active contracts of
     * different types — the highest-ranked type is the client's headline tier.
     * Managed (full proactive coverage) outranks Custom (negotiated terms),
     * which outranks Break-Fix (reactive, pay-per-incident). Drives pre-ring
     * call routing (PlivoWebhookController::resolveCaller).
     */
    public function routingPriority(): int
    {
        return match ($this) {
            self::Managed => 3,
            self::Custom => 2,
            self::BreakFix => 1,
        };
    }
}
