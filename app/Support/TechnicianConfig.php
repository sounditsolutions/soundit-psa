<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Setting-backed coverage-profile reader (spec §5). Everything is data — no
 * hardcoded operator/trip. Fail-closed: unreadable JSON yields the safe empty
 * default (an empty tier map default-denies every action in the classifier).
 */
class TechnicianConfig
{
    /** Master on/off for the whole Technician subsystem. */
    public static function enabled(): bool
    {
        return (bool) Setting::getValue('technician_enabled');
    }

    /** Global pause — re-checked inside the gate immediately before execution. */
    public static function killSwitchEngaged(): bool
    {
        return (bool) Setting::getValue('technician_kill_switch');
    }

    /**
     * The reused "System User (AI Actor)" id (spec §3/§4.6). Same selection as
     * TriageConfig::systemUserId(): the configured setting, else the first user.
     */
    public static function aiActorUserId(): ?int
    {
        $configured = Setting::getValue('triage_system_user_id');

        if ($configured) {
            return (int) $configured;
        }

        return User::orderBy('id')->value('id');
    }

    /** The configured AI actor's display name (spec §3), for the disclosure persona. */
    public static function aiActorName(): string
    {
        $id = self::aiActorUserId();
        $name = $id ? User::find($id)?->name : null;

        return is_string($name) && trim($name) !== '' ? $name : 'our virtual assistant';
    }

    /**
     * action_type => tier-string map (data, not code). Invalid/missing → [],
     * which the classifier reads as "default-deny everything to Approve".
     *
     * @return array<string, string>
     */
    public static function tierMap(): array
    {
        return self::decodeMap('technician_action_tiers');
    }

    public static function clientExcluded(int $clientId): bool
    {
        return in_array($clientId, self::decodeList('technician_excluded_client_ids'), true);
    }

    public static function clientAlwaysHuman(int $clientId): bool
    {
        return in_array($clientId, self::decodeList('technician_always_human_client_ids'), true);
    }

    /** @return array<int, int> ordered user ids */
    public static function escalationChain(): array
    {
        return array_values(array_map('intval', self::decodeList('technician_escalation_chain')));
    }

    /** Authoritative manual "covering / not covering" toggle (default: covering). */
    public static function operatorCovering(): bool
    {
        $value = Setting::getValue('technician_operator_covering');

        return $value === null || (bool) $value;
    }

    public static function ackEtaText(): string
    {
        $value = Setting::getValue('technician_ack_eta_text');

        return is_string($value) && $value !== '' ? $value : 'within one business day';
    }

    /** Category tokens whose inbound tickets must NOT be auto-acknowledged (spec §9). */
    public static function ackSuppressedCategories(): array
    {
        $raw = Setting::getValue('technician_ack_suppressed_categories');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_map('strval', $decoded);
            }
        }

        return ['billing', 'security', 'incident', 'outage'];
    }

    public static function ackSuppressedForCategory(?string $category): bool
    {
        if (! is_string($category) || $category === '') {
            return false;
        }

        $haystack = mb_strtolower($category);
        foreach (self::ackSuppressedCategories() as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /** Operator email for notifications (spec §1C). Null when not configured. */
    public static function notifyEmail(): ?string
    {
        $value = Setting::getValue('technician_notify_email');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Teams incoming-webhook URL for operator notifications (spec §1C). */
    public static function teamsWebhookUrl(): ?string
    {
        $value = Setting::getValue('technician_teams_webhook_url');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** The Technician's own daily token ceiling (spec §11). */
    public static function dailyTokenLimit(): int
    {
        $value = Setting::getValue('technician_daily_token_limit');

        return is_numeric($value) ? (int) $value : 1_000_000;
    }

    /** Per-run token budget for a single Technician AI call. */
    public static function maxTokensPerRun(): int
    {
        $value = Setting::getValue('technician_max_tokens_per_run');

        return is_numeric($value) ? (int) $value : 100_000;
    }

    /** Whether the daily digest notification is enabled (spec §1C). Default: true. */
    public static function digestEnabled(): bool
    {
        $value = Setting::getValue('technician_digest_enabled');

        return $value === null || (bool) $value; // default true
    }

    /** Local time (HH:MM) at which the digest should fire (spec §1C). Default: 08:00. */
    public static function digestTimeLocal(): string
    {
        $value = Setting::getValue('technician_digest_time');

        return is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value) ? $value : '08:00';
    }

    /** When the last digest was sent; null if never. */
    public static function lastDigestAt(): ?Carbon
    {
        $value = Setting::getValue('technician_last_digest_at');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    /** Record that the digest was just sent now. */
    public static function recordDigestSent(): void
    {
        Setting::setValue('technician_last_digest_at', now()->toIso8601String());
    }

    /** When the worker last processed a job on the technician queue; null if never. */
    public static function workerLastSeen(): ?Carbon
    {
        $value = Setting::getValue('technician_worker_last_seen');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    /** Record that the technician worker is alive right now. */
    public static function recordWorkerSeen(): void
    {
        Setting::setValue('technician_worker_last_seen', now()->toIso8601String());
    }

    /** How often (minutes) the heartbeat command checks for a stale worker. Default: 15. */
    public static function heartbeatIntervalMinutes(): int
    {
        $value = Setting::getValue('technician_heartbeat_interval');

        return is_numeric($value) ? max(10, (int) $value) : 15;
    }

    /** When the heartbeat last sent an alert; null if never. */
    public static function lastHeartbeatAlertAt(): ?Carbon
    {
        $value = Setting::getValue('technician_last_heartbeat_alert_at');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    /** Record that the heartbeat alert was just sent now. */
    public static function recordHeartbeatAlert(): void
    {
        Setting::setValue('technician_last_heartbeat_alert_at', now()->toIso8601String());
    }

    /** @return array<string, string> */
    private static function decodeMap(string $key): array
    {
        $raw = Setting::getValue($key);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    /** @return array<int, mixed> */
    private static function decodeList(string $key): array
    {
        $raw = Setting::getValue($key);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
