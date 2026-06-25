<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\TicketPriority;
use App\Models\Setting;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
