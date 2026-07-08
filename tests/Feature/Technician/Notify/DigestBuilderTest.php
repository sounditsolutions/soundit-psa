<?php

namespace Tests\Feature\Technician\Notify;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Technician\Notify\DigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigestBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_when_nothing_pending_or_done(): void
    {
        $digest = app(DigestBuilder::class)->build();
        $this->assertTrue($digest->isEmpty);
    }

    public function test_summarizes_pending_and_actions_taken(): void
    {
        $client = Client::factory()->create(['name' => 'Acme']);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'VPN down']);
        TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $client->id, 'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64), 'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'd',
        ]);
        TechnicianActionLog::create([
            'actor_label' => 'ai-technician', 'action_type' => 'send_ack', 'tier' => 'auto',
            'result_status' => 'executed', 'content_hash' => str_repeat('b', 64), 'summary' => 'ack', 'correlation_id' => 'x',
        ]);

        $digest = app(DigestBuilder::class)->build();

        $this->assertFalse($digest->isEmpty);
        $this->assertStringContainsString('1', $digest->body);        // 1 awaiting
        $this->assertStringContainsString('VPN down', $digest->body); // the pending item
        $this->assertStringContainsString('Acme', $digest->body);
    }
}
