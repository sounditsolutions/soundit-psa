<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\Cockpit\CockpitQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The offline-script queue's cockpit surface (bd psa-xr84). An approved staged
 * action whose target device was offline at approval time parks in QueuedOffline
 * until the device reconnects (auto-run) or the safety window elapses (Expired,
 * needs an explicit operator re-confirm). The CAS transitions themselves are
 * pinned by TechnicianRunOfflineQueueTest — this suite covers the two new
 * read-model lanes, the counts() fold-in, and the cockpit's operator controls
 * (cancel a queued item, re-confirm an expired one).
 */
class CockpitOfflineQueueLaneTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function ticket(): Ticket
    {
        $client = Client::factory()->create();

        return Ticket::factory()->for($client)->create();
    }

    private function queuedRun(Ticket $ticket, array $overrides = []): TechnicianRun
    {
        return TechnicianRun::create(array_merge([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'tactical_stage_script',
            'content_hash' => hash('sha256', 'queued-'.$ticket->id.'-'.uniqid('', true)),
            'state' => TechnicianRunState::QueuedOffline,
            'proposed_content' => 'Run disk cleanup script.',
            'proposed_meta' => ['asset_hostname' => 'PC-QUEUED-01'],
            'queued_agent_id' => 'agent-queued-1',
            'queued_at' => now()->subHour(),
            'expires_at' => now()->addDays(3),
            'coalesce_count' => 0,
        ], $overrides));
    }

    private function expiredRun(Ticket $ticket, array $overrides = []): TechnicianRun
    {
        return TechnicianRun::create(array_merge([
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'action_type' => 'tactical_stage_reboot',
            'content_hash' => hash('sha256', 'expired-'.$ticket->id.'-'.uniqid('', true)),
            'state' => TechnicianRunState::Expired,
            'proposed_content' => 'Reboot workstation.',
            'proposed_meta' => ['asset_hostname' => 'PC-EXPIRED-01'],
            'queued_agent_id' => 'agent-expired-1',
            'queued_at' => now()->subDays(8),
            'expires_at' => now()->subDay(),
            'coalesce_count' => 0,
        ], $overrides));
    }

    // ── CockpitQuery read model ─────────────────────────────────────────────

    public function test_queued_offline_lists_only_queued_runs_soonest_expiry_first(): void
    {
        $ticket = $this->ticket();
        $farOut = $this->queuedRun($ticket, ['expires_at' => now()->addDays(5), 'content_hash' => hash('sha256', 'a')]);
        $soon = $this->queuedRun($ticket, ['expires_at' => now()->addHours(2), 'content_hash' => hash('sha256', 'b')]);
        $this->expiredRun($ticket); // different state — must not appear
        TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'action_type' => 'send_reply', 'content_hash' => hash('sha256', 'c'),
            'state' => TechnicianRunState::AwaitingApproval, 'proposed_content' => 'draft',
        ]); // an ordinary draft — must not appear

        $lane = app(CockpitQuery::class)->queuedOffline();

        $this->assertCount(2, $lane);
        $this->assertSame($soon->id, $lane->first()->id, 'soonest-to-expire must sort first');
        $this->assertSame($farOut->id, $lane->last()->id);
        // Eager-loaded so the view never N+1s the ticket relationship.
        $this->assertTrue($lane->first()->relationLoaded('ticket'));
        $this->assertTrue($lane->first()->ticket->relationLoaded('client'));
    }

    public function test_expired_queue_lists_only_expired_runs_newest_first(): void
    {
        $ticket = $this->ticket();
        // expiredQueue() orders by updated_at (the CAS transition into Expired stamps
        // it), not created_at — so exercise that directly via a raw update, bypassing
        // Eloquent's auto-timestamping which would otherwise stamp both "now".
        $older = $this->expiredRun($ticket, ['content_hash' => hash('sha256', 'd')]);
        DB::table('technician_runs')->where('id', $older->id)->update(['updated_at' => now()->subDays(2)]);
        $newer = $this->expiredRun($ticket, ['content_hash' => hash('sha256', 'e')]);
        DB::table('technician_runs')->where('id', $newer->id)->update(['updated_at' => now()->subHour()]);
        $this->queuedRun($ticket); // different state — must not appear

        $lane = app(CockpitQuery::class)->expiredQueue();

        $this->assertCount(2, $lane);
        $this->assertSame($newer->id, $lane->first()->id, 'most recently expired must sort first');
        $this->assertSame($older->id, $lane->last()->id);
    }

    public function test_counts_includes_queued_and_expired_in_pending_and_total(): void
    {
        $ticket = $this->ticket();
        $this->queuedRun($ticket);
        $this->expiredRun($ticket);

        $counts = app(CockpitQuery::class)->counts();

        $this->assertSame(2, $counts['queued']);
        $this->assertSame(2, $counts['pending']);
        $this->assertSame(2, $counts['total']);
    }

    public function test_pending_count_nav_badge_includes_queued_and_expired(): void
    {
        // The always-visible nav badge must surface an Expired action (needs re-confirm)
        // so it can't go unnoticed by an operator who never opens /cockpit.
        $ticket = $this->ticket();
        $this->queuedRun($ticket);
        $this->expiredRun($ticket);

        $this->assertSame(2, app(CockpitQuery::class)->pendingCount());
    }

    // ── rendering ────────────────────────────────────────────────────────────

    public function test_cockpit_index_renders_queued_run_with_device_name_and_waiting_copy(): void
    {
        $ticket = $this->ticket();
        $this->queuedRun($ticket, ['proposed_meta' => ['asset_hostname' => 'RECEPTION-PC'], 'expires_at' => now()->addDays(2)]);

        $this->actingAs($this->user)
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('Queued — waiting for device')
            ->assertSee('RECEPTION-PC')
            ->assertSee('waiting for RECEPTION-PC to come online', false);
    }

    public function test_queued_run_falls_back_to_agent_id_when_no_hostname_is_recorded(): void
    {
        $ticket = $this->ticket();
        $this->queuedRun($ticket, ['proposed_meta' => [], 'queued_agent_id' => 'agent-xyz-789']);

        $this->actingAs($this->user)
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('agent-xyz-789');
    }

    public function test_queued_run_with_coalesce_count_shows_duplicate_approvals_note(): void
    {
        $ticket = $this->ticket();
        $this->queuedRun($ticket, ['coalesce_count' => 3]);

        $this->actingAs($this->user)
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('+3 duplicate approvals', false);
    }

    public function test_cockpit_index_renders_expired_run_with_reconfirm_control(): void
    {
        $ticket = $this->ticket();
        $this->expiredRun($ticket, ['proposed_meta' => ['asset_hostname' => 'WAREHOUSE-KIOSK']]);

        $this->actingAs($this->user)
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('Expired — needs re-confirm')
            ->assertSee('WAREHOUSE-KIOSK')
            ->assertSee('stayed offline past the safety window', false)
            ->assertSee('Re-confirm');
    }

    public function test_cockpit_index_does_not_render_queue_sections_when_empty(): void
    {
        $this->actingAs($this->user)
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertDontSee('Queued — waiting for device')
            ->assertDontSee('Expired — needs re-confirm');
    }

    // ── cancel ───────────────────────────────────────────────────────────────

    public function test_cancel_transitions_a_queued_offline_run_to_cancelled(): void
    {
        $run = $this->queuedRun($this->ticket());

        $this->actingAs($this->user)
            ->post(route('cockpit.cancel', $run))
            ->assertRedirect(route('cockpit.index'))
            ->assertSessionHas('success', 'Queued action cancelled.');

        $this->assertSame(TechnicianRunState::Cancelled, $run->fresh()->state);
    }

    public function test_cancel_is_a_safe_no_op_on_a_non_queued_run(): void
    {
        $run = $this->expiredRun($this->ticket());

        $this->actingAs($this->user)
            ->post(route('cockpit.cancel', $run))
            ->assertRedirect(route('cockpit.index'))
            ->assertSessionHas('error');

        $this->assertSame(TechnicianRunState::Expired, $run->fresh()->state, 'a non-queued run must not change state');
    }

    // ── reconfirm ────────────────────────────────────────────────────────────

    public function test_reconfirm_transitions_an_expired_run_to_awaiting_approval(): void
    {
        $run = $this->expiredRun($this->ticket());

        $this->actingAs($this->user)
            ->post(route('cockpit.reconfirm', $run))
            ->assertRedirect(route('cockpit.index'))
            ->assertSessionHas('success', 'Back in the approval queue.');

        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    public function test_reconfirm_is_a_safe_no_op_on_a_non_expired_run(): void
    {
        $run = $this->queuedRun($this->ticket());

        $this->actingAs($this->user)
            ->post(route('cockpit.reconfirm', $run))
            ->assertRedirect(route('cockpit.index'))
            ->assertSessionHas('error');

        $this->assertSame(TechnicianRunState::QueuedOffline, $run->fresh()->state, 'a non-expired run must not change state');
    }
}
