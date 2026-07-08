<?php

namespace Tests\Feature\Teams;

use App\Models\McpToken;
use App\Models\TeamsPersona;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * mcp_token_label uniqueness (Teams AI-Staff Personas P1 Task 5) — the whole
 * cross-persona isolation guarantee rests on TeamsPersonaConfig::byTokenLabel()
 * (a `firstWhere`, i.e. "first match wins") being 1:1 with persona rows. T1
 * shipped mcp_token_label as a plain index; this hardens it to a
 * nullable-unique DB constraint so a labeling collision fails loudly at
 * write time instead of silently shadowing a persona at read time.
 */
class TeamsPersonaTokenLabelUniquenessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function personaAttributes(array $overrides = []): array
    {
        return array_merge([
            'persona_key' => 'gus',
            'display_name' => 'Gus',
            'bot_app_id' => 'app-1',
            'enabled' => false,
        ], $overrides);
    }

    public function test_two_personas_cannot_share_a_non_null_token_label(): void
    {
        McpToken::create(['label' => 'shared-label', 'token_hash' => hash('sha256', 'shared-label-token')]);

        TeamsPersona::create($this->personaAttributes([
            'persona_key' => 'gus',
            'bot_app_id' => 'app-1',
            'mcp_token_label' => 'shared-label',
        ]));

        $this->expectException(QueryException::class);

        TeamsPersona::create($this->personaAttributes([
            'persona_key' => 'other',
            'bot_app_id' => 'app-2',
            'mcp_token_label' => 'shared-label',
        ]));
    }

    public function test_two_personas_can_both_have_a_null_token_label(): void
    {
        TeamsPersona::create($this->personaAttributes([
            'persona_key' => 'gus',
            'bot_app_id' => 'app-1',
            'mcp_token_label' => null,
        ]));

        TeamsPersona::create($this->personaAttributes([
            'persona_key' => 'other',
            'bot_app_id' => 'app-2',
            'mcp_token_label' => null,
        ]));

        $this->assertSame(2, TeamsPersona::count());
    }
}
