<?php

namespace App\Services;

use App\Enums\AssetHealthGrade;
use App\Enums\TicketPriority;
use App\Models\Asset;
use App\Services\Ai\AiClient;
use App\Services\AssetHealth\AssetHealthResult;
use App\Support\AiConfig;
use Illuminate\Support\Facades\Log;

/**
 * Computes a 0-100 health score for an asset from the monitoring signals we
 * already hold locally, plus a one-paragraph plain-English explanation.
 *
 * Design (the "what signals + what weights" discussion):
 *   - The score is DETERMINISTIC and DB-only (no external API calls). It starts
 *     at 100 and each signal subtracts a capped penalty. This keeps it fast,
 *     auditable, and cheap to recompute.
 *   - The AI only writes the *narrative* — it never sets the number. When no AI
 *     provider is configured we fall back to a deterministic paragraph, so the
 *     feature degrades gracefully (mirrors the triage pipeline).
 *   - UNKNOWN is not UNHEALTHY. A signal we can't read (no RMM link, no backup
 *     tooling, no M365 data) contributes nothing — it neither helps nor hurts.
 *     If we can't read ANY monitoring signal, the score is null and the asset
 *     grades Unknown rather than a misleading 100.
 *
 * Signals and their maximum penalties:
 *   Connectivity (offline / stale last-seen) .......... up to -30
 *   Active alerts (weighted by severity, capped) ...... up to -40
 *   Backup (stale or never-reported) .................. up to -18
 *   Patch / reboot state .............................. up to -12
 *   M365 compliance + Defender ........................ up to -20
 *   Open ticket load .................................. up to -10
 */
class AssetHealthService
{
    /** Recompute cached score on view when older than this many hours. */
    public const STALE_HOURS = 12;

    // ── Connectivity ──
    private const PENALTY_OFFLINE = 30;

    private const PENALTY_STALE_SEEN = 12;

    private const STALE_SEEN_HOURS = 8;

    private const OFFLINE_SEEN_HOURS = 24;

    // ── Alerts (per open alert, by severity; total capped) ──
    private const ALERT_CRITICAL = 25;

    private const ALERT_ERROR = 15;

    private const ALERT_WARNING = 8;

    private const ALERT_INFO = 2;

    private const ALERT_CAP = 40;

    // ── Backup ──
    private const PENALTY_BACKUP_STALE = 18;

    private const PENALTY_BACKUP_WARN = 8;

    private const BACKUP_WARN_HOURS = 26;

    private const BACKUP_STALE_HOURS = 48;

    // ── Patch / reboot ──
    private const PENALTY_REBOOT_PENDING = 10;

    private const PENALTY_LONG_UPTIME = 5;

    private const LONG_UPTIME_DAYS = 30;

    private const PATCH_CAP = 12;

    // ── M365 ──
    private const PENALTY_NONCOMPLIANT = 15;

    private const PENALTY_DEFENDER = 10;

    private const M365_CAP = 20;

    // ── Tickets ──
    private const PENALTY_PER_OPEN_TICKET = 3;

    private const PENALTY_URGENT_TICKET = 8;

    private const TICKET_CAP = 10;

    // "unknown ≠ unhealthy": only penalise Defender statuses we recognise as bad,
    // never merely-unrecognised ones.
    private const DEFENDER_BAD = ['disabled', 'off', 'not running', 'notrunning', 'stopped', 'expired', 'error', 'unprotected'];

    private const NARRATIVE_SYSTEM_PROMPT = <<<'PROMPT'
        You are an MSP operations assistant. You are given a computed health assessment
        for a single managed device (workstation or server) belonging to a client.

        Write ONE short paragraph (2-4 sentences, plain English) that explains the
        device's health to a technician. Lead with the overall standing, then name the
        specific factors dragging the score down — or confirm it looks healthy. Be
        concrete and reference the actual signals provided. Do NOT invent data beyond
        what is given. Do NOT use markdown, bullet points, headings, or a preamble like
        "Here is" — return only the paragraph text.
        PROMPT;

    public function __construct(
        private readonly AiClient $ai = new AiClient,
    ) {}

    /**
     * Deterministically score an asset from local monitoring data. No external
     * calls, no AI. Safe to call on every render, but prefer refreshIfStale().
     */
    public function compute(Asset $asset): AssetHealthResult
    {
        $factors = [];
        $penalty = 0;
        $monitoringKnown = 0;

        // ── Connectivity ────────────────────────────────────────────────
        $connectivity = $this->connectivityFactor($asset);
        $factors[] = $connectivity;
        if ($connectivity['status'] !== 'unknown') {
            $monitoringKnown++;
            $penalty += -$connectivity['points'];
        }

        // ── Active alerts ───────────────────────────────────────────────
        $alerts = $this->alertsFactor($asset);
        if ($alerts !== null) {
            $factors[] = $alerts;
            $monitoringKnown++;
            $penalty += -$alerts['points'];
        }

        // ── Backup ──────────────────────────────────────────────────────
        $backup = $this->backupFactor($asset);
        if ($backup !== null) {
            $factors[] = $backup;
            $monitoringKnown++;
            $penalty += -$backup['points'];
        }

        // ── Patch / reboot ──────────────────────────────────────────────
        $patch = $this->patchFactor($asset);
        if ($patch !== null) {
            $factors[] = $patch;
            $monitoringKnown++;
            $penalty += -$patch['points'];
        }

        // ── M365 compliance ─────────────────────────────────────────────
        $m365 = $this->m365Factor($asset);
        if ($m365 !== null) {
            $factors[] = $m365;
            $monitoringKnown++;
            $penalty += -$m365['points'];
        }

        // ── Open tickets (supplementary — does not gate "known") ─────────
        $tickets = $this->ticketsFactor($asset);
        if ($tickets !== null) {
            $factors[] = $tickets;
            $penalty += -$tickets['points'];
        }

        if ($monitoringKnown === 0) {
            return new AssetHealthResult(null, AssetHealthGrade::Unknown, $factors);
        }

        $score = (int) max(0, min(100, 100 - $penalty));

        return new AssetHealthResult($score, AssetHealthGrade::fromScore($score), $factors);
    }

    /**
     * Recompute + persist the score and narrative on the asset.
     *
     * @param  bool  $useAi  Allow an AI-written narrative (skipped when the AI
     *                       provider is unconfigured, or when a still-valid AI
     *                       narrative is already cached).
     */
    public function refresh(Asset $asset, bool $useAi = false): AssetHealthResult
    {
        $result = $this->compute($asset);

        $storedGrade = $asset->health_grade instanceof AssetHealthGrade
            ? $asset->health_grade->value
            : null;

        // Reuse an existing AI narrative when nothing that would change the story
        // has changed. This bounds AI spend to assets whose health actually moved,
        // and keeps on-view (deterministic) refreshes from clobbering a good AI
        // paragraph.
        $unchanged = $asset->health_summary
            && $asset->health_summary_is_ai
            && $storedGrade === $result->grade->value
            && $this->storedNotableKeys($asset) === $result->notableFactorKeys();

        if ($unchanged) {
            $summary = $asset->health_summary;
            $isAi = true;
        } else {
            [$summary, $isAi] = $this->narrative($asset, $result, $useAi);
        }

        $asset->forceFill([
            'health_score' => $result->score,
            'health_grade' => $result->grade->value,
            'health_summary' => $summary,
            'health_summary_is_ai' => $isAi,
            'health_breakdown' => $result->factors,
            'health_computed_at' => now(),
        ])->saveQuietly();

        return $result;
    }

    /**
     * Return the cached result if it is still fresh, otherwise recompute (with a
     * deterministic narrative — never blocks a page render on an AI call).
     */
    public function refreshIfStale(Asset $asset, ?int $ttlHours = null): AssetHealthResult
    {
        $ttl = $ttlHours ?? self::STALE_HOURS;

        if ($asset->health_computed_at !== null
            && $asset->health_computed_at->gt(now()->subHours($ttl))) {
            return $this->resultFromAsset($asset);
        }

        return $this->refresh($asset, useAi: false);
    }

    /** Rebuild a result object from the asset's cached columns. */
    public function resultFromAsset(Asset $asset): AssetHealthResult
    {
        $grade = $asset->health_grade instanceof AssetHealthGrade
            ? $asset->health_grade
            : AssetHealthGrade::fromScore($asset->health_score);

        return new AssetHealthResult(
            $asset->health_score,
            $grade,
            is_array($asset->health_breakdown) ? $asset->health_breakdown : [],
        );
    }

    /**
     * Build the explanation paragraph.
     *
     * @return array{0: string, 1: bool} [text, wasWrittenByAi]
     */
    public function narrative(Asset $asset, AssetHealthResult $result, bool $useAi): array
    {
        if ($useAi && AiConfig::isConfigured()) {
            try {
                $response = $this->ai->complete(
                    self::NARRATIVE_SYSTEM_PROMPT,
                    $this->narrativeContext($asset, $result),
                    maxTokens: 300,
                );
                $text = trim($response->text);
                if ($text !== '') {
                    return [$text, true];
                }
            } catch (\Throwable $e) {
                Log::debug('[AssetHealth] AI narrative failed, using deterministic fallback: '.$e->getMessage());
            }
        }

        return [$this->deterministicNarrative($asset, $result), false];
    }

    // ────────────────────────────────────────────────────────────────────
    // Individual signals
    // ────────────────────────────────────────────────────────────────────

    private function connectivityFactor(Asset $asset): array
    {
        $rmmLinked = $asset->ninja_id || $asset->level_id || $asset->tactical_asset_id;

        // Nothing to go on — unknown, no penalty.
        if ($asset->rmm_online === null && $asset->last_seen_at === null) {
            return $this->factor('connectivity', 'Connectivity', 'unknown', 0,
                $rmmLinked ? 'RMM-linked but no status reported yet' : 'No RMM link — connectivity not monitored');
        }

        if ($asset->rmm_online === true) {
            return $this->factor('connectivity', 'Connectivity', 'ok', 0, 'Online per RMM');
        }

        $lastSeen = $asset->last_seen_at;

        if ($asset->rmm_online === false) {
            $detail = $lastSeen
                ? 'Offline per RMM; last seen '.$lastSeen->diffForHumans()
                : 'Offline per RMM';

            return $this->factor('connectivity', 'Connectivity', 'bad', -self::PENALTY_OFFLINE, $detail);
        }

        // rmm_online null but we have a last_seen timestamp.
        $hoursAgo = $lastSeen->diffInHours(now());
        if ($hoursAgo >= self::OFFLINE_SEEN_HOURS) {
            return $this->factor('connectivity', 'Connectivity', 'bad', -self::PENALTY_OFFLINE,
                'Not seen since '.$lastSeen->diffForHumans());
        }
        if ($hoursAgo >= self::STALE_SEEN_HOURS) {
            return $this->factor('connectivity', 'Connectivity', 'warn', -self::PENALTY_STALE_SEEN,
                'Last seen '.$lastSeen->diffForHumans());
        }

        return $this->factor('connectivity', 'Connectivity', 'ok', 0, 'Last seen '.$lastSeen->diffForHumans());
    }

    private function alertsFactor(Asset $asset): ?array
    {
        $rmmLinked = $asset->ninja_id || $asset->level_id || $asset->tactical_asset_id;

        $open = $asset->alerts()->open()->get(['severity']);
        $known = $open->isNotEmpty() || $rmmLinked || $asset->alerts()->exists();

        if (! $known) {
            return null;
        }

        if ($open->isEmpty()) {
            return $this->factor('alerts', 'Active alerts', 'ok', 0, 'No open alerts');
        }

        $counts = ['critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($open as $alert) {
            $sev = $alert->severity?->value ?? 'info';
            $counts[$sev] = ($counts[$sev] ?? 0) + 1;
        }

        $raw = $counts['critical'] * self::ALERT_CRITICAL
            + $counts['error'] * self::ALERT_ERROR
            + $counts['warning'] * self::ALERT_WARNING
            + $counts['info'] * self::ALERT_INFO;
        $points = -min($raw, self::ALERT_CAP);

        $parts = [];
        foreach ($counts as $sev => $n) {
            if ($n > 0) {
                $parts[] = "{$n} {$sev}";
            }
        }
        $detail = implode(', ', $parts).' open';
        $status = ($counts['critical'] > 0 || $counts['error'] > 0) ? 'bad' : 'warn';

        return $this->factor('alerts', 'Active alerts', $status, $points, $detail);
    }

    private function backupFactor(Asset $asset): ?array
    {
        $hasBackup = $asset->comet_backup_enabled
            || $asset->servosity_backup_enabled
            || ($asset->backup_cloud_bytes ?? 0) > 0
            || $asset->backup_synced_at !== null;

        if (! $hasBackup) {
            return null;
        }

        $last = $asset->backup_synced_at;

        if ($last === null) {
            return $this->factor('backup', 'Backup', 'bad', -self::PENALTY_BACKUP_STALE,
                'Backup enabled but no successful run reported');
        }

        $hoursAgo = $last->diffInHours(now());
        if ($hoursAgo >= self::BACKUP_STALE_HOURS) {
            return $this->factor('backup', 'Backup', 'bad', -self::PENALTY_BACKUP_STALE,
                'Last backup '.$last->diffForHumans());
        }
        if ($hoursAgo >= self::BACKUP_WARN_HOURS) {
            return $this->factor('backup', 'Backup', 'warn', -self::PENALTY_BACKUP_WARN,
                'Last backup '.$last->diffForHumans());
        }

        return $this->factor('backup', 'Backup', 'ok', 0, 'Last backup '.$last->diffForHumans());
    }

    private function patchFactor(Asset $asset): ?array
    {
        if ($asset->needs_reboot === null && $asset->last_boot_at === null) {
            return null;
        }

        $points = 0;
        $notes = [];

        if ($asset->needs_reboot === true) {
            $points -= self::PENALTY_REBOOT_PENDING;
            $notes[] = 'reboot pending';
        }

        $uptimeDays = $asset->last_boot_at !== null
            ? (int) $asset->last_boot_at->diffInDays(now())
            : 0;
        if ($asset->last_boot_at !== null && $uptimeDays >= self::LONG_UPTIME_DAYS) {
            $points -= self::PENALTY_LONG_UPTIME;
            $notes[] = "up {$uptimeDays}d (patches may be pending)";
        }

        $points = -min(-$points, self::PATCH_CAP);

        if ($points === 0) {
            $detail = $asset->last_boot_at
                ? 'Rebooted '.$asset->last_boot_at->diffForHumans()
                : 'No reboot pending';

            return $this->factor('patch', 'Patch / reboot', 'ok', 0, $detail);
        }

        return $this->factor('patch', 'Patch / reboot', 'warn', $points, ucfirst(implode('; ', $notes)));
    }

    private function m365Factor(Asset $asset): ?array
    {
        $known = $asset->m365_compliance_state !== null
            || $asset->m365_defender_status !== null
            || $asset->m365_is_compliant !== null;

        if (! $known) {
            return null;
        }

        $points = 0;
        $notes = [];
        $status = 'ok';

        $nonCompliant = $asset->m365_is_compliant === false
            || strtolower((string) $asset->m365_compliance_state) === 'noncompliant';
        if ($nonCompliant) {
            $points -= self::PENALTY_NONCOMPLIANT;
            $notes[] = 'not Intune-compliant';
            $status = 'bad';
        }

        if ($asset->m365_defender_status !== null
            && $this->defenderLooksBad($asset->m365_defender_status)) {
            $points -= self::PENALTY_DEFENDER;
            $notes[] = 'Defender: '.$asset->m365_defender_status;
            $status = $status === 'bad' ? 'bad' : 'warn';
        }

        $points = -min(-$points, self::M365_CAP);

        if ($points === 0) {
            return $this->factor('m365', 'M365 compliance', 'ok', 0, 'Compliant'.
                ($asset->m365_defender_status ? '; Defender '.strtolower($asset->m365_defender_status) : ''));
        }

        return $this->factor('m365', 'M365 compliance', $status, $points, ucfirst(implode('; ', $notes)));
    }

    private function ticketsFactor(Asset $asset): ?array
    {
        // Only relevant when the asset has ticket history — otherwise omit.
        if (! $asset->tickets()->exists()) {
            return null;
        }

        $open = $asset->tickets()->open()->get(['tickets.id', 'priority']);
        $count = $open->count();

        if ($count === 0) {
            return $this->factor('tickets', 'Open tickets', 'ok', 0, 'No open tickets');
        }

        $urgent = $open->contains(
            fn ($t) => in_array($t->priority, [TicketPriority::P1, TicketPriority::P2], true)
        );

        $raw = $count * self::PENALTY_PER_OPEN_TICKET;
        if ($urgent) {
            $raw = max($raw, self::PENALTY_URGENT_TICKET);
        }
        $points = -min($raw, self::TICKET_CAP);

        $detail = $count.' open ticket'.($count === 1 ? '' : 's').($urgent ? ' (incl. P1/P2)' : '');

        return $this->factor('tickets', 'Open tickets', $urgent ? 'bad' : 'warn', $points, $detail);
    }

    // ────────────────────────────────────────────────────────────────────
    // Narrative helpers
    // ────────────────────────────────────────────────────────────────────

    private function narrativeContext(Asset $asset, AssetHealthResult $result): string
    {
        $name = $asset->hostname ?: $asset->name;
        $descriptor = trim(($asset->os ?? '').($asset->asset_type ? ' '.$asset->asset_type : ''));

        $lines = [];
        $lines[] = "Device: {$name}".($descriptor !== '' ? " ({$descriptor})" : '').'.';
        $client = $asset->client?->name;
        if ($client) {
            $lines[] = "Client: {$client}.";
        }
        $lines[] = 'Overall health score: '.$result->score.'/100 ('.$result->grade->label().').';
        $lines[] = 'Signals considered:';
        foreach ($result->factors as $f) {
            $pts = $f['points'] < 0 ? " ({$f['points']} pts)" : '';
            $lines[] = '- '.$f['label'].': '.strtoupper($f['status']).' — '.$f['detail'].$pts;
        }

        return implode("\n", $lines);
    }

    private function deterministicNarrative(Asset $asset, AssetHealthResult $result): string
    {
        $name = $asset->hostname ?: $asset->name;

        if (! $result->isKnown()) {
            return "There isn't enough monitoring data to score {$name} yet. "
                .'Link it to your RMM, backup, or M365 tooling to start tracking its health.';
        }

        $lead = "{$name} scores {$result->score}/100 (".$result->grade->label().').';

        $notable = $result->notableFactors();
        if (empty($notable)) {
            return $lead.' All monitored signals look healthy.';
        }

        $issues = array_map(fn ($f) => strtolower($f['label']).' — '.$f['detail'], $notable);

        return $lead.' The main '.(count($issues) === 1 ? 'issue is ' : 'issues are ').implode('; ', $issues).'.';
    }

    // ────────────────────────────────────────────────────────────────────

    private function defenderLooksBad(string $status): bool
    {
        $s = strtolower(trim($status));

        return in_array($s, self::DEFENDER_BAD, true)
            || str_contains($s, 'disabl')
            || str_contains($s, 'not running')
            || str_contains($s, 'out of date');
    }

    /**
     * @return array<int, string>
     */
    private function storedNotableKeys(Asset $asset): array
    {
        $breakdown = is_array($asset->health_breakdown) ? $asset->health_breakdown : [];
        $keys = [];
        foreach ($breakdown as $f) {
            if (($f['points'] ?? 0) < 0 && isset($f['key'])) {
                $keys[] = $f['key'];
            }
        }
        sort($keys);

        return $keys;
    }

    private function factor(string $key, string $label, string $status, int $points, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
            'points' => $points,
        ];
    }
}
