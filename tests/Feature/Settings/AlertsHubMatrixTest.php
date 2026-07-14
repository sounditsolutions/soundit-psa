<?php

namespace Tests\Feature\Settings;

use App\Models\SignalEventTypeSetting;
use App\Models\User;
use App\Services\Signals\SignalRelayMatrix;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertsHubMatrixTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        McpConfig::rotateStaffToken(['poll_signals'], 'Chet');
        McpConfig::rotateStaffToken(['find_clients'], 'NoPoll');
    }

    private function cell(string $token, string $type): array
    {
        return app(SignalRelayMatrix::class)->matrix()['cells'][$token][$type];
    }

    public function test_matrix_page_renders_types_and_token_columns(): void
    {
        $this->actingAs($this->user)->get(route('settings.alerts.matrix'))
            ->assertOk()
            ->assertSee('Ticket created')
            ->assertSee('ticket.created')
            ->assertSee('Chet')
            ->assertSee('NoPoll')
            ->assertSee('no poll_signals'); // the guard warning for the NoPoll column
    }

    public function test_post_relay_on_persists(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.alerts.matrix.relay'), [
                'token_label' => 'Chet', 'type_key' => 'ticket.created', 'relay' => 1,
            ])
            ->assertRedirect();

        $this->assertTrue($this->cell('Chet', 'ticket.created')['relayed']);
    }

    public function test_post_nudge_on_a_relayed_cell_persists(): void
    {
        app(SignalRelayMatrix::class)->setRelay('Chet', 'ticket.created', true);

        $this->actingAs($this->user)
            ->post(route('settings.alerts.matrix.nudge'), [
                'token_label' => 'Chet', 'type_key' => 'ticket.created', 'nudge' => 1,
            ])
            ->assertRedirect();

        $this->assertTrue($this->cell('Chet', 'ticket.created')['nudge']);
    }

    public function test_post_nudge_on_a_non_relayed_cell_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.alerts.matrix.nudge'), [
                'token_label' => 'Chet', 'type_key' => 'ticket.created', 'nudge' => 1,
            ])
            ->assertSessionHas('error');

        $this->assertFalse($this->cell('Chet', 'ticket.created')['nudge']);
    }

    public function test_post_type_global_toggle_off_persists(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.alerts.matrix.type-toggle'), [
                'type_key' => 'ticket.created', 'enabled' => 0,
            ])
            ->assertRedirect();

        $this->assertFalse(SignalEventTypeSetting::isTypeGloballyEnabled('ticket.created'));
    }

    public function test_relay_on_a_non_routable_type_is_rejected_gracefully(): void
    {
        $this->actingAs($this->user)
            ->post(route('settings.alerts.matrix.relay'), [
                'token_label' => 'Chet', 'type_key' => 'system.test', 'relay' => 1,
            ])
            ->assertSessionHas('error');

        $this->assertFalse($this->cell('Chet', 'system.test')['relayed']);
    }
}
