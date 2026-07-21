<?php

namespace Tests\Feature\Teams;

use App\Enums\EmailDirection;
use App\Models\Email;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiPage;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Teams\TeamsReadOnlyToolset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * psa-uw2o.13 / psa-uw2o.14: the ReadOnly bot could write, and the guard suite said it could not.
 *
 * TeamsReadOnlyToolset guards a Teams bot named ReadOnly. It denylisted the
 * tools named in AssistantToolExecutor::WRITE_TOOLS and passed everything else
 * through to the executor, which dispatches BY NAME. So a mutating tool was
 * permitted by DEFAULT and only became forbidden once somebody remembered to
 * name it in the constant. Two reviewers, independently, added a mutating
 * dispatch arm beside wiki_create_page without naming it — and driving that name
 * through the ReadOnly surface persisted a real WikiPage row, 0 -> 1.
 *
 * The old suite could not catch it, by construction:
 *   - MUTATING was DERIVED from WRITE_TOOLS, so asserting one contained the
 *     other was a tautology that could not fail;
 *   - it checked DECLARED -> DISPATCH ("every name in the list has an arm") and
 *     never DISPATCH -> CLASSIFIED ("every arm has a classification"), which is
 *     the direction the defect travels.
 *
 * Two things changed, and the tests below are shaped to hold both:
 *   1. The executor's dispatch table now registers every tool as a
 *      [ToolEffect, handler] PAIR, so a dispatchable tool is necessarily a
 *      classified one. There is no separate write-list left to fall out of sync.
 *   2. This surface allowlists READS instead of denylisting writes, so anything
 *      unclassified, unrecognised or new is refused by default rather than
 *      permitted by default.
 *
 * These tests therefore assert BEHAVIOUR through the real surface — what the
 * guard does when driven — rather than comparing two lists that are derived from
 * each other.
 */
class TeamsReadOnlyWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSAL = ['error' => 'That tool is not available in chat (read-only).'];

    /**
     * The writers that were live when this defect was found. Spelled out rather
     * than derived: the derived assertions below stay green if the classification
     * SHRINKS, so something has to pin the specific names. Removing any of these
     * from the executor's write set fails here.
     */
    private const KNOWN_WRITERS = [
        'create_ticket',
        'add_ticket_note',
        'propose_close',
        'wiki_add_fact',
        'wiki_create_page',
        'wiki_update_page',
    ];

    /**
     * FAIL-CLOSED. The guard must refuse what it has not positively classified as
     * a read, rather than passing unrecognised names through to the executor and
     * trusting a denylist to have named every writer.
     */
    public function test_the_read_only_surface_refuses_a_tool_it_has_not_classified_as_a_read(): void
    {
        $user = User::factory()->create();

        $result = (TeamsReadOnlyToolset::forTurn($user->id)->executor())('wiki_create_page_alias', []);

        $this->assertSame(
            self::REFUSAL,
            $result,
            'The ReadOnly surface passed an unclassified tool name through to the executor instead of refusing it.'
        );
    }

    /**
     * The exploit, end to end: a mutating dispatch arm that no write-list names
     * must not be able to persist anything through the bot called ReadOnly.
     */
    public function test_an_unclassified_mutating_dispatch_arm_cannot_write_through_the_read_only_surface(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $user = User::factory()->create();

        (TeamsReadOnlyToolset::forTurn($user->id)->executor())('wiki_create_page_alias', [
            'slug' => 'runbooks/guard-probe',
            'title' => 'Guard Probe',
            'body_md' => "## Probe\n\nThe ReadOnly bot must never persist this.\n",
            'change_summary' => 'Probe',
        ]);

        $this->assertSame(
            0,
            WikiPage::count(),
            'The ReadOnly chat surface persisted a wiki page — a mutating tool it never classified was executed.'
        );
    }

    /**
     * Every write the executor can dispatch is refused when driven through the
     * surface. Enumerated from the executor's own dispatch table, so a writer
     * added there is covered here the moment it exists.
     */
    public function test_every_write_the_executor_can_dispatch_is_refused_by_the_surface(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $user = User::factory()->create();
        $run = TeamsReadOnlyToolset::forTurn($user->id)->executor();

        $writes = AssistantToolExecutor::writeTools();
        $this->assertNotEmpty($writes, 'The executor reports no write tools at all — the classification is not being read.');

        foreach ($writes as $name) {
            $this->assertSame(
                self::REFUSAL,
                $run($name, []),
                "'{$name}' mutates state but the ReadOnly chat surface executed it."
            );
        }
    }

    /**
     * ...and the guard must NOT over-block. A read wrongly refused silently
     * removes capability from the chat surface, which a write-refusal test alone
     * would never notice — a guard that refuses everything would pass it.
     *
     * psa-uw2o.17 changed WHAT "not over-blocking" means here, and the change is
     * deliberate. This used to demand that every read the EXECUTOR can dispatch
     * stay runnable in Teams — which is precisely the over-broad expectation that
     * left 40 unadvertised tools reachable, because the executor is shared with
     * the MCP staff server and carries its reads too. The bot's capability is
     * what it PUBLISHES; that is the set that must not shrink, and every member
     * of it must really run.
     */
    public function test_every_read_the_teams_surface_publishes_stays_available(): void
    {
        $user = User::factory()->create();
        $surface = TeamsReadOnlyToolset::forTurn($user->id);
        $run = $surface->executor();

        $offered = array_column($surface->tools(), 'name');
        $this->assertGreaterThan(15, count($offered), 'The published surface has collapsed — the chat bot has lost most of its capability.');

        foreach ($offered as $name) {
            $this->assertTrue($surface->allows($name), "'{$name}' is published but the surface will not allow it.");
            $this->assertNotSame(
                self::REFUSAL,
                $run($name, []),
                "'{$name}' is published to the model and must stay runnable, but the guard refused it."
            );
        }
    }

    /**
     * The specific writers that were live, pinned by name. The derived assertions
     * above would all stay green if someone quietly reclassified one of these as
     * a read, because they would simply stop being enumerated as writes.
     */
    public function test_the_known_writers_are_still_classified_as_writes(): void
    {
        $writes = AssistantToolExecutor::writeTools();
        $reads = AssistantToolExecutor::readTools();
        $surface = TeamsReadOnlyToolset::forTurn(null);

        foreach (self::KNOWN_WRITERS as $writer) {
            $this->assertContains($writer, $writes, "{$writer} mutates state and must be classified as a write");
            $this->assertNotContains($writer, $reads, "{$writer} mutates state and must never be classified as a read");
            $this->assertFalse($surface->allows($writer), "{$writer} must be refused in chat");
        }
    }

    /**
     * A read must actually read. This does not prove a handler is correctly
     * classified in general — a writer needing valid input still errors out
     * before touching anything — but it does catch a tool that mutates on the
     * way in, which is what a newly added and casually classified one would do.
     *
     * Driven through the INNER executor, not the Teams guard. The guard now
     * refuses anything Teams does not publish (psa-uw2o.17), so routing this
     * through it would quietly stop executing 40 of the 59 read handlers and
     * shrink to a test of the guard rather than of the classification. The
     * property under test belongs to the executor — every tool it classifies as
     * a read must be one — and it is asserted over ALL of them, including the
     * MCP-only reads that Teams never sees.
     */
    public function test_reads_do_not_write(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $user = User::factory()->create();
        $executor = new AssistantToolExecutor(null, null, $user->id);

        $watching = true;
        $mutations = [];
        DB::listen(function ($query) use (&$watching, &$mutations) {
            if ($watching && preg_match('/^\s*(insert|update|delete|truncate|alter|drop)\b/i', $query->sql)) {
                $mutations[] = $query->sql;
            }
        });

        $reads = AssistantToolExecutor::readTools();
        $this->assertGreaterThan(20, count($reads), 'the executor reports almost no reads — the classification is not being read');

        foreach ($reads as $name) {
            $executor->execute($name, []);
        }

        $watching = false;

        $this->assertSame([], $mutations, 'A tool classified as a read issued a write: '.implode(' | ', $mutations));
    }

    /**
     * The published schema and the set of tools that will actually run must be
     * the same allowlist. A writer advertised but refused wastes a turn; a writer
     * advertised AND run is the original defect.
     */
    public function test_the_published_schema_offers_only_tools_the_surface_will_run(): void
    {
        $surface = TeamsReadOnlyToolset::forTurn(null);
        $offered = array_column($surface->tools(), 'name');

        $this->assertNotEmpty($offered);

        foreach ($offered as $name) {
            $this->assertTrue(
                $surface->allows($name),
                "The chat schema advertises '{$name}', which the guard refuses to run."
            );
        }
    }

    /**
     * psa-uw2o.17: ...and the OTHER direction, which is the one that was missing.
     *
     * The test above only checks offered => runnable. The previous commit
     * asserted in a comment that filtering both definitions() and the executor
     * guard through the same allows() meant the two "cannot disagree". They do
     * not disagree about the FILTER; they disagreed about the BASE SET.
     * definitions() filters the merged AssistantToolDefinitions surface (19
     * names); allows() filtered the executor's entire read classification (59).
     * Identical filtering over different inputs is not equality, and the 40-name
     * gap was silently runnable by name.
     *
     * Asserting BOTH directions is what makes "the schema is the allowlist" a
     * fact rather than a claim.
     */
    public function test_the_teams_surface_runs_exactly_what_the_teams_schema_publishes(): void
    {
        $user = User::factory()->create();
        // ONE surface for both halves — which is now the only way to ask. Taking
        // the schema and the executor from separate calls is what psa-uw2o.21/.22
        // exploited: each resolved the vendor availability probes for itself, so
        // the two could describe different turns. TeamsPerTurnToolSurfaceTest
        // drives that timing directly; this asserts the resulting set equality.
        $surface = TeamsReadOnlyToolset::forTurn($user->id);
        $run = $surface->executor();

        $offered = array_column($surface->tools(), 'name');
        sort($offered);

        // Probed against the LIVE executor, not just the predicate. The surface
        // snapshots the allowlist rather than calling allows() per invocation, so
        // asserting the predicate alone would leave the snapshot unpinned — and
        // "two things that agree today" is the shape of every defect on this PR.
        // Every dispatchable name is a candidate, so a write leaking in fails here too.
        $everyDispatchable = array_merge(
            AssistantToolExecutor::readTools(),
            AssistantToolExecutor::writeTools(),
        );

        $runnable = array_values(array_filter(
            $everyDispatchable,
            fn (string $n) => $run($n, []) !== self::REFUSAL
        ));
        sort($runnable);

        $this->assertSame(
            $offered,
            $runnable,
            "What the Teams bot will RUN is not what its schema PUBLISHES.\n".
            'Runnable but unpublished: '.(implode(', ', array_diff($runnable, $offered)) ?: '(none)')."\n".
            'Published but refused: '.(implode(', ', array_diff($offered, $runnable)) ?: '(none)')
        );

        // ...and the public predicate must describe that same set, so a caller
        // asking allows() gets the truth about what the executor will do.
        foreach ($everyDispatchable as $name) {
            $this->assertSame(
                in_array($name, $offered, true),
                $surface->allows($name),
                "allows('{$name}') disagrees with what the executor actually does with it."
            );
        }
    }

    /**
     * psa-uw2o.17, the exploit end to end.
     *
     * list_email_items is dispatchable by AssistantToolExecutor (it belongs to
     * the MCP staff surface, which shares the executor) and is classified Read,
     * so the old executor-wide allowlist permitted it — while the Teams schema
     * never advertised it. AiClient::executeToolLoop() dispatches whatever name
     * comes back, so an unadvertised arm stayed reachable and handed FULL EMAIL
     * METADATA to a bot whose published surface says it cannot see email at all.
     */
    public function test_an_unpublished_read_cannot_be_run_by_name_through_the_teams_surface(): void
    {
        $user = User::factory()->create();

        Email::create([
            'graph_id' => null,
            'direction' => EmailDirection::Inbound,
            'from_address' => 'someone@example.test',
            'to_recipients' => [['address' => 'support@example.test']],
            'subject' => 'Hidden read probe',
            'body_preview' => 'Should never reach the ReadOnly Teams bot.',
            'body_text' => 'Should never reach the ReadOnly Teams bot.',
            'has_attachments' => false,
            'importance' => 'normal',
            'received_at' => now(),
            'is_read' => false,
        ]);

        $surface = TeamsReadOnlyToolset::forTurn($user->id);

        $this->assertNotContains(
            'list_email_items',
            array_column($surface->tools(), 'name'),
            'precondition: the Teams schema must not publish list_email_items'
        );

        $result = ($surface->executor())('list_email_items', ['limit' => 5]);

        $this->assertSame(
            self::REFUSAL,
            $result,
            'The Teams ReadOnly bot executed a tool it never published and got email data back: '.
            json_encode($result)
        );
    }
}
