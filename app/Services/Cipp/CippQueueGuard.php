<?php

namespace App\Services\Cipp;

/**
 * Refuse a queue-backed CIPP answer instead of letting it read as data.
 *
 * For an AllTenants read CIPP may reply "still loading" rather than results.
 * Unguarded, the dominant shape unwraps to an empty Results array — so "we do
 * not know yet" reaches the caller as a confident "nothing found". On a
 * security surface ("no CA gaps", "nothing in quarantine") that is the
 * false-clear this repo has already been burned by (psa-7lgo rule 3), and an
 * agent cannot tell it apart from a real all-clear. So it must throw.
 *
 * Shared because BOTH clients unwrap {"Results": ...} and therefore both own
 * the hazard: CippMcpClient (the MCP relay path) and CippClient (the REST
 * direct path, which also backs every CIPP sync service).
 *
 * Shapes read from the vendor's producers (CIPP-API, verified 2026-07-15) —
 * re-verify there, do not infer:
 *   NESTED {Results: [], Metadata: {QueueMessage, QueueId, Queued?}}
 *     - Invoke-ListGraphRequest.ps1:176-191 hoists the queue fields into
 *       Metadata and blanks Results to @(); the empty list IS the hazard.
 *     - Invoke-ListMailQuarantine.ps1:48-59,95-96 and
 *       Invoke-ListConditionalAccessPolicies.ps1:209-235 build the same shape
 *       with NO Queued flag — hence QueueMessage is the marker.
 *   FLAT {QueueMessage, QueueId, Queued: true}
 *     - Get-GraphRequestList.ps1:259-271,317-348, the generic producer, for
 *       entrypoints that pass it through without the hoist above.
 *
 * QueueId is deliberately NOT a marker: Invoke-ListMailQuarantine.ps1:73-77
 * emits Metadata{QueueId} on the healthy rows-present branch, where it
 * serialises to null — keying on it would reject good data. Metadata is also
 * the pagination carrier (nextLink, :20-25), so its mere presence means
 * nothing either. Both traps are pinned by regression tests.
 *
 * Latent today, not live: every queue path above is gated on
 * TenantFilter = AllTenants, and we only ever send a concrete tenant domain.
 * This guard exists so that mapping a queue-capable endpoint later cannot
 * silently reintroduce the false-clear.
 */
class CippQueueGuard
{
    /**
     * @param  array<int|string, mixed>  $data
     *
     * @throws CippClientException when CIPP answered with a queue marker.
     */
    public static function assertNotQueueBacked(array $data): void
    {
        $marker = self::marker($data);

        foreach (['Metadata', 'metadata'] as $key) {
            if ($marker === null && isset($data[$key]) && is_array($data[$key])) {
                $marker = self::marker($data[$key]);
            }
        }

        if ($marker !== null) {
            throw new CippClientException(
                'CIPP returned a queue-backed result instead of data: '.$marker.
                ' This is NOT an empty result — the tenant data is still loading upstream, so the answer is unknown. Retry once the queue completes.'
            );
        }
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private static function marker(array $data): ?string
    {
        $message = $data['QueueMessage'] ?? null;
        if (is_string($message) && $message !== '') {
            return mb_substr($message, 0, 300);
        }

        if (($data['Queued'] ?? null) === true) {
            return 'upstream set Queued=true with no QueueMessage';
        }

        return null;
    }
}
