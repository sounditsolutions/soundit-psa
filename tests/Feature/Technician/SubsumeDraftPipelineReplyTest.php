<?php

namespace Tests\Feature\Technician;

use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Agent\SendReplyTool;
use App\Services\Technician\DraftPipeline;
use App\Services\Technician\TechnicianAssessment;
use App\Services\Technician\TechnicianClassifier;
use App\Services\Technician\TechnicianDraft;
use App\Services\Technician\TechnicianReplyDrafter;
use App\Services\TicketResolutionDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A2b atomicity proof: the reactive agent (SendReplyTool) is the SOLE producer of held
 * send_reply runs, and DraftPipeline's reply branch is retired — so there is NO window
 * where both produce a held client reply (the collision A2b was designed to prevent).
 * DraftPipeline keeps only its propose_resolution branch (Mayor Decision A).
 */
class SubsumeDraftPipelineReplyTest extends TestCase
{
    use RefreshDatabase;

    /** A ticket with a contact + a genuine unaddressed client reply (so both producers would "want" to act). */
    private function ticketWithClientReply(): Ticket
    {
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'test-key');
        User::factory()->create(); // AI actor for the audit rows

        $client = Client::factory()->create();
        $contact = Person::create([
            'client_id' => $client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'C', 'last_name' => 'U', 'email' => 'c@example.com', 'is_active' => true,
        ]);
        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $contact->id]);

        TicketNote::create([
            'ticket_id' => $ticket->id, 'author_name' => 'C', 'who_type' => \App\Enums\WhoType::EndUser,
            'ai_authored' => false, 'body' => 'Any update on this?', 'note_type' => \App\Enums\NoteType::Reply,
            'is_private' => false, 'noted_at' => now(),
        ]);

        return $ticket;
    }

    public function test_draftpipeline_never_produces_a_held_reply(): void
    {
        // The structural guarantee: even on a ticket that previously WOULD have drafted a
        // reply (ownable + unaddressed client reply), DraftPipeline now produces ZERO
        // send_reply runs — so it cannot collide with or clobber the agent's draft.
        $this->mock(TechnicianClassifier::class, fn (MockInterface $m) => $m->shouldReceive('classify')
            ->andReturn(new TechnicianAssessment(0.9, true, ['runbook'], 50)));
        $this->mock(TicketResolutionDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')
            ->andReturn('Resolution text.'));

        $ticket = $this->ticketWithClientReply();
        app(DraftPipeline::class)->run($ticket);

        $this->assertSame(0, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count(),
            'DraftPipeline must NEVER produce a held send_reply (the agent is the sole producer)');
        // It still does its remaining job — the held resolution.
        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_resolution')->count());
    }

    public function test_loop_order_yields_exactly_one_held_reply_and_one_resolution(): void
    {
        // Simulate the RunTechnicianLoop flow: DraftPipeline runs (resolution), then the
        // woken agent drafts the reply. Exactly ONE held send_reply (the agent's) and ONE
        // propose_resolution (DraftPipeline's) — never two replies for one ticket.
        $this->mock(TechnicianClassifier::class, fn (MockInterface $m) => $m->shouldReceive('classify')
            ->andReturn(new TechnicianAssessment(0.9, true, ['runbook'], 50)));
        $this->mock(TicketResolutionDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')
            ->andReturn('Resolution text.'));
        $this->mock(TechnicianReplyDrafter::class, fn (MockInterface $m) => $m->shouldReceive('draft')
            ->andReturn(new TechnicianDraft('The client reply body.', 'c@example.com', 80)));

        $ticket = $this->ticketWithClientReply();

        app(DraftPipeline::class)->run($ticket);                              // resolution only
        app(SendReplyTool::class)->execute($ticket, ['reason' => 'reply']);   // the held reply

        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_reply')->count(),
            'exactly one held reply, produced by the agent');
        $this->assertSame(1, TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'propose_resolution')->count(),
            'exactly one held resolution, produced by DraftPipeline');
    }
}
