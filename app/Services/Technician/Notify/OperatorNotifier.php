<?php

namespace App\Services\Technician\Notify;

use App\Services\EmailService;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\Log;

/**
 * The single seam every Technician notification flows through. Delivers to Teams
 * (if a webhook is configured) AND to the operator's email (if set) — independently
 * and fail-soft, so a Teams outage never loses the notification and an email outage
 * never stops Teams. Email is the guaranteed always-on fallback.
 */
class OperatorNotifier
{
    public function __construct(
        private readonly TeamsNotifier $teams,
        private readonly EmailService $email,
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
}
