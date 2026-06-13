<?php

namespace Tests\Unit\Qa;

use App\Services\Qa\QaScenario;
use App\Services\Qa\QaScenarioLoader;
use PHPUnit\Framework\TestCase;

class QaScenarioLoaderTest extends TestCase
{
    private string $md = "## tix-resolve: Resolve a ticket with a resolution\n- goal: confirm resolving captures a resolution and triggers mining\n- setup: a triaged open ticket for an active client\n- steps:\n  1. Open the ticket\n  2. Set status to Resolved and enter a resolution\n- expect:\n  - The ticket shows Resolved with the resolution text\n  - A wiki_run completes for the client\n- watch:\n  - Does the Resolve action prompt for a resolution, or silently allow empty?\n";

    public function test_parses_a_scenario_block(): void
    {
        $scenarios = (new QaScenarioLoader)->parse($this->md);

        $this->assertCount(1, $scenarios);
        $s = $scenarios[0];
        $this->assertInstanceOf(QaScenario::class, $s);
        $this->assertSame('tix-resolve', $s->id);
        $this->assertSame('Resolve a ticket with a resolution', $s->title);
        $this->assertStringContainsString('triggers mining', $s->goal);
        $this->assertCount(2, $s->steps);
        $this->assertCount(2, $s->expectations);
        $this->assertCount(1, $s->watchFors);
        $this->assertStringContainsString('prompt for a resolution', $s->watchFors[0]);
    }

    public function test_parses_multiple_scenarios(): void
    {
        $md = $this->md."\n## tix-merge: Merge two tickets\n- goal: merging closes the secondary\n- setup: two open tickets\n- steps:\n  1. Merge B into A\n- expect:\n  - B is closed\n- watch:\n  - Is the merge reversible or clearly warned?\n";

        $scenarios = (new QaScenarioLoader)->parse($md);

        $this->assertCount(2, $scenarios);
        $this->assertSame('tix-merge', $scenarios[1]->id);
    }

    public function test_loads_from_directory(): void
    {
        $dir = sys_get_temp_dir().'/qa-scen-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/tickets.md', $this->md);

        $scenarios = (new QaScenarioLoader)->loadDir($dir);

        $this->assertCount(1, $scenarios);
        $this->assertSame('tix-resolve', $scenarios[0]->id);

        unlink($dir.'/tickets.md');
        rmdir($dir);
    }
}
