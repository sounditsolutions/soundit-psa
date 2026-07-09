<?php

namespace Database\Factories;

use App\Enums\ToolingGapClassification;
use App\Enums\ToolingGapSource;
use App\Enums\ToolingGapStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\ToolingGap> */
class ToolingGapFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => null,   // set by caller
            'client_id' => null,   // set by caller
            'capability_gap' => fake()->sentence(),
            'tool_name' => null,   // set by caller (only for tool_broken reports)
            'evidence' => fake()->optional()->sentence(),
            'classification' => ToolingGapClassification::ToolMissing,
            'source' => ToolingGapSource::Agent,
            'status' => ToolingGapStatus::Open,
            'agent_note' => null,
        ];
    }
}
