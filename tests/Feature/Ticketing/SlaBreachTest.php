<?php

namespace Tests\Feature\Ticketing;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SLA breach detection (psa-lqlu sibling fix). The SLA window is (due − opened). Carbon 3's
 * signed diffInMinutes made `due_at->diffInMinutes(opened_at)` NEGATIVE, so the check
 * `netElapsed > $slaMinutes` recorded a breach on EVERY resolve/close — a systemic false
 * positive. These pin the sign-safe behavior.
 */
class SlaBreachTest extends TestCase
{
    use RefreshDatabase;

    private function checkSlaBreach(Ticket $ticket): void
    {
        $svc = app(TicketService::class);
        $m = new \ReflectionMethod($svc, 'checkSlaBreach');
        $m->setAccessible(true);
        $m->invoke($svc, $ticket);
    }

    public function test_no_breach_for_a_ticket_resolved_within_sla(): void
    {
        // Opened 30 min ago, due in 2h → a 150-min SLA window, only ~30 min elapsed → NO breach.
        // With the Carbon-3 sign bug, $slaMinutes was negative and a breach was ALWAYS recorded.
        $ticket = Ticket::factory()->for(Client::factory())->create([
            'status' => TicketStatus::InProgress,
            'opened_at' => now()->subMinutes(30),
            'due_at' => now()->addMinutes(120),
            'sla_breach_recorded_at' => null,
            'total_pending_minutes' => 0,
        ]);

        $this->checkSlaBreach($ticket);

        $this->assertNull($ticket->fresh()->sla_breach_recorded_at, 'a ticket well within SLA must NOT record a breach');
    }

    public function test_breach_recorded_when_past_due(): void
    {
        // Opened 200 min ago, due 50 min ago → 150-min window, 200 min elapsed → breach.
        $ticket = Ticket::factory()->for(Client::factory())->create([
            'status' => TicketStatus::InProgress,
            'opened_at' => now()->subMinutes(200),
            'due_at' => now()->subMinutes(50),
            'sla_breach_recorded_at' => null,
            'total_pending_minutes' => 0,
        ]);

        $this->checkSlaBreach($ticket);

        $this->assertNotNull($ticket->fresh()->sla_breach_recorded_at, 'a genuinely overdue ticket must record a breach');
    }
}
