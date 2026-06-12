<?php

namespace Database\Factories;

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
            'hostname' => strtoupper(fake()->bothify('HOST-##')),
            'os' => 'Windows 11 Pro',
            'ram_gb' => 16,
            'asset_type' => 'Workstation',
            'is_active' => true,
        ];
    }
}
