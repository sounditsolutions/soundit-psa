<?php

namespace Tests\Feature\ContactIntake;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Mcp\StaffPsaActionToolExecutor;
use App\Services\Technician\Emergency\EmergencyDetector;
use App\Services\Technician\Scheduled\ScheduledPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ActionContainmentTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(bool $held): Ticket
    {
        Bus::fake();
        $ticket = Ticket::factory()->create(['client_id' => Client::factory()->create()->id, 'status' => TicketStatus::New]);
        $ticket->forceFill(['contact_intake_origin' => $held])->saveQuietly();

        return $ticket;
    }

    public function test_scheduled_execution_binding_refuses_held_ticket_but_accepts_normal(): void
    {
        $policy = app(ScheduledPolicy::class);
        $normal = $this->ticket(false);
        $run = new TechnicianRun(['ticket_id' => $normal->id, 'client_id' => $normal->client_id]);
        $policy->ticket($run);
        $this->addToAssertionCount(1);
        $held = $this->ticket(true);
        $run->fill(['ticket_id' => $held->id, 'client_id' => $held->client_id]);
        $this->expectExceptionMessage('ticket_binding_changed');
        $policy->ticket($run);
    }

    public function test_staff_machine_action_cannot_edit_held_ticket(): void
    {
        \App\Models\Setting::setValue('triage_system_user_id', (string) \App\Models\User::factory()->create()->id);
        $executor = app(StaffPsaActionToolExecutor::class);
        foreach ([false, true] as $held) {
            $ticket = $this->ticket($held);
            $before = $ticket->subject;
            $result = $executor->execute('update_ticket', ['ticket_id' => $ticket->id, 'subject' => 'Synthetic edited subject', 'reason' => 'Synthetic containment control'], $ticket->client_id, 'Synthetic staff');
            if ($held) {
                $this->assertArrayHasKey('error', $result);
                $this->assertSame($before, $ticket->fresh()->subject);
            } else {
                $this->assertArrayNotHasKey('error', $result);
                $this->assertSame('Synthetic edited subject', $ticket->fresh()->subject);
            }
        }
    }

    public function test_emergency_detector_never_grades_held_content(): void
    {
        $detector = app(EmergencyDetector::class);
        $this->assertTrue($detector->assess($this->ticket(false), 5)->isEmergency);
        $held = $detector->assess($this->ticket(true), 5);
        $this->assertFalse($held->isEmergency);
        $this->assertSame(0, $held->severity);
    }
}
