<?php

namespace Tests\Feature\Agent\Steering;

use App\Enums\TechnicianRunState;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Steering\CorrectionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorrectionRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_creates_a_conversation_and_one_message(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create();
        $operator = User::factory()->create();

        $conversation = app(CorrectionRecorder::class)->record(
            $ticket,
            $operator,
            'client is on a no-auto-close contract'
        );

        $this->assertInstanceOf(AssistantConversation::class, $conversation);

        $this->assertDatabaseCount('assistant_conversations', 1);
        $this->assertDatabaseHas('assistant_conversations', [
            'context_type' => 'ticket_correction',
            'context_id' => $ticket->id,
            'user_id' => $operator->id,
        ]);

        $this->assertDatabaseCount('assistant_messages', 1);
        $this->assertDatabaseHas('assistant_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'client is on a no-auto-close contract',
        ]);
    }

    public function test_record_twice_same_day_produces_one_conversation_two_messages(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create();
        $operator = User::factory()->create();

        $recorder = app(CorrectionRecorder::class);
        $recorder->record($ticket, $operator, 'first correction');
        $recorder->record($ticket, $operator, 'second correction');

        $this->assertDatabaseCount('assistant_conversations', 1);
        $this->assertDatabaseCount('assistant_messages', 2);

        $conversation = AssistantConversation::where('context_type', 'ticket_correction')
            ->where('context_id', $ticket->id)
            ->first();

        $this->assertNotNull($conversation);

        $messages = AssistantMessage::where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->pluck('content')
            ->all();

        $this->assertSame(['first correction', 'second correction'], $messages);
    }

    public function test_record_with_corrected_run_back_links_the_conversation(): void
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create();
        $operator = User::factory()->create();

        $correctedRun = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'propose_close',
            'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::AwaitingApproval,
            'tokens_used' => 0,
        ]);

        $conversation = app(CorrectionRecorder::class)->record(
            $ticket,
            $operator,
            'do not close — client is still waiting on parts',
            $correctedRun
        );

        $fresh = $correctedRun->fresh();
        $this->assertNotNull($fresh->proposed_meta);
        $this->assertArrayHasKey('corrected_by_conversation_id', $fresh->proposed_meta);
        $this->assertSame($conversation->id, $fresh->proposed_meta['corrected_by_conversation_id']);
    }
}
