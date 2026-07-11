<?php

namespace Tests\Feature\Signals;

use App\Models\McpToken;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\User;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertsHubDestinationDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_show_renders_config_health_and_recent_deliveries_without_leaking_secrets(): void
    {
        $dest = SignalDestination::create([
            'label' => 'Ops webhook', 'type' => 'webhook',
            'address' => 'https://93.184.216.34/hooks/super-secret-1234',
            'last_delivery_at' => now()->subMinutes(3), 'last_delivery_status' => 'delivered',
        ]);
        $event = SignalEvent::create(['type_key' => 'system.test', 'summary' => 'ping',
            'context' => [], 'occurred_at' => now()]);
        SignalDelivery::create(['event_id' => $event->id, 'destination_id' => $dest->id,
            'step_order' => 0, 'status' => 'delivered', 'delivered_at' => now()]);

        $this->actingAs($this->user)->get(route('settings.alerts.destinations.show', $dest))
            ->assertOk()
            ->assertSee('Ops webhook')
            ->assertSee('93.184.216.34')          // masked host shown
            ->assertSee('1234')                    // last-4 shown
            ->assertSee('delivered')               // health + recent delivery
            ->assertSee('All destinations')        // back-link
            ->assertSee('Rotating this token&#039;s label re-points or orphans this destination', false) // mcp_token_label rotate warning, from _form
            ->assertDontSee('https://93.184.216.34/hooks/super-secret-1234'); // full secret never rendered
    }

    public function test_show_never_reveals_any_wake_secret_chars_for_mcp_destination(): void
    {
        // Unlike address/wake_url (host + last-4 is a legit identifier), a
        // SECRET must render zero characters — the edit-form placeholder is
        // the opaque mask, never mask()'s ...last4 form (review finding, PR #163).
        $dest = SignalDestination::create([
            'label' => 'Chet doorbell', 'type' => 'mcp',
            'mcp_token_label' => 'office-signals',
            'wake_url' => 'https://198.51.100.7/wake',
            'wake_secret' => 'hmac-secret-value-zx9q',
        ]);

        $this->actingAs($this->user)->get(route('settings.alerts.destinations.show', $dest))
            ->assertOk()
            ->assertSee('Chet doorbell')
            ->assertDontSee('zx9q')                       // not even last-4
            ->assertDontSee('hmac-secret-value-zx9q');    // never the value
    }

    public function test_show_warns_when_linked_mcp_token_is_revoked(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'ghost');
        McpToken::where('label', 'ghost')->update(['revoked_at' => now()]);
        $dest = SignalDestination::create([
            'label' => 'Orphan inbox',
            'type' => 'mcp',
            'mcp_token_label' => 'ghost',
            'enabled' => true,
        ]);

        $this->actingAs($this->user)->get(route('settings.alerts.destinations.show', $dest))
            ->assertOk()
            ->assertSee('Token revoked')
            ->assertSee('revoked or no longer exists');
    }

    public function test_show_does_not_warn_when_linked_mcp_token_is_live(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        $dest = SignalDestination::create([
            'label' => 'Healthy inbox',
            'type' => 'mcp',
            'mcp_token_label' => 'chet',
            'enabled' => true,
        ]);

        $this->actingAs($this->user)->get(route('settings.alerts.destinations.show', $dest))
            ->assertOk()
            ->assertDontSee('Token revoked')
            ->assertDontSee('revoked or no longer exists');
    }
}
