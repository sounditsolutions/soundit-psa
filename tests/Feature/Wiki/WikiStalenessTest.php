<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Models\Setting;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiStalenessTest extends TestCase
{
    use RefreshDatabase;

    private function fact(array $attrs): WikiFact
    {
        $page = WikiPage::factory()->create();

        return WikiFact::factory()->create(array_merge([
            'page_id' => $page->id, 'client_id' => $page->client_id, 'scope' => $page->scope,
            'subject_key' => 'asset:dc01:fw', 'statement' => 'firmware 7.2.1',
            'status' => WikiFactStatus::Confirmed, 'source_type' => WikiFactSource::Ticket,
            'volatility' => WikiFactVolatility::Volatile, 'last_affirmed_at' => now()->subDays(120),
        ], $attrs));
    }

    public function test_volatile_unaffirmed_past_window_is_stale(): void
    {
        Setting::setValue('wiki_staleness_days_volatile', '90');
        $this->assertTrue($this->fact([])->isStale());
    }

    public function test_durable_fact_never_stale(): void
    {
        $this->assertFalse($this->fact(['volatility' => WikiFactVolatility::Durable])->isStale());
    }

    public function test_sync_sourced_volatile_fact_is_exempt(): void
    {
        // §4.2: sync-backed facts refresh at the source; they never go stale.
        $this->assertFalse($this->fact(['source_type' => WikiFactSource::Sync])->isStale());
    }

    public function test_retired_fact_not_stale(): void
    {
        $this->assertFalse($this->fact(['status' => WikiFactStatus::Retired])->isStale());
    }

    public function test_recently_affirmed_not_stale(): void
    {
        $this->assertFalse($this->fact(['last_affirmed_at' => now()->subDays(5)])->isStale());
    }

    public function test_scope_matches_accessor(): void
    {
        Setting::setValue('wiki_staleness_days_volatile', '90');
        $stale = $this->fact([]);
        $fresh = $this->fact(['last_affirmed_at' => now()]);
        $ids = WikiFact::stale()->pluck('id');
        $this->assertTrue($ids->contains($stale->id));
        $this->assertFalse($ids->contains($fresh->id));
    }
}
