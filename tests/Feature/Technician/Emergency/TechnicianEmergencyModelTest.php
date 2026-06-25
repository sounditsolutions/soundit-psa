<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Models\Client;
use App\Models\TechnicianEmergency;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianEmergencyModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_scope_and_has_open_emergency(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        $this->assertFalse(TechnicianEmergency::hasOpenEmergency($ticket));

        $e = TechnicianEmergency::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id,
            'signature' => 'sig', 'severity' => 3, 'reasons' => ['age'],
            'detected_by' => 'rules', 'state' => EmergencyState::Open,
            'escalation_step' => 0, 'ticket_ids' => [$ticket->id], 'alerted_at' => now(),
        ]);

        $this->assertTrue(TechnicianEmergency::hasOpenEmergency($ticket));
        $this->assertEqualsCanonicalizing(['age'], $e->fresh()->reasons);

        $e->update(['state' => EmergencyState::Resolved, 'resolved_at' => now()]);
        $this->assertFalse(TechnicianEmergency::hasOpenEmergency($ticket));
    }

    public function test_has_open_emergency_checks_json_ticket_ids_array(): void
    {
        $client = Client::factory()->create();
        $ticketA = Ticket::factory()->create(['client_id' => $client->id]);
        $ticketB = Ticket::factory()->create(['client_id' => $client->id]);

        // Emergency's representative ticket_id is A, but ticket_ids storm-member array also contains B
        TechnicianEmergency::create([
            'ticket_id' => $ticketA->id,
            'client_id' => $client->id,
            'signature' => 'storm-sig',
            'severity' => 2,
            'reasons' => ['overdue'],
            'detected_by' => 'rules',
            'state' => EmergencyState::Open,
            'escalation_step' => 0,
            'ticket_ids' => [$ticketA->id, $ticketB->id],
            'alerted_at' => now(),
        ]);

        // ticketB is not the representative ticket_id, but IS in ticket_ids — must return true
        $this->assertTrue(
            TechnicianEmergency::hasOpenEmergency($ticketB),
            'hasOpenEmergency must return true when ticket is a storm member in ticket_ids'
        );
    }
}
