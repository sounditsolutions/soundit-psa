<?php

namespace App\Services\Technician;

use RuntimeException;

/** Thrown when a client-facing body is missing the structural disclosure. */
class MissingDisclosureException extends RuntimeException {}

/**
 * Structural disclosure (spec §6/§7). The disclosed-AI banner + "get a human"
 * affordance are appended by THIS sending layer — never authored by the model.
 * The persona name is config-derived (TechnicianConfig::aiActorName), but the
 * pre-send scan keys on a NAME-INDEPENDENT sentinel so it still rejects an
 * undisclosed or human-signed body whatever the configured name is.
 */
class TechnicianDisclosure
{
    /** The load-bearing, name-independent disclosure sentinel the scan checks. */
    public const DISCLOSURE_SENTINEL = ', an AI assistant for our team.';

    private const HUMAN_AFFORDANCE =
        'If you would prefer to work with a person, just reply and ask — a member of our team will take over.';

    public function withDisclosure(string $body, string $actorName): string
    {
        $name = trim($actorName) !== '' ? trim($actorName) : 'our virtual assistant';
        $banner = '— Sent by '.$name.self::DISCLOSURE_SENTINEL;

        return rtrim($body)."\n\n".$banner."\n".self::HUMAN_AFFORDANCE;
    }

    public function assertPresent(string $body): void
    {
        if (! str_contains($body, self::DISCLOSURE_SENTINEL)) {
            throw new MissingDisclosureException(
                'Client-facing Technician message is missing the structural AI disclosure.',
            );
        }
    }
}
