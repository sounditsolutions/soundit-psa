<?php

namespace Tests\Feature\Web;

use App\Enums\TechnicianRunState;
use App\Jobs\RunTechnicianAgent;
use App\Models\AssistantConversation;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CockpitCorrectTest extends TestCase
{
    use RefreshDatabase;

    private function awaitingRun(): TechnicianRun
    {
        $user = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $user->id);
        Setting::setValue('technician_action_tiers', json_encode([]));

        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'email' => 'contact@example.com',
            'is_active' => true,
        ]);
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $person->id,
            'subject' => 'Test ticket',
        ]);

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'propose_close',
            'content_hash' => str_repeat('b', 64),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'Looks resolved.',
        ]);
    }

    public function test_correction_lane_shows_one_decline_and_reassess_button(): void
    {
        // psa-gt66 (Charlie's UX feedback): the two confusing buttons — 'Decline & correct'
        // and 'Add context & re-assess' (identical behaviour in v1) — are collapsed into ONE
        // 'Decline & re-assess'. Same /cockpit/runs/{run}/correct flow; the note field stays.
        $run = $this->awaitingRun();

        $response = $this->actingAs(User::factory()->create())->get(route('cockpit.index'));

        $response->assertOk();
        $response->assertSee('Looks resolved.'); // the approval card (and its correction lane) renders
        $response->assertSee('Decline & re-assess'); // the single collapsed button
        $response->assertDontSee('Add context & re-assess'); // the removed sibling
        $response->assertDontSee('Decline & correct'); // the removed label
        $response->assertSee('name="correction"', false); // the note field remains
    }

    public function test_authed_staff_can_submit_correction_and_triggers_reassess(): void
    {
        Queue::fake();

        $run = $this->awaitingRun();
        $staff = User::factory()->create();

        $response = $this->actingAs($staff)
            ->post(route('cockpit.correct', $run), [
                'correction' => 'client is on a no-auto-close contract',
            ]);

        $response->assertRedirect(route('cockpit.index'));

        // A ticket_correction conversation should exist for this ticket.
        $conversation = AssistantConversation::where('context_type', 'ticket_correction')
            ->where('context_id', $run->ticket_id)
            ->first();
        $this->assertNotNull($conversation, 'Expected a ticket_correction AssistantConversation to exist');
        $this->assertTrue(
            $conversation->messages()->where('content', 'client is on a no-auto-close contract')->exists(),
            'Expected correction text in conversation messages'
        );

        // Run should be superseded.
        $this->assertSame(TechnicianRunState::Superseded, $run->fresh()->state);

        // A correctionDriven RunTechnicianAgent job should have been pushed.
        Queue::assertPushed(
            RunTechnicianAgent::class,
            fn ($job) => $job->correctionDriven === true && $job->ticketId === $run->ticket_id
        );
    }

    public function test_empty_correction_returns_validation_error(): void
    {
        Queue::fake();

        $run = $this->awaitingRun();
        $staff = User::factory()->create();

        $response = $this->actingAs($staff)
            ->post(route('cockpit.correct', $run), ['correction' => '']);

        $response->assertSessionHasErrors('correction');

        // RunTechnicianAgent must NOT be pushed on validation failure.
        Queue::assertNotPushed(RunTechnicianAgent::class);

        // Run is NOT superseded.
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_unauthenticated_request_redirects_to_login(): void
    {
        Queue::fake();

        $run = $this->awaitingRun();

        $response = $this->post(route('cockpit.correct', $run), [
            'correction' => 'should not be recorded',
        ]);

        $response->assertRedirect('/login');

        Queue::assertNotPushed(RunTechnicianAgent::class);

        $this->assertDatabaseMissing('assistant_conversations', [
            'context_type' => 'ticket_correction',
            'context_id' => $run->ticket_id,
        ]);
    }
}
