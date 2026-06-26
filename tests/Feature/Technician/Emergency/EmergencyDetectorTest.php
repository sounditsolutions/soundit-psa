<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Technician\Emergency\EmergencyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    // ── coverage-start anchor (psa-wmqp): the age signal only fires for tickets
    //    OPENED during the coverage window; keyword + SLA stay always-on. ──────

    public function test_age_does_not_fire_for_a_ticket_opened_before_coverage_start(): void
    {
        // Coverage started now; a benign backlog ticket opened an hour ago predates it.
        Setting::setValue('technician_coverage_start_at', now()->toIso8601String());
        $t = $this->ticket(['opened_at' => now()->subHour(), 'subject' => 'Printer is a little slow', 'description' => 'minor']);

        $a = app(EmergencyDetector::class)->assess($t);

        $this->assertFalse($a->isEmergency, 'a pre-coverage backlog ticket must not flag by age');
        $this->assertNotContains('age', $a->reasons);
    }

    public function test_age_fires_for_a_ticket_opened_exactly_at_coverage_start(): void
    {
        // Inclusive start boundary (gte): a ticket that arrived at the very moment
        // coverage began is within the window. Pins the gte vs gt decision so a
        // future regression to a strict `>` would fail here. Frozen time + a
        // second-precision anchor make the equality exact.
        Carbon::setTestNow(Carbon::parse('2026-06-26 13:00:00'));
        $start = Carbon::parse('2026-06-26 12:00:00'); // 1h before now, past the 15m P1 floor
        Setting::setValue('technician_coverage_start_at', $start->toIso8601String());
        $t = $this->ticket(['opened_at' => $start->copy(), 'subject' => 'Printer is a little slow', 'description' => 'minor']);

        $a = app(EmergencyDetector::class)->assess($t);

        $this->assertTrue($a->isEmergency);
        $this->assertContains('age', $a->reasons);

        Carbon::setTestNow();
    }

    public function test_age_fires_for_a_ticket_opened_after_coverage_start(): void
    {
        // Coverage started two hours ago; a ticket opened an hour ago is within the
        // window and now past the 15m P1 floor while still untouched.
        Setting::setValue('technician_coverage_start_at', now()->subHours(2)->toIso8601String());
        $t = $this->ticket(['opened_at' => now()->subHour(), 'subject' => 'Printer is a little slow', 'description' => 'minor']);

        $a = app(EmergencyDetector::class)->assess($t);

        $this->assertTrue($a->isEmergency);
        $this->assertContains('age', $a->reasons);
    }

    public function test_keyword_still_fires_for_a_pre_coverage_ticket(): void
    {
        // Always-on: the anchor gates ONLY the age signal, so a pre-coverage ticket
        // with an emergency keyword still flags (by keyword, not age).
        Setting::setValue('technician_coverage_start_at', now()->toIso8601String());
        $t = $this->ticket(['opened_at' => now()->subHour(), 'subject' => 'Server OUTAGE at site']);

        $a = app(EmergencyDetector::class)->assess($t);

        $this->assertTrue($a->isEmergency);
        $this->assertContains('keyword', $a->reasons);
        $this->assertNotContains('age', $a->reasons);
    }

    public function test_sla_breach_still_fires_for_a_pre_coverage_ticket(): void
    {
        // Always-on: a pre-coverage ticket past its response SLA still flags by SLA.
        Setting::setValue('technician_coverage_start_at', now()->toIso8601String());
        $t = $this->ticket([
            'opened_at' => now()->subHour(),
            'subject' => 'Printer is a little slow',
            'description' => 'minor',
            'response_due_at' => now()->subMinutes(5), // breached, responded_at is null
        ]);

        $a = app(EmergencyDetector::class)->assess($t);

        $this->assertTrue($a->isEmergency);
        $this->assertContains('sla', $a->reasons);
        $this->assertNotContains('age', $a->reasons);
    }

    public function test_null_coverage_start_leaves_age_unanchored(): void
    {
        // No anchor ⇒ the age signal behaves as the isolated unit always has (an aged
        // untouched ticket flags), preserving every existing detector test.
        $t = $this->ticket(['opened_at' => now()->subHour(), 'subject' => 'Printer is a little slow', 'description' => 'minor']);

        $a = app(EmergencyDetector::class)->assess($t);

        $this->assertTrue($a->isEmergency);
        $this->assertContains('age', $a->reasons);
    }
}
