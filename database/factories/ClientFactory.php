<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Client> */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'halo_id' => fake()->unique()->randomNumber(6, true),
            'name' => fake()->company(),
        ];
    }

    public function prospect(): static
    {
        return $this->state(fn () => ['stage' => \App\Enums\ClientStage::Prospect, 'is_active' => true]);
    }
}
