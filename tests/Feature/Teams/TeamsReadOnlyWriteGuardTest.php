<?php

namespace Tests\Feature\Teams;

use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Teams\TeamsReadOnlyToolset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-uw2o.10: the ReadOnly bot could write.
 *
 * TeamsReadOnlyToolset guards a chat surface named ReadOnly, and its second
 * guard filtered on a list derived from AssistantToolDefinitions::WRITE_TOOLS.
 * That constant describes the writers that FILE DEFINES — but the guard defends
 * AssistantToolExecutor, whose write set is strictly larger: it also dispatches
 * wiki_add_fact, wiki_create_page and wiki_update_page. Those are not offered by
 * any definition set, so they never appeared in a schema, but the executor
 * dispatches by NAME and the filter never named them.
 *
 * A reviewer proved it against clean HEAD: driving wiki_create_page through the
 * ReadOnly executor persisted a real WikiPage row, 0 → 1.
 *
 * The guard is now sourced one layer down, from the executor's own declaration
 * of what it can mutate — the layer it actually defends. This is the third time
 * in this bead that a guard's comment claimed completeness it did not have, so
 * these tests are written to fail on that specific shape.
 */
class TeamsReadOnlyWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every write the executor can dispatch must be refused by the ReadOnly
     * surface. Enumerated from the executor rather than restated, so a writer
     * added there without updating the guard fails here.
     */
    public function test_every_executor_write_tool_is_refused_by_the_read_only_surface(): void
    {
        $writes = AssistantToolExecutor::WRITE_TOOLS;

        $this->assertNotEmpty($writes);

        foreach ($writes as $name) {
            $this->assertContains(
                $name,
                TeamsReadOnlyToolset::MUTATING,
                "'{$name}' is dispatched by AssistantToolExecutor as a WRITE, but the ReadOnly ".
                'chat surface does not filter it — the bot named ReadOnly could perform it.'
            );
        }
    }

    /**
     * The specific writers that were live. Named explicitly, because the
     * assertion above would still pass if someone quietly shrank WRITE_TOOLS.
     */
    public function test_the_wiki_writers_are_filtered(): void
    {
        foreach (['wiki_add_fact', 'wiki_create_page', 'wiki_update_page'] as $writer) {
            $this->assertContains($writer, TeamsReadOnlyToolset::MUTATING, "{$writer} must be refused in chat");
        }
    }

    /**
     * ...and the guard must not over-block: a read wrongly listed as mutating
     * silently removes capability from the chat surface.
     */
    public function test_reads_are_not_filtered(): void
    {
        foreach (['wiki_search', 'wiki_get_page', 'list_open_tickets', 'find_persons'] as $read) {
            $this->assertNotContains($read, TeamsReadOnlyToolset::MUTATING, "{$read} is a read and must stay available");
        }
    }

    /**
     * The executor's declaration must match what it actually dispatches. Without
     * this, WRITE_TOOLS is just another hand-maintained list — the exact defect
     * this whole thread has been closing.
     */
    public function test_the_executor_declaration_matches_its_dispatch_table(): void
    {
        $source = (string) file_get_contents(app_path('Services/Assistant/AssistantToolExecutor.php'));

        foreach (AssistantToolExecutor::WRITE_TOOLS as $name) {
            $this->assertStringContainsString(
                "'{$name}' =>",
                $source,
                "WRITE_TOOLS declares '{$name}' but the executor has no dispatch arm for it"
            );
        }
    }
}
