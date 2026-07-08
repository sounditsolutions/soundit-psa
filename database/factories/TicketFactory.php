<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Ticket> */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'subject' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'resolution' => null,
            'resolution_ai_drafted' => false,
            'source' => TicketSource::Manual->value,
            'type' => TicketType::Incident->value,
            'status' => TicketStatus::Closed->value,
            'priority' => TicketPriority::P3->value,
            'opened_at' => now()->subDays(3),
            'closed_at' => now(),
        ];
    }
}
