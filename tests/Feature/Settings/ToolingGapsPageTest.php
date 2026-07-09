<?php

namespace Tests\Feature\Settings;

use App\Enums\ToolingGapClassification;
use App\Enums\ToolingGapSource;
use App\Enums\ToolingGapStatus;
use App\Models\ToolingGap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operator review UI for the AI tooling-gap backlog (psa-g25o, Part 2).
 *
 * Before this page, ToolingGaps were visible ONLY via the tooling-gaps:list CLI.
 * These tests pin the web surface: auth-gated, status-filtered listing, and
 * lifecycle transitions.
 */
class ToolingGapsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_page_requires_authentication(): void
    {
        $this->get(route('settings.tooling-gaps.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_gaps_with_capability_tool_and_labels(): void
    {
        ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Open,
            'capability_gap' => 'UNIQUE_SYMPTOM_SENTINEL_ABC',
            'tool_name' => 'ninja_get_devices',
            'classification' => ToolingGapClassification::ToolBroken,
            'source' => ToolingGapSource::Agent,
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.tooling-gaps.index'))
            ->assertOk()
            ->assertSee('AI Tooling Gaps')
            ->assertSee('UNIQUE_SYMPTOM_SENTINEL_ABC')
            ->assertSee('ninja_get_devices')
            ->assertSee('Tool broken')       // classification label
            ->assertSee('Agent self-report'); // source label
    }

    public function test_status_filter_scopes_the_list(): void
    {
        ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Open,
            'capability_gap' => 'OPEN_GAP_SENTINEL_AAA',
        ]);
        ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Resolved,
            'capability_gap' => 'RESOLVED_GAP_SENTINEL_BBB',
        ]);

        // Default (open) hides the resolved gap.
        $this->actingAs($this->user)
            ->get(route('settings.tooling-gaps.index'))
            ->assertOk()
            ->assertSee('OPEN_GAP_SENTINEL_AAA')
            ->assertDontSee('RESOLVED_GAP_SENTINEL_BBB');

        // ?status=resolved shows only the resolved gap.
        $this->actingAs($this->user)
            ->get(route('settings.tooling-gaps.index', ['status' => 'resolved']))
            ->assertOk()
            ->assertDontSee('OPEN_GAP_SENTINEL_AAA')
            ->assertSee('RESOLVED_GAP_SENTINEL_BBB');

        // ?status=all shows both.
        $this->actingAs($this->user)
            ->get(route('settings.tooling-gaps.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee('OPEN_GAP_SENTINEL_AAA')
            ->assertSee('RESOLVED_GAP_SENTINEL_BBB');
    }

    public function test_update_transitions_status(): void
    {
        $gap = ToolingGap::factory()->create(['status' => ToolingGapStatus::Open]);

        $this->actingAs($this->user)
            ->patch(route('settings.tooling-gaps.update', $gap), ['status' => 'resolved'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(ToolingGapStatus::Resolved, $gap->fresh()->status);
    }

    public function test_update_rejects_an_invalid_status(): void
    {
        $gap = ToolingGap::factory()->create(['status' => ToolingGapStatus::Open]);

        $this->actingAs($this->user)
            ->patch(route('settings.tooling-gaps.update', $gap), ['status' => 'not_a_status'])
            ->assertSessionHasErrors('status');

        // Unchanged.
        $this->assertSame(ToolingGapStatus::Open, $gap->fresh()->status);
    }

    public function test_update_requires_authentication(): void
    {
        $gap = ToolingGap::factory()->create(['status' => ToolingGapStatus::Open]);

        $this->patch(route('settings.tooling-gaps.update', $gap), ['status' => 'resolved'])
            ->assertRedirect(route('login'));

        $this->assertSame(ToolingGapStatus::Open, $gap->fresh()->status);
    }
}
