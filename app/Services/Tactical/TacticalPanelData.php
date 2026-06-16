<?php

namespace App\Services\Tactical;

use App\Models\TacticalAsset;
use App\Services\Wiki\Mining\WikiRedactor;

/**
 * Maps the on-demand Tactical panel sections (software / patches / checks-health)
 * for the asset-page lazy AJAX branch (P4 chunk 2, plan Task 3 + amendments F/G).
 *
 * Every section goes through the ONE bounded-read primitive
 * (TacticalInsightService::read) — a short ~2-3s timeout that degrades to a
 * snapshot fallback rather than throwing. The shapes this returns are consumed
 * by the JS panel renderers in show.blade.php; the controller stays thin.
 *
 * Three-state honesty (amendment G) is encoded in the payload, NOT inferred by
 * the view:
 *   (a) loaded-with-data   — the mapped list / counts.
 *   (b) genuinely-empty    — an empty list + the positive-copy flags (the view
 *                            renders "✓ all passing" / "no pending updates").
 *   (c) could-not-load     — {error:…}; the bounded read came back Unavailable.
 * (b) and (c) are structurally different keys so a degraded checks read can
 * never be rendered as "all passing", nor a degraded patches read as "fully
 * patched".
 */
class TacticalPanelData
{
    /** Per-check stdout display clip (chars) before it reaches the browser. */
    public const STDOUT_CLIP = 200;

    /** Cap the per-panel software/patch list so a 5k-row agent can't blow the response. */
    public const SOFTWARE_LIMIT = 500;

    public const PATCH_LIST_LIMIT = 200;

    public function __construct(
        private readonly TacticalInsightService $insight,
        private readonly TacticalClient $client,
        private readonly WikiRedactor $redactor,
    ) {}

    /**
     * Build one section payload. Returns the existing {error:…} degrade shape on
     * an Unavailable bounded read (the JS already renders {error}).
     *
     * @return array<string, mixed>
     */
    public function section(TacticalAsset $ta, string $section): array
    {
        return match ($section) {
            'software' => $this->software($ta),
            'patches' => $this->patches($ta),
            'checks' => $this->checks($ta),
            default => ['error' => 'Unknown Tactical section.'],
        };
    }

    /**
     * Software inventory: name / version / publisher. Genuinely-empty renders as
     * a clean "no inventory" state; a failed read degrades to {error}.
     *
     * @return array<string, mixed>
     */
    private function software(TacticalAsset $ta): array
    {
        $read = $this->insight->read(
            fn () => $this->client->getSoftware($ta->agent_id, timeout: TacticalInsightService::LIVE_TIMEOUT_SECONDS),
            signal: 'software',
            agentId: $ta->agent_id,
        );

        if ($read->state === SignalState::Unavailable || ! is_array($read->value)) {
            return $this->degrade();
        }

        // The software endpoint can return either a flat list or {software:[…]}.
        $rows = $this->rowsFrom($read->value, 'software');

        $software = collect($rows)
            ->filter(fn ($s) => is_array($s))
            ->take(self::SOFTWARE_LIMIT)
            ->map(fn ($s) => [
                'name' => $s['name'] ?? '—',
                'version' => $s['version'] ?? null,
                'publisher' => $s['publisher'] ?? null,
            ])
            ->values()
            ->all();

        return [
            'tactical' => true,
            'software' => $software,
        ];
    }

    /**
     * Patches — count-first compliance summary (amendment F). winupdate
     * (GET /winupdate/<id>/) returns a LIST of {id, kb, guid, title, installed,
     * downloaded, action, severity, …}; a patch is pending iff installed===false
     * (confirmed source v1.5.0 + live VM 105). Lead with the pending count + a
     * severity rollup; carry the full list as opt-in "show all"; and on a shape we
     * don't recognise, return shape_error ("couldn't read patch detail") rather
     * than presenting an empty list as "fully patched".
     *
     * @return array<string, mixed>
     */
    private function patches(TacticalAsset $ta): array
    {
        $read = $this->insight->read(
            fn () => $this->client->getPatches($ta->agent_id, timeout: TacticalInsightService::LIVE_TIMEOUT_SECONDS),
            signal: 'patches',
            agentId: $ta->agent_id,
        );

        if ($read->state === SignalState::Unavailable || ! is_array($read->value)) {
            return $this->degrade();
        }

        $rows = $this->rowsFrom($read->value, 'winupdates');

        // A genuinely-empty payload is "no pending updates" (compliant) — distinct
        // from a shape we can't parse.
        if ($rows === []) {
            return [
                'tactical' => true,
                'pending_count' => 0,
                'severity' => ['critical' => 0, 'important' => 0, 'other' => 0],
                'patches' => [],
                'needs_reboot' => (bool) $ta->needs_reboot,
            ];
        }

        // Shape guard: a recognisable winupdate row carries at least one of the
        // expected fields. If NONE of the rows do, the shape is wrong — say so
        // (never render "0 pending" off an unrecognised payload).
        if (! $this->looksLikePatchRows($rows)) {
            return [
                'tactical' => true,
                'shape_error' => "Couldn't read patch detail from Tactical (unexpected response). Open in Tactical to review updates.",
                'pending_count' => null,
            ];
        }

        $pending = collect($rows)
            ->filter(fn ($p) => is_array($p) && $this->isPendingPatch($p))
            ->values();

        $severity = ['critical' => 0, 'important' => 0, 'other' => 0];
        foreach ($pending as $p) {
            $bucket = $this->severityBucket($p['severity'] ?? null);
            $severity[$bucket]++;
        }

        $list = $pending->take(self::PATCH_LIST_LIMIT)->map(fn ($p) => [
            'kb' => $p['kb'] ?? null,
            'title' => $p['title'] ?? '—',
            'severity' => $p['severity'] ?? null,
        ])->values()->all();

        return [
            'tactical' => true,
            'pending_count' => $pending->count(),
            'severity' => $severity,
            'patches' => $list,
            'needs_reboot' => (bool) $ta->needs_reboot,
        ];
    }

    /**
     * Checks-health: the failing checks (RAW stdout from the insight), redacted +
     * length-clipped here for display (amendment G). All-passing is an empty
     * failing list (positive copy); a failed read degrades to {error}.
     *
     * @return array<string, mixed>
     */
    private function checks(TacticalAsset $ta): array
    {
        $read = $this->insight->read(
            fn () => $this->client->getAgentChecks($ta->agent_id, timeout: TacticalInsightService::LIVE_TIMEOUT_SECONDS),
            signal: 'checks',
            agentId: $ta->agent_id,
        );

        if ($read->state === SignalState::Unavailable || ! is_array($read->value)) {
            return $this->degrade();
        }

        $rows = $this->rowsFrom($read->value, 'checks');
        $counts = TacticalFieldMap::checksSummary($rows);

        $failing = [];
        foreach ($rows as $check) {
            if (! is_array($check) || TacticalFieldMap::checkStatus($check) !== 'failing') {
                continue;
            }
            $result = $check['check_result'] ?? [];
            $failing[] = [
                'name' => $check['name'] ?? $check['readable_desc'] ?? 'Unknown',
                'retcode' => isset($result['retcode']) ? (int) $result['retcode'] : null,
                'stdout' => $this->safeStdout((string) ($result['stdout'] ?? '')),
            ];
        }

        return [
            'tactical' => true,
            'checks_total' => $counts['total'],
            'checks_failing' => $counts['failing'],
            'failing_checks' => $failing,
        ];
    }

    /**
     * Redact (WikiRedactor) THEN length-clip raw check stdout for display.
     *
     * KNOWN RESIDUAL (accepted, documented like the P3 audit-output residual):
     * WikiRedactor::redact() is keyword-shape BEST-EFFORT. It catches keyword=value
     * forms, connection strings, PEM blocks, JWTs, and base64-distinctive runs — but
     * a curated check that echoes a BARE secret with no keyword (e.g. a short hex/
     * alphanumeric token, a raw key value, or a secret buried inside a JSON blob
     * whose escaping breaks the contiguous-shape match) can pass through. The
     * STDOUT_CLIP (~200 chars) BOUNDS the blast radius (a dumped key is truncated),
     * it does not guarantee redaction. The check library is operator-curated, so
     * this is a bounded, accepted exposure — not a claim of total stdout safety.
     */
    private function safeStdout(string $stdout): string
    {
        $redacted = $this->redactor->redact($stdout);

        if (mb_strlen($redacted) > self::STDOUT_CLIP) {
            return mb_substr($redacted, 0, self::STDOUT_CLIP).'…';
        }

        return $redacted;
    }

    /**
     * Normalize a list-or-wrapped payload to rows. Tactical read endpoints
     * usually return a bare list, but some wrap under a key — accept either.
     *
     * @param  array<mixed>  $payload
     * @return array<int, mixed>
     */
    private function rowsFrom(array $payload, string $wrapKey): array
    {
        if (isset($payload[$wrapKey]) && is_array($payload[$wrapKey])) {
            return array_values($payload[$wrapKey]);
        }

        // A bare list (sequential keys) — the common shape.
        if (array_is_list($payload)) {
            return $payload;
        }

        // An associative single object is not a list of rows.
        return [];
    }

    /**
     * Does the payload look like winupdate rows? At least one row must carry a
     * recognisable patch field. Guards against rendering an unknown shape as
     * "0 pending" (amendment F). Fields are the real getPatches shape (source
     * v1.5.0 + live VM 105).
     *
     * @param  array<int, mixed>  $rows
     */
    private function looksLikePatchRows(array $rows): bool
    {
        $known = ['kb', 'title', 'installed', 'action', 'severity', 'guid', 'downloaded'];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($known as $field) {
                if (array_key_exists($field, $row)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Is a winupdate row pending? A patch is PENDING iff installed === false
     * (source v1.5.0 + live VM 105). `action` is the APPROVAL policy
     * ("nothing"/"approve"/"ignore"/"inherit"), NOT install status — it does not
     * decide pending. A row missing the installed flag is not counted pending
     * (looksLikePatchRows already gated the payload as recognisable).
     *
     * @param  array<string, mixed>  $patch
     */
    private function isPendingPatch(array $patch): bool
    {
        return ($patch['installed'] ?? null) === false;
    }

    private function severityBucket(?string $severity): string
    {
        return match (strtolower((string) $severity)) {
            'critical' => 'critical',
            'important' => 'important',
            default => 'other',
        };
    }

    /**
     * The shared degrade payload — mirrors the existing Ninja/Level {error:…}
     * shape the JS already renders as a "couldn't reach the agent — try again"
     * panel.
     *
     * @return array<string, string>
     */
    private function degrade(): array
    {
        return ['error' => "Couldn't reach the agent. Try again in a moment."];
    }
}
