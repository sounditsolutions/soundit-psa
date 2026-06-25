<?php

namespace Tests\Feature\Agent;

use App\Enums\PersonType;
use App\Enums\TechnicianRunState;
use App\Enums\TicketStatus;
use App\Jobs\SendPortalNotification;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\TechnicianApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Operator-approval path for propose_close runs (Task 8).
 *
 * Covers:
 *  1. Silent-close with teeth: portal contact present → result 'closed', ticket Closed,
 *     run Done, executed audit row, NO SendPortalNotification dispatched.
 *  2. Double-approve is single-use (run-state CAS latch).
 *  3. Gate-declined releases claim → run retryable, NOT stuck in Executing (CO-3).
 *  4. Route-level: no body required for propose_close path (CO-2).
 *  5. Deny: ticket stays open, run → Denied, no executed audit row (CO-10).
 */
class ApproveProposeCloseTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns [actor User, open Ticket (InProgress), held propose_close TechnicianRun].
     * The ticket's contact has portal_enabled = $portalEnabled.
     *
     * Note: Ticket::factory() defaults to Closed (CO-6) — override to InProgress.
     */
    private function heldCloseRun(bool $portalEnabled = false): array
    {
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);

        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'email' => 'c@example.com',
            'is_active' => true,
            'portal_enabled' => $portalEnabled,
        ]);

        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $person->id,
            'status' => TicketStatus::InProgress,
        ]);

        $reason = 'No reply in 60 days.';
        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'propose_close',
            'content_hash' => hash('sha256', 'propose_close:'.$ticket->id.':'.$reason),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => $reason,
            'confidence' => 0.85,
            'tokens_used' => 0,
        ]);

        return [$actor, $ticket, $run];
    }

    // ── 1. Silent-close with teeth ────────────────────────────────────────────

    /**
     * Approving a propose_close run closes the ticket to Closed silently.
     * Even with a portal-enabled contact present, SendPortalNotification is NOT
     * dispatched — closing to Closed is deliberately silent (CO-18).
     */
    public function test_approve_close_closes_silently_with_portal_contact_present(): void
    {
        Queue::fake();

        [$actor, $ticket, $run] = $this->heldCloseRun(portalEnabled: true);

        $result = app(TechnicianApprovalService::class)->approveClose($run, $actor->id);

        $this->assertSame('closed', $result->status);
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'propose_close',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
        ]);
        // Portal contact is present and enabled — assert no notification fires.
        Queue::assertNotPushed(SendPortalNotification::class);
    }

    // ── 2. Double-approve is single-use ──────────────────────────────────────

    public function test_double_approve_returns_already_handled(): void
    {
        [$actor, $ticket, $run] = $this->heldCloseRun();

        $service = app(TechnicianApprovalService::class);

        $first = $service->approveClose($run, $actor->id);
        $second = $service->approveClose($run->fresh(), $actor->id);

        $this->assertSame('closed', $first->status);
        $this->assertSame('already_handled', $second->status);
    }

    // ── 3. Gate-declined releases claim (CO-3) ────────────────────────────────

    /**
     * When the gate declines (kill-switch engaged), the claim is released so the
     * operator can retry. The run must be back at AwaitingApproval, NOT stuck at
     * Executing. Disengaging and re-approving must succeed.
     */
    public function test_gate_declined_releases_claim_and_run_is_retryable(): void
    {
        [$actor, $ticket, $run] = $this->heldCloseRun();

        Setting::setValue('technician_kill_switch', '1');

        $result = app(TechnicianApprovalService::class)->approveClose($run, $actor->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state); // NOT stuck in Executing
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);        // ticket untouched

        // Disengage — the operator can approve again.
        Setting::setValue('technician_kill_switch', '0');

        $retry = app(TechnicianApprovalService::class)->approveClose($run->fresh(), $actor->id);
        $this->assertSame('closed', $retry->status);
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
    }

    // ── 4. Route-level: no body required for propose_close (CO-2) ────────────

    /**
     * POSTing to cockpit.approve with NO body for a propose_close run must succeed
     * (redirect + success flash, ticket Closed). The body-required validation must
     * only apply to the reply arm, not the close arm.
     */
    public function test_route_approve_close_redirects_success_without_body(): void
    {
        [$actor, $ticket, $run] = $this->heldCloseRun();

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run)) // NO body
            ->assertRedirect();

        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'propose_close',
            'result_status' => 'executed',
            'ticket_id' => $ticket->id,
        ]);
    }

    // ── 5. Deny (CO-10) ──────────────────────────────────────────────────────

    public function test_deny_leaves_ticket_open_and_run_denied(): void
    {
        [$actor, $ticket, $run] = $this->heldCloseRun();

        app(TechnicianApprovalService::class)->deny($run);

        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertDatabaseMissing('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'result_status' => 'executed',
        ]);
    }
}
