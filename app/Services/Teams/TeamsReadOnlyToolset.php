<?php

namespace App\Services\Teams;

use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Assistant\AssistantToolExecutor;

/**
 * The assistant tool surface, hardened for the Teams staff chat (E2a): every
 * MUTATING tool is stripped from the schema AND a second guard in the executor
 * refuses them outright. The bot can look things up but can never change anything.
 */
class TeamsReadOnlyToolset
{
    /** Tools that Teams staff chat must never expose or execute. */
    /**
     * Derived from the EXECUTOR's write set, not the definitions'.
     *
     * psa-uw2o.6 first replaced a hardcoded list here with a derivation from
     * AssistantToolDefinitions::WRITE_TOOLS. psa-uw2o.10 showed that was the
     * WRONG LAYER and the gap was live: this class strips writers for a bot
     * named ReadOnly, but it guards AssistantToolExecutor, which dispatches
     * three wiki writers no definition set offers — dispatch is by NAME, so an
     * unadvertised tool is still reachable. A reviewer drove wiki_create_page
     * through this surface and persisted a real WikiPage row.
     *
     * Guard at the layer you defend: this now sources from
     * AssistantToolExecutor::WRITE_TOOLS, which is what actually executes.
     */
    public const MUTATING = AssistantToolExecutor::WRITE_TOOLS;

    /** What the executor returns if a mutating tool is somehow requested. */
    private const REFUSAL = ['error' => 'That tool is not available in chat (read-only).'];

    /**
     * The FULL read-only assistant surface for the staff chat: the general queue
     * tools (getTools(false): list_open_tickets, list_my_tickets, search_all_tickets,
     * get_queue_stats …) MERGED with the PSA entity lookups + integration reads
     * (getTools(true): find_persons, find_assets, get_*, ninja/cipp …), deduplicated
     * by name, with unavailable action tools removed. This is the surface the teammate needs
     * to answer both "what's open?" and "find this person". Client-scoped tools return
     * a graceful "no client context" when used without one.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        $merged = array_merge(
            AssistantToolDefinitions::getTools(false),
            AssistantToolDefinitions::getTools(true),
        );

        $seen = [];
        $out = [];
        foreach ($merged as $tool) {
            $name = $tool['name'] ?? null;
            if (! is_string($name) || in_array($name, self::MUTATING, true) || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out[] = $tool;
        }

        return $out;
    }

    /**
     * A tool executor that runs as $userId but refuses mutating tools BEFORE the
     * inner executor is ever reached (defense in depth — the schema already hides
     * them, this guarantees they cannot run even if a name slips through).
     *
     * @return callable(string, array<string, mixed>): mixed
     */
    public static function executor(?int $userId): callable
    {
        $inner = new AssistantToolExecutor(null, null, $userId);

        return function (string $name, array $input) use ($inner): mixed {
            if (in_array($name, self::MUTATING, true)) {
                return self::REFUSAL;
            }

            return $inner->execute($name, $input);
        };
    }
}
