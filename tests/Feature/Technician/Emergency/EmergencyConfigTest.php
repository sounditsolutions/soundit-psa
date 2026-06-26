<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\TicketPriority;
use App\Models\Setting;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmergencyConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults(): void
    {
        $this->assertSame(15, TechnicianConfig::emergencyAgeMinutes(TicketPriority::P1));
        $this->assertSame(1440, TechnicianConfig::emergencyAgeMinutes(TicketPriority::P4));
        $this->assertContains('ransomware', TechnicianConfig::emergencyKeywords());
        $this->assertTrue(TechnicianConfig::operatorAvailable(999)); // unset ⇒ available
        $this->assertSame(15, TechnicianConfig::escalationTimeoutMinutes());
    }

    public function test_overrides_and_availability_floor(): void
    {
        Setting::setValue('technician_emergency_age_minutes', json_encode(['p1' => 5]));
        $this->assertSame(5, TechnicianConfig::emergencyAgeMinutes(TicketPriority::P1));

        Setting::setValue('technician_escalation_timeout', '1'); // below floor
        $this->assertSame(5, TechnicianConfig::escalationTimeoutMinutes());

        TechnicianConfig::setOperatorAvailable(3, false);
        $this->assertFalse(TechnicianConfig::operatorAvailable(3));
        $this->assertTrue(TechnicianConfig::operatorAvailable(1));
    }

    public function test_storm_window_and_max_hold_message_defaults(): void
    {
        $this->assertSame(15, TechnicianConfig::stormWindowMinutes());
        $this->assertSame(
            "Thank you for reaching out. We've flagged this as urgent and are working to get a technician to you as quickly as possible. We'll be in touch shortly.",
            TechnicianConfig::maxHoldMessage()
        );
    }

    public function test_emergency_reping_default_and_floor(): void
    {
        $this->assertSame(30, TechnicianConfig::emergencyRepingMinutes());

        Setting::setValue('technician_emergency_reping', '2'); // below floor
        $this->assertSame(5, TechnicianConfig::emergencyRepingMinutes());
    }

    public function test_operator_phone_set_and_read(): void
    {
        // Unset → null
        $this->assertNull(TechnicianConfig::operatorPhone(7));

        // Set then read
        TechnicianConfig::setOperatorPhone(7, '+12065550101');
        $this->assertSame('+12065550101', TechnicianConfig::operatorPhone(7));

        // Different user is still null
        $this->assertNull(TechnicianConfig::operatorPhone(8));

        // Null clears it
        TechnicianConfig::setOperatorPhone(7, null);
        $this->assertNull(TechnicianConfig::operatorPhone(7));
    }

    // ── coverage-start anchor (psa-wmqp) ────────────────────────────────────

    public function test_coverage_start_is_null_when_unset(): void
    {
        $this->assertNull(TechnicianConfig::coverageStartAt());
    }

    public function test_record_coverage_start_stamps_now(): void
    {
        $known = Carbon::parse('2026-06-26 12:00:00');
        Carbon::setTestNow($known);

        TechnicianConfig::recordCoverageStart();

        $this->assertNotNull(TechnicianConfig::coverageStartAt());
        $this->assertTrue(TechnicianConfig::coverageStartAt()->equalTo($known));

        Carbon::setTestNow();
    }

    public function test_ensure_coverage_start_stamps_only_when_unset(): void
    {
        // Already anchored 3 days ago ⇒ ensure must NOT move it (idempotent).
        $anchor = Carbon::parse('2026-06-23 09:00:00');
        Setting::setValue('technician_coverage_start_at', $anchor->toIso8601String());

        TechnicianConfig::ensureCoverageStart();

        $this->assertTrue(TechnicianConfig::coverageStartAt()->equalTo($anchor), 'ensure must not re-anchor an existing value');

        // Cleared ⇒ ensure stamps now.
        TechnicianConfig::clearCoverageStart();
        $now = Carbon::parse('2026-06-26 12:00:00');
        Carbon::setTestNow($now);

        TechnicianConfig::ensureCoverageStart();

        $this->assertTrue(TechnicianConfig::coverageStartAt()->equalTo($now), 'ensure stamps now when unset');

        Carbon::setTestNow();
    }

    public function test_clear_coverage_start_resets_to_null(): void
    {
        TechnicianConfig::recordCoverageStart();
        $this->assertNotNull(TechnicianConfig::coverageStartAt());

        TechnicianConfig::clearCoverageStart();

        $this->assertNull(TechnicianConfig::coverageStartAt());
    }
}
