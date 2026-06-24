<?php

namespace Tests\Feature\Technician;

use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Technician\TechnicianBudget;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults(): void
    {
        $this->assertSame(1_000_000, TechnicianConfig::dailyTokenLimit());
        $this->assertSame(100_000, TechnicianConfig::maxTokensPerRun());
    }

    public function test_limit_reached_sums_todays_runs(): void
    {
        Setting::setValue('technician_daily_token_limit', '500');
        $budget = new TechnicianBudget;

        $this->assertFalse($budget->dailyLimitReached());

        $ticket = Ticket::factory()->create();
        TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'send_reply',
            'content_hash' => str_repeat('a', 64),
            'state' => 'awaiting_approval',
            'tokens_used' => 300,
        ]);
        TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'propose_resolution',
            'content_hash' => str_repeat('b', 64),
            'state' => 'awaiting_approval',
            'tokens_used' => 250,
        ]);

        $this->assertSame(550, $budget->usedToday());
        $this->assertTrue($budget->dailyLimitReached());
    }
}
