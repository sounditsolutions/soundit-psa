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
        $banner = '— Sent by '.self::displayName($actorName).self::DISCLOSURE_SENTINEL;

        return self::append($body, $banner);
    }

    /**
     * Dual credit for an AI-DRAFTED, HUMAN-APPROVED-AND-SENT message (psa-u51h part 2):
     * the client sees both who wrote it and who reviewed it. The auto-sent path keeps
     * withDisclosure() — the AI alone is accountable there, and claiming a human
     * reviewer who never existed is exactly the trust breach this disclosure exists
     * to prevent.
     *
     * DISCLOSURE_SENTINEL is embedded verbatim, so assertPresent() stays name- AND
     * shape-independent. A blank approver degrades to the AI-only banner rather than
     * crediting nobody (manager ruling, psa-u51h Q3).
     */
    public function withDualDisclosure(string $body, string $actorName, string $approverName): string
    {
        $approver = trim($approverName);
        if ($approver === '') {
            return $this->withDisclosure($body, $actorName);
        }

        $banner = '— Drafted by '.self::displayName($actorName).self::DISCLOSURE_SENTINEL
            .' Reviewed and sent by '.$approver.'.';

        return self::append($body, $banner);
    }

    /** Never leave the persona slot empty — an unnamed sender reads as a human. */
    private static function displayName(string $actorName): string
    {
        return trim($actorName) !== '' ? trim($actorName) : 'our virtual assistant';
    }

    private static function append(string $body, string $banner): string
    {
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
