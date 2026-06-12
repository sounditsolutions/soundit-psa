<?php

namespace Database\Factories;

use App\Enums\WikiAuthorType;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\WikiPage> */
class WikiPageFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->words(3, true);

        return [
            'scope' => WikiScope::Global,
            'client_id' => null,
            'slug' => Str::slug($title),
            'title' => $title,
            'kind' => WikiPageKind::Note,
            'parent_page_id' => null,
            'body_md' => "## Notes\n\nSome content.",
            'meta' => null,
            'is_archived' => false,
            'created_by_type' => WikiAuthorType::System,
        ];
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => [
            'scope' => WikiScope::Client,
            'client_id' => $client->id,
        ]);
    }
}
