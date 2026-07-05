<?php

namespace Tests\Feature\Teams;

use App\Models\TeamsPersona;
use Database\Seeders\TeamsPersonaScaffoldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Gus scaffold seeder (Teams AI-Staff Personas P1 Task 5) — a manual-only,
 * idempotent way to hand-register a DISABLED Gus persona row so the AI-Staff
 * roster has something to render during the P1 feel-test. Never wired into
 * DatabaseSeeder::run(); P2 replaces this with a real provisioning wizard.
 */
class TeamsPersonaScaffoldSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_a_disabled_gus_persona_with_no_secret(): void
    {
        (new TeamsPersonaScaffoldSeeder)->run();

        $gus = TeamsPersona::where('persona_key', 'gus')->firstOrFail();

        $this->assertSame('Gus', $gus->display_name);
        $this->assertFalse($gus->enabled);
        $this->assertFalse($gus->hasSecret());
        $this->assertNull($gus->mcp_token_label);
    }

    public function test_running_it_twice_is_idempotent(): void
    {
        (new TeamsPersonaScaffoldSeeder)->run();
        (new TeamsPersonaScaffoldSeeder)->run();

        $this->assertSame(1, TeamsPersona::where('persona_key', 'gus')->count());
    }
}
