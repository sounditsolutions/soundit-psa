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

    // --- THE POISONED STATE: rows the old leak already broke ---

    /**
     * psa-lunj round 2 (review catch). Guarding the doors stops NEW corruption; it does
     * nothing for a row the leak already broke — and this fix would otherwise ENTRENCH it,
     * because it also removes the only door the operator had to toggle it back on.
     *
     * For a managed route `enabled` is DERIVED, not configured: setRelay does
     * `$route->enabled = $types !== []` (:136), relayRouteFor creates enabled=false with
     * types=[] (:209), and setNudge never touches it. The matrix exposes no enable/disable
     * control at all. So (types non-empty AND enabled=false) is a state the OWNER CANNOT
     * PRODUCE OR EXPRESS — it is corruption, and the old Routes-page toggle was its only
     * possible source.
     *
     * The matrix must never render such a row as relayed: SignalRouter skips it
     * (SignalRouter.php:42-43 filters enabled=true), so claiming "relayed" is the exact lie
     * this bead exists to kill.
     */
    public function test_a_disabled_managed_route_is_never_rendered_as_relaying(): void
    {
        \App\Support\McpConfig::rotateStaffToken(['poll_signals'], 'Chet');

        SignalRoute::create([
            'label' => 'Relay to Chet',
            'managed_token_label' => 'Chet',
            // The poisoned shape: configured types, but disabled behind the matrix's back.
            'event_filter' => ['types' => ['ticket.created'], 'nudge_types' => ['ticket.created']],
            'enabled' => false,
        ]);

        $matrix = app(\App\Services\Signals\SignalRelayMatrix::class)->matrix();

        $this->assertFalse(
            $matrix['cells']['Chet']['ticket.created']['relayed'] ?? true,
            'a DISABLED managed route delivers nothing (SignalRouter filters enabled=true) — the matrix must not claim it relays',
        );
        $this->assertFalse(
            $matrix['cells']['Chet']['ticket.created']['nudge'] ?? true,
            'nudge_types ⊆ types: a type that cannot relay cannot nudge either',
        );
    }

    /**
     * The 2026_07_15_100001 heal, exercised directly. Guarding the doors is not enough:
     * rows the leak already broke must be repaired, or this fix entrenches the outage it
     * was written to end (it removes the only control that could have switched them back on).
     */
    public function test_the_heal_migration_re_enables_corrupted_managed_routes_only(): void
    {
        $poisoned = SignalRoute::create([
            'label' => 'Relay to Chet', 'managed_token_label' => 'Chet',
            'event_filter' => ['types' => ['ticket.created'], 'nudge_types' => []], 'enabled' => false,
        ]);
        // Consistent resting state — no types, disabled. Must be left alone.
        $resting = SignalRoute::create([
            'label' => 'Relay to Quiet', 'managed_token_label' => 'Quiet',
            'event_filter' => ['types' => [], 'nudge_types' => []], 'enabled' => false,
        ]);
        // An operator route disabled on purpose — a legitimate, expressible choice.
        $operatorOff = SignalRoute::create([
            'label' => 'Operator route, off on purpose', 'managed_token_label' => null,
            'event_filter' => ['types' => ['ticket.created']], 'enabled' => false,
        ]);

        require_once database_path('migrations/2026_07_15_100001_heal_disabled_managed_relay_routes.php');
        (require database_path('migrations/2026_07_15_100001_heal_disabled_managed_relay_routes.php'))->up();

        $this->assertTrue($poisoned->fresh()->enabled, 'a managed route with types but disabled is corruption — heal it');
        $this->assertFalse($resting->fresh()->enabled, 'no types + disabled is the CONSISTENT resting state — do not touch it');
        $this->assertFalse($operatorOff->fresh()->enabled, 'an operator route disabled on purpose is a real choice — never override it');
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
