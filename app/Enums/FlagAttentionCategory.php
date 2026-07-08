<?php

namespace App\Enums;

/**
 * The reason-shape the agent attaches to a flag_attention notice (Increment H).
 *
 * It is a HINT, recorded in the run's proposed_meta for later role-based routing
 * (deferred): judgment/decision flags route to the operator who owns decisions;
 * hands/onsite/overflow flags route to the on-site contractor. H records the hint
 * only — it does NOT route or notify on it yet.
 */
enum FlagAttentionCategory: string
{
    case NeedsDecision = 'needs_decision';
    case NeedsHandsOnsite = 'needs_hands_onsite';
    case NeedsOverflow = 'needs_overflow';
    case Uncertain = 'uncertain';
    case Other = 'other';

    /**
     * Normalise model-supplied input to a known category, failing safe to Other.
     * The model is instructed to pick from the enum, but never trusted to.
     */
    public static function fromInput(?string $value): self
    {
        return self::tryFrom(trim((string) $value)) ?? self::Other;
    }

    /** A short human label for the cockpit lane. */
    public function label(): string
    {
        return match ($this) {
            self::NeedsDecision => 'Needs a decision',
            self::NeedsHandsOnsite => 'Needs hands on site',
            self::NeedsOverflow => 'Needs overflow help',
            self::Uncertain => 'Unsure',
            self::Other => 'Other',
        };
    }
}
