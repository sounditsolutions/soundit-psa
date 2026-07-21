<?php

namespace App\Services\Agent;

use App\Models\Ticket;
use App\Services\Triage\TriageToolDefinitions;

/**
 * ONE AI Technician turn's tool surface: the exact schema handed to the model,
 * and the executor that will run its calls — the same snapshot, carried together.
 *
 * psa-hbbuq — WHY THIS IS AN OBJECT AND NOT TWO INDEPENDENT HALVES.
 *
 * TechnicianAgent used to build the two halves from unrelated sources:
 *
 *     $tools    = TriageToolDefinitions::readTools();      // flag-GATED
 *     $executor = new TechnicianAgentToolExecutor(...);    // allowlist UNGATED
 *
 * readTools() offers the three situation drill-downs (list_client_tickets,
 * list_client_calls, get_client_security_posture) only when
 * AgentConfig::situationContextEnabled() is on. The executor's READ_TOOLS const
 * named all three unconditionally — the flag appeared nowhere in it. And
 * AiClient::runToolLoop() dispatches whatever tool NAME the model returns without
 * checking it against the schema it sent, so with the flag off (the default, and
 * the shipped posture) all three were unpublished and still RAN:
 * get_client_security_posture returned mfa_gaps, external_forwards,
 * inactive_accounts, open_device_alerts and mail_security for a capability the
 * operator had switched off.
 *
 * Not-offered is not not-callable when dispatch is by name. TechnicianAgent fences
 * the ticket body as untrusted client text (correctly — it is), so a prompt
 * injection naming one of these tools reaches the executor: the model does not need
 * a tool in its schema to emit its name.
 *
 * THE INVARIANT, AND WHY IT IS STRUCTURAL RATHER THAN MAINTAINED.
 *
 * $allowed is not filtered from the same sources as $tools — it is DERIVED FROM
 * $tools, the very array this object hands to AiClient. There is no second
 * derivation to keep in step, and nothing here re-reads the flag. The published set
 * and the runnable set are the same list read twice, so they cannot disagree about
 * a capability being on: dropping a tool from the schema removes it from the
 * allowlist in the same expression.
 *
 * That is the property the previous shape lacked. Two lists that happen to match
 * are only as correct as whoever last edited both, and this one had drifted the
 * moment the flag was introduced on one side alone.
 *
 * The dispatchability check is kept as a second conjunct even though the publisher
 * emits no mutators. It is the property the executor exists for, and it must not
 * survive merely as a side effect of how the publisher happens to be written today:
 * a schema that published set_ticket_status would still be refused here.
 */
final class TechnicianAgentSurface
{
    /**
     * @param  list<array<string, mixed>>  $tools  the schema published to the model
     * @param  list<string>  $allowed  names($tools) that the executor also knows how to route
     */
    private function __construct(
        private readonly array $tools,
        private readonly array $allowed,
        private readonly TechnicianAgentToolExecutor $inner,
    ) {}

    /**
     * Build this turn's surface: the fenced read set (flag-sensitive) plus the
     * gated ACT tools and the recording-only RECORD tool.
     *
     * readTools() is resolved exactly ONCE, here, and the result is both published
     * and derived from. The agent's published set exists nowhere else — there is no
     * second assembly of it for a caller to pair up wrongly.
     */
    public static function forTicket(Ticket $ticket, ?array $correctionContext = null): self
    {
        // A2b: send_reply is OFFERED to the model — the agent is the SOLE producer of
        // held client replies. It is always held for operator approval (Approve-tier,
        // never auto-sent). The whole reply capability stays dormant until the
        // operator enables the agent (AgentConfig).
        $tools = array_merge(TriageToolDefinitions::readTools(), [
            ProposeCloseTool::definition(),
            FlagAttentionTool::definition(),
            SendReplyTool::definition(),
            RequestToolTool::definition(),
        ]);

        return self::of($tools, new TechnicianAgentToolExecutor(
            $ticket,
            app(ProposeCloseTool::class),
            app(FlagAttentionTool::class),
            app(SendReplyTool::class),
            app(RequestToolTool::class),
            $correctionContext,
        ));
    }

    /**
     * Bind a turn to the schema it published.
     *
     * @param  list<array<string, mixed>>  $tools  the array that will be handed to AiClient, verbatim
     */
    public static function of(array $tools, TechnicianAgentToolExecutor $inner): self
    {
        return new self(
            $tools,
            array_values(array_intersect(array_column($tools, 'name'), $inner->dispatchableTools())),
            $inner,
        );
    }

    /**
     * The schema for this turn. Must be handed to AiClient AS IS — the allowlist was
     * derived from this array, so publishing anything else breaks the only property
     * this class provides.
     *
     * @return list<array<string, mixed>>
     */
    public function tools(): array
    {
        return $this->tools;
    }

    /** Will this turn run $name? True only for names this turn published. */
    public function allows(string $name): bool
    {
        return in_array($name, $this->allowed, true);
    }

    /**
     * The executor for this turn: refuses anything outside the published set BEFORE
     * the inner executor is reached.
     *
     * This guard is load-bearing, not belt-and-braces. AiClient::runToolLoop()
     * dispatches whatever tool NAME comes back from the model without checking it
     * against the schema it sent (hardening that seam for every surface is filed
     * separately as psa-ejzjd). So this allowlist is what makes an unpublished
     * capability unrunnable.
     *
     * The refusal is the inner executor's own default-deny value, unchanged: an
     * unpublished tool must be indistinguishable from an unknown one, so a caller
     * probing by name learns nothing about which capabilities exist but are off.
     *
     * @return callable(string, array<string, mixed>): mixed
     */
    public function executor(): callable
    {
        return function (string $name, array $input): mixed {
            if (! $this->allows($name)) {
                return TechnicianAgentToolExecutor::REFUSAL;
            }

            return $this->inner->execute($name, $input);
        };
    }
}
