<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Models\Client;
use App\Models\WikiFact;
use App\Models\WikiPage;
use App\Services\Wiki\WikiFactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @see WikiFactService::upsertCorrectionFact()
 *
 * Tests the Correction source — an operator re-assertion that becomes a durable,
 * pinned wiki fact, superseding even prior pinned corrections.
 */
class WikiFactCorrectionSourceTest extends TestCase
{
    use RefreshDatabase;

    private function setUpPage(): array
    {
        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);

        return [$client, $page];
    }

    // ── 1. Born correctly ─────────────────────────────────────────────────────

    public function test_correction_fact_is_born_confirmed_pinned_durable(): void
    {
        [, $page] = $this->setUpPage();

        $sourceRefs = [['type' => 'correction', 'conversation_id' => 42]];

        $fact = app(WikiFactService::class)->upsertCorrectionFact(
            $page,
            'active',
            'acme:no-auto-close',
            'Acme is on a no-auto-close contract',
            $sourceRefs,
        );

        $this->assertSame(WikiFactSource::Correction, $fact->source_type);
        $this->assertSame(WikiFactStatus::Confirmed, $fact->status);
        $this->assertTrue($fact->pinned);
        $this->assertSame(WikiFactVolatility::Durable, $fact->volatility);
        $this->assertSame($sourceRefs, $fact->source_refs);
        $this->assertNotNull($fact->last_affirmed_at);
    }

    // ── 2. Reaffirm (same statement, same subject) ────────────────────────────

    public function test_reaffirm_same_statement_returns_same_row_no_duplicate(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $sourceRefs = [['type' => 'correction', 'conversation_id' => 42]];

        $first = $service->upsertCorrectionFact(
            $page, 'active', 'acme:no-auto-close',
            'Acme is on a no-auto-close contract', $sourceRefs,
        );

        $this->travel(1)->days();

        $second = $service->upsertCorrectionFact(
            $page, 'active', 'acme:no-auto-close',
            'Acme is on a no-auto-close contract', $sourceRefs,
        );

        // Same row returned, no duplicate.
        $this->assertTrue($first->is($second));
        $this->assertSame(1, WikiFact::count());

        // last_affirmed_at bumped.
        $this->assertTrue($second->last_affirmed_at->gt($first->last_affirmed_at));
    }

    // ── 3. Supersede (same subject, new statement) — load-bearing test ────────

    public function test_re_correction_supersedes_prior_pinned_correction(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $sourceRefs = [['type' => 'correction', 'conversation_id' => 42]];

        // First correction — creates fact A, pinned.
        $factA = $service->upsertCorrectionFact(
            $page, 'active', 'acme:no-auto-close',
            'Acme is on a no-auto-close contract', $sourceRefs,
        );

        $this->assertTrue($factA->pinned);

        // Second correction with a DIFFERENT statement — must supersede even though A is pinned.
        $factB = $service->upsertCorrectionFact(
            $page, 'active', 'acme:no-auto-close',
            'Acme requires manual close — checked 2026-06-27', $sourceRefs,
        );

        $factA->refresh();

        // Fact A retired and pointing at B.
        $this->assertSame(WikiFactStatus::Retired, $factA->status);
        $this->assertSame($factB->id, $factA->superseded_by_fact_id);

        // Fact B confirmed and pinned.
        $this->assertSame(WikiFactStatus::Confirmed, $factB->status);
        $this->assertTrue($factB->pinned);
        $this->assertSame(WikiFactSource::Correction, $factB->source_type);

        // Exactly one non-retired row for that subject_key.
        $nonRetiredCount = WikiFact::where('subject_key', 'acme:no-auto-close')
            ->whereNot('status', WikiFactStatus::Retired->value)
            ->count();

        $this->assertSame(1, $nonRetiredCount);

        // Total rows: 2 (A retired, B live).
        $this->assertSame(2, WikiFact::count());
    }

    // ── 4. Different subject_key → independent second row ─────────────────────

    public function test_different_subject_keys_produce_independent_rows(): void
    {
        [, $page] = $this->setUpPage();
        $service = app(WikiFactService::class);

        $refs = [['type' => 'correction', 'conversation_id' => 1]];

        $factA = $service->upsertCorrectionFact(
            $page, 'active', 'acme:no-auto-close',
            'Acme is on a no-auto-close contract', $refs,
        );

        $factB = $service->upsertCorrectionFact(
            $page, 'active', 'acme:billing-weekly',
            'Acme bills weekly, not monthly', $refs,
        );

        $this->assertNotSame($factA->id, $factB->id);
        $this->assertSame(2, WikiFact::count());

        // Both are live.
        $this->assertSame(WikiFactStatus::Confirmed, $factA->fresh()->status);
        $this->assertSame(WikiFactStatus::Confirmed, $factB->fresh()->status);
    }
}
