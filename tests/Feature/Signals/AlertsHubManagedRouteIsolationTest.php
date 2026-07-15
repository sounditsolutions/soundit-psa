<?php

namespace Tests\Feature\Signals;

use App\Models\SignalRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-lunj — the Routes page must honour the managed_token_label discriminator.
 *
 * signal_routes.managed_token_label (nullable UNIQUE, migration 2026_07_14_100001) is
 * "the discriminator that separates operator-authored" routes from matrix-owned ones.
 * SignalRelayMatrix honours it scrupulously (whereNotNull) and is TESTED for it —
 * SignalRelayMatrixTest:257 asserts the matrix never touches operator routes.
 *
 * NOTHING ASSERTED THE REVERSE, and the code matched the tests: AlertsHubController
 * was a bare SignalRoute::query()->get() with no filter and no guards. So the operator
 * Routes page listed matrix-owned routes indistinguishably and could mutate them:
 *
 *   relay a type in the Matrix -> managed route "Relay to {token}" (enabled=true)
 *   -> Routes page lists it, looking like duplicate clutter
 *   -> operator toggles it off -> SignalRouter (:43,:126) filters enabled=true
 *   -> RELAY SILENTLY STOPS, while the Matrix STILL shows the cell as relayed
 *      (its cells read event_filter.types, never enabled)
 *   -> the next Matrix edit silently RE-ENABLES it (setRelay: enabled = types !== [])
 *
 * Both surfaces lie, on the lane feeding Chet's wake-on-alert. These tests are the
 * mirror of SignalRelayMatrixTest:257 — the direction nobody was guarding.
 *
 * KEEP-BOTH (manager, 2026-07-15): managed routes are shown READ-ONLY with a link to
 * the matrix rather than hidden — a route that delivers and appears nowhere is its own
 * trap. So this is a partition of the display, not a disappearance.
 */
class AlertsHubManagedRouteIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function operatorRoute(string $label = 'Operator route'): SignalRoute
    {
        return SignalRoute::create([
            'label' => $label,
            'managed_token_label' => null,
            'event_filter' => ['types' => ['ticket.created']],
            'enabled' => true,
        ]);
    }

    private function managedRoute(string $token = 'chet'): SignalRoute
    {
        return SignalRoute::create([
            'label' => "Relay to {$token}",
            'managed_token_label' => $token,
            'event_filter' => ['types' => ['ticket.created'], 'nudge_types' => []],
            'enabled' => true,
        ]);
    }

    // --- the display partition ---

    public function test_the_routes_list_separates_operator_routes_from_matrix_managed_routes(): void
    {
        $this->operatorRoute('My operator route');
        $this->managedRoute('chet');

        $response = $this->actingAs($this->user)->get(route('settings.alerts.index'))->assertOk();

        // The operator's own routes remain in the editable list.
        $response->assertViewHas('routes', fn ($routes) => $routes->pluck('label')->contains('My operator route')
            && ! $routes->pluck('label')->contains('Relay to chet'));

        // Matrix-owned routes are surfaced separately — shown, not hidden.
        $response->assertViewHas('managedRoutes', fn ($managed) => $managed->pluck('label')->contains('Relay to chet')
            && ! $managed->pluck('label')->contains('My operator route'));
    }

    public function test_a_managed_route_is_shown_read_only_and_points_at_the_matrix(): void
    {
        $this->managedRoute('chet');

        $this->actingAs($this->user)
            ->get(route('settings.alerts.index'))
            ->assertOk()
            ->assertSee('Relay to chet')                       // shown, not hidden
            ->assertSee('Managed by the relay matrix')          // named as such
            ->assertSee(route('settings.alerts.matrix'), false); // and reachable
    }

    // --- the mutation guards: the operator UI must never silently break a relay ---

    public function test_toggling_a_managed_route_from_the_routes_page_is_refused(): void
    {
        $route = $this->managedRoute('chet');

        $this->actingAs($this->user)
            ->from(route('settings.alerts.index'))
            ->post(route('settings.alerts.routes.toggle', $route))
            ->assertRedirect(route('settings.alerts.index'))
            ->assertSessionHas('error');

        $this->assertTrue(
            $route->fresh()->enabled,
            'the operator Routes page must NEVER silently disable a matrix-owned relay — the Matrix would go on claiming it relays',
        );
    }

    public function test_updating_a_managed_route_from_the_routes_page_is_refused(): void
    {
        $route = $this->managedRoute('chet');

        $this->actingAs($this->user)
            ->from(route('settings.alerts.index'))
            ->put(route('settings.alerts.routes.update', $route), [
                'label' => 'Hijacked',
                'event_filter_types' => ['ticket.closed'],
                'enabled' => '0',
            ])
            ->assertRedirect(route('settings.alerts.index'))
            ->assertSessionHas('error');

        $fresh = $route->fresh();
        $this->assertSame('Relay to chet', $fresh->label, 'a managed route must not be re-labelled from the operator UI');
        $this->assertTrue($fresh->enabled, 'a managed route must not be disabled from the operator UI');
        $this->assertSame(['ticket.created'], $fresh->event_filter['types'] ?? null, 'replaceRouteSteps/forceFill must not rewire a managed route');
    }

    public function test_opening_the_edit_form_for_a_managed_route_is_refused(): void
    {
        $route = $this->managedRoute('chet');

        // The edit form is the door to the mutation; it must not open at all.
        $this->actingAs($this->user)
            ->get(route('settings.alerts.routes.show', $route))
            ->assertRedirect(route('settings.alerts.matrix'));
    }

    // --- the guards must NOT over-block: operator routes still work exactly as before ---

    public function test_operator_routes_remain_fully_editable(): void
    {
        $route = $this->operatorRoute();

        $this->actingAs($this->user)
            ->get(route('settings.alerts.routes.show', $route))
            ->assertOk();

        $this->actingAs($this->user)
            ->post(route('settings.alerts.routes.toggle', $route))
            ->assertRedirect();

        $this->assertFalse($route->fresh()->enabled, 'an operator route toggles exactly as it always did');
    }
}
