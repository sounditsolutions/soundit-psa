<?php

namespace Tests\Feature\Prospect;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ClientStageFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_default_list_shows_both_stages_with_a_badge_on_prospects(): void
    {
        $user = User::factory()->create();
        Client::factory()->create(['name' => 'Acme Active Co']);
        Client::factory()->prospect()->create(['name' => 'Lead Prospect Co']);

        $resp = $this->actingAs($user)->get(route('clients.index'))->assertOk();
        $resp->assertSee('Acme Active Co', false);
        $resp->assertSee('Lead Prospect Co', false);
        $resp->assertSee('badge-prospect', false);   // prospect row carries the badge
    }

    public function test_active_filter_excludes_prospects(): void
    {
        $user = User::factory()->create();
        Client::factory()->create(['name' => 'Acme Active Co']);
        Client::factory()->prospect()->create(['name' => 'Lead Prospect Co']);

        $resp = $this->actingAs($user)->get(route('clients.index', ['stage' => 'active']))->assertOk();
        $resp->assertSee('Acme Active Co', false);
        $resp->assertDontSee('Lead Prospect Co', false);
    }

    public function test_prospect_filter_shows_only_prospects(): void
    {
        $user = User::factory()->create();
        Client::factory()->create(['name' => 'Acme Active Co']);
        Client::factory()->prospect()->create(['name' => 'Lead Prospect Co']);

        $resp = $this->actingAs($user)->get(route('clients.index', ['stage' => 'prospect']))->assertOk();
        $resp->assertSee('Lead Prospect Co', false);
        $resp->assertDontSee('Acme Active Co', false);
    }
}
