<?php

namespace Tests\Feature\Console;

use App\Enums\ToolingGapClassification;
use App\Enums\ToolingGapSource;
use App\Enums\ToolingGapStatus;
use App\Models\ToolingGap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ToolingGapsListTest extends TestCase
{
    use RefreshDatabase;

    // 1. Lists open gaps: capability shown, evidence hidden by default
    public function test_lists_open_gaps_capability_shown_evidence_hidden_by_default(): void
    {
        ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Open,
            'capability_gap' => 'UNIQUE_CAPABILITY_GAP_SENTINEL_ABC',
            'evidence' => 'PRIVATE_EVIDENCE_SENTINEL_XYZ',
            'classification' => ToolingGapClassification::ToolMissing,
            'source' => ToolingGapSource::Agent,
        ]);

        Artisan::call('tooling-gaps:list');
        $output = Artisan::output();

        $this->assertStringContainsString('UNIQUE_CAPABILITY_GAP_SENTINEL_ABC', $output);
        $this->assertStringContainsString('Tool missing', $output);    // classification label
        $this->assertStringContainsString('Agent self-report', $output); // source label
        $this->assertStringNotContainsString('PRIVATE_EVIDENCE_SENTINEL_XYZ', $output);
    }

    // 2. --with-evidence includes the evidence column
    public function test_with_evidence_flag_includes_evidence(): void
    {
        ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Open,
            'capability_gap' => 'UNIQUE_CAPABILITY_GAP_SENTINEL_DEF',
            'evidence' => 'PRIVATE_EVIDENCE_SENTINEL_UVW',
        ]);

        Artisan::call('tooling-gaps:list', ['--with-evidence' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('PRIVATE_EVIDENCE_SENTINEL_UVW', $output);
    }

    // 3. --status filter: open, resolved, all
    public function test_status_filter_open_resolved_and_all(): void
    {
        ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Open,
            'capability_gap' => 'OPEN_GAP_SENTINEL_AAA',
        ]);
        ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Resolved,
            'capability_gap' => 'RESOLVED_GAP_SENTINEL_BBB',
        ]);

        // Default (open) shows only the open gap
        Artisan::call('tooling-gaps:list');
        $output = Artisan::output();
        $this->assertStringContainsString('OPEN_GAP_SENTINEL_AAA', $output);
        $this->assertStringNotContainsString('RESOLVED_GAP_SENTINEL_BBB', $output);

        // --status=resolved shows only the resolved gap
        Artisan::call('tooling-gaps:list', ['--status' => 'resolved']);
        $output = Artisan::output();
        $this->assertStringNotContainsString('OPEN_GAP_SENTINEL_AAA', $output);
        $this->assertStringContainsString('RESOLVED_GAP_SENTINEL_BBB', $output);

        // --status=all shows both
        Artisan::call('tooling-gaps:list', ['--status' => 'all']);
        $output = Artisan::output();
        $this->assertStringContainsString('OPEN_GAP_SENTINEL_AAA', $output);
        $this->assertStringContainsString('RESOLVED_GAP_SENTINEL_BBB', $output);
    }

    // 4. Empty list → friendly message
    public function test_empty_list_prints_friendly_message(): void
    {
        $this->artisan('tooling-gaps:list')
            ->expectsOutputToContain('No tooling gaps')
            ->assertExitCode(0);
    }

    // 5. Bad --status doesn't crash (fromInput defaults to Open)
    public function test_bad_status_defaults_to_open_and_exits_zero(): void
    {
        $this->artisan('tooling-gaps:list', ['--status' => 'garbage'])
            ->assertExitCode(0);
    }
}
