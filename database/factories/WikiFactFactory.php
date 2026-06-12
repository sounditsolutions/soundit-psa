<?php

namespace Database\Factories;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Enums\WikiScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\WikiFact> */
class WikiFactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scope' => WikiScope::Client,
            'client_id' => null, // set by caller
            'page_id' => null,   // set by caller
            'section_anchor' => 'assets',
            'subject_key' => 'asset:'.fake()->word().':os',
            'statement' => fake()->sentence(),
            'status' => WikiFactStatus::Confirmed,
            'pinned' => false,
            'volatility' => WikiFactVolatility::Durable,
            'source_type' => WikiFactSource::Sync,
            'source_refs' => [['type' => 'sync', 'id' => 'test']],
            'confidence' => null,
            'last_affirmed_at' => now(),
        ];
    }
}
