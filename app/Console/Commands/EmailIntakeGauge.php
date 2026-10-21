<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Agent\Intake\EmailTriageWatch;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class EmailIntakeGauge extends Command
{
    protected $signature = 'email:intake-gauge';

    protected $description = 'Check email intake routes and the Graph subscription';

    public function handle(EmailTriageWatch $watch, NotificationService $notifications): int
    {
        $expiry = null;
        $rawExpiry = Setting::getValue('graph_subscription_expiry');
        if ($rawExpiry) {
            try {
                $expiry = Carbon::parse($rawExpiry);
            } catch (\Exception) {
                // An invalid expiry is not evidence of a current subscription.
            }
        }

        $state = [
            'unwatched' => ! $watch->isWatchedByAgent(),
            'pile_up' => $watch->isPileUpRisk(),
            'subscription_lapse' => (bool) Setting::getValue('graph_mailbox') && (
                ! Setting::getValue('graph_subscription_id') || ! $expiry || $expiry->lessThanOrEqualTo(now()->addHours(20))
            ),
        ];
        $previous = json_decode(Setting::getValue('email_intake_gauge_state', '{}'), true) ?? [];
        $texts = [
            'unwatched' => 'Support inbox email is not reaching any agent route',
            'pile_up' => 'Auto-ticketing is off and no agent route is watching: inbound support email could pile up unhandled',
            'subscription_lapse' => 'Microsoft Graph mail subscription is missing, expired or due to expire within 20 hours; if it lapses, new mail will arrive only via the fallback poll',
        ];
        $autoTicket = $watch->autoTicketEnabled();

        foreach ($state as $condition => $now) {
            $was = $previous[$condition] ?? false;
            if (! $was && $now) {
                Log::critical('[EmailIntakeGauge] '.$texts[$condition], [
                    'condition' => $condition,
                    'auto_ticket' => $autoTicket,
                ]);
                $detail = 'auto_ticket: '.($autoTicket ? 'true' : 'false');
                if ($condition === 'subscription_lapse' && $expiry) {
                    $detail .= "\nexpiry: ".$expiry->toIso8601String();
                }
                $notifications->notifyEmailIntakeGauge($texts[$condition], $detail);
            }
        }

        Setting::setValue('email_intake_gauge_state', json_encode($state));

        return self::SUCCESS;
    }
}
