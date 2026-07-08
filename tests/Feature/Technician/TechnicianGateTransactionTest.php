<?php

namespace Tests\Feature\Technician;

use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Technician\TechnicianActionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TechnicianGateTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(); // first user = AI actor fallback
        Setting::setValue('technician_action_tiers', json_encode(['send_ack' => 'auto']));
    }

    public function test_executor_db_writes_roll_back_when_the_executor_throws(): void
    {
        $ticket = Ticket::factory()->create();
        $gate = app(TechnicianActionGate::class);

        try {
            $gate->dispatch(
                actionType: 'send_ack',
                ticketId: $ticket->id,
                clientId: null,
                contentHash: str_repeat('a', 64),
                summary: 'ack',
                runId: 1,
                executor: function () use ($ticket): void {
                    TicketNote::create([
                        'ticket_id' => $ticket->id,
                        'author_name' => 'x',
                        'who_type' => \App\Enums\WhoType::Agent,
                        'body' => 'partial',
                        'note_type' => \App\Enums\NoteType::Reply,
                        'is_private' => false,
                        'noted_at' => now(),
                    ]);
                    throw new RuntimeException('boom');
                },
            );
            $this->fail('expected the executor exception to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // Atomicity: the note the executor wrote must have rolled back...
        $this->assertSame(0, TicketNote::where('ticket_id', $ticket->id)->count());
        // ...and no 'executed' audit row was committed.
        $this->assertDatabaseMissing('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'result_status' => 'executed',
        ]);
    }

    public function test_happy_path_commits_note_and_audit_together(): void
    {
        $ticket = Ticket::factory()->create();
        $gate = app(TechnicianActionGate::class);

        $result = $gate->dispatch(
            actionType: 'send_ack',
            ticketId: $ticket->id,
            clientId: null,
            contentHash: str_repeat('b', 64),
            summary: 'ack',
            runId: 1,
            executor: function () use ($ticket): void {
                TicketNote::create([
                    'ticket_id' => $ticket->id,
                    'author_name' => 'x',
                    'who_type' => \App\Enums\WhoType::Agent,
                    'body' => 'ok',
                    'note_type' => \App\Enums\NoteType::Reply,
                    'is_private' => false,
                    'noted_at' => now(),
                ]);
            },
        );

        $this->assertSame('executed', $result->status);
        $this->assertSame(1, TicketNote::where('ticket_id', $ticket->id)->count());
        $this->assertDatabaseHas('technician_action_logs', [
            'ticket_id' => $ticket->id,
            'result_status' => 'executed',
        ]);
    }
}
