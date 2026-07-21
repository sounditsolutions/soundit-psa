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
 *
 * THE PUBLIC SURFACE IS ONE METHOD, forTurn(), AND THAT IS DELIBERATE.
 *
 * There used to be two: definitions() for the schema and executor() for the
 * runner. Callers took both — and executor() resolved the published set a SECOND
 * time to build its allowlist. Both calls read the live vendor availability
 * probes, so they were two answers to the same question asked a moment apart,
 * and a lane that flipped in between made the bot's schema and the bot's
 * behaviour disagree. In the granting direction that is a fail-open: the
 * executor runs something the model was never offered (psa-uw2o.21). In the
 * revoking direction it is an over-block: the executor refuses something that
 * WAS offered (psa-uw2o.22).
 *
 * A caller cannot make that mistake now, because there is nothing to pair up
 * wrongly. forTurn() resolves availability ONCE and hands back a
 * TeamsReadOnlySurface carrying both halves, with the allowlist derived from the
 * published array itself. definitions() is private: the only way to obtain this
 * schema is from the object that also knows how to run it.
 */
class TeamsReadOnlyToolset
{
    /**
     * Resolve ONE Teams turn's read-only surface: the schema to publish and the
     * executor that will run its calls, from a single availability snapshot.
     *
     * NOT FREE TO CALL — and that is the second reason this is once-per-turn.
     * Resolving the published set runs the vendor availability probes behind
     * getTools(true), and LevelClient::isHealthy() is an unconditional live HTTP
     * GET (NinjaClient's is a cached token, but misses hit the network too). The
     * old shape paid for that twice per turn to answer a question whose answer
     * must not change mid-turn anyway.
     *
     * The executor's read classification is snapshotted here too, so the same
     * $reads filters what gets published AND backs the surface's allowlist.
     */
    public static function forTurn(?int $userId): TeamsReadOnlySurface
    {
        $reads = AssistantToolExecutor::readTools();

        return TeamsReadOnlySurface::of(self::publish($reads), $reads, $userId);
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
     * Config-dependent, deliberately — AssistantToolDefinitions merges the vendor
     * lanes only when those integrations are live, so a lane that is off is
     * neither offered nor runnable, and one that is on is both. That is exactly
     * why it must be resolved once per turn and shared, not re-derived.
     *
     * @param  list<string>  $reads  AssistantToolExecutor::readTools() for this turn
     * @return list<array<string, mixed>>
     */
    private static function publish(array $reads): array
    {
        $merged = array_merge(
            AssistantToolDefinitions::getTools(false),
            AssistantToolDefinitions::getTools(true),
        );

        // Keep only reads. NOTE what this does and does not buy: it makes the
        // published set a SUBSET of the executor's reads. Subset is not equality,
        // and an earlier version of this class claimed equality on exactly that
        // basis — that because the schema and the guard both filtered through
        // readTools(), the two "cannot disagree". They did not disagree about the
        // FILTER; they disagreed about the BASE SET. This filters the merged
        // AssistantToolDefinitions surface (19 names); the guard filtered the
        // executor's ENTIRE read classification (59), which also carries the MCP
        // staff server's reads and every vendor lane. Identical filtering over
        // different inputs is not equality, and a reviewer drove list_email_items
        // through the gap and got email metadata back.
        //
        // Equality is delivered by TeamsReadOnlySurface deriving its allowlist
        // FROM the array this method returns, not by both sides calling
        // readTools(). $reads is threaded in rather than read here so one
        // snapshot covers both uses.
        $seen = [];
        $out = [];
        foreach ($merged as $tool) {
            $name = $tool['name'] ?? null;
            if (! is_string($name) || ! in_array($name, $reads, true) || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out[] = $tool;
        }

        return $out;
    }
}
