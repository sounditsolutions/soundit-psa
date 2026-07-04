<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Str;

class TacticalConfig
{
    public static function generateWebhookKey(): string
    {
        return Str::random(48);
    }

    public static function get(string $key): ?string
    {
        return match ($key) {
            'api_key' => Setting::getEncrypted('tactical_api_key'),
            'webhook_key' => Setting::getEncrypted('tactical_webhook_key'),
            'api_url' => Setting::getValue('tactical_api_url'),
            default => Setting::getValue("tactical_{$key}"),
        };
    }

    public static function apiUrl(): ?string
    {
        return self::get('api_url');
    }

    /**
     * The Tactical *web dashboard* base URL (psa-6h5r) — a separate, plain
     * (non-secret) Setting, NOT derived from the API URL (spec §11). Used only
     * as the "Open in Tactical" browser link target; null when unset (the link
     * is hidden rather than falling back to the API root).
     */
    public static function webUrl(): ?string
    {
        // Treat a stored blank (cleared in Settings) the same as unset, so the
        // link logic / any consumer sees one "not configured" state.
        $value = self::get('web_url');

        return $value === '' ? null : $value;
    }

    public static function isConfigured(): bool
    {
        return ! empty(self::get('api_url')) && ! empty(self::get('api_key'));
    }

    public static function isEnabled(): bool
    {
        return self::isConfigured();
    }

    /**
     * Minimum alert severity to create tickets.
     * Options: 'error', 'warning', 'info'
     * Alerts below this threshold are silently ignored.
     */
    public static function alertMinSeverity(): string
    {
        return self::get('alert_min_severity') ?: 'warning';
    }

    // ── P7 auto-ticket (G6) ──────────────────────────────────────────────────

    /**
     * Whether auto-ticketing is enabled for Tactical alerts.
     * Default OFF (opt-in) to prevent first-run flooding.
     */
    public static function autoTicket(): bool
    {
        return (bool) Setting::getValue('tactical_auto_ticket');
    }

    /**
     * Minimum severity to auto-create a ticket.
     * Validated against the known severity keys; falls back to 'error' if
     * the stored value is invalid/empty (never falls through to 0 / "everything").
     */
    public static function autoTicketMinSeverity(): string
    {
        $validKeys = ['error', 'warning', 'info', 'informational'];
        $stored = strtolower(trim((string) Setting::getValue('tactical_auto_ticket_min_severity')));

        return in_array($stored, $validKeys, true) ? $stored : 'error';
    }

    // ── P7 provisioning keys ─────────────────────────────────────────────────

    /**
     * The Tactical URLAction id stored after provisioning, or null if not yet provisioned.
     */
    public static function urlActionId(): ?int
    {
        $v = Setting::getValue('tactical_url_action_id');

        return $v !== null ? (int) $v : null;
    }

    /**
     * The Tactical AlertTemplate id stored after provisioning, or null if not yet provisioned.
     */
    public static function alertTemplateId(): ?int
    {
        $v = Setting::getValue('tactical_alert_template_id');

        return $v !== null ? (int) $v : null;
    }

    /**
     * The prior default alert template id (recorded when provisioning detects a
     * different existing default and refuses to clobber it). Null when absent.
     */
    public static function priorDefaultAlertTemplateId(): ?int
    {
        $v = Setting::getValue('tactical_prior_default_alert_template_id');

        return $v !== null ? (int) $v : null;
    }

    /**
     * Whether the alert→ticket auto-provisioning webhook has been set up.
     */
    public static function isAlertProvisioned(): bool
    {
        return self::urlActionId() !== null && self::alertTemplateId() !== null;
    }

    // ── Offline-script queue (bd psa-xr84) ───────────────────────────────────

    /**
     * Whether an approved staged Tactical action whose device is offline queues
     * (and auto-runs on reconnect) instead of dead-ending. Default ON — it only
     * replaces a dead-end for already-approved actions, so it is safe on by
     * default; an operator can still turn it off.
     */
    public static function offlineQueueEnabled(): bool
    {
        $v = Setting::getValue('tactical_offline_queue_enabled');

        return $v === null ? true : (bool) $v;
    }

    /**
     * Days a queued action waits for its device before it expires and is
     * re-surfaced for explicit re-confirm (never auto-runs stale). Default 7,
     * mirroring EndpointInsight::LONG_OFFLINE_AFTER_DAYS. A zero/invalid setting
     * falls back to the default rather than disabling the safety window.
     */
    public static function offlineQueueExpiryDays(): int
    {
        $v = (int) Setting::getValue('tactical_offline_queue_expiry_days');

        return $v > 0 ? $v : 7;
    }

    /**
     * Fallback sweep cadence (minutes) so queue processing never depends solely on
     * device-sync cadence. Default 10. Zero/invalid falls back to the default.
     */
    public static function offlineQueueSweepMinutes(): int
    {
        $v = (int) Setting::getValue('tactical_offline_queue_sweep_minutes');

        return $v > 0 ? $v : 10;
    }

    /**
     * Whether to send a per-run notification when a queued action fires on
     * reconnect. Default OFF — the cockpit history is the record.
     */
    public static function offlineQueueNotifyOnRun(): bool
    {
        return (bool) Setting::getValue('tactical_offline_queue_notify_on_run');
    }
}
