<?php

namespace Tests\Feature\Agent;

use App\Models\Setting;
use App\Support\AgentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_dormant_and_auto_off(): void
    {
        $this->assertFalse(AgentConfig::enabled());
        $this->assertSame(10, AgentConfig::maxPendingProposals());
        $this->assertNull(AgentConfig::proposeCloseAutoThreshold()); // auto band OFF by default
        $this->assertSame(0.50, AgentConfig::proposeCloseApproveFloor());
        $this->assertNotSame('', AgentConfig::significanceModel());
    }

    public function test_auto_threshold_absent_is_null_not_floor(): void // BLOCKER guard
    {
        $this->assertNull(AgentConfig::proposeCloseAutoThreshold());        // genuinely absent → null
        Setting::setValue('propose_close_auto_threshold', '0.5');
        $this->assertSame(0.90, AgentConfig::proposeCloseAutoThreshold());  // below floor → clamped up
        Setting::setValue('propose_close_auto_threshold', '0.97');
        $this->assertSame(0.97, AgentConfig::proposeCloseAutoThreshold());  // above floor → honored
    }

    public function test_overrides_and_floors(): void
    {
        Setting::setValue('backlog_agent_enabled', '1');
        Setting::setValue('backlog_agent_max_pending', '0'); // below floor
        $this->assertTrue(AgentConfig::enabled());
        $this->assertSame(1, AgentConfig::maxPendingProposals());
    }
}
