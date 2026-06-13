<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Enums\WikiScope;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Models\WikiFact;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiFactActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WikiFact $fact;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('wiki_enabled', '1');

        $this->user = User::factory()->create();

        $client = Client::factory()->create();
        $page = WikiPage::factory()->forClient($client)->create(['slug' => 'infrastructure']);

        $this->fact = WikiFact::factory()->create([
            'client_id' => $client->id,
            'page_id' => $page->id,
            'scope' => WikiScope::Client,
            'section_anchor' => 'assets',
            'subject_key' => 'asset:dc01:ram',
            'statement' => 'DC01 has 32 GB RAM',
            'status' => WikiFactStatus::Unverified,
            'source_type' => WikiFactSource::Ticket,
            'volatility' => WikiFactVolatility::Durable,
            'source_refs' => [['type' => 'ticket', 'id' => 1]],
        ]);
    }

    public function test_confirm_promotes_fact_to_confirmed(): void
    {
        $this->actingAs($this->user)
            ->post(route('wiki.facts.confirm', $this->fact))
            ->assertRedirect();

        $this->assertSame(WikiFactStatus::Confirmed, $this->fact->fresh()->status);
    }

    public function test_retire_marks_fact_retired(): void
    {
        $this->actingAs($this->user)
            ->post(route('wiki.facts.retire', $this->fact))
            ->assertRedirect();

        $this->assertSame(WikiFactStatus::Retired, $this->fact->fresh()->status);
    }

    public function test_correct_creates_new_pinned_fact_and_retires_old(): void
    {
        $this->actingAs($this->user)
            ->post(route('wiki.facts.correct', $this->fact), [
                'statement' => 'DC01 has 64 GB RAM',
            ])
            ->assertRedirect();

        $this->assertSame(WikiFactStatus::Retired, $this->fact->fresh()->status);

        $new = WikiFact::where('subject_key', 'asset:dc01:ram')
            ->where('status', WikiFactStatus::Confirmed->value)
            ->where('pinned', true)
            ->latest('id')
            ->first();

        $this->assertNotNull($new);
        $this->assertSame('DC01 has 64 GB RAM', $new->statement);
    }

    public function test_correct_rejects_a_credential(): void
    {
        $this->actingAs($this->user)
            ->post(route('wiki.facts.correct', $this->fact), [
                'statement' => 'password: s3cr3t123',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('statement');

        // Old fact must NOT be retired
        $this->assertSame(WikiFactStatus::Unverified, $this->fact->fresh()->status);
    }

    public function test_resolve_accepts_challenger_fact(): void
    {
        // Create a disputed pair
        $incumbent = WikiFact::factory()->create([
            'client_id' => $this->fact->client_id,
            'page_id' => $this->fact->page_id,
            'scope' => WikiScope::Client,
            'section_anchor' => 'assets',
            'subject_key' => 'asset:dc02:ram',
            'statement' => 'DC02 has 16 GB RAM',
            'status' => WikiFactStatus::Disputed,
            'source_type' => WikiFactSource::Sync,
            'volatility' => WikiFactVolatility::Durable,
            'source_refs' => [['type' => 'sync', 'id' => 'test']],
        ]);

        $challenger = WikiFact::factory()->create([
            'client_id' => $this->fact->client_id,
            'page_id' => $this->fact->page_id,
            'scope' => WikiScope::Client,
            'section_anchor' => 'assets',
            'subject_key' => 'asset:dc02:ram',
            'statement' => 'DC02 has 32 GB RAM',
            'status' => WikiFactStatus::Disputed,
            'source_type' => WikiFactSource::Ticket,
            'volatility' => WikiFactVolatility::Durable,
            'source_refs' => [['type' => 'ticket', 'id' => 99]],
            'disputed_with_fact_id' => $incumbent->id,
        ]);

        $incumbent->update(['disputed_with_fact_id' => $challenger->id]);

        $this->actingAs($this->user)
            ->post(route('wiki.facts.resolve', $challenger), ['action' => 'accept'])
            ->assertRedirect();

        $this->assertSame(WikiFactStatus::Confirmed, $challenger->fresh()->status);
        $this->assertSame(WikiFactStatus::Retired, $incumbent->fresh()->status);
    }

    public function test_actions_404_when_wiki_disabled(): void
    {
        Setting::setValue('wiki_enabled', '0');

        $this->actingAs($this->user)
            ->post(route('wiki.facts.confirm', $this->fact))
            ->assertNotFound();

        $this->actingAs($this->user)
            ->post(route('wiki.facts.retire', $this->fact))
            ->assertNotFound();

        $this->actingAs($this->user)
            ->post(route('wiki.facts.correct', $this->fact), ['statement' => 'new'])
            ->assertNotFound();

        $this->actingAs($this->user)
            ->post(route('wiki.facts.resolve', $this->fact), ['action' => 'accept'])
            ->assertNotFound();
    }
}
