<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\NoteType;
use App\Enums\PersonType;
use App\Enums\WhoType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAuthoredBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // PortalEnabled middleware gates all portal routes on this setting.
        Setting::setValue('portal_enabled', '1');
    }

    private function aiNote(Ticket $ticket): TicketNote
    {
        return TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'Chet', 'who_type' => WhoType::Agent,
            'ai_authored' => true, 'body' => 'Thanks for reaching out — disclosed AI reply.',
            'note_type' => NoteType::Reply, 'is_private' => false, 'noted_at' => now(),
        ]);
    }

    public function test_staff_timeline_marks_an_ai_authored_note(): void
    {
        $ticket = Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);
        $this->aiNote($ticket);

        $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('AI-authored', false) // the badge text/label — unambiguous (not just 'AI')
            ->assertSee('disclosed AI reply');
    }

    public function test_portal_marks_an_ai_authored_note_to_the_client(): void
    {
        // Client::factory()->create() uses the DB default stage='active' (no state override).
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $this->aiNote($ticket);

        // canAccessPortal() requires: portal_enabled=true + is_active=true + client.stage=Active.
        // person_type->canHavePortal() requires PersonType::User.
        // company_wide_access=true lets the person see all client tickets without being contact_id.
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Client',
            'last_name' => 'User',
            'email' => 'client@example.com',
            'is_active' => true,
            'portal_enabled' => true,
            'company_wide_access' => true,
        ]);

        $this->actingAs($person, 'portal')
            ->get(route('portal.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('AI-authored');
    }
}
