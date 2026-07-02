<?php

namespace App\Services\Technician\Notify;

use App\Models\User;
use App\Services\Agent\Escalation\OperatorDelivery;
use App\Services\EmailService;
use App\Support\TeamsBotConfig;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;

/**
 * The single seam every Technician notification flows through. Delivers to Teams
 * (if a webhook is configured) AND to the operator's email (if set) — independently
 * and fail-soft, so a Teams outage never loses the notification and an email outage
 * never stops Teams. Email is the always-on fallback when configured.
 *
 * notifyUser() addresses a specific operator: sends through the modern operator
 * delivery channel (Teams bot when configured, legacy webhook fallback, plus email)
 * and optionally sends an SMS (CO-3: phone sourced from TechnicianConfig::operatorPhone,
 * not the users table).
 */
class OperatorNotifier
{
    public function __construct(
        private readonly TeamsNotifier $teams,
        private readonly EmailService $email,
        private readonly SmsNotifier $sms,
        private readonly OperatorDelivery $delivery,
    ) {}

    public function notify(string $subject, string $body): void
    {
        $this->teams->post($subject, $body); // fail-soft internally

        $to = TechnicianConfig::notifyEmail();
        if ($to !== null) {
            try {
                $this->email->sendNew($to, $subject, $body, null, null, null);
            } catch (\Throwable $e) {
                Log::warning('[Technician] Operator notify email failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Address a specific operator: post to the shared Teams chat (bot when configured,
     * webhook fallback otherwise), email them directly, and — only when $sms is true
     * and a phone is configured for that user — send an SMS (CO-3: phone sourced from
     * TechnicianConfig::operatorPhone, not the users table; CO-11a: caller is
     * responsible for non-identifying SMS text).
     *
     * CO-5d/CO-11a: $body may carry sensitive detail (e.g. a bearer ack URL) that
     * must NEVER reach SMS. When $smsText is supplied it is sent verbatim to SMS
     * instead of the body; when null the legacy "$subject — $body" is used so
     * existing callers are unaffected. Escalation passes a non-identifying stub.
     */
    public function notifyUser(int $userId, string $subject, string $body, bool $sms = false, ?string $smsText = null): void
    {
        $user = User::find($userId);
        if ($user === null) {
            return;
        }

        $this->delivery->send(
            $user,
            TeamsBotConfig::escalationConversationId(),
            TeamsBotConfig::escalationServiceUrl(),
            $subject,
            $body,
        );

        $phone = TechnicianConfig::operatorPhone($userId);
        if ($sms && is_string($phone) && $phone !== '') {
            $this->sms->send($phone, $smsText ?? $subject.' — '.$body);
        }
    }
}
