<?php

namespace Tests\Feature\Signals;

use App\Jobs\DeliverSignal;
use App\Jobs\RouteSignalEvent;
use App\Models\McpToken;
use App\Models\SignalConfigLog;
use App\Models\SignalDelivery;
use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Models\User;
use App\Services\Signals\Sinks\WebhookSink;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

class AlertsHubDestinationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('settings.alerts.index'))
            ->assertRedirect(route('login'));
    }

    public function test_index_lists_destinations_without_echoing_secret_addresses(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://hooks.example.com/signal/super-secret-1234',
        ]);
        SignalDestination::create([
            'label' => 'Chet inbox',
            'type' => 'mcp',
            'mcp_token_label' => 'chet',
            'wake_url' => 'https://doorbell.example.com/wake/private-9999',
            'wake_secret' => 'wake-secret-1234',
        ]);

        $this->actingAs($this->user)
            ->get(route('settings.alerts.index'))
            ->assertOk()
            ->assertSee('Alerts Hub')
            ->assertSee('Destinations')
            ->assertSee('Ops webhook')
            ->assertSee('hooks.example.com')
            ->assertSee('1234')
            ->assertSee('Chet inbox')
            ->assertDontSee('https://hooks.example.com/signal/super-secret-1234')
            ->assertDontSee('https://doorbell.example.com/wake/private-9999')
            ->assertDontSee('wake-secret-1234');
    }

    public function test_landing_shows_an_escaped_failure_indicator_only_for_broken_destinations(): void
    {
        SignalDestination::create([
            'label' => 'Broken hook',
            'type' => 'webhook',
            'address' => 'https://93.184.216.34/hooks/abcd1234',
            'last_delivery_at' => now()->subMinutes(2),
            'last_delivery_status' => 'failed',
            'last_error' => '<script>alert(1)</script> HTTP 500 Server Error',
        ]);
        SignalDestination::create([
            'label' => 'Healthy hook',
            'type' => 'webhook',
            'address' => 'https://93.184.216.34/hooks/wxyz9999',
            'last_delivery_at' => now()->subMinutes(1),
            'last_delivery_status' => 'delivered',
            'last_error' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('settings.alerts.index'))
            ->assertOk();

        // The broken destination surfaces its error text...
        $response->assertSee('HTTP 500 Server Error');
        // ...HTML-escaped (last_error can carry remote-server response text) — never raw.
        $response->assertSee('&lt;script&gt;', false);
        $response->assertDontSee('<script>alert(1)</script>', false);
        // The failure indicator renders once per layout: the desktop table and the
        // mobile stacked rows both live in the DOM (toggled by CSS), so the one broken
        // destination surfaces it exactly twice — and the healthy one never does (a
        // wrongly-flagged healthy destination would push this to four). psa-0h6e.
        $this->assertSame(2, substr_count($response->getContent(), 'alerts-destination-failure'));
    }

    public function test_index_flags_mcp_destination_whose_token_is_revoked(): void
    {
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'ghost');
        McpToken::where('label', 'ghost')->update(['revoked_at' => now()]);
        SignalDestination::create([
            'label' => 'Orphan inbox',
            'type' => 'mcp',
            'mcp_token_label' => 'ghost',
            'enabled' => true,
        ]);

        // A destination backed by a live token must not be flagged.
        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');
        SignalDestination::create([
            'label' => 'Healthy inbox',
            'type' => 'mcp',
            'mcp_token_label' => 'chet',
            'enabled' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('settings.alerts.index'))
            ->assertOk()
            ->assertSee('Token revoked');

        // Exactly one broken-token indicator — only the orphaned destination.
        $this->assertSame(1, substr_count($response->getContent(), 'alerts-destination-token-broken'));
    }

    public function test_create_page_renders_and_store_redirects_to_detail(): void
    {
        $this->actingAs($this->user)->get(route('settings.alerts.destinations.create'))
            ->assertOk()->assertSee('New destination')->assertSee('All destinations');

        $this->actingAs($this->user)->post(route('settings.alerts.destinations.store'), [
            'label' => 'Ops webhook', 'type' => 'webhook', 'address' => 'https://93.184.216.34/hooks/abcd1234',
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.alerts.destinations.show', SignalDestination::firstOrFail()));
    }

    public function test_stores_webhook_destination_with_safe_url_and_config_log(): void
    {
        $url = 'https://93.184.216.34/hooks/abcd1234';

        $this->actingAs($this->user)
            ->post(route('settings.alerts.destinations.store'), [
                'label' => 'Ops webhook',
                'type' => 'webhook',
                'address' => $url,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.alerts.destinations.show', SignalDestination::firstOrFail()));

        $destination = SignalDestination::firstOrFail();
        $this->assertSame('Ops webhook', $destination->label);
        $this->assertSame('webhook', $destination->type);
        $this->assertSame($url, $destination->address);
        $this->assertNotSame($url, DB::table('signal_destinations')->value('address'));

        $log = SignalConfigLog::firstOrFail();
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame('created', $log->action);
        $this->assertSame(SignalDestination::class, $log->subject_type);
        $this->assertSame($destination->id, $log->subject_id);
        $this->assertStringNotContainsString($url, json_encode($log->changes));
    }

    public function test_validation_rejects_unsafe_webhook_and_email_header_injection(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.alerts.destinations.store'), [
                'label' => 'Bad webhook',
                'type' => 'webhook',
                'address' => 'https://127.0.0.1/hook',
            ])
            ->assertSessionHasErrors('address');

        $this->actingAs($this->user)
            ->post(route('settings.alerts.destinations.store'), [
                'label' => 'Bad email',
                'type' => 'email',
                'address' => "ops@example.com\r\nBcc: attacker@example.com",
            ])
            ->assertSessionHasErrors('address');

        $this->assertSame(0, SignalDestination::count());
    }

    public function test_mcp_destination_requires_existing_token_and_wake_secret_for_wake_url(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.alerts.destinations.store'), [
                'label' => 'Bad MCP',
                'type' => 'mcp',
                'mcp_token_label' => 'missing',
            ])
            ->assertSessionHasErrors('mcp_token_label');

        McpConfig::rotateStaffToken(allowedTools: ['find_staff'], label: 'chet');

        $this->actingAs($this->user)
            ->post(route('settings.alerts.destinations.store'), [
                'label' => 'Chet inbox',
                'type' => 'mcp',
                'mcp_token_label' => 'chet',
                'wake_url' => 'https://93.184.216.34/wake/abcd',
            ])
            ->assertSessionHasErrors('wake_secret');

        $this->actingAs($this->user)
            ->post(route('settings.alerts.destinations.store'), [
                'label' => 'Chet inbox',
                'type' => 'mcp',
                'mcp_token_label' => 'chet',
                'wake_url' => 'https://93.184.216.34/wake/abcd',
                'wake_secret' => 'wake-secret-1234',
            ])
            ->assertSessionHasNoErrors();

        $destination = SignalDestination::firstOrFail();
        $this->assertSame('mcp', $destination->type);
        $this->assertSame('chet', $destination->mcp_token_label);
        $this->assertSame('https://93.184.216.34/wake/abcd', $destination->wake_url);
        $this->assertSame('wake-secret-1234', $destination->wake_secret);

        $this->assertStringNotContainsString('wake-secret-1234', json_encode(SignalConfigLog::latest()->firstOrFail()->changes));
    }

    public function test_blank_update_keeps_existing_masked_address_and_logs_changes(): void
    {
        $destination = SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://93.184.216.34/hooks/original-9999',
        ]);

        $this->actingAs($this->user)
            ->put(route('settings.alerts.destinations.update', $destination), [
                'label' => 'Ops renamed',
                'type' => 'webhook',
                'address' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.alerts.destinations.show', $destination));

        $destination->refresh();
        $this->assertSame('Ops renamed', $destination->label);
        $this->assertSame('https://93.184.216.34/hooks/original-9999', $destination->address);

        $this->assertDatabaseHas('signal_config_log', [
            'user_id' => $this->user->id,
            'action' => 'updated',
            'subject_type' => SignalDestination::class,
            'subject_id' => $destination->id,
        ]);
    }

    public function test_toggle_records_enabled_and_disabled_actions(): void
    {
        $destination = SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://93.184.216.34/hooks/abcd',
            'enabled' => true,
        ]);

        $this->actingAs($this->user)
            ->from(route('settings.alerts.index'))
            ->post(route('settings.alerts.destinations.toggle', $destination))
            ->assertRedirect(route('settings.alerts.index'));

        $this->assertFalse($destination->fresh()->enabled);
        $this->assertDatabaseHas('signal_config_log', [
            'action' => 'disabled',
            'subject_id' => $destination->id,
        ]);

        $this->actingAs($this->user)
            ->from(route('settings.alerts.index'))
            ->post(route('settings.alerts.destinations.toggle', $destination))
            ->assertRedirect(route('settings.alerts.index'));

        $this->assertTrue($destination->fresh()->enabled);
        $this->assertDatabaseHas('signal_config_log', [
            'action' => 'enabled',
            'subject_id' => $destination->id,
        ]);
    }

    public function test_test_send_route_is_throttled_to_six_per_minute(): void
    {
        $destination = SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://93.184.216.34/hooks/abcd',
        ]);
        $this->mock(WebhookSink::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deliver')
                ->times(6)
                ->andReturnUsing(function (SignalDestination $destination, SignalEvent $event, SignalDelivery $delivery): void {
                    $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
                });
        });

        $middleware = Route::getRoutes()->getByName('settings.alerts.destinations.test')->gatherMiddleware();
        $this->assertContains('throttle:6,1', $middleware);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($this->user)
                ->post(route('settings.alerts.destinations.test', $destination))
                ->assertRedirect(route('settings.alerts.destinations.show', $destination));
        }

        $this->actingAs($this->user)
            ->post(route('settings.alerts.destinations.test', $destination))
            ->assertStatus(429);
    }

    public function test_test_send_directly_delivers_system_test_without_routing(): void
    {
        Bus::fake();
        $destination = SignalDestination::create([
            'label' => 'Ops webhook',
            'type' => 'webhook',
            'address' => 'https://93.184.216.34/hooks/abcd',
        ]);
        $configuredRoute = SignalRoute::create([
            'label' => 'System tests should not route',
            'event_filter' => ['types' => ['system.test']],
            'enabled' => true,
        ]);
        SignalRouteStep::create([
            'route_id' => $configuredRoute->id,
            'step_order' => 1,
            'destination_id' => $destination->id,
        ]);

        $this->mock(WebhookSink::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deliver')
                ->once()
                ->withArgs(function (SignalDestination $destination, SignalEvent $event, SignalDelivery $delivery): bool {
                    $this->assertSame('system.test', $event->type_key);
                    $this->assertSame('pending', $delivery->status);
                    $this->assertNull($delivery->route_id);

                    $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();

                    return true;
                });
        });

        $this->actingAs($this->user)
            ->post(route('settings.alerts.destinations.test', $destination))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.alerts.destinations.show', $destination));

        $event = SignalEvent::where('type_key', 'system.test')->firstOrFail();
        $delivery = SignalDelivery::where('event_id', $event->id)->firstOrFail();
        $this->assertSame('delivered', $delivery->status);
        $this->assertNull($delivery->route_id);
        $this->assertFalse(SignalDelivery::where('route_id', $configuredRoute->id)->exists());
        Bus::assertNotDispatched(RouteSignalEvent::class);
        Bus::assertNotDispatched(DeliverSignal::class);
    }
}
