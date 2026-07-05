<?php

namespace Tests\Feature\Signals;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\TranscriptionStatus;
use App\Http\Controllers\Api\PlivoWebhookController;
use App\Models\Client;
use App\Models\PhoneCall;
use App\Models\SignalEvent;
use App\Services\Signals\SignalHub;
use App\Services\TranscriptionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use ReflectionMethod;
use Tests\TestCase;

/**
 * psa-ip15 W1 Task 3: E3 (intake.call_received) + E4 (intake.call_transcribed)
 * emissions. Parallel-plane: these tests assert the SIGNAL side effects add up
 * correctly without ever changing native call-handling or transcription
 * behaviour (hangup processing, transcription status transitions all stay
 * byte-identical).
 *
 * Reference-only per Q1(A): entity = the PhoneCall model, context = client_id
 * ONLY, summary is a terse mechanical string — never transcript/recording text.
 */
class IntakeCallEmissionsTest extends TestCase
{
    use InteractsWithSignalEvents;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fakes RouteSignalEvent (dispatched inside SignalHub::emit) and
        // CallIntakeJob (dispatched by finalizeSuccessfulTranscription when
        // intake is enabled) — no external calls, no real job execution.
        Bus::fake();
    }

    // ── E3 — PlivoWebhookController::handle() ───────────────────────────────

    public function test_hangup_branch_emits_one_intake_call_received_signal(): void
    {
        $client = Client::factory()->create();
        $call = $this->makeCall('hangup-call-1');
        $call->client_id = $client->id;
        $call->save();

        $request = Request::create('/api/webhooks/plivo', 'POST', [
            'CallUUID' => 'hangup-call-1',
            'DialAction' => 'hangup',
        ]);

        $response = app(PlivoWebhookController::class)->handle($request);

        $this->assertSame(200, $response->getStatusCode());

        $event = $this->assertSingleSignalEvent('intake.call_received');
        $this->assertSame($call->getMorphClass(), $event->entity_type);
        $this->assertSame($call->id, $event->entity_id);
        $this->assertSame(['client_id' => $client->id], $event->context);
        $this->assertSame('inbound call received', $event->summary);

        // Native effect intact: the call was marked ended by handleCallEnded.
        $this->assertNotNull($call->fresh()->ended_at);
    }

    public function test_call_status_terminal_branch_emits_one_intake_call_received_signal(): void
    {
        $client = Client::factory()->create();
        $call = $this->makeCall('status-call-1');
        $call->client_id = $client->id;
        $call->save();

        $request = Request::create('/api/webhooks/plivo', 'POST', [
            'CallUUID' => 'status-call-1',
            'CallStatus' => 'completed',
        ]);

        $response = app(PlivoWebhookController::class)->handle($request);

        $this->assertSame(200, $response->getStatusCode());

        $event = $this->assertSingleSignalEvent('intake.call_received');
        $this->assertSame($call->getMorphClass(), $event->entity_type);
        $this->assertSame($call->id, $event->entity_id);
        $this->assertSame(['client_id' => $client->id], $event->context);
        $this->assertSame('inbound call received', $event->summary);

        $this->assertNotNull($call->fresh()->ended_at);
    }

    public function test_outbound_hangup_uses_outbound_word_in_summary(): void
    {
        $call = $this->makeCall('hangup-outbound-1', CallDirection::Outbound);

        $request = Request::create('/api/webhooks/plivo', 'POST', [
            'CallUUID' => 'hangup-outbound-1',
            'DialAction' => 'hangup',
        ]);

        app(PlivoWebhookController::class)->handle($request);

        $event = $this->assertSingleSignalEvent('intake.call_received');
        $this->assertSame('outbound call received', $event->summary);
    }

    public function test_hangup_branch_completes_native_call_ended_effect_when_signal_hub_throws(): void
    {
        $this->useThrowingSignalHub();

        $call = $this->makeCall('hangup-throw-1');

        $request = Request::create('/api/webhooks/plivo', 'POST', [
            'CallUUID' => 'hangup-throw-1',
            'DialAction' => 'hangup',
        ]);

        $response = app(PlivoWebhookController::class)->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($call->fresh()->ended_at);
        $this->assertDatabaseCount('signal_events', 0);
    }

    // ── E4 — TranscriptionService::finalizeSuccessfulTranscription() ────────

    public function test_finalize_successful_transcription_emits_one_intake_call_transcribed_signal(): void
    {
        $client = Client::factory()->create();
        $call = $this->makeCall('transcribe-call-1');
        $call->client_id = $client->id;
        $call->save();

        $this->invokeFinalize($call);

        $this->assertSame(TranscriptionStatus::Completed, $call->fresh()->transcription_status);

        $event = $this->assertSingleSignalEvent('intake.call_transcribed');
        $this->assertSame($call->getMorphClass(), $event->entity_type);
        $this->assertSame($call->id, $event->entity_id);
        $this->assertSame(['client_id' => $client->id], $event->context);
        $this->assertSame('call transcribed', $event->summary);
    }

    public function test_finalize_successful_transcription_keeps_completed_status_when_signal_hub_throws(): void
    {
        $this->useThrowingSignalHub();

        $call = $this->makeCall('transcribe-throw-1');

        $this->invokeFinalize($call);

        // The whole point of the wrap: a SignalHub failure must NEVER flip a
        // successful transcription to Failed, and must never propagate.
        // Proof the wrap holds: if finalizeSuccessfulTranscription's try/catch were
        // removed, the throwing SignalHub would propagate through invoke() and error
        // this test — reaching this assertion at all means the emit failure was swallowed.
        $this->assertSame(TranscriptionStatus::Completed, $call->fresh()->transcription_status);
        $this->assertDatabaseCount('signal_events', 0);
    }

    // ── Summary-mechanical lock ──────────────────────────────────────────────

    public function test_call_emission_summaries_never_leak_transcript_or_call_summary_text(): void
    {
        $client = Client::factory()->create();
        $call = $this->makeCall('secret-call-1');
        $call->client_id = $client->id;
        $call->transcription = 'SECRET RAW TRANSCRIPT TEXT';
        $call->call_summary = 'SECRET CALL SUMMARY TEXT';
        $call->save();

        // E3
        $request = Request::create('/api/webhooks/plivo', 'POST', [
            'CallUUID' => 'secret-call-1',
            'DialAction' => 'hangup',
        ]);
        app(PlivoWebhookController::class)->handle($request);

        // E4
        $this->invokeFinalize($call->fresh());

        $events = SignalEvent::where('type_key', 'like', 'intake.call_%')->get();
        $this->assertSame(2, $events->count());

        foreach ($events as $event) {
            $this->assertStringNotContainsString('SECRET RAW TRANSCRIPT TEXT', $event->summary);
            $this->assertStringNotContainsString('SECRET CALL SUMMARY TEXT', $event->summary);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCall(string $callUuid, CallDirection $direction = CallDirection::Inbound): PhoneCall
    {
        return PhoneCall::create([
            'call_uuid' => $callUuid,
            'direction' => $direction,
            'from_number' => '+15555550123',
            'to_number' => '+15555550000',
            'status' => CallStatus::InProgress,
            'started_at' => now()->subMinutes(2),
        ]);
    }

    private function invokeFinalize(PhoneCall $call): void
    {
        $service = new TranscriptionService;
        $ref = new ReflectionMethod($service, 'finalizeSuccessfulTranscription');
        $ref->invoke($service, $call);
    }

    private function useThrowingSignalHub(): void
    {
        $this->app->instance(SignalHub::class, new class extends SignalHub
        {
            public function __construct() {}

            public function emit(
                string $typeKey,
                ?Model $entity,
                string $summary,
                array $context = [],
                ?int $originEventId = null,
            ): ?SignalEvent {
                throw new \RuntimeException('boom');
            }
        });
    }
}
