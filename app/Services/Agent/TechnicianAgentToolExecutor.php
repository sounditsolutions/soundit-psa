<?php

namespace App\Services\Agent;

use App\Models\Ticket;
use App\Services\Triage\TriageToolExecutor;

/**
 * The agent's tool executor — a default-deny enforcement boundary between
 * the LLM tool loop and the PSA's mutating actions.
 *
 * WHAT THIS CLASS DECIDES, AND WHAT IT DOES NOT.
 *
 * It classifies tool names by KIND: which names this lane knows how to route at
 * all, and to where. It does NOT decide which capabilities are switched on — that
 * is a per-turn question, answered by TechnicianAgentSurface, which derives the
 * runnable set from the schema actually published to the model and refuses
 * everything else before reaching this class.
 *
 * The split matters. This list is deliberately flag-free: psa-hbbuq was a flag
 * that gated publication on one side while a hand-maintained list here named the
 * same tools unconditionally, and dispatch is by name, so tools the operator had
 * switched off still ran. Re-introducing an enablement check here would recreate
 * exactly that — two lists, separately maintained, free to disagree. Enablement
 * has one home, and it is not this const.
 *
 * Dispatchable names (CO-1):
 *   READ:    search_tickets, get_ticket_notes, list_client_tickets,
 *            list_client_calls, get_client_security_posture,
 *            wiki_list_pages, wiki_search, wiki_get_page
 *   ACT:     propose_close (gated mutator → ProposeCloseTool/gate)
 *            flag_attention (held NOTICE → FlagAttentionTool/gate; no execution side-effect)
 *            send_reply     (held client reply → SendReplyTool/gate; never auto-sent)
 *   RECORD:  request_tool  (recording-only, internal → RequestToolTool; no ticket/client mutation)
 *
 * Any other tool name returns self::REFUSAL. TriageToolExecutor is NEVER called for
 * an arbitrary name — the check happens first, so un-gated mutators (set_ticket_*,
 * tactical_run_diagnostic) can never reach their executing paths through this class.
 */
class TechnicianAgentToolExecutor
{
    /** What this lane returns for anything it will not run. */
    public const REFUSAL = ['error' => 'tool not available to the agent'];

    /** Names that are permitted for read delegation to TriageToolExecutor. */
    private const READ_TOOLS = [
        'search_tickets',
        'get_ticket_notes',
        'list_client_tickets',
        'list_client_calls',
        'get_client_security_posture',
        'wiki_list_pages',
        'wiki_search',
        'wiki_get_page',
    ];

    /** Names routed to a gated ACT tool or the recording-only RECORD tool. */
    private const ACTION_TOOLS = [
        'propose_close',
        'flag_attention',
        'send_reply',
        'request_tool',
    ];

    private ?TriageToolExecutor $triageExecutor = null;

    public function __construct(
        private readonly Ticket $ticket,
        private readonly ProposeCloseTool $proposeClose,
        private readonly FlagAttentionTool $flagAttention,
        private readonly SendReplyTool $sendReply,
        private readonly RequestToolTool $requestTool,
        private readonly ?array $correctionContext = null,
    ) {}

    /**
     * Every name this executor knows how to route — reads plus the gated ACT/RECORD
     * tools. This is a classification of KIND, not of enablement: it says what could
     * be dispatched, never what is switched on.
     *
     * TechnicianAgentSurface intersects it with the schema it published, so a name
     * absent here is unrunnable no matter what was published (a mutator that leaked
     * into the schema is still refused), and a name present here is unrunnable unless
     * it was published.
     *
     * @return list<string>
     */
    public function dispatchableTools(): array
    {
        return array_merge(self::ACTION_TOOLS, self::READ_TOOLS);
    }

    /**
     * Execute a tool call from the agent's LLM loop.
     *
     * Routing (strict — default deny):
     *   propose_close       → ProposeCloseTool (the gated ACT path)
     *   flag_attention      → FlagAttentionTool (the held NOTICE path; no side-effect)
     *   send_reply          → SendReplyTool (the held client-reply path; never auto-sent)
     *   allowlisted READ    → TriageToolExecutor (read-only; client-scoped)
     *   anything else       → error (never falls through to TriageToolExecutor)
     */
    public function execute(string $name, array $input): mixed
    {
        if ($name === 'propose_close') {
            return $this->proposeClose->execute($this->ticket, $input, $this->correctionContext);
        }

        if ($name === 'flag_attention') {
            return $this->flagAttention->execute($this->ticket, $input, $this->correctionContext);
        }

        if ($name === 'send_reply') {
            return $this->sendReply->execute($this->ticket, $input, $this->correctionContext);
        }

        if ($name === 'request_tool') {
            return $this->requestTool->execute($this->ticket, $input);
        }

        if (in_array($name, self::READ_TOOLS, true)) {
            return $this->triageExecutor()->execute($name, $input);
        }

        // Default deny — every un-gated mutator and unknown tool lands here.
        return self::REFUSAL;
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
