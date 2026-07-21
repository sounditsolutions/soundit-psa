<?php

namespace App\Services\Teams;

use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Assistant\AssistantToolExecutor;

/**
 * The assistant tool surface, hardened for the Teams staff chat (E2a): the bot
 * may run a tool only if this surface PUBLISHED it and the executor classifies
 * it as a READ. The published schema is the allowlist. The bot can look things
 * up but can never change anything, and can never reach a capability its own
 * schema does not advertise.
 *
 * Nothing here enumerates writers — a guard that has to name what it blocks is
 * only as complete as that list, and on this surface it twice was not.
 */
class TeamsReadOnlyToolset
{
    /** What the executor returns for anything this surface will not run. */
    private const REFUSAL = ['error' => 'That tool is not available in chat (read-only).'];

    /**
     * May the ReadOnly chat surface run this tool? Only if it is BOTH published
     * in this surface's own schema AND classified as a read by the executor that
     * would run it.
     *
     * This is an ALLOWLIST, and that is the whole guard. It used to be a
     * denylist of known writers, which meant the bot's safety depended on that
     * list having named every writer the executor could dispatch — so a
     * mutating tool was permitted by DEFAULT and stayed permitted until someone
     * remembered to name it. Twice, in review, someone did not: a mutating arm
     * added beside wiki_create_page went unnamed and the bot called ReadOnly
     * persisted a real WikiPage row, with the guard suite green.
     *
     * psa-uw2o.17 — WHY THE PUBLISHED SCHEMA, AND NOT JUST THE READ LIST.
     * The previous version allowlisted AssistantToolExecutor::readTools() alone,
     * and a comment asserted that because definitions() filtered through the
     * same call, "the published schema and the set of tools that will actually
     * run cannot disagree". That was false, and the reason is worth stating
     * plainly: the two filters were identical but their BASE SETS were not.
     * definitions() filters the merged AssistantToolDefinitions surface; this
     * filtered the executor's ENTIRE read classification, which also carries the
     * MCP staff server's reads (list_email_items, get_email_item, list_invoices,
     * list_phone_calls, …) plus every vendor lane. Nineteen names were offered
     * and fifty-nine were runnable. Identical filtering over different inputs is
     * not equality.
     *
     * It mattered because AiClient::executeToolLoop() dispatches whatever tool
     * NAME comes back from the model without checking it against the schema it
     * sent. So the forty unadvertised arms were reachable by name, and a
     * reviewer drove list_email_items straight through this executor and got
     * email metadata back. Anchoring to the published names closes that by
     * construction: a capability this surface does not advertise is one it
     * cannot run.
     *
     * The read check is kept as well rather than leaned out of definitions(). It
     * is the property this class exists for, and it must not become a
     * consequence of how some other method happens to be written today.
     */
    public static function allows(string $name): bool
    {
        return in_array($name, self::runnable(), true);
    }

    /**
     * The exact set this surface will execute: published AND read-classified.
     *
     * Config-dependent, deliberately — AssistantToolDefinitions merges the
     * vendor lanes only when those integrations are live, so a lane that is off
     * is neither offered nor runnable, and one that is on is both.
     *
     * NOT FREE TO CALL. Resolving it runs the vendor availability probes behind
     * getTools(true), and LevelClient::isHealthy() is an unconditional live HTTP
     * GET (NinjaClient's is a cached token, but misses hit the network too). So
     * executor() snapshots this ONCE per Teams turn rather than re-deriving it on
     * every tool call — see there for why that is also the more correct reading.
     *
     * @return list<string>
     */
    public static function runnable(): array
    {
        return array_values(array_intersect(
            array_column(self::definitions(), 'name'),
            AssistantToolExecutor::readTools(),
        ));
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

        // Keep only reads. NOTE what this does and does not buy: it makes the
        // published set a SUBSET of the executor's reads, which is why the
        // earlier claim here — that sharing this call with the executor guard
        // meant the two "cannot disagree" — was false in the direction that
        // mattered. Subset is not equality. Equality is delivered by allows()
        // anchoring to THIS list (see runnable()), not by both sides calling
        // readTools(). TeamsReadOnlyWriteGuardTest asserts both directions.
        //
        // This must read the executor's classification DIRECTLY, never
        // self::runnable() — runnable() is defined in terms of this method, so
        // routing it back through here is unbounded recursion.
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
     * A tool executor that runs as $userId but refuses anything outside the
     * allowlist BEFORE the inner executor is ever reached (defense in depth —
     * the schema already hides them, this guarantees they cannot run even if a
     * name slips through, which on this surface it did: psa-uw2o.17).
     *
     * The allowlist is SNAPSHOT once, here, and closed over — it is the set of
     * names published for THIS Teams turn. Two reasons, and the first is the one
     * that matters:
     *
     *  - Correctness. TeamsReplyService builds the schema from definitions()
     *    once and then runs this executor for each tool call in the loop. If a
     *    vendor integration flipped mid-turn, re-deriving the allowlist per call
     *    would let it drift from the schema the model was actually handed — a
     *    fresh instance of the exact bug this commit closes. One snapshot cannot.
     *  - Cost. runnable() runs the vendor health probes; Level's is a live HTTP
     *    GET. Per tool call, in a chat loop, that is a network round trip each
     *    time for an answer that must not change mid-turn anyway.
     *
     * @return callable(string, array<string, mixed>): mixed
     */
    public static function executor(?int $userId): callable
    {
        $inner = new AssistantToolExecutor(null, null, $userId);
        $allowed = self::runnable();

        return function (string $name, array $input) use ($inner, $allowed): mixed {
            if (! in_array($name, $allowed, true)) {
                return self::REFUSAL;
            }

            return $inner->execute($name, $input);
        };
    }
}
