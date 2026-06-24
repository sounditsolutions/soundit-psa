<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CockpitControllerTest extends TestCase
{
    use RefreshDatabase;

    private function heldRun(User $actor): TechnicianRun
    {
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode([]));
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test', 'last_name' => 'Contact', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id, 'subject' => 'Printer down']);

        return TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64), 'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'We will get the printer back online.',
        ]);
    }

    public function test_cockpit_index_requires_auth_and_shows_a_held_draft(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldRun($actor);

        $this->get(route('cockpit.index'))->assertRedirect(); // guest → login

        $this->actingAs(User::factory()->create())
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('Printer down')
            ->assertSee('We will get the printer back online.');
    }

    public function test_approve_sends_and_clears_the_draft(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldRun($actor);
        $this->mock(EmailService::class, fn (MockInterface $m) => $m->shouldReceive('sendTicketReplyNote')->once()->andReturnNull());

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.approve', $run), ['body' => 'Edited before sending.'])
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Done, $run->fresh()->state);
        $this->assertSame(1, TicketNote::where('ticket_id', $run->ticket_id)->where('ai_authored', true)->count());
    }

    public function test_deny_removes_the_draft_from_the_queue(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $run = $this->heldRun($actor);

        $this->actingAs(User::factory()->create())
            ->post(route('cockpit.deny', $run))
            ->assertRedirect(route('cockpit.index'));

        $this->assertSame(TechnicianRunState::Denied, $run->fresh()->state);
    }
}
