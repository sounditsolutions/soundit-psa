<?php

namespace App\Services\Agent;

use App\Models\Ticket;
use App\Services\Triage\TriageToolExecutor;

/**
 * The Backlog Agent's tool executor — a default-deny enforcement boundary between
 * the LLM tool loop and the PSA's mutating actions.
 *
 * Allowlist (CO-1):
 *   READ: search_tickets, get_ticket_notes, wiki_list_pages, wiki_search, wiki_get_page
 *   ACT:  propose_close (the only gated mutator — routes through ProposeCloseTool/gate)
 *
 * Any other tool name returns ['error' => 'tool not available to the backlog agent'].
 * TriageToolExecutor is NEVER called for an arbitrary name — the check happens first,
 * so un-gated mutators (set_ticket_*, tactical_run_diagnostic) can never reach their
 * executing paths through this class.
 */
class BacklogAgentToolExecutor
{
    /** Names that are permitted for read delegation to TriageToolExecutor. */
    private const READ_TOOLS = [
        'search_tickets',
        'get_ticket_notes',
        'wiki_list_pages',
        'wiki_search',
        'wiki_get_page',
    ];

    private ?TriageToolExecutor $triageExecutor = null;

    public function __construct(
        private readonly Ticket $ticket,
        private readonly ProposeCloseTool $proposeClose,
    ) {}

    /**
     * Execute a tool call from the Backlog Agent's LLM loop.
     *
     * Routing (strict — default deny):
     *   propose_close       → ProposeCloseTool (the gated ACT path)
     *   allowlisted READ    → TriageToolExecutor (read-only; client-scoped)
     *   anything else       → error (never falls through to TriageToolExecutor)
     */
    public function execute(string $name, array $input): mixed
    {
        if ($name === 'propose_close') {
            return $this->proposeClose->execute($this->ticket, $input);
        }

        if (in_array($name, self::READ_TOOLS, true)) {
            return $this->triageExecutor()->execute($name, $input);
        }

        // Default deny — every un-gated mutator and unknown tool lands here.
        return ['error' => 'tool not available to the backlog agent'];
    }

    /**
     * Lazily construct the TriageToolExecutor when a read tool is first called.
     * Construction is deferred so the mutator-refusal path never touches it.
     */
    private function triageExecutor(): TriageToolExecutor
    {
        return $this->triageExecutor ??= new TriageToolExecutor($this->ticket);
    }
}
