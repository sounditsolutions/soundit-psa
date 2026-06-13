<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Models\Client;
use App\Models\User;
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

    // ── sync-fact tests (existing) ────────────────────────────────────────────

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

    // ── upsertMinedFact tests ────────────────────────────────────────────────

    public function test_mined_fact_is_born_unverified(): void
    {
        [, $page] = $this->setUpPage();

        $fact = app(WikiFactService::class)->upsertMinedFact(
            $page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 1]], 0.9,
        );

        $this->assertSame(WikiFactStatus::Unverified, $fact->status);
        $this->assertSame(WikiFactSource::Ticket, $fact->source_type);
        $this->assertNotNull($fact->last_affirmed_at);
    }

    public function test_mined_reaffirms_same_statement(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $first = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 1]], 0.9);
        $this->travel(1)->days();
        $second = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 2]], 0.85);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, WikiFact::count());
        $this->assertTrue($second->last_affirmed_at->gt($first->last_affirmed_at));
    }

    public function test_mined_contradiction_creates_disputed_pair(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $existing = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 16 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 1]], 0.9);

        $challenger = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 2]], 0.85);

        $existing->refresh();
        $challenger->refresh();

        $this->assertSame(WikiFactStatus::Disputed, $existing->status);
        $this->assertSame(WikiFactStatus::Disputed, $challenger->status);
        $this->assertSame($challenger->id, $existing->disputed_with_fact_id);
        $this->assertSame($existing->id, $challenger->disputed_with_fact_id);
        $this->assertSame(2, WikiFact::count());
    }

    public function test_mined_contradiction_against_confirmed_creates_disputed_pair(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $confirmed = $service->upsertSyncFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 16 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'sync', 'id' => 'ninja']]);

        $challenger = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 2]], 0.85);

        $confirmed->refresh();
        $this->assertSame(WikiFactStatus::Disputed, $confirmed->status);
        $this->assertSame(WikiFactStatus::Disputed, $challenger->status);
        $this->assertSame($challenger->id, $confirmed->disputed_with_fact_id);
        $this->assertSame($confirmed->id, $challenger->disputed_with_fact_id);
    }

    public function test_mined_returns_null_when_pinned_and_evidence_is_subset_of_dismissed(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $existing = WikiFact::factory()->create([
            'client_id' => $page->client_id,
            'page_id' => $page->id,
            'section_anchor' => 'assets',
            'subject_key' => 'asset:dc01:ram',
            'statement' => 'DC01 has 16 GB RAM',
            'status' => WikiFactStatus::Confirmed,
            'pinned' => true,
            'source_type' => WikiFactSource::Human,
            'source_refs' => [['type' => 'human', 'id' => 'manual']],
            'dismissed_evidence' => [['type' => 'ticket', 'id' => 99]],
        ]);

        $result = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 99]], 0.85);

        $this->assertNull($result);
        $this->assertSame(1, WikiFact::count()); // no new row
        $existing->refresh();
        $this->assertSame(WikiFactStatus::Confirmed, $existing->status); // untouched
    }

    public function test_mined_returns_null_when_already_disputed(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $a = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 16 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 1]], 0.9);
        $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 2]], 0.85);

        // A third extraction with yet another statement — already disputed, should return null
        $result = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 64 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 3]], 0.8);

        $this->assertNull($result);
        $this->assertSame(2, WikiFact::count()); // no third row
    }

    // ── lifecycle action tests ────────────────────────────────────────────────

    public function test_confirm_promotes_unverified_to_confirmed(): void
    {
        [, $page] = $this->setUpPage();
        $user = User::factory()->create();
        $service = app(WikiFactService::class);

        $fact = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 1]], 0.9);

        $service->confirm($fact, $user);

        $fact->refresh();
        $this->assertSame(WikiFactStatus::Confirmed, $fact->status);
        $this->assertSame($user->id, $fact->confirmed_by);
    }

    public function test_retire_marks_fact_retired(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $fact = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 1]], 0.9);

        $service->retire($fact);

        $this->assertSame(WikiFactStatus::Retired, $fact->fresh()->status);
    }

    public function test_correct_creates_pinned_human_fact_and_retires_old(): void
    {
        [, $page] = $this->setUpPage();
        $user = User::factory()->create();
        $service = app(WikiFactService::class);

        $old = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 16 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 1]], 0.9);

        $new = $service->correct($old, 'DC01 has 32 GB RAM', $user);

        $old->refresh();
        $this->assertSame(WikiFactStatus::Retired, $old->status);
        $this->assertSame($new->id, $old->superseded_by_fact_id);
        $this->assertSame(WikiFactStatus::Confirmed, $new->status);
        $this->assertTrue($new->pinned);
        $this->assertSame(WikiFactSource::Human, $new->source_type);
        $this->assertSame($user->id, $new->confirmed_by);
    }

    public function test_resolve_dispute_accept_confirms_challenger_and_retires_incumbent(): void
    {
        [, $page] = $this->setUpPage();
        $user = User::factory()->create();
        $service = app(WikiFactService::class);

        $incumbent = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 16 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 1]], 0.9);
        $challenger = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 2]], 0.85);

        // resolveDispute(challenger, 'accept', user) — accept the challenger's claim
        $service->resolveDispute($challenger, 'accept', $user);

        $incumbent->refresh();
        $challenger->refresh();
        $this->assertSame(WikiFactStatus::Retired, $incumbent->status);
        $this->assertSame(WikiFactStatus::Confirmed, $challenger->status);
        $this->assertSame($user->id, $challenger->confirmed_by);
    }

    public function test_resolve_dispute_dismiss_retires_challenger_and_pins_incumbent(): void
    {
        [, $page] = $this->setUpPage();
        $user = User::factory()->create();
        $service = app(WikiFactService::class);

        $incumbent = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 16 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 1]], 0.9);
        $challenger = $service->upsertMinedFact($page, 'assets', 'asset:dc01:ram', 'DC01 has 32 GB RAM',
            WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 2]], 0.85);

        // resolveDispute(challenger, 'dismiss', user) — dismiss the challenger
        $service->resolveDispute($challenger, 'dismiss', $user);

        $incumbent->refresh();
        $challenger->refresh();
        $this->assertSame(WikiFactStatus::Retired, $challenger->status);
        $this->assertSame(WikiFactStatus::Confirmed, $incumbent->status);
        $this->assertTrue($incumbent->pinned);
        // dismissed_evidence on incumbent should contain challenger source_refs
        $this->assertNotEmpty($incumbent->dismissed_evidence);
    }

    public function test_is_subset_of_dismissed_returns_true_for_subset(): void
    {
        $dismissed = [['type' => 'ticket', 'id' => 1], ['type' => 'ticket', 'id' => 2]];
        $evidence = [['type' => 'ticket', 'id' => 1]];

        $this->assertTrue(WikiFactService::isSubsetOfDismissed($evidence, $dismissed));
    }

    public function test_is_subset_of_dismissed_returns_false_for_new_evidence(): void
    {
        $dismissed = [['type' => 'ticket', 'id' => 1]];
        $evidence = [['type' => 'ticket', 'id' => 1], ['type' => 'ticket', 'id' => 99]];

        $this->assertFalse(WikiFactService::isSubsetOfDismissed($evidence, $dismissed));
    }

    public function test_is_subset_of_dismissed_returns_false_for_empty_dismissed(): void
    {
        $this->assertFalse(WikiFactService::isSubsetOfDismissed([['type' => 'ticket', 'id' => 1]], []));
    }
}
