<?php

namespace Database\Factories;

use App\Enums\AssetHealthGrade;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Asset> */
class AssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->bothify('HOST-##'),
            // unique(): hostnames feed wiki fact subject keys — collisions would collapse them.
            'hostname' => strtoupper(fake()->unique()->bothify('HOST-####')),
            'os' => 'Windows 11 Pro',
            'ram_gb' => 16,
            'asset_type' => 'Workstation',
            'is_active' => true,
        ];
    }

    /** Pre-seed a cached health score + matching grade (fresh). */
    public function scored(int $score): static
    {
        return $this->state(fn () => [
            'health_score' => $score,
            'health_grade' => AssetHealthGrade::fromScore($score)->value,
            'health_computed_at' => now(),
        ]);
    }
}
