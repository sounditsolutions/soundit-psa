<?php

namespace Tests\Feature\Wiki;

use App\Enums\WikiFactStatus;
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
            'client_id' => $client->id, 'page_id' => $page->id,
            'status' => WikiFactStatus::Unverified,
            'subject_key' => 'asset:dc01:os', 'statement' => 'DC01 runs Windows Server 2022',
            'source_type' => 'ticket', 'source_refs' => [['type' => 'ticket', 'id' => 7]],
        ]);
    }

    public function test_confirm_is_one_click(): void
    {
        $this->actingAs($this->user)
            ->post("/wiki-facts/{$this->fact->id}/confirm")
            ->assertRedirect();

        $this->assertSame(WikiFactStatus::Confirmed, $this->fact->fresh()->status);
        $this->assertSame($this->user->id, $this->fact->fresh()->confirmed_by);
    }

    public function test_retire(): void
    {
        $this->actingAs($this->user)
            ->post("/wiki-facts/{$this->fact->id}/retire")
            ->assertRedirect();

        $this->assertSame(WikiFactStatus::Retired, $this->fact->fresh()->status);
    }

    public function test_correct_creates_pinned_human_fact(): void
    {
        $this->actingAs($this->user)
            ->patch("/wiki-facts/{$this->fact->id}/correct", ['statement' => 'DC01 runs Windows Server 2025'])
            ->assertRedirect();

        $this->assertSame(WikiFactStatus::Retired, $this->fact->fresh()->status);
        $new = WikiFact::where('statement', 'DC01 runs Windows Server 2025')->first();
        $this->assertTrue($new->pinned);
        $this->assertSame('human', $new->source_type->value);
    }

    public function test_correct_rejects_a_credential(): void
    {
        // Security review M3 / spec §4.4: a human correction carrying a secret is refused.
        $this->actingAs($this->user)
            ->from("/clients/{$this->fact->client_id}/wiki/infrastructure")
            ->patch("/wiki-facts/{$this->fact->id}/correct", ['statement' => 'DC01 admin password is Hunter2'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(WikiFactStatus::Unverified, $this->fact->fresh()->status); // unchanged
        $this->assertSame(0, WikiFact::where('statement', 'like', '%Hunter2%')->count());
    }

    public function test_dispute_resolution_routes(): void
    {
        $service = app(\App\Services\Wiki\WikiFactService::class);
        $challenger = $service->upsertMinedFact(
            $this->fact->page, 'assets', 'asset:dc01:os', 'DC01 runs Windows Server 2019',
            \App\Enums\WikiFactVolatility::Durable, [['type' => 'ticket', 'id' => 9]], 0.7,
        );

        $this->actingAs($this->user)
            ->post("/wiki-facts/{$challenger->id}/resolve", ['resolution' => 'dismiss'])
            ->assertRedirect();

        $this->assertSame(WikiFactStatus::Retired, $challenger->fresh()->status);
        $this->assertTrue($this->fact->fresh()->pinned);
    }

    public function test_actions_404_when_wiki_disabled(): void
    {
        Setting::setValue('wiki_enabled', '0');

        $this->actingAs($this->user)
            ->post("/wiki-facts/{$this->fact->id}/confirm")
            ->assertNotFound();
    }
}
