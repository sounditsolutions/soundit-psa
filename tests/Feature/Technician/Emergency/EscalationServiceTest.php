<?php

namespace Tests\Feature\Technician\Emergency;

use App\Enums\EmergencyState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianEmergency;
use App\Models\User;
use App\Services\Technician\Emergency\EscalationService;
use App\Services\Technician\Notify\OperatorNotifier;
use App\Support\TechnicianConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class EscalationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function emergency(Client $client): TechnicianEmergency
    {
        return TechnicianEmergency::create([
            'ticket_id' => \App\Models\Ticket::factory()->create(['client_id' => $client->id])->id,
            'client_id' => $client->id, 'signature' => 's', 'severity' => 3, 'reasons' => ['age'],
            'detected_by' => 'rules', 'state' => EmergencyState::Open, 'escalation_step' => 0,
            'ticket_ids' => [], 'alerted_at' => now(),
        ]);
    }

    public function test_pings_first_available_chain_member(): void
    {
        $client = Client::factory()->create();
        $justin = User::factory()->create();
        $charlie = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id, $charlie->id]));
        $e = $this->emergency($client);

        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')
            ->once()->withArgs(fn ($uid) => $uid === $justin->id));

        app(EscalationService::class)->escalate($e);
        $this->assertSame($justin->id, $e->fresh()->current_target_user_id);
        $this->assertDatabaseHas('technician_action_logs', ['action_type' => 'emergency_escalate']);
    }

    public function test_skips_unavailable_member_immediately(): void
    {
        $client = Client::factory()->create();
        $justin = User::factory()->create();
        $charlie = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id, $charlie->id]));
        \App\Support\TechnicianConfig::setOperatorAvailable($justin->id, false); // away

        $e = $this->emergency($client);
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')
            ->once()->withArgs(fn ($uid) => $uid === $charlie->id));

        app(EscalationService::class)->escalate($e);
        $this->assertSame($charlie->id, $e->fresh()->current_target_user_id);
    }

    public function test_no_ack_within_timeout_advances_chain(): void
    {
        $client = Client::factory()->create();
        $justin = User::factory()->create();
        $charlie = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id, $charlie->id]));
        Setting::setValue('technician_escalation_timeout', '15');

        $e = $this->emergency($client);
        $e->update(['escalation_step' => 0, 'current_target_user_id' => $justin->id, 'last_pinged_at' => now()->subMinutes(20)]);

        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')
            ->once()->withArgs(fn ($uid) => $uid === $charlie->id));

        app(EscalationService::class)->escalate($e);
        $this->assertSame($charlie->id, $e->fresh()->current_target_user_id);
    }

    public function test_acknowledged_emergency_does_nothing(): void
    {
        $client = Client::factory()->create();
        $e = $this->emergency($client);
        $e->update(['state' => EmergencyState::Acknowledged, 'acknowledged_at' => now()]);
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->never());
        app(EscalationService::class)->escalate($e);
    }

    // ── CO-8: per-tick idempotency ───────────────────────────────────────────

    public function test_target_pinged_within_timeout_is_not_repinged_this_tick(): void
    {
        $client = Client::factory()->create();
        $justin = User::factory()->create();
        $charlie = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id, $charlie->id]));
        Setting::setValue('technician_escalation_timeout', '15');
        Setting::setValue('technician_emergency_reping', '30');

        $e = $this->emergency($client);
        $pinged = now()->subMinutes(2); // well within the 15-minute timeout
        $e->update(['escalation_step' => 0, 'current_target_user_id' => $justin->id, 'last_pinged_at' => $pinged]);

        // Re-hydrate from the DB so the idempotency path runs against a CAST-exercised
        // model, not the in-memory write above. On prod MariaDB an uncast bigint comes
        // back as a string ("5") and breaks the strict-compare guard; this exercises the
        // 'current_target_user_id' => 'integer' cast that prevents the every-minute storm.
        $e = TechnicianEmergency::find($e->id);
        // Pin the cast: the column MUST hydrate as an int (the guard's invariant).
        $this->assertIsInt($e->current_target_user_id);

        // Already pinged this tick's target within the timeout ⇒ NEVER notify again.
        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')->never());

        app(EscalationService::class)->escalate($e);

        $fresh = $e->fresh();
        $this->assertSame($justin->id, $fresh->current_target_user_id);
        // last_pinged_at MUST be untouched this tick.
        $this->assertSame($pinged->toDateTimeString(), $fresh->last_pinged_at->toDateTimeString());
        // No second audit row was appended.
        $this->assertSame(0, \App\Models\TechnicianActionLog::where('action_type', 'emergency_escalate')->count());
    }

    // ── CO-8: 3-member skip + advance lands on C ─────────────────────────────

    public function test_three_member_chain_skips_unavailable_and_advances_to_c(): void
    {
        $client = Client::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $c = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$a->id, $b->id, $c->id]));
        Setting::setValue('technician_escalation_timeout', '15');
        TechnicianConfig::setOperatorAvailable($b->id, false); // B is away

        $e = $this->emergency($client);
        // A was pinged, timed out with no ack ⇒ advance past A; B unavailable ⇒ land on C.
        $e->update(['escalation_step' => 0, 'current_target_user_id' => $a->id, 'last_pinged_at' => now()->subMinutes(20)]);

        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')
            ->once()->withArgs(fn ($uid) => $uid === $c->id));

        app(EscalationService::class)->escalate($e);
        $this->assertSame($c->id, $e->fresh()->current_target_user_id);
    }

    // ── Task 7: both-unavailable re-ping path (invariant #4) ─────────────────

    public function test_both_unavailable_repings_last_target_and_audits_all_unavailable(): void
    {
        $client = Client::factory()->create();
        $justin = User::factory()->create();
        $charlie = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id, $charlie->id]));
        Setting::setValue('technician_emergency_reping', '30');
        TechnicianConfig::setOperatorAvailable($justin->id, false);
        TechnicianConfig::setOperatorAvailable($charlie->id, false);

        $e = $this->emergency($client);
        // Last-known target was pinged longer ago than the reping cadence ⇒ re-ping.
        $e->update(['current_target_user_id' => $justin->id, 'last_pinged_at' => now()->subMinutes(40)]);

        $this->mock(OperatorNotifier::class, fn (MockInterface $m) => $m->shouldReceive('notifyUser')
            ->once()->withArgs(fn ($uid) => $uid === $justin->id));

        app(EscalationService::class)->escalate($e);

        $row = \App\Models\TechnicianActionLog::where('action_type', 'emergency_escalate')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('all_unavailable', $row->summary);
    }

    // ── CO-5d / CO-11a: the ack URL must NEVER reach SMS ──────────────────────

    public function test_sms_text_excludes_the_ack_url(): void
    {
        $client = Client::factory()->create();
        $justin = User::factory()->create();
        Setting::setValue('technician_escalation_chain', json_encode([$justin->id]));
        $e = $this->emergency($client);

        $capturedBody = null;
        $capturedSmsText = null;
        $this->mock(OperatorNotifier::class, function (MockInterface $m) use (&$capturedBody, &$capturedSmsText) {
            $m->shouldReceive('notifyUser')->once()
                ->withArgs(function ($uid, $subject, $body, $sms, $smsText = null) use (&$capturedBody, &$capturedSmsText) {
                    $capturedBody = $body;
                    $capturedSmsText = $smsText;

                    return $sms === true;
                });
        });

        app(EscalationService::class)->escalate($e);

        // The ack URL lives in the email/Teams body...
        $this->assertStringContainsString('/technician/emergency/ack/', (string) $capturedBody);
        // ...but the SMS stub must NOT carry it (CO-11a).
        $this->assertNotNull($capturedSmsText);
        $this->assertStringNotContainsString('/technician/emergency/ack/', (string) $capturedSmsText);
        $this->assertStringNotContainsString('http', (string) $capturedSmsText);
    }
}
