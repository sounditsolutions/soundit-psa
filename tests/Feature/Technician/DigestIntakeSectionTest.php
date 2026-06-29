<?php

namespace Tests\Feature\Technician;

use App\Enums\TechnicianRunState;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Technician\Notify\DigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The digest 'Intake' section (psa-xcyo Task 3).
 *
 * Gated on AgentConfig::intakeEnabled(). When off (the default) the digest body
 * must be byte-identical to what it was before the feature shipped — the "Intake
 * routed" line must not appear even if intake_route records exist.
 *
 * Mirrors DigestEscalationsSectionTest style.
 */
class DigestIntakeSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    /** Create an intake_route run. autoAttached=true → Done/attached; false → AwaitingApproval. */
    private function intakeRun(bool $autoAttached): TechnicianRun
    {
        $ticket = Ticket::factory()->create();

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'intake_route',
            'content_hash' => hash('sha256', 'intake-digest-'.microtime().rand()),
            'state' => $autoAttached ? TechnicianRunState::Done : TechnicianRunState::AwaitingApproval,
            'proposed_meta' => [
                'attached' => $autoAttached,
                'suggested_ticket_id' => 1,
            ],
            'tokens_used' => 0,
        ]);
    }

    // 1. Section present when enabled: count breakdown and isEmpty false

    public function test_section_present_with_count_breakdown(): void
    {
        Setting::setValue('intake_enabled', '1');
        $this->intakeRun(autoAttached: true);
        $this->intakeRun(autoAttached: false);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringContainsString(
            'Intake routed (last 24h): 2 (1 auto-attached, 1 flagged for review)',
            $digest->body,
        );
        $this->assertFalse($digest->isEmpty);
    }

    // 2. Section entirely absent when feature is disabled (dormant byte-identical)

    public function test_section_absent_when_intake_disabled(): void
    {
        // intake_enabled NOT set → AgentConfig::intakeEnabled() returns false
        // Create runs anyway so only the feature gate, not an empty DB, is responsible.
        $this->intakeRun(autoAttached: true);
        $this->intakeRun(autoAttached: false);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringNotContainsString('Intake routed', $digest->body);
    }

    // 3. Intake-only digest still sends (isEmpty=false when enabled + count>0)

    public function test_intake_only_digest_sends(): void
    {
        Setting::setValue('intake_enabled', '1');
        $this->intakeRun(autoAttached: false);

        $digest = app(DigestBuilder::class)->build();

        $this->assertFalse($digest->isEmpty);
    }

    // 4. 24h boundary: runs older than 24h are excluded

    public function test_24h_boundary_has_teeth(): void
    {
        Setting::setValue('intake_enabled', '1');

        // Too old
        $ticket = Ticket::factory()->create();
        $old = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'intake_route',
            'content_hash' => hash('sha256', 'old-intake'),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_meta' => ['attached' => false, 'suggested_ticket_id' => 1],
        ]);
        TechnicianRun::whereKey($old->id)->update(['created_at' => now()->subDays(2)]);

        // Recent
        $this->intakeRun(autoAttached: false);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringContainsString('Intake routed (last 24h): 1', $digest->body);
    }
}
