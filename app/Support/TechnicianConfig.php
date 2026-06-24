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

    // ── Phase 2: emergency / escalation config ──────────────────────────────

    /**
     * Minutes after which a ticket of the given priority is considered an emergency.
     * Setting: technician_emergency_age_minutes (JSON {p1,p2,p3,p4}).
     */
    public static function emergencyAgeMinutes(\App\Enums\TicketPriority $p): int
    {
        $defaults = ['p1' => 15, 'p2' => 60, 'p3' => 240, 'p4' => 1440];
        $map = self::decodeMap('technician_emergency_age_minutes');
        $val = $map[$p->value] ?? $defaults[$p->value] ?? 240;

        return max(1, (int) $val);
    }

    /**
     * Keywords that flag an inbound ticket as an emergency.
     * Setting: technician_emergency_keywords (JSON string array).
     *
     * @return array<string>
     */
    public static function emergencyKeywords(): array
    {
        $default = ['down', 'outage', 'offline', 'ransomware', 'breach', 'hacked', 'no internet', 'cannot work', 'urgent', 'emergency'];
        $raw = Setting::getValue('technician_emergency_keywords');
        $list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($list) && $list !== [] ? array_values(array_filter(array_map('strval', $list))) : $default;
    }

    /**
     * Minutes to wait for a human response before escalating to the next person.
     * Setting: technician_escalation_timeout. Floor: 5.
     */
    public static function escalationTimeoutMinutes(): int
    {
        $value = Setting::getValue('technician_escalation_timeout');

        return is_numeric($value) ? max(5, (int) $value) : 15;
    }

    /**
     * Whether the given user is available for escalation (default: true when unset).
     * Setting: technician_operator_availability (JSON {userId: "1"/"0"}).
     */
    public static function operatorAvailable(int $userId): bool
    {
        $map = self::decodeMap('technician_operator_availability');
        if (! array_key_exists((string) $userId, $map)) {
            return true; // unset ⇒ available
        }

        return (bool) $map[(string) $userId];
    }

    /** Toggle availability for a single operator in the shared map. */
    public static function setOperatorAvailable(int $userId, bool $covering): void
    {
        $map = self::decodeMap('technician_operator_availability');
        $map[(string) $userId] = $covering ? '1' : '0';
        Setting::setValue('technician_operator_availability', json_encode($map));
    }

    /**
     * Minutes wide the grouping window is for storm detection.
     * Setting: technician_storm_window. Default: 15.
     */
    public static function stormWindowMinutes(): int
    {
        $value = Setting::getValue('technician_storm_window');

        return is_numeric($value) ? max(1, (int) $value) : 15;
    }

    /**
     * Message sent to clients when the Technician hits its maximum-hold state.
     * Setting: technician_max_hold_message.
     */
    public static function maxHoldMessage(): string
    {
        $value = Setting::getValue('technician_max_hold_message');

        return is_string($value) && trim($value) !== ''
            ? $value
            : "Thank you for reaching out. We've flagged this as urgent and are working to get a technician to you as quickly as possible. We'll be in touch shortly.";
    }

    /**
     * Minutes between repeat emergency pings when no one has responded.
     * Setting: technician_emergency_reping. Floor: 5. Default: 30.
     */
    public static function emergencyRepingMinutes(): int
    {
        $value = Setting::getValue('technician_emergency_reping');

        return is_numeric($value) ? max(5, (int) $value) : 30;
    }

    // ── CO-3: operator phone map (users table has no phone column) ───────────

    /**
     * Phone number for a specific operator, from the Setting map keyed by user id.
     * Setting: technician_operator_phones (JSON {userId: "number"}).
     * Returns null when unset or empty.
     */
    public static function operatorPhone(int $userId): ?string
    {
        $map = self::decodeMap('technician_operator_phones');
        $value = $map[(string) $userId] ?? '';

        return trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Store or clear the phone number for a single operator.
     * Passing null or empty string removes the entry from the map.
     */
    public static function setOperatorPhone(int $userId, ?string $phone): void
    {
        $map = self::decodeMap('technician_operator_phones');
        $trimmed = trim((string) $phone);

        if ($trimmed !== '') {
            $map[(string) $userId] = $trimmed;
        } else {
            unset($map[(string) $userId]);
        }

        Setting::setValue('technician_operator_phones', json_encode($map));
    }

    // ── private helpers ──────────────────────────────────────────────────────

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
