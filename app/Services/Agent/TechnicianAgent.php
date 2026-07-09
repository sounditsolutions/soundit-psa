<?php

namespace App\Services\Agent;

use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Services\Technician\PromptFence;
use App\Services\Triage\ContextBuilder;
use App\Services\Triage\TriageToolDefinitions;
use App\Support\AgentConfig;
use App\Support\AiConfig;
use Illuminate\Support\Facades\Log;

/**
 * The agent's tool-loop brain.
 *
 * Reasons over a ticket using the read-only fence (Task 4) +
 * propose_close (Task 3) and decides whether to propose closing it.
 *
 * Safety invariants:
 *  - Constructor-inject AiClient so tests can mock it.
 *  - run() NEVER throws — any Throwable is caught and logged (fail-soft).
 *  - The tool list is the fenced read set + propose_close (no mutators).
 *  - CO-4 propose-once guard: the second propose_close call in a single run
 *    is refused before it reaches ProposeCloseTool, preventing duplicate rows.
 *  - The agent NEVER closes a ticket itself — only propose_close, which is
 *    the gated path (held by default; auto only above the opt-in threshold).
 */
class TechnicianAgent
{
    public function __construct(
        private readonly AiClient $ai,
    ) {}

    /**
     * Convenience factory that builds an Opus-configured instance for production use.
     * Tests should inject a mock AiClient via the constructor instead.
     * Mirrors SignificanceGate::haiku().
     */
    public static function withConfiguredModel(): self
    {
        return new self(new AiClient(AgentConfig::agentModel()));
    }

    /**
     * Reason over a ticket and propose closing it if clearly appropriate.
     *
     * @param  Ticket  $ticket  The ticket to inspect.
     * @param  array|null  $correctionContext  When the run was triggered by an operator
     *                                         correction, carries ['conversation_id', 'operator_id',
     *                                         'summary'] so each ACT tool can record provenance in
     *                                         the resulting TechnicianRun's proposed_meta. Null on
     *                                         a normal (non-correction) run.
     */
    public function run(Ticket $ticket, ?array $correctionContext = null): void
    {
        if (! AiConfig::isConfigured() || ! AiConfig::isEnabled()) {
            return;
        }

        try {
            $system = 'You are a junior MSP technician reviewing a ticket. Read it with your tools, then take '
                .'AT MOST ONE action. If it is clearly resolved or abandoned with no further action needed, call '
                .'`propose_close` ONCE with a one-line reason quoting the evidence and a confidence 0–1. '
                .'If the client is awaiting a substantive reply and you can genuinely move the ticket forward, call '
                .'`send_reply` ONCE with a short reason — the system drafts the message body in house voice and it is '
                .'ALWAYS held for a person to approve, never sent automatically (you do not write the body yourself). '
                .'If instead it genuinely needs a human — a decision you cannot make, something you cannot resolve, '
                .'or blocking ambiguity that needs a person — call `flag_attention` ONCE with a 1–3 sentence reason '
                .'and the best-fit category. A flag means "a person needs to look at this", NOT "I did not close it"; '
                .'it does nothing to the ticket. Use it sparingly, only for a genuine need for human attention. '
                .'Otherwise — awaiting us internally, awaiting the client, still active, or simply low-value — do '
                .'NOTHING, leave it. When unsure, LEAVE IT. Take only ONE action per ticket. '
                .'If you needed a tool or data you lacked — or an existing tool misbehaved (errored or returned '
                .'wrong/empty data) — you may also call `request_tool` to flag it for the team; it does not count '
                .'as your action.'
                // The context below (ticket + the fenced Client Situation digest) is UNTRUSTED client text.
                .' '.PromptFence::UNTRUSTED_INPUT_NOTICE;

            $userMessage = ContextBuilder::buildForTicket($ticket, includeClientSituation: true);

            // A2b: send_reply is now OFFERED to the model — the agent is the SOLE producer
            // of held client replies (DraftPipeline's reply branch is retired). It is always
            // held for operator approval (Approve-tier, never auto-sent). The whole reply
            // capability stays dormant until the operator enables the agent (AgentConfig).
            $tools = array_merge(TriageToolDefinitions::readTools(), [
                ProposeCloseTool::definition(),
                FlagAttentionTool::definition(),
                SendReplyTool::definition(),
                RequestToolTool::definition(),
            ]);

            $toolExecutor = new TechnicianAgentToolExecutor(
                $ticket,
                app(ProposeCloseTool::class),
                app(FlagAttentionTool::class),
                app(SendReplyTool::class),
                app(RequestToolTool::class),
                $correctionContext,
            );

            // One-action-per-run guard (CO-4, generalised for Increment H): the agent
            // takes AT MOST one action per ticket — propose_close OR flag_attention OR
            // nothing. The FIRST of either lands; any SECOND action call (of either type)
            // returns a stop string and is NOT dispatched, so a single loop can never
            // produce two TechnicianRun rows (e.g. a close AND a flag, or two flags with
            // different reasons that would each pass the tools' own idempotency).
            $acted = false;
            $executor = function (string $toolName, array $input) use ($toolExecutor, &$acted): mixed {
                if (in_array($toolName, ['propose_close', 'flag_attention', 'send_reply'], true)) {
                    if ($acted) {
                        Log::info('[TechnicianAgent] Suppressed a second action call (one-action-per-run guard)', ['tool' => $toolName]);

                        return 'already acted on this ticket — stop';
                    }

                    $acted = true;
                }

                return $toolExecutor->execute($toolName, $input);
            };

            $this->ai->runToolLoop(
                system: $system,
                userMessage: $userMessage,
                tools: $tools,
                executor: $executor,
                maxRounds: 10,
                maxTokenBudget: 200_000,
                wallClockSeconds: 240,
            );
        } catch (\Throwable $e) {
            Log::warning('[TechnicianAgent] run() failed — skipping ticket', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
        }
    }
}
