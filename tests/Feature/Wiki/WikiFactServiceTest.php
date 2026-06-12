<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Models\Client;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Wiki\WikiFactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** @see WikiFactService::upsertSyncFact() — the empty-subject gap-lock race cannot be exercised on SQLite; see the service docblock. */
class WikiFactServiceTest extends TestCase
{
    use RefreshDatabase;

    private function setUpPage(): array
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);

        return [$client, $page];
    }

    public function test_inserts_new_sync_fact_as_confirmed(): void
    {
        [$client, $page] = $this->setUpPage();

        $fact = app(WikiFactService::class)->upsertSyncFact(
            $page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']],
        );

        $this->assertSame(WikiFactStatus::Confirmed, $fact->status);
        $this->assertSame($client->id, $fact->client_id);
        $this->assertNotNull($fact->last_affirmed_at);
    }

    public function test_reaffirms_unchanged_statement_without_new_row(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $first = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);
        $this->travel(1)->days();
        $second = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, WikiFact::count());
        $this->assertTrue($second->last_affirmed_at->gt($first->last_affirmed_at));
    }

    public function test_supersedes_changed_statement(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $old = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 16 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);
        $new = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);

        $old->refresh();
        $this->assertSame(WikiFactStatus::Retired, $old->status);
        $this->assertSame($new->id, $old->superseded_by_fact_id);
        $this->assertSame(WikiFactStatus::Confirmed, $new->status);
        $this->assertSame(2, WikiFact::count());
    }

    public function test_pinned_fact_is_never_auto_superseded(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $pinned = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 16 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);
        $pinned->update(['pinned' => true]);

        $result = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);

        // Spec §5.2: pinned facts are never auto-superseded; sync leaves them untouched.
        $this->assertTrue($result->is($pinned->fresh()));
        $this->assertSame(WikiFactStatus::Confirmed, $pinned->fresh()->status);
        $this->assertSame('DC01 has 16 GB RAM', $pinned->fresh()->statement);
        $this->assertSame(1, WikiFact::count());
    }
}
