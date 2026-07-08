<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\Cockpit\CockpitQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Intake" cockpit lane (psa-xcyo Task 3).
 *
 * Surfaces held intake suggestions (intake_route AwaitingApproval) so the
 * operator can calibrate the auto-attach threshold. Visibility only — no merge
 * action (deferred). The lane is only rendered when non-empty.
 */
class IntakeLaneTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * Create an intake_route run with a given state. The run's ticket_id is
     * the "created" (duplicate) ticket; suggested_ticket_id is the open
     * existing ticket the AI matched against.
     */
    private function intakeRun(
        int $suggestedTicketId = 999,
        TechnicianRunState $state = TechnicianRunState::AwaitingApproval,
    ): TechnicianRun {
        $client = Client::factory()->create();
        $createdTicket = Ticket::factory()->for($client)->create();

        return TechnicianRun::create([
            'ticket_id' => $createdTicket->id,
            'client_id' => $client->id,
            'action_type' => 'intake_route',
            'content_hash' => hash('sha256', 'intake-test-'.microtime().rand()),
            'state' => $state,
            'proposed_content' => 'same printer issue (test)',
            'proposed_meta' => [
                'email_id' => 1,
                'decision' => 'attach',
                'suggested_ticket_id' => $suggestedTicketId,
                'confidence' => 0.9,
                'attached' => $state === TechnicianRunState::Done,
                'created_ticket_id' => $createdTicket->id,
            ],
            'tokens_used' => 0,
        ]);
    }

    // ── 1. Lane renders with the created + suggested ticket IDs visible ────────

    /**
     * A real GET (not redirect-only) so that any Blade compile error surfaces as
     * a 500 rather than a silent failure. Catches "glued @-directive" bugs.
     */
    public function test_intake_lane_renders_with_ticket_ids(): void
    {
        $run = $this->intakeRun(suggestedTicketId: 9919);
        $createdId = $run->ticket_id;

        $this->actingAs($this->user)
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('intake-dismiss')          // dismiss form action is unique to the intake lane
            ->assertSee((string) $createdId)       // the newly-created (dup) ticket
            ->assertSee('9919');                   // the AI's suggested open ticket
    }

    // ── 2. Dismiss transitions the run to Done ────────────────────────────────

    public function test_intake_dismiss_transitions_run_to_done(): void
    {
        $run = $this->intakeRun(suggestedTicketId: 42);

        $this->actingAs($this->user)
            ->post(route('cockpit.intake-dismiss', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
    }

    public function test_dismissed_run_no_longer_appears_in_lane(): void
    {
        $run = $this->intakeRun(suggestedTicketId: 42);

        // Dismiss it
        $this->actingAs($this->user)
            ->post(route('cockpit.intake-dismiss', $run));

        // The intake lane heading / dismiss buttons must be gone
        $this->actingAs($this->user)
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertDontSee('intake-dismiss');
    }

    // ── 3. A Done (auto-attached) run does NOT appear in the intake review lane ─

    public function test_done_run_does_not_appear_in_intake_lane(): void
    {
        $this->intakeRun(suggestedTicketId: 77, state: TechnicianRunState::Done);

        // No AwaitingApproval intake runs → the lane section is not rendered
        $this->actingAs($this->user)
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertDontSee('intake-dismiss');
    }

    // ── 4. intakeReview() query returns only AwaitingApproval intake_route runs ─

    public function test_intake_review_query_returns_held_suggestions_only(): void
    {
        $held = $this->intakeRun(suggestedTicketId: 1, state: TechnicianRunState::AwaitingApproval);
        $this->intakeRun(suggestedTicketId: 2, state: TechnicianRunState::Done);

        $lane = app(CockpitQuery::class)->intakeReview();

        $this->assertCount(1, $lane);
        $this->assertSame($held->id, $lane->first()->id);
    }

    // ── 5. intake_route runs do NOT appear in the approval (pendingDrafts) lane ─

    public function test_intake_route_does_not_appear_in_pending_drafts(): void
    {
        $this->intakeRun(suggestedTicketId: 5);

        $drafts = app(CockpitQuery::class)->pendingDrafts();

        $this->assertTrue(
            $drafts->where('action_type', 'intake_route')->isEmpty(),
            'intake_route runs must not appear in the approval lane',
        );
    }
}
