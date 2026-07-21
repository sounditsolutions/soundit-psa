<?php

namespace Tests\Feature\Teams;

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

        $result = (TeamsReadOnlyToolset::executor($user->id))('wiki_create_page_alias', []);

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

        (TeamsReadOnlyToolset::executor($user->id))('wiki_create_page_alias', [
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
        $run = TeamsReadOnlyToolset::executor($user->id);

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
     */
    public function test_every_read_the_executor_can_dispatch_stays_available(): void
    {
        $user = User::factory()->create();
        $run = TeamsReadOnlyToolset::executor($user->id);

        $reads = AssistantToolExecutor::readTools();
        $this->assertGreaterThan(20, count($reads), 'The read surface has collapsed — the chat bot has lost most of its capability.');

        foreach ($reads as $name) {
            $this->assertTrue(TeamsReadOnlyToolset::allows($name), "'{$name}' is a read but the surface will not allow it.");
            $this->assertNotSame(
                self::REFUSAL,
                $run($name, []),
                "'{$name}' is a read and must stay available in chat, but the guard refused it."
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

        foreach (self::KNOWN_WRITERS as $writer) {
            $this->assertContains($writer, $writes, "{$writer} mutates state and must be classified as a write");
            $this->assertNotContains($writer, $reads, "{$writer} mutates state and must never be classified as a read");
            $this->assertFalse(TeamsReadOnlyToolset::allows($writer), "{$writer} must be refused in chat");
        }
    }

    /**
     * A read must actually read. This does not prove a handler is correctly
     * classified in general — a writer needing valid input still errors out
     * before touching anything — but it does catch a tool that mutates on the
     * way in, which is what a newly added and casually classified one would do.
     */
    public function test_reads_do_not_write(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $user = User::factory()->create();
        $run = TeamsReadOnlyToolset::executor($user->id);

        $watching = true;
        $mutations = [];
        DB::listen(function ($query) use (&$watching, &$mutations) {
            if ($watching && preg_match('/^\s*(insert|update|delete|truncate|alter|drop)\b/i', $query->sql)) {
                $mutations[] = $query->sql;
            }
        });

        foreach (AssistantToolExecutor::readTools() as $name) {
            $run($name, []);
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
        $offered = array_column(TeamsReadOnlyToolset::definitions(), 'name');

        $this->assertNotEmpty($offered);

        foreach ($offered as $name) {
            $this->assertTrue(
                TeamsReadOnlyToolset::allows($name),
                "The chat schema advertises '{$name}', which the guard refuses to run."
            );
        }
    }
}
