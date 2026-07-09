<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TicketActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_updating_priority_records_a_field_activity(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $ticket = Ticket::factory()->create(['priority' => TicketPriority::P3]);

        $ticket->update(['priority' => TicketPriority::P2]);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'field' => 'priority',
            'old_value' => 'P3 - Medium',
            'new_value' => 'P2 - High',
        ]);
    }

    public function test_creating_a_ticket_records_no_activity(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $ticket = Ticket::factory()->create();

        // Creation is captured by created_at/created_by — the audit trail only
        // records subsequent field changes, not the initial state.
        $this->assertDatabaseMissing('ticket_activities', ['ticket_id' => $ticket->id]);
    }

    public function test_assignment_change_resolves_user_name_and_records_null_old_value(): void
    {
        $user = User::factory()->create();
        $assignee = User::factory()->create(['name' => 'Alice Tech']);
        $this->actingAs($user);

        $ticket = Ticket::factory()->create(['assignee_id' => null]);

        $ticket->update(['assignee_id' => $assignee->id]);

        $activity = TicketActivity::where('ticket_id', $ticket->id)->where('field', 'assignee_id')->firstOrFail();
        $this->assertNull($activity->old_value);
        $this->assertSame('Alice Tech', $activity->new_value);
    }

    public function test_status_change_via_service_records_a_status_activity(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $ticket = Ticket::factory()->create(['status' => TicketStatus::New]);

        app(TicketService::class)->changeStatus($ticket, TicketStatus::InProgress, $user->id);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'field' => 'status',
            'old_value' => 'New',
            'new_value' => 'In Progress',
        ]);
    }

    public function test_multiple_tracked_fields_in_one_save_record_separate_rows(): void
    {
        $user = User::factory()->create();
        $assignee = User::factory()->create(['name' => 'Bob Ops']);
        $this->actingAs($user);

        $ticket = Ticket::factory()->create([
            'priority' => TicketPriority::P3,
            'type' => TicketType::Incident,
            'assignee_id' => null,
        ]);

        $ticket->update([
            'priority' => TicketPriority::P1,
            'type' => TicketType::ServiceRequest,
            'assignee_id' => $assignee->id,
        ]);

        $this->assertSame(3, TicketActivity::where('ticket_id', $ticket->id)->count());
        $this->assertDatabaseHas('ticket_activities', ['ticket_id' => $ticket->id, 'field' => 'priority']);
        $this->assertDatabaseHas('ticket_activities', ['ticket_id' => $ticket->id, 'field' => 'type']);
        $this->assertDatabaseHas('ticket_activities', ['ticket_id' => $ticket->id, 'field' => 'assignee_id']);
    }

    public function test_untracked_field_change_records_no_activity(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $ticket = Ticket::factory()->create();

        // description is deliberately not audited (long-text, noisy).
        $ticket->update(['description' => 'A brand new description that should not be audited.']);

        $this->assertDatabaseMissing('ticket_activities', ['ticket_id' => $ticket->id]);
    }

    public function test_mixed_save_records_only_the_tracked_field(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $ticket = Ticket::factory()->create(['priority' => TicketPriority::P3]);

        $ticket->update([
            'priority' => TicketPriority::P4,
            'description' => 'edited body',
        ]);

        $this->assertSame(1, TicketActivity::where('ticket_id', $ticket->id)->count());
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'field' => 'priority',
        ]);
    }

    public function test_touch_records_no_activity(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $ticket = Ticket::factory()->create();

        // touch() only bumps updated_at — no tracked field changes.
        $ticket->touch();

        $this->assertDatabaseMissing('ticket_activities', ['ticket_id' => $ticket->id]);
    }

    public function test_unauthenticated_change_records_null_actor(): void
    {
        $ticket = Ticket::factory()->create(['priority' => TicketPriority::P3]);

        // No actingAs — e.g. a queued/system save with no logged-in user.
        $ticket->update(['priority' => TicketPriority::P2]);

        $activity = TicketActivity::where('ticket_id', $ticket->id)->where('field', 'priority')->firstOrFail();
        $this->assertNull($activity->user_id);
    }

    public function test_due_date_change_is_recorded_with_formatted_values(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $ticket = Ticket::factory()->create(['due_at' => null]);

        $ticket->update(['due_at' => '2026-08-01 15:30:00']);

        $activity = TicketActivity::where('ticket_id', $ticket->id)->where('field', 'due_at')->firstOrFail();
        $this->assertNull($activity->old_value);
        // Default app timezone is UTC in tests.
        $this->assertSame('Aug 1, 2026 3:30 PM', $activity->new_value);
    }

    public function test_change_history_card_renders_on_show_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $ticket = Ticket::factory()->create(['status' => TicketStatus::New, 'priority' => TicketPriority::P3]);
        $ticket->update(['priority' => TicketPriority::P1]);

        $resp = $this->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertSee('Change History');
        $resp->assertSee('Priority');
        $resp->assertSee('P3 - Medium');
        $resp->assertSee('P1 - Critical');
    }

    public function test_change_history_card_hidden_when_no_activity(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $ticket = Ticket::factory()->create();

        $resp = $this->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $resp->assertDontSee('Change History');
    }

    public function test_http_update_endpoint_records_activity_with_actor(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['priority' => TicketPriority::P3]);

        $resp = $this->actingAs($user)->patch(route('tickets.update', $ticket), [
            'priority' => TicketPriority::P2->value,
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'field' => 'priority',
            'old_value' => 'P3 - Medium',
            'new_value' => 'P2 - High',
        ]);
    }
}
