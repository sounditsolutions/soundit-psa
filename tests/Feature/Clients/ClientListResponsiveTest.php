<?php

namespace Tests\Feature\Clients;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ClientListResponsiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_client_list_surfaces_phone_email_and_action_on_mobile(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create([
            'name' => 'QA Responsive Co',
            'phone' => '+15550101234',
            'phone_display' => '(555) 010-1234',
            'email' => 'ops@qa-responsive.example',
        ]);

        $resp = $this->actingAs($user)->get(route('clients.index'))->assertOk();

        // The name, phone, and email are all present in the rendered markup so the
        // mobile result is not clipped — the whole point of psa-o9xh.
        $resp->assertSee('QA Responsive Co', false);
        $resp->assertSee('(555) 010-1234', false);
        $resp->assertSee('ops@qa-responsive.example', false);

        // Phone/email are surfaced in a mobile-only stacked block (shown below md,
        // where the desktop columns are hidden), keeping them reachable on a phone.
        $resp->assertSee('d-md-none', false);

        // The open action stays reachable on every viewport.
        $resp->assertSee(route('clients.show', $client), false);
    }

    public function test_secondary_columns_are_hidden_below_md(): void
    {
        $user = User::factory()->create();
        Client::factory()->create([
            'name' => 'QA Responsive Co',
            'phone' => '+15550101234',
            'phone_display' => '(555) 010-1234',
            'email' => 'ops@qa-responsive.example',
        ]);

        $resp = $this->actingAs($user)->get(route('clients.index'))->assertOk();

        // The Phone/Email/People columns collapse below the md breakpoint so the
        // table no longer overflows a narrow (390px) viewport.
        $resp->assertSee('d-none d-md-table-cell', false);
    }
}
