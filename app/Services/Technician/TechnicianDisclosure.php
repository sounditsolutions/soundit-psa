<?php

namespace App\Services\Technician;

use RuntimeException;

/** Thrown when a client-facing body is missing the structural disclosure. */
class MissingDisclosureException extends RuntimeException {}

/**
 * Structural disclosure (spec §6/§7). The disclosed-AI banner + "get a human"
 * affordance are appended by THIS sending layer — never authored by the model.
 * assertPresent() is the pre-send check that rejects any body lacking the
 * structural disclosure (or one a model tried to sign off as a named human).
 */
class TechnicianDisclosure
{
    /** The load-bearing, model-independent disclosure sentinel. */
    public const MARKER = '— Sent by Chet, an AI assistant for our team.';

    private const HUMAN_AFFORDANCE =
        'If you would prefer to work with a person, just reply and ask — a member of our team will take over.';

    public function withDisclosure(string $body): string
    {
        return rtrim($body)
            ."\n\n".self::MARKER
            ."\n".self::HUMAN_AFFORDANCE;
    }

    public function assertPresent(string $body): void
    {
        if (! str_contains($body, self::MARKER)) {
            throw new MissingDisclosureException(
                'Client-facing Technician message is missing the structural AI disclosure.',
            );
        }
    }
}
