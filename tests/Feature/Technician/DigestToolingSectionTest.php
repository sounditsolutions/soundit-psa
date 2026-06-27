<?php

namespace Tests\Feature\Technician;

use App\Enums\ToolingGapStatus;
use App\Models\ToolingGap;
use App\Services\Technician\Notify\DigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigestToolingSectionTest extends TestCase
{
    use RefreshDatabase;

    // 1. 2 open gaps (created now) → section shown
    public function test_section_present_with_count_and_sample(): void
    {
        $gap1 = ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Open,
            'created_at' => now(),
            'capability_gap' => 'Agent needs to check ticket history for prior context',
        ]);
        $gap2 = ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Open,
            'created_at' => now(),
            'capability_gap' => 'Agent needs a tool to query DNS logs directly',
        ]);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringContainsString('Tooling gaps to review:', $digest->body);
        $this->assertStringContainsString('Tooling gaps to review (last 24h): 2', $digest->body);
        $this->assertTrue(
            str_contains($digest->body, $gap1->capability_gap) || str_contains($digest->body, $gap2->capability_gap),
            'At least one sampled capability_gap should appear in the body'
        );
        $this->assertFalse($digest->isEmpty);
    }

    // 2. none → detail section header omitted (count line at 0 may still appear)
    public function test_section_header_omitted_when_none(): void
    {
        $digest = app(DigestBuilder::class)->build();

        $this->assertStringNotContainsString('Tooling gaps to review:', $digest->body);
    }

    // 3. filters have teeth: open+last24h only
    public function test_24h_boundary_and_status_filters_have_teeth(): void
    {
        // Too old — should be excluded
        ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Open,
            'created_at' => now()->subDays(2),
            'capability_gap' => 'Old gap should not appear',
        ]);

        // Wrong status (Triaged) — should be excluded
        ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Triaged,
            'created_at' => now(),
            'capability_gap' => 'Triaged gap should not appear',
        ]);

        // One valid Open gap created now — should be included
        ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Open,
            'created_at' => now(),
            'capability_gap' => 'Valid open gap from last 24h',
        ]);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringContainsString('Tooling gaps to review (last 24h): 1', $digest->body);
        $this->assertStringContainsString('Valid open gap from last 24h', $digest->body);
        $this->assertStringNotContainsString('Old gap should not appear', $digest->body);
        $this->assertStringNotContainsString('Triaged gap should not appear', $digest->body);
    }

    // 4. evidence NEVER in the digest (privacy contract)
    public function test_evidence_never_in_digest(): void
    {
        ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Open,
            'created_at' => now(),
            'capability_gap' => 'The abstract capability gap description',
            'evidence' => 'PRIVATE_EVIDENCE_SENTINEL_12345',
        ]);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringNotContainsString('PRIVATE_EVIDENCE_SENTINEL_12345', $digest->body);
        $this->assertStringContainsString('The abstract capability gap description', $digest->body);
    }

    // 5. gaps-only digest still sends (isEmpty = false)
    public function test_gaps_only_digest_still_sends(): void
    {
        // No pending drafts, no needs-human, no executed actions, no learned facts — only an open gap
        ToolingGap::factory()->create([
            'status' => ToolingGapStatus::Open,
            'created_at' => now(),
        ]);

        $digest = app(DigestBuilder::class)->build();

        $this->assertFalse($digest->isEmpty);
    }
}
