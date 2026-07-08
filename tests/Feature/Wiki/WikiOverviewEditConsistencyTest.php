<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiPageKind;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiOverviewEditConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_overview_body_clears_composed_hash(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create([
            'kind' => WikiPageKind::Overview,
            'slug' => 'overview',
            'meta' => ['composed_at' => now()->toIso8601String(), 'composed_hash' => 'deadbeef'],
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->patch(route('wiki.update', $page), [
                'body_md' => "## Env\n\nHand-edited.\n",
                'change_summary' => 'Human edit',
                'expected_updated_at' => $page->updated_at->toIso8601String(),
            ])
            ->assertRedirect();

        $fresh = $page->fresh();
        $this->assertArrayNotHasKey('composed_hash', $fresh->meta ?? []);
    }

    public function test_editing_overview_body_preserves_composed_at(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create();
        $composedAt = now()->subHour()->toIso8601String();
        $page = WikiPage::factory()->forClient($client)->create([
            'kind' => WikiPageKind::Overview,
            'slug' => 'overview',
            'meta' => ['composed_at' => $composedAt, 'composed_hash' => 'deadbeef'],
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->patch(route('wiki.update', $page), [
                'body_md' => "## Env\n\nHand-edited.\n",
                'change_summary' => 'Human edit',
                'expected_updated_at' => $page->updated_at->toIso8601String(),
            ])
            ->assertRedirect();

        $fresh = $page->fresh();
        $this->assertSame($composedAt, $fresh->meta['composed_at'] ?? null);
    }

    public function test_editing_non_overview_body_does_not_alter_meta(): void
    {
        Setting::setValue('wiki_enabled', '1');
        $client = Client::factory()->create();
        $meta = ['some_key' => 'some_value'];
        $page = WikiPage::factory()->forClient($client)->create([
            'kind' => WikiPageKind::Environment,
            'slug' => 'network',
            'meta' => $meta,
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)
            ->patch(route('wiki.update', $page), [
                'body_md' => "## Network\n\nEdited.\n",
                'change_summary' => 'Human edit',
                'expected_updated_at' => $page->updated_at->toIso8601String(),
            ])
            ->assertRedirect();

        $fresh = $page->fresh();
        $this->assertSame('some_value', $fresh->meta['some_key'] ?? null);
    }
}
