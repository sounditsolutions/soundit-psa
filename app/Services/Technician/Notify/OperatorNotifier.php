<?php

namespace App\Services\Technician\Notify;

use App\Models\User;
use App\Services\EmailService;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;

/**
 * The single seam every Technician notification flows through. Delivers to Teams
 * (if a webhook is configured) AND to the operator's email (if set) — independently
 * and fail-soft, so a Teams outage never loses the notification and an email outage
 * never stops Teams. Email is the always-on fallback when configured.
 *
 * notifyUser() addresses a specific operator: emails them directly, posts to the
 * shared Teams webhook, and optionally sends an SMS (CO-3: phone sourced from
 * TechnicianConfig::operatorPhone, not the users table).
 */
class OperatorNotifier
{
    public function __construct(
        private readonly TeamsNotifier $teams,
        private readonly EmailService $email,
        private readonly SmsNotifier $sms,
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
     * Address a specific operator: post to the shared Teams webhook, email them
     * directly, and — only when $sms is true and a phone is configured for that
     * user — send an SMS (CO-3: phone sourced from TechnicianConfig::operatorPhone,
     * not the users table; CO-11a: caller is responsible for non-identifying SMS text).
     */
    public function notifyUser(int $userId, string $subject, string $body, bool $sms = false): void
    {
        $user = User::find($userId);
        if ($user === null) {
            return;
        }

        $this->teams->post($subject, $body); // shared channel, fail-soft

        if (! empty($user->email)) {
            try {
                $this->email->sendNew($user->email, $subject, $body, null, null, null);
            } catch (\Throwable $e) {
                Log::warning('[Technician] notifyUser email failed', ['error' => $e->getMessage()]);
            }
        }

        $phone = TechnicianConfig::operatorPhone($userId);
        if ($sms && is_string($phone) && $phone !== '') {
            $this->sms->send($phone, $subject.' — '.$body);
        }
    }
}
