<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\TacticalWebhook> */
class TacticalWebhookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event' => 'alert_failure',
            'agent_id' => fake()->regexify('[A-Za-z0-9]{40}'),
            'payload' => [
                'event' => 'alert_failure',
                'agent_id' => fake()->regexify('[A-Za-z0-9]{40}'),
                'hostname' => 'WS-'.fake()->numerify('####'),
                'alert_type' => 'check',
                'severity' => 'error',
                'check_name' => 'Disk Space - C:',
                'check_output' => 'Drive C: low on space',
                'alert_message' => 'Disk space low',
            ],
            'status' => 'pending',
            'attempts' => 0,
            'error' => null,
            'dedup_key' => fake()->unique()->uuid(),
            'processed_at' => null,
        ];
    }
}
