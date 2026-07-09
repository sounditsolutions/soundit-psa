<?php

namespace Tests\Feature\Tickets;

use App\Enums\CabApproval;
use App\Enums\ChangeType;
use App\Enums\RiskLevel;
use App\Enums\TicketType;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ChangeManagementFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ticket creation fires the observer (triage dispatch + creation
        // notification). Fake the bus so no queued work escapes the test.
        Bus::fake();
    }

    public function test_create_change_ticket_persists_change_management_fields(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $resp = $this->actingAs($user)->post(route('tickets.store'), [
            'subject' => 'Upgrade core switch firmware',
            'client_id' => $client->id,
            'type' => TicketType::Change->value,
            'priority' => 'p3',
            'change_type' => ChangeType::Normal->value,
            'risk_level' => RiskLevel::High->value,
            'cab_approval' => CabApproval::Pending->value,
        ]);

        $ticket = Ticket::where('subject', 'Upgrade core switch firmware')->firstOrFail();

        $resp->assertRedirect(route('tickets.show', $ticket));

        $this->assertSame(ChangeType::Normal, $ticket->change_type);
        $this->assertSame(RiskLevel::High, $ticket->risk_level);
        $this->assertSame(CabApproval::Pending, $ticket->cab_approval);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'change_type' => 'normal',
            'risk_level' => 'high',
            'cab_approval' => 'pending',
        ]);
    }

    public function test_non_change_ticket_leaves_change_fields_null(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $this->actingAs($user)->post(route('tickets.store'), [
            'subject' => 'Printer offline',
            'client_id' => $client->id,
            'type' => TicketType::Incident->value,
            'priority' => 'p3',
        ]);

        $ticket = Ticket::where('subject', 'Printer offline')->firstOrFail();

        $this->assertNull($ticket->change_type);
        $this->assertNull($ticket->risk_level);
        $this->assertNull($ticket->cab_approval);
    }

    public function test_update_persists_change_management_fields(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->change()->create([
            'cab_approval' => CabApproval::Pending->value,
        ]);

        $resp = $this->actingAs($user)->patch(route('tickets.update', $ticket), [
            'change_type' => ChangeType::Emergency->value,
            'risk_level' => RiskLevel::Low->value,
            'cab_approval' => CabApproval::Approved->value,
        ]);

        $resp->assertRedirect(route('tickets.show', $ticket));

        $ticket->refresh();
        $this->assertSame(ChangeType::Emergency, $ticket->change_type);
        $this->assertSame(RiskLevel::Low, $ticket->risk_level);
        $this->assertSame(CabApproval::Approved, $ticket->cab_approval);
    }

    public function test_invalid_change_type_is_rejected(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $resp = $this->actingAs($user)->post(route('tickets.store'), [
            'subject' => 'Bad change type',
            'client_id' => $client->id,
            'type' => TicketType::Change->value,
            'priority' => 'p3',
            'change_type' => 'catastrophic',
        ]);

        $resp->assertSessionHasErrors('change_type');
        $this->assertDatabaseMissing('tickets', ['subject' => 'Bad change type']);
    }

    public function test_show_renders_change_management_card_for_change_tickets(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->change()->create();

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $html = $resp->getContent();

        $this->assertStringContainsString('Change Management', $html);
        $this->assertStringContainsString('name="change_type"', $html);
        $this->assertStringContainsString('name="risk_level"', $html);
        $this->assertStringContainsString('name="cab_approval"', $html);
    }

    public function test_show_hides_change_management_card_for_non_change_tickets(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create(['type' => TicketType::Incident->value]);

        $resp = $this->actingAs($user)->get(route('tickets.show', $ticket));

        $resp->assertOk();
        $this->assertStringNotContainsString('name="change_type"', $resp->getContent());
    }

    public function test_create_form_includes_change_management_fields(): void
    {
        $user = User::factory()->create();

        $resp = $this->actingAs($user)->get(route('tickets.create'));

        $resp->assertOk();
        $html = $resp->getContent();

        $this->assertStringContainsString('id="changeMgmtFields"', $html);
        $this->assertStringContainsString('name="change_type"', $html);
        $this->assertStringContainsString('name="risk_level"', $html);
        $this->assertStringContainsString('name="cab_approval"', $html);
    }
}
