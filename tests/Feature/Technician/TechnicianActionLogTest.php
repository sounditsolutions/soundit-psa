<?php

namespace Tests\Feature\Technician;

use App\Models\TechnicianActionLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TechnicianActionLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_can_be_created(): void
    {
        $log = TechnicianActionLog::create([
            'actor_id' => null,
            'actor_label' => 'ai-technician',
            'action_type' => 'send_ack',
            'tier' => 'auto',
            'result_status' => 'executed',
            'ticket_id' => null,
            'client_id' => null,
            'run_id' => null,
            'content_hash' => str_repeat('a', 64),
            'summary' => 'Auto-acknowledged the client.',
            'correlation_id' => 'c0ffee',
        ]);

        $this->assertDatabaseHas('technician_action_logs', [
            'id' => $log->id,
            'actor_label' => 'ai-technician',
            'action_type' => 'send_ack',
            'result_status' => 'executed',
        ]);
        $this->assertNull($log->updated_at ?? null);
    }

    public function test_updating_a_row_throws(): void
    {
        $log = TechnicianActionLog::create([
            'actor_label' => 'ai-technician',
            'action_type' => 'send_ack',
            'tier' => 'auto',
            'result_status' => 'executed',
            'content_hash' => str_repeat('b', 64),
            'summary' => 'x',
            'correlation_id' => 'abc',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('technician_action_logs is append-only');

        $log->update(['summary' => 'tampered']);
    }

    public function test_deleting_a_row_throws(): void
    {
        $log = TechnicianActionLog::create([
            'actor_label' => 'ai-technician',
            'action_type' => 'send_ack',
            'tier' => 'auto',
            'result_status' => 'executed',
            'content_hash' => str_repeat('c', 64),
            'summary' => 'x',
            'correlation_id' => 'def',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('technician_action_logs is append-only');

        $log->delete();
    }
}
