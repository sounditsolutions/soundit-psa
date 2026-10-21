<?php

namespace Tests\Feature\Prepay;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Services\PhoneCallService;
use App\Services\PrepayService;
use App\Services\Triage\TriagePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * Card 6ad7c912, Jeeves's option 2 ruling (19:28 PT 10/20): debitFromPhoneCall() never debits
 * a Voicemail call, or a call whose stored duration is non-null and <= 0, and reverses any
 * debit such a call already has. Missed is not in the gate: a Missed call with a positive
 * duration still debits, as at base. A null stored duration is not gated: it still bills from
 * recording_duration, unchanged from base. Synthetic data only (G-13).
 *
 * The pipeline tests drive the public TriagePipeline::run(). AiClient posts through its own
 * Guzzle client, which Http::fake() cannot reach, and the pipeline constructs it itself with
 * a named argument that a Mockery overload's constructor refuses. So each test runs in its own
 * process and aliases AiClient to an anonymous stand-in before the real class can autoload;
 * the stand-in answers the classification and records each call.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ZeroDurationCallNeverDebitsTest extends TestCase
{
    use RefreshDatabase;

    private Contract $contract;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();

        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'synthetic-key');
        // Only the classification stage runs; its reconcile is the path under test.
        foreach (['contact_resolution', 'junk_filter', 'asset_assignment', 'technical_triage'] as $stage) {
            Setting::setValue("triage_stage_{$stage}", '0');
        }

        $client = Client::create(['name' => 'Synthetic Client Z']);
        $this->contract = Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic Prepay Z',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $this->ticket = Ticket::factory()->create([
            'client_id' => $client->id, 'contract_id' => $this->contract->id,
            'subject' => 'Synthetic Z ticket', 'status' => TicketStatus::New->value,
        ]);
    }

    /** Calls the AiClient stand-in received, for the pipeline assertions. */
    public static int $classifyCalls = 0;

    /** A linked call whose billability is unset, so the reconcile selects it. */
    private function makeCall(array $attrs = []): PhoneCall
    {
        return PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate(array_merge([
            'call_uuid' => 'synthetic-'.uniqid(), 'direction' => 'inbound', 'from_number' => '+15555550188',
            'status' => 'completed', 'is_billable' => null, 'ticket_id' => $this->ticket->id,
            'duration' => 0, 'recording_duration' => 1800, 'started_at' => now(),
        ], $attrs)));
    }

    /** Runs the public pipeline with a classification that says the work is billable. */
    private function runPipelineClassifiedBillable(): void
    {
        $this->assertFalse(class_exists(AiClient::class, false), 'Precondition: AiClient must not be loaded before it is aliased.');
        $standIn = new class
        {
            public function __construct(?string $modelOverride = null, mixed $http = null) {}

            public function completeJson(string $system, string $userMessage, int $maxTokens = 2048): array
            {
                ZeroDurationCallNeverDebitsTest::$classifyCalls++;

                return [
                    'client_type' => 'break_fix', 'has_active_contract' => true, 'has_prepaid_time' => true,
                    'prepaid_balance' => 10, 'contract_ids' => [], 'work_covered_by_managed' => false,
                    'reasoning' => 'Synthetic',
                ];
            }

            public function cumulativeInputTokens(): int
            {
                return 0;
            }

            public function cumulativeOutputTokens(): int
            {
                return 0;
            }
        };
        $this->assertTrue(class_alias($standIn::class, AiClient::class));

        $run = app(TriagePipeline::class)->run($this->ticket->fresh());

        $this->assertSame(1, self::$classifyCalls, 'The classification stage must have asked the stand-in exactly once.');
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame([], $run->fresh()->errors);
        $this->assertContains('classification', $run->fresh()->stages_completed);
        $this->assertFalse($run->fresh()->stage_results['classification']['work_covered_by_managed']);
    }

    private function balance(): float
    {
        return (float) $this->contract->fresh()->prepay_balance;
    }

    private function assertNotDebited(PhoneCall $call): void
    {
        $this->assertFalse(PrepayTransaction::where('phone_call_id', $call->id)->exists());
        $this->assertEquals(10.0, $this->balance());
    }

    private function assertDebited(PhoneCall $call, float $hours): void
    {
        $row = PrepayTransaction::where('phone_call_id', $call->id)->sole();
        $this->assertSame($this->contract->id, $row->contract_id);
        $this->assertEquals(-$hours, (float) $row->hours);
        // prepay_balance is decimal(10,2).
        $this->assertEquals(round(10 - $hours, 2), $this->balance());
    }

    /** z1: a Completed call stored at 0 s never debits, even with a 30-minute recording. */
    public function test_a_zero_duration_call_with_a_recording_is_not_debited_by_the_pipeline(): void
    {
        $call = $this->makeCall(['duration' => 0, 'recording_duration' => 1800]);
        $this->assertSame(1800, $call->effectiveDurationSeconds());

        $this->runPipelineClassifiedBillable();

        $this->assertTrue($call->fresh()->is_billable);
        $this->assertNotDebited($call);
    }

    /**
     * z2: a Voicemail call with a recording never debits. The pipeline's reconcile already
     * skips voicemail at this branch, so this drives PhoneCallService::setBillable(), a
     * public caller that reaches debitFromPhoneCall() for any status. A voicemail set
     * billable is the shape the 9 debited prod rows have.
     */
    public function test_a_voicemail_with_a_recording_is_not_debited_when_set_billable(): void
    {
        $call = $this->makeCall(['status' => 'voicemail', 'duration' => null, 'recording_duration' => 45]);
        $this->assertSame(45, $call->effectiveDurationSeconds());

        app(PhoneCallService::class)->setBillable($call, true);

        $this->assertTrue($call->fresh()->is_billable);
        $this->assertNotDebited($call);
    }

    /** z2m: a Missed call stored at 0 s never debits, even with a recording (the <= 0 half). */
    public function test_a_missed_call_with_zero_duration_and_a_recording_is_not_debited_when_set_billable(): void
    {
        $call = $this->makeCall(['status' => 'missed', 'duration' => 0, 'recording_duration' => 45]);
        $this->assertSame(45, $call->effectiveDurationSeconds());

        app(PhoneCallService::class)->setBillable($call, true);

        $this->assertTrue($call->fresh()->is_billable);
        $this->assertNotDebited($call);
    }

    /**
     * z2m180: pin, not red-first. Missed is not in the gate: a Missed call with a stored
     * 180 s duration still debits 180 s (round(180 / 3600, 4) = 0.05 h), unchanged from base.
     */
    public function test_a_missed_call_with_a_positive_duration_still_debits_when_set_billable(): void
    {
        $call = $this->makeCall(['status' => 'missed', 'duration' => 180, 'recording_duration' => null]);

        app(PhoneCallService::class)->setBillable($call, true);

        $this->assertTrue($call->fresh()->is_billable);
        $this->assertDebited($call, 0.05);
    }

    /** z2p: pin, not red-first. A null stored duration still bills from recording_duration, as at base. */
    public function test_a_null_duration_call_with_a_recording_still_debits_its_recording_length(): void
    {
        $call = $this->makeCall(['duration' => null, 'recording_duration' => 1800]);

        $this->runPipelineClassifiedBillable();

        $this->assertTrue($call->fresh()->is_billable);
        $this->assertDebited($call, 0.5);
    }

    /** z3: control. A 60 s call debits 60 s (round(60 / 3600, 4) = 0.0167 h). */
    public function test_a_sixty_second_call_debits_sixty_seconds(): void
    {
        $call = $this->makeCall(['duration' => 60, 'recording_duration' => 1800]);

        $this->runPipelineClassifiedBillable();

        $this->assertTrue($call->fresh()->is_billable);
        $this->assertDebited($call, 0.0167);
    }

    /**
     * z4: reversal. A call debited at 60 s whose stored duration later reads 0 has that debit
     * reversed when the pipeline reconciles it. The reconcile selects only calls whose
     * billability is unset or mismatched, so the arrangement clears is_billable after the
     * first debit (labelled: a constructed state, not a webhook replay).
     */
    public function test_an_existing_debit_is_reversed_when_the_stored_duration_becomes_zero(): void
    {
        $call = $this->makeCall(['duration' => 60, 'recording_duration' => 1800, 'is_billable' => true]);
        app(PrepayService::class)->debitFromPhoneCall($call);
        $this->assertDebited($call, 0.0167);

        PhoneCall::whereKey($call->id)->update(['duration' => 0, 'is_billable' => null]);

        $this->runPipelineClassifiedBillable();

        $this->assertTrue($call->fresh()->is_billable);
        $this->assertNotDebited($call);
        $this->assertEquals(0.0, (float) $this->contract->fresh()->prepay_used);
    }
}
