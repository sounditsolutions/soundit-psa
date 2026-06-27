<?php

namespace Tests\Feature\Technician\Notify;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Technician\Notify\DigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigestLearnedSectionTest extends TestCase
{
    use RefreshDatabase;

    private function correctionFact(array $overrides = []): WikiFact
    {
        $page = WikiPage::factory()->create();

        return WikiFact::factory()->create(array_merge([
            'page_id' => $page->id,
            'source_type' => WikiFactSource::Correction,
            'created_at' => now(),
        ], $overrides));
    }

    private function syncFact(array $overrides = []): WikiFact
    {
        $page = WikiPage::factory()->create();

        return WikiFact::factory()->create(array_merge([
            'page_id' => $page->id,
            'source_type' => WikiFactSource::Sync,
            'created_at' => now(),
        ], $overrides));
    }

    public function test_section_present_with_count_and_sample(): void
    {
        $this->correctionFact(['statement' => 'Acme: no auto-close']);
        $this->correctionFact(['statement' => 'Beta uses Datto BCDR']);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringContainsString('What I learned (from your corrections):', $digest->body);
        $this->assertStringContainsString('Learned from your corrections (last 24h): 2', $digest->body);
        $this->assertTrue(
            str_contains($digest->body, 'Acme: no auto-close') || str_contains($digest->body, 'Beta uses Datto BCDR'),
            'At least one sampled statement should appear in the body'
        );
        $this->assertFalse($digest->isEmpty);
    }

    public function test_section_omitted_when_none(): void
    {
        $digest = app(DigestBuilder::class)->build();

        $this->assertStringNotContainsString('What I learned (from your corrections):', $digest->body);
    }

    public function test_24h_boundary_and_status_and_source_filters_have_teeth(): void
    {
        // Too old — should be excluded
        $this->correctionFact([
            'created_at' => now()->subDays(2),
            'statement' => 'Old fact should not appear',
        ]);

        // Retired — should be excluded
        $this->correctionFact([
            'status' => WikiFactStatus::Retired,
            'statement' => 'Retired fact should not appear',
        ]);

        // Sync source — should be excluded
        $this->syncFact(['statement' => 'Sync fact should not appear']);

        // One valid Correction fact — should be included
        $this->correctionFact(['statement' => 'Valid correction fact']);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringContainsString('Learned from your corrections (last 24h): 1', $digest->body);
        $this->assertStringContainsString('Valid correction fact', $digest->body);
        $this->assertStringNotContainsString('Old fact should not appear', $digest->body);
        $this->assertStringNotContainsString('Retired fact should not appear', $digest->body);
        $this->assertStringNotContainsString('Sync fact should not appear', $digest->body);
    }

    public function test_lessons_only_digest_still_sends(): void
    {
        // No pending drafts, no needs-human, no executed actions — only a Correction fact
        $this->correctionFact(['statement' => 'Something learned from a correction']);

        $digest = app(DigestBuilder::class)->build();

        $this->assertFalse($digest->isEmpty);
    }
}
