<?php

namespace Tests\Feature\Technician;

use App\Enums\NoteType;
use App\Enums\TechnicianRunState;
use App\Enums\WhoType;
use App\Jobs\RunTechnicianLoop;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Technician\TechnicianDisclosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AutoAcknowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake(); // we drive the job body directly, not the queue
    }

    private function configureAutoAck(User $actor): void
    {
        Setting::setValue('technician_enabled', '1');
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        Setting::setValue('technician_action_tiers', json_encode(['send_ack' => 'auto']));
    }

    public function test_auto_acknowledge_produces_disclosed_ai_authored_note_audit_and_advances_run(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->configureAutoAck($actor);
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        (new RunTechnicianLoop($ticket->id))->handle();

        // 1. A client-facing note authored by the AI actor, WhoType::Agent, ai_authored.
        $note = TicketNote::where('ticket_id', $ticket->id)
            ->where('ai_authored', true)
            ->first();
        $this->assertNotNull($note, 'expected an AI-authored ack note');
        $this->assertSame($actor->id, $note->author_id);
        $this->assertSame(WhoType::Agent, $note->who_type);
        $this->assertFalse((bool) $note->is_private);
        $this->assertSame(NoteType::Reply, $note->note_type);

        // 2. Structural disclosure present (sending layer appended it).
        $this->assertStringContainsString(TechnicianDisclosure::MARKER, $note->body);
        $this->assertStringContainsString('prefer to work with a person', $note->body);

        // 3. An append-only audit row, attributable to the AI actor + label, executed.
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_ack',
            'result_status' => 'executed',
            'actor_label' => 'ai-technician',
            'actor_id' => $actor->id,
            'tier' => 'auto',
            'ticket_id' => $ticket->id,
        ]);

        // 4. The run advanced to done.
        $run = TechnicianRun::where('ticket_id', $ticket->id)->where('action_type', 'send_ack')->firstOrFail();
        $this->assertSame(TechnicianRunState::Done, $run->state);
    }

    public function test_auto_acknowledge_is_idempotent_on_re_run(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->configureAutoAck($actor);
        $ticket = Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);

        (new RunTechnicianLoop($ticket->id))->handle();
        (new RunTechnicianLoop($ticket->id))->handle(); // re-import / retry

        // The run is reused (idempotency key), so a second ack note is NOT created.
        $this->assertSame(
            1,
            TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->count(),
        );
    }

    public function test_kill_switch_engaged_writes_no_ack_note(): void
    {
        $actor = User::factory()->create(['name' => 'Chet']);
        $this->configureAutoAck($actor);
        Setting::setValue('technician_kill_switch', '1');
        $ticket = Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);

        (new RunTechnicianLoop($ticket->id))->handle();

        $this->assertSame(
            0,
            TicketNote::where('ticket_id', $ticket->id)->where('ai_authored', true)->count(),
        );
        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'send_ack',
            'result_status' => 'held',
        ]);
    }
}
