<?php

namespace Tests\Feature\Tactical;

use App\Enums\TechnicianRunState;
use App\Jobs\ProcessTacticalWebhook;
use App\Jobs\SweepQueuedActionsForAgent;
use App\Models\Alert;
use App\Models\TacticalWebhook;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Services\Tactical\TacticalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Webhook fast-path for the offline-script queue (bd psa-xr84): a resolved Tactical
 * alert for an agent with queued actions triggers a targeted sweep. Best-effort —
 * Tactical drops availability alerts for workstations (G4), so the device-sync hook
 * remains the authoritative trigger; this only accelerates the server case.
 */
class TacticalWebhookOfflineQueueTest extends TestCase
{
    use RefreshDatabase;

    private function webhook(string $event, ?string $agentId): TacticalWebhook
    {
        return TacticalWebhook::create([
            'event' => $event,
            'agent_id' => $agentId,
            'payload' => ['agent_id' => $agentId, 'event' => $event],
            'status' => 'pending',
            'dedup_key' => 'dk-'.$event.'-'.$agentId,
        ]);
    }

    private function queueAction(string $agentId): void
    {
        $ticket = Ticket::factory()->create();
        TechnicianRun::create([
            'ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'action_type' => 'tactical_stage_script', 'content_hash' => str_repeat('a', 64),
            'state' => TechnicianRunState::QueuedOffline, 'queued_agent_id' => $agentId,
            'queued_dedup_key' => 'k', 'queued_at' => now()->subMinutes(5), 'expires_at' => now()->addDays(7),
        ]);
    }

    private function fakeAlertService(mixed $return): void
    {
        $svc = Mockery::mock(TacticalAlertService::class);
        $svc->shouldReceive('handleAlertResolved')->andReturn($return);
        $svc->shouldReceive('handleAlertFailure')->andReturn($return);
        $this->app->instance(TacticalAlertService::class, $svc);
    }

    public function test_resolved_alert_dispatches_sweep_for_agent_with_queued_actions(): void
    {
        Queue::fake();
        $this->queueAction('AGENT-1');
        $this->fakeAlertService(new Alert);
        $row = $this->webhook('alert_resolved', 'AGENT-1');

        (new ProcessTacticalWebhook($row->id))->handle(app(TacticalAlertService::class));

        Queue::assertPushed(SweepQueuedActionsForAgent::class, fn ($j) => $j->agentId === 'AGENT-1');
    }

    public function test_resolved_alert_does_not_dispatch_when_no_queued_actions(): void
    {
        Queue::fake();
        $this->fakeAlertService(new Alert);
        $row = $this->webhook('alert_resolved', 'AGENT-1');

        (new ProcessTacticalWebhook($row->id))->handle(app(TacticalAlertService::class));

        Queue::assertNotPushed(SweepQueuedActionsForAgent::class);
    }

    public function test_dropped_alert_resolve_does_not_dispatch(): void
    {
        Queue::fake();
        $this->queueAction('AGENT-1');
        // Service dropped it (e.g. workstation availability alert, G4) → null.
        $this->fakeAlertService(null);
        $row = $this->webhook('alert_resolved', 'AGENT-1');

        (new ProcessTacticalWebhook($row->id))->handle(app(TacticalAlertService::class));

        Queue::assertNotPushed(SweepQueuedActionsForAgent::class);
    }
}
