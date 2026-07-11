<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\DailyBriefing> */
class DailyBriefingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'briefing_date' => now()->toDateString(),
            'sent_at' => now(),
            'open_ticket_count' => 0,
            'alert_count' => 0,
            'voicemail_count' => 0,
            'sla_risk_count' => 0,
            'ai_suggestions_included' => false,
        ];
    }
}
