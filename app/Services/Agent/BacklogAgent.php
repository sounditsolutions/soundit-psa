<?php

namespace App\Services\Agent;

use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Services\Triage\ContextBuilder;
use App\Services\Triage\TriageToolDefinitions;
use App\Support\AiConfig;
use Illuminate\Support\Facades\Log;

/**
 * The Backlog Agent's tool-loop brain.
 *
 * Reasons over one stale ticket using the read-only fence (Task 4) +
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
class BacklogAgent
{
    public function __construct(
        private readonly AiClient $ai,
    ) {}

    /**
     * Reason over a stale ticket and propose closing it if clearly appropriate.
     *
     * @param  Ticket  $ticket  The stale ticket to inspect.
     */
    public function run(Ticket $ticket): void
    {
        if (! AiConfig::isConfigured() || ! AiConfig::isEnabled()) {
            return;
        }

        try {
            $system = 'You are a junior MSP technician doing an onboarding pass over an OLD ticket. '
                .'Read it with your tools. If it is clearly resolved or abandoned with no further action '
                .'needed, call `propose_close` ONCE with a one-line reason quoting the evidence and a '
                .'confidence 0–1. If it is awaiting us, awaiting the client, or still active — do NOTHING, '
                .'leave it. When unsure, leave it.';

            $userMessage = ContextBuilder::buildForTicket($ticket);

            $tools = array_merge(TriageToolDefinitions::readTools(), [ProposeCloseTool::definition()]);

            $backlogExecutor = new BacklogAgentToolExecutor($ticket, app(ProposeCloseTool::class));

            // CO-4 propose-once guard: track whether a propose_close has already been
            // dispatched this run. On a SECOND call the model returns a stop string and
            // ProposeCloseTool is NOT invoked — prevents duplicate proposals with varied
            // reasons from producing multiple TechnicianRun rows in one loop.
            $proposed = false;
            $executor = function (string $toolName, array $input) use ($backlogExecutor, &$proposed): mixed {
                if ($toolName === 'propose_close') {
                    if ($proposed) {
                        Log::info('[BacklogAgent] Suppressed duplicate propose_close call (CO-4 guard)');

                        return 'already proposed — stop';
                    }

                    $proposed = true;
                }

                return $backlogExecutor->execute($toolName, $input);
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
            Log::warning('[BacklogAgent] run() failed — skipping ticket', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
        }
    }
}
