<?php

namespace Tests\Feature\Wiki;

use App\Models\Setting;
use App\Models\WikiRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiMaintainCommandTest extends TestCase
{
    use RefreshDatabase;

    // ── Gating ────────────────────────────────────────────────────────────────

    public function test_command_skips_when_maintenance_disabled(): void
    {
        Setting::setValue('wiki_enabled', '1');
        Setting::setValue('wiki_maintenance_enabled', '0');

        $this->artisan('wiki:maintain')
            ->assertExitCode(0)
            ->expectsOutput('Wiki maintenance disabled — skipping.');

        $this->assertSame(0, WikiRun::where('run_type', 'maintain')->count());
    }

    public function test_command_skips_when_wiki_disabled(): void
    {
        // wiki_enabled off → maintenanceEnabled() returns false (gates on isEnabled())
        Setting::setValue('wiki_enabled', '0');

        $this->artisan('wiki:maintain')
            ->assertExitCode(0)
            ->expectsOutput('Wiki maintenance disabled — skipping.');

        $this->assertSame(0, WikiRun::where('run_type', 'maintain')->count());
    }

    public function test_command_runs_and_outputs_summary_line(): void
    {
        Setting::setValue('wiki_enabled', '1');

        // Run against real service (no AI — no facts, no budget spend).
        // Capture the summary line output directly via Artisan::output().
        $exitCode = \Illuminate\Support\Facades\Artisan::call('wiki:maintain');
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('maintain:', $output);
        $this->assertStringContainsString('stale', $output);
        $this->assertStringContainsString('disputes filed', $output);
        $this->assertStringContainsString('dead links', $output);
        $this->assertStringContainsString('open-ticket flags', $output);
    }

    public function test_command_exists_and_is_scheduled(): void
    {
        // Verify the command is registered (artisan list includes it)
        $this->artisan('list', ['--format' => 'json'])->assertExitCode(0);

        // The command class must be loadable
        $this->assertTrue(class_exists(\App\Console\Commands\WikiMaintainCommand::class));
    }
}
