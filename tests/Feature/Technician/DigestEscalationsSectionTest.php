<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Technician\Notify\DigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class DigestEscalationsSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    /** Helper — create a flag_attention TechnicianRun with a given category and optional timestamp. */
    private function flagRun(string $category, array $overrides = []): TechnicianRun
    {
        $ticket = Ticket::factory()->create();

        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'flag_attention',
            'content_hash' => str_pad(md5($category.microtime()), 64, '0'),
            'state' => TechnicianRunState::Flagged,
            'proposed_meta' => ['category' => $category],
        ]);

        // created_at is not in $fillable so cannot be set via create(); use a direct
        // update to set a backdated timestamp for 24h-boundary tests.
        if (isset($overrides['created_at'])) {
            TechnicianRun::whereKey($run->id)->update(['created_at' => $overrides['created_at']]);
        }

        return $run;
    }

    // 1. Section present with category breakdown (2 runs, 2 categories)
    public function test_section_present_with_category_breakdown(): void
    {
        $this->flagRun('needs_decision');
        $this->flagRun('needs_hands_onsite');

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringContainsString('Escalations raised (last 24h): 2', $digest->body);
        $this->assertStringContainsString('Escalations (by category):', $digest->body);
        $this->assertStringContainsString('Needs a decision: 1', $digest->body);
        $this->assertStringContainsString('Needs hands on site: 1', $digest->body);
        $this->assertFalse($digest->isEmpty);
    }

    // 2. Omitted when none — detail header absent (count line at 0 may remain)
    public function test_section_header_omitted_when_none(): void
    {
        $digest = app(DigestBuilder::class)->build();

        $this->assertStringNotContainsString('Escalations (by category):', $digest->body);
    }

    // 3. 24h boundary has teeth: too-old run excluded, recent run counted
    public function test_24h_boundary_has_teeth(): void
    {
        // Too old — should be excluded
        $this->flagRun('needs_overflow', ['created_at' => now()->subDays(2)]);

        // Recent — should be included
        $this->flagRun('needs_decision', ['created_at' => now()]);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringContainsString('Escalations raised (last 24h): 1', $digest->body);
        $this->assertStringContainsString('Needs a decision: 1', $digest->body);
        $this->assertStringNotContainsString('Needs overflow help', $digest->body);
    }

    // 4. Escalations-only digest still sends (isEmpty = false)
    public function test_escalations_only_digest_still_sends(): void
    {
        // No pending drafts, no needs-human, no executed actions, no learned facts, no gaps — only a flag
        $this->flagRun('uncertain');

        $digest = app(DigestBuilder::class)->build();

        $this->assertFalse($digest->isEmpty);
    }
}
