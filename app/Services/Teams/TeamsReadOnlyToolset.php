<?php

namespace App\Services\Teams;

use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Assistant\AssistantToolExecutor;

/**
 * The assistant tool surface, hardened for the Teams staff chat (E2a): only
 * tools the executor classifies as READS are published in the schema, and a
 * second guard refuses everything else before the executor is reached. The bot
 * can look things up but can never change anything.
 *
 * Both filters are allowlists over the same source (see allows()). Nothing here
 * enumerates writers — a guard that has to name what it blocks is only as
 * complete as that list, and on this surface it twice was not.
 */
class TeamsReadOnlyToolset
{
    /** What the executor returns for anything this surface will not run. */
    private const REFUSAL = ['error' => 'That tool is not available in chat (read-only).'];

    /**
     * May the ReadOnly chat surface run this tool? Only if the executor that
     * would run it classifies it as a READ.
     *
     * This is an ALLOWLIST, and that is the whole guard. It used to be a
     * denylist of known writers, which meant the bot's safety depended on that
     * list having named every writer the executor could dispatch — so a
     * mutating tool was permitted by DEFAULT and stayed permitted until someone
     * remembered to name it. Twice, in review, someone did not: a mutating arm
     * added beside wiki_create_page went unnamed and the bot called ReadOnly
     * persisted a real WikiPage row, with the guard suite green.
     *
     * Allowlisting inverts the default. An unrecognised name, a newly added
     * tool, a writer nobody classified — each is refused because none of them
     * is on the read list, not because someone predicted it. The list itself
     * comes from AssistantToolExecutor's dispatch table, where the effect is
     * declared on the same entry as the handler, so it cannot omit a tool that
     * exists (psa-uw2o.13, psa-uw2o.14).
     */
    public static function allows(string $name): bool
    {
        return in_array($name, AssistantToolExecutor::readTools(), true);
    }

    /**
     * The FULL read-only assistant surface for the staff chat: the general queue
     * tools (getTools(false): list_open_tickets, list_my_tickets, search_all_tickets,
     * get_queue_stats …) MERGED with the PSA entity lookups + integration reads
     * (getTools(true): find_persons, find_assets, get_*, ninja/cipp …), deduplicated
     * by name, keeping only what the executor classifies as a read. This is the surface the teammate needs
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

        // Same allowlist as the executor guard, so the published schema and the
        // set of tools that will actually run cannot disagree.
        $allowed = AssistantToolExecutor::readTools();

        $seen = [];
        $out = [];
        foreach ($merged as $tool) {
            $name = $tool['name'] ?? null;
            if (! is_string($name) || ! in_array($name, $allowed, true) || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out[] = $tool;
        }

        return $out;
    }

    /**
     * A tool executor that runs as $userId but refuses anything outside the read
     * allowlist BEFORE the inner executor is ever reached (defense in depth —
     * the schema already hides them, this guarantees they cannot run even if a
     * name slips through).
     *
     * @return callable(string, array<string, mixed>): mixed
     */
    public static function executor(?int $userId): callable
    {
        $inner = new AssistantToolExecutor(null, null, $userId);

        return function (string $name, array $input) use ($inner): mixed {
            if (! self::allows($name)) {
                return self::REFUSAL;
            }

            return $inner->execute($name, $input);
        };
    }
}
