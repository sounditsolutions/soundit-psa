<?php

namespace Tests\Feature\Signals;

use App\Models\McpToken;
use App\Models\SignalConfigLog;
use App\Models\SignalDestination;
use App\Models\SignalEventTypeSetting;
use App\Models\SignalRoute;
use App\Services\Signals\SignalRelayMatrix;
use App\Support\McpConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalRelayMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The matrix write path requires a LIVE token label; 'Chet' is the column used
        // across these tests, 'NoPoll' exercises the poll_signals guard.
        McpConfig::rotateStaffToken(['poll_signals'], 'Chet');
        McpConfig::rotateStaffToken(['find_clients'], 'NoPoll');
    }

    private function matrix(): SignalRelayMatrix
    {
        return app(SignalRelayMatrix::class);
    }

    public function test_set_relay_creates_a_managed_route_destination_and_step(): void
    {
        $this->matrix()->setRelay('Chet', 'ticket.created', true);

        $route = SignalRoute::where('managed_token_label', 'Chet')->firstOrFail();
        $this->assertTrue($route->enabled);
        $this->assertSame(['ticket.created'], $route->event_filter['types']);

        $step = $route->steps()->firstOrFail();
        $destination = SignalDestination::findOrFail($step->destination_id);
        $this->assertSame('mcp', $destination->type);
        $this->assertSame('Chet', $destination->mcp_token_label);
    }

    public function test_set_relay_is_idempotent(): void
    {
        $this->matrix()->setRelay('Chet', 'ticket.created', true);
        $this->matrix()->setRelay('Chet', 'ticket.created', true);

        $this->assertSame(1, SignalRoute::where('managed_token_label', 'Chet')->count());
        $route = SignalRoute::where('managed_token_label', 'Chet')->firstOrFail();
        $this->assertSame(['ticket.created'], array_values($route->event_filter['types']));
        $this->assertSame(1, $route->steps()->count());
    }

    public function test_set_relay_off_removes_the_type_and_disables_the_route_when_empty(): void
    {
        $this->matrix()->setRelay('Chet', 'ticket.created', true);
        $this->matrix()->setRelay('Chet', 'intake.email_received', true);
        $this->matrix()->setRelay('Chet', 'ticket.created', false);

        $route = SignalRoute::where('managed_token_label', 'Chet')->firstOrFail();
        $this->assertSame(['intake.email_received'], array_values($route->event_filter['types']));
        $this->assertTrue($route->enabled);

        $this->matrix()->setRelay('Chet', 'intake.email_received', false);
        $route->refresh();
        $this->assertSame([], array_values($route->event_filter['types'] ?? []));
        $this->assertFalse($route->enabled);
    }

    public function test_set_relay_off_also_removes_the_type_from_nudge_types(): void
    {
        $this->matrix()->setRelay('Chet', 'ticket.created', true);
        $this->matrix()->setNudge('Chet', 'ticket.created', true);
        $this->matrix()->setRelay('Chet', 'ticket.created', false);

        $route = SignalRoute::where('managed_token_label', 'Chet')->firstOrFail();
        $this->assertSame([], array_values($route->event_filter['nudge_types'] ?? []));
    }

    public function test_set_relay_refuses_a_globally_disabled_type(): void
    {
        SignalEventTypeSetting::setGlobalEnabled('ticket.created', false);

        $this->expectException(\InvalidArgumentException::class);
        $this->matrix()->setRelay('Chet', 'ticket.created', true);
    }

    public function test_set_relay_rejects_an_unknown_or_non_routable_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // system.test exists in the catalog but is not routable.
        $this->matrix()->setRelay('Chet', 'system.test', true);
    }

    public function test_set_relay_rejects_a_type_not_in_the_catalog(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->matrix()->setRelay('Chet', 'bogus.event', true);
    }

    public function test_set_nudge_requires_the_type_to_be_relayed_first(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->matrix()->setNudge('Chet', 'ticket.created', true);
    }

    public function test_set_nudge_toggles_the_nudge_subset(): void
    {
        $this->matrix()->setRelay('Chet', 'ticket.created', true);
        $this->matrix()->setNudge('Chet', 'ticket.created', true);

        $route = SignalRoute::where('managed_token_label', 'Chet')->firstOrFail();
        $this->assertSame(['ticket.created'], array_values($route->event_filter['nudge_types']));

        $this->matrix()->setNudge('Chet', 'ticket.created', false);
        $route->refresh();
        $this->assertSame([], array_values($route->event_filter['nudge_types'] ?? []));
    }

    public function test_matrix_read_surfaces_all_18_types_tokens_cells_and_poll_signals_guard(): void
    {
        // 'Chet' (poll_signals) and 'NoPoll' (no poll_signals) are minted in setUp.
        $this->matrix()->setRelay('Chet', 'ticket.created', true);
        $this->matrix()->setNudge('Chet', 'ticket.created', true);

        $grid = $this->matrix()->matrix();

        $this->assertCount(18, $grid['types']);
        $typeKeys = array_column($grid['types'], 'key');
        $this->assertContains('ticket.created', $typeKeys);
        $this->assertContains('system.test', $typeKeys); // surfaced even though not routable

        $tokensByLabel = collect($grid['tokens'])->keyBy('label');
        $this->assertTrue($tokensByLabel['Chet']['has_poll_signals']);
        $this->assertFalse($tokensByLabel['NoPoll']['has_poll_signals']);

        $this->assertTrue($grid['cells']['Chet']['ticket.created']['relayed']);
        $this->assertTrue($grid['cells']['Chet']['ticket.created']['nudge']);
        $this->assertFalse($grid['cells']['Chet']['intake.email_received']['relayed']);
        $this->assertFalse($grid['cells']['NoPoll']['ticket.created']['relayed']);
    }

    public function test_operator_authored_routes_are_never_touched_by_the_matrix(): void
    {
        // A manual route (managed_token_label = null), exactly like the legacy seed.
        $destination = SignalDestination::create([
            'label' => 'Manual webhook',
            'type' => 'webhook',
            'address' => 'https://example.test/hook',
            'enabled' => true,
        ]);
        $route = SignalRoute::create([
            'label' => 'Manual route',
            'managed_token_label' => null,
            'event_filter' => ['types' => ['ticket.created']],
            'enabled' => true,
            'cooldown_seconds' => 300,
        ]);
        $route->steps()->create(['step_order' => 1, 'destination_id' => $destination->id]);

        $before = $route->fresh()->toArray();

        // Matrix ops for a token must not touch the manual route.
        $this->matrix()->setRelay('Chet', 'ticket.created', true);
        $this->matrix()->setRelay('Chet', 'ticket.created', false);

        $after = $route->fresh()->toArray();
        $this->assertSame($before['event_filter'], $after['event_filter']);
        $this->assertSame($before['enabled'], $after['enabled']);
        $this->assertSame($before['cooldown_seconds'], $after['cooldown_seconds']);
    }

    public function test_matrix_edits_are_audited_to_the_signal_config_log(): void
    {
        $user = \App\Models\User::factory()->create();

        $this->matrix()->setRelay('Chet', 'ticket.created', true, $user->id);
        $this->matrix()->setNudge('Chet', 'ticket.created', true, $user->id);
        $this->matrix()->setTypeGlobalEnabled('ticket.created', false, $user->id);

        $this->assertGreaterThanOrEqual(3, SignalConfigLog::count());
        $this->assertTrue(SignalConfigLog::where('user_id', $user->id)->exists());
        // Relay, nudge, and global-toggle each log their own action.
        $this->assertTrue(SignalConfigLog::where('action', 'relay_added')->exists());
        $this->assertTrue(SignalConfigLog::where('action', 'nudge_added')->exists());
        $this->assertTrue(SignalConfigLog::where('action', 'type_global_toggle')->exists());
    }

    public function test_set_relay_rejects_a_nonexistent_token_label(): void
    {
        try {
            $this->matrix()->setRelay('Ghost', 'ticket.created', true);
            $this->fail('Expected InvalidArgumentException for a nonexistent token label.');
        } catch (\InvalidArgumentException) {
            // No matrix config may be pre-armed for a label with no live token.
            $this->assertSame(0, SignalRoute::where('managed_token_label', 'Ghost')->count());
            $this->assertSame(0, SignalDestination::where('mcp_token_label', 'Ghost')->count());
        }
    }

    public function test_set_relay_rejects_a_revoked_token_label(): void
    {
        McpConfig::rotateStaffToken(['poll_signals'], 'Gone');
        McpToken::where('label', 'Gone')->update(['revoked_at' => now()]);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->matrix()->setRelay('Gone', 'ticket.created', true);
        } finally {
            $this->assertSame(0, SignalRoute::where('managed_token_label', 'Gone')->count());
        }
    }

    public function test_set_relay_rejects_a_malformed_or_oversized_label(): void
    {
        $this->assertThrows(fn () => $this->matrix()->setRelay('', 'ticket.created', true), \InvalidArgumentException::class);
        $this->assertThrows(fn () => $this->matrix()->setRelay(str_repeat('x', 300), 'ticket.created', true), \InvalidArgumentException::class);
    }

    public function test_set_relay_off_for_a_token_with_no_route_is_a_noop(): void
    {
        $this->matrix()->setRelay('Chet', 'ticket.created', false);

        $this->assertSame(0, SignalRoute::where('managed_token_label', 'Chet')->count());
        $this->assertSame(0, SignalDestination::where('mcp_token_label', 'Chet')->count());
    }

    public function test_the_db_enforces_one_managed_route_per_token(): void
    {
        $this->matrix()->setRelay('Chet', 'ticket.created', true);
        $this->assertSame(1, SignalRoute::where('managed_token_label', 'Chet')->count());

        // A second managed route for the same token is rejected at the DB level — the
        // invariant does not rely on service code alone.
        $this->expectException(UniqueConstraintViolationException::class);
        SignalRoute::create([
            'label' => 'dup',
            'managed_token_label' => 'Chet',
            'event_filter' => ['types' => []],
            'enabled' => false,
            'cooldown_seconds' => 0,
        ]);
    }

    public function test_many_operator_authored_routes_may_share_a_null_managed_label(): void
    {
        // The unique key is nullable — operator-authored routes (managed_token_label IS NULL)
        // are unaffected and can coexist in any number.
        SignalRoute::create(['label' => 'a', 'event_filter' => ['types' => []], 'enabled' => false, 'cooldown_seconds' => 300]);
        SignalRoute::create(['label' => 'b', 'event_filter' => ['types' => []], 'enabled' => false, 'cooldown_seconds' => 300]);

        $this->assertSame(2, SignalRoute::whereNull('managed_token_label')->count());
    }
}
