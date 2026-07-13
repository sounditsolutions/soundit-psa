<?php

namespace App\Services\Agent\Intake;

use App\Models\Setting;
use App\Services\Signals\SignalRouter;

/**
 * "Is anyone actually watching the support inbox?"
 *
 * psa-28j4.3 / so-4nd9. Charlie asked to STOP auto-email→ticket, keep emails LANDING, and
 * have CHET NOTIFIED so he wins the create-vs-attach decision. He was explicit about which
 * clause matters: ship the first two WITHOUT the notify and inbound support email silently
 * piles up unhandled — strictly WORSE than the duplicate tickets he has today, because a
 * duplicate ticket is VISIBLE and an unread support email is not.
 *
 * That failure state is one checkbox away at all times. `email_auto_ticket` is a plain
 * toggle in Settings → Integrations, and the intake signal it leaves behind
 * (intake.email_unresolved) goes NOWHERE unless an operator has separately wired a signal
 * ROUTE to an enabled MCP DESTINATION. Out of the box, none exists — the emission fires
 * into a void, and every layer of that void is quiet: the signal row is written, the router
 * matches no route, and no delivery is attempted. Nothing errors. The inbox just stops
 * being anybody's job.
 *
 * So this is the guard, and it sits at the CHOKE POINT rather than at a call site. It does
 * not block the toggle and it does not auto-repair a config — an operator may legitimately
 * run auto-ticketing off with humans working the unresolved-email queue. It refuses only
 * one thing: to let that state be SILENT.
 */
class EmailTriageWatch
{
    /** The signal an untriaged inbound email raises. */
    public const SIGNAL = 'intake.email_unresolved';

    public function __construct(private readonly SignalRouter $router) {}

    /** Does PSA still auto-create tickets from inbound email? */
    public function autoTicketEnabled(): bool
    {
        return (bool) Setting::getValue('email_auto_ticket');
    }

    /**
     * Does an untriaged inbound email actually reach an agent's MCP inbox?
     *
     * The probe is deliberately sent with NO client_id context. An unresolved email very
     * often has no client (that is frequently *why* it is unresolved), so a route filtered
     * by client_ids does not watch the emails that most need a human or an agent. Probing
     * with the weakest context asks the honest question — would the WORST case still reach
     * somebody? — instead of the flattering one.
     */
    public function isWatchedByAgent(): bool
    {
        return $this->router->wouldReachMcpDestination(self::SIGNAL);
    }

    /**
     * The dangerous state: PSA creates no ticket AND no agent is notified, so a real
     * support email lands and becomes nobody's job.
     *
     * (Staff can still opt into the UnresolvedInboundEmail notification, but that is
     * per-user opt-in and may have zero subscribers — it is a fallback, not a guarantee,
     * and it is not what Charlie asked for. He asked for Chet.)
     */
    public function isPileUpRisk(): bool
    {
        return ! $this->autoTicketEnabled() && ! $this->isWatchedByAgent();
    }
}
