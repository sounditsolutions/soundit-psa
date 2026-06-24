<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Ticket;
use App\Services\Technician\Emergency\EmergencyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencyDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(array $attrs = []): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->create(array_merge([
            'client_id' => $client->id,
            'status' => TicketStatus::New->value,
            'priority' => TicketPriority::P1->value,
            'opened_at' => now()->subHours(2),
            'responded_at' => null,
            'subject' => 'Printer is a little slow',
            'description' => 'minor',
        ], $attrs));
    }

    public function test_fresh_low_priority_ticket_is_not_an_emergency(): void
    {
        $t = $this->ticket(['priority' => TicketPriority::P4->value, 'opened_at' => now()->subMinute()]);
        $a = app(EmergencyDetector::class)->assess($t);
        $this->assertFalse($a->isEmergency);
    }

    public function test_aged_p1_untouched_is_an_emergency_by_age(): void
    {
        $t = $this->ticket(['opened_at' => now()->subHour()]); // > 15m P1 floor, no response
        $a = app(EmergencyDetector::class)->assess($t);
        $this->assertTrue($a->isEmergency);
        $this->assertContains('age', $a->reasons);
    }

    public function test_keyword_triggers_regardless_of_priority(): void
    {
        $t = $this->ticket(['priority' => TicketPriority::P4->value, 'opened_at' => now(), 'subject' => 'Server is DOWN - ransomware?']);
        $a = app(EmergencyDetector::class)->assess($t);
        $this->assertTrue($a->isEmergency);
        $this->assertContains('keyword', $a->reasons);
    }

    public function test_ai_severity_raises_but_rules_floor_holds(): void
    {
        $t = $this->ticket(['priority' => TicketPriority::P4->value, 'opened_at' => now(), 'subject' => 'all good']);
        // rules say nothing, AI raised severity 3 → emergency at 3
        $a = app(EmergencyDetector::class)->assess($t, 3);
        $this->assertTrue($a->isEmergency);
        $this->assertSame(3, $a->severity);
        // and a low AI severity cannot lower a rule signal:
        $t2 = $this->ticket(['subject' => 'OUTAGE', 'opened_at' => now()]);
        $a2 = app(EmergencyDetector::class)->assess($t2, 0);
        $this->assertTrue($a2->isEmergency);
        $this->assertGreaterThanOrEqual(2, $a2->severity);
    }

    public function test_signature_is_stable_per_client_and_subject(): void
    {
        $t = $this->ticket(['subject' => 'OUTAGE at site']);
        $a = app(EmergencyDetector::class)->assess($t);
        $b = app(EmergencyDetector::class)->assess($t->fresh());
        $this->assertSame($a->signature, $b->signature);
    }

    /**
     * CO-12: AI severity is clamped to [0,5] at the top of assess() so an injected
     * absurd value can neither inflate emergency severity nor underflow it. On a
     * fresh P4 with a benign subject and no SLA dates, NO rule signal fires, so the
     * clamped AI severity is the sole contributor and the resulting severity equals
     * the clamp exactly.
     */
    public function test_ai_severity_is_clamped_to_zero_through_five(): void
    {
        // Absurd high AI severity clamps to 5 (rules silent ⇒ severity is exactly the clamp).
        $high = $this->ticket(['priority' => TicketPriority::P4->value, 'opened_at' => now(), 'subject' => 'all good']);
        $aHigh = app(EmergencyDetector::class)->assess($high, 99);
        $this->assertSame(5, $aHigh->severity);
        $this->assertTrue($aHigh->isEmergency);

        // Negative AI severity clamps to 0 ⇒ with no rule signal, not an emergency.
        $low = $this->ticket(['priority' => TicketPriority::P4->value, 'opened_at' => now(), 'subject' => 'all good']);
        $aLow = app(EmergencyDetector::class)->assess($low, -42);
        $this->assertSame(0, $aLow->severity);
        $this->assertFalse($aLow->isEmergency);

        // Even clamped to 0, a real rule signal still fires (floor invariant holds).
        $floored = $this->ticket(['priority' => TicketPriority::P4->value, 'opened_at' => now(), 'subject' => 'total OUTAGE']);
        $aFloored = app(EmergencyDetector::class)->assess($floored, -42);
        $this->assertTrue($aFloored->isEmergency);
        $this->assertContains('keyword', $aFloored->reasons);
        $this->assertGreaterThanOrEqual(2, $aFloored->severity);
    }
}
