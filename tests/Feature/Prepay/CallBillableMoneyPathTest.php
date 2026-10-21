<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TriageRun;
use App\Services\Ai\AiClient;
use App\Services\PhoneCallService;
use App\Services\Triage\TriagePipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class CallBillableMoneyPathTest extends TestCase
{
    use RefreshDatabase;

    private Contract $contract;

    private Ticket $ticket;

    public static bool $covered = false;

    public static int $classifyCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        Setting::setValue('triage_auto_new_tickets', '0');
        Setting::setValue('ai_provider', 'anthropic');
        Setting::setEncrypted('ai_api_key', 'synthetic-key');
        foreach (['contact_resolution', 'junk_filter', 'asset_assignment', 'technical_triage'] as $stage) {
            Setting::setValue("triage_stage_{$stage}", '0');
        }
        $client = Client::create(['name' => 'Synthetic Money Client']);
        $this->contract = Contract::create([
            'client_id' => $client->id, 'name' => 'Synthetic Money Contract',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $this->ticket = Ticket::factory()->create([
            'client_id' => $client->id, 'contract_id' => $this->contract->id,
            'subject' => 'Synthetic money ticket', 'status' => 'new',
        ]);
    }

    private function makeCall(array $attrs = []): PhoneCall
    {
        return PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate(array_merge([
            'call_uuid' => 'synthetic-'.uniqid(), 'direction' => 'inbound',
            'from_number' => '+15555550199', 'status' => 'completed',
            'is_billable' => null, 'duration' => 1800, 'started_at' => now(),
        ], $attrs)));
    }

    private function link(PhoneCall $call): void
    {
        app(PhoneCallService::class)->linkCallToTicket($call, $this->ticket->id);
    }

    private function runPipeline(bool $covered): void
    {
        self::$covered = $covered;
        $this->assertFalse(class_exists(AiClient::class, false));
        // Classification response only: the public pipeline and money services remain real.
        $standIn = new class
        {
            public function __construct(?string $modelOverride = null, mixed $http = null) {}

            public function completeJson(string $system, string $userMessage, int $maxTokens = 2048): array
            {
                CallBillableMoneyPathTest::$classifyCalls++;

                return [
                    'client_type' => CallBillableMoneyPathTest::$covered ? 'managed_services' : 'break_fix',
                    'has_active_contract' => true, 'has_prepaid_time' => true,
                    'prepaid_balance' => 10, 'contract_ids' => [],
                    'work_covered_by_managed' => CallBillableMoneyPathTest::$covered,
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
        $this->assertSame(1, self::$classifyCalls);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame([], $run->fresh()->errors);
        $this->assertContains('classification', $run->fresh()->stages_completed);
        $this->assertSame($covered, $run->fresh()->stage_results['classification']['work_covered_by_managed']);
    }

    private function assertNotDebited(PhoneCall $call): void
    {
        $this->assertFalse(PrepayTransaction::where('phone_call_id', $call->id)->exists());
        $this->assertEquals(10.0, (float) $this->contract->fresh()->prepay_balance);
        $this->assertEquals(0.0, (float) $this->contract->fresh()->prepay_used);
    }

    private function assertDebited(PhoneCall $call): void
    {
        $this->assertSame(1, PrepayTransaction::where('phone_call_id', $call->id)->count());
        $row = PrepayTransaction::where('phone_call_id', $call->id)->sole();
        $this->assertSame($this->contract->id, $row->contract_id);
        $this->assertEquals(-0.5, (float) $row->hours);
        $this->assertEquals(9.5, (float) $this->contract->fresh()->prepay_balance);
    }

    public function test_negative_stored_duration_never_debits_through_public_pipeline(): void
    {
        $call = $this->makeCall(['ticket_id' => $this->ticket->id, 'duration' => -5, 'recording_duration' => 1800]);
        $this->assertSame(1800, $call->effectiveDurationSeconds());
        $this->runPipeline(false);
        $this->assertTrue($call->fresh()->is_billable);
        $this->assertNotDebited($call);
    }

    public function test_zero_stored_duration_with_recording_never_debits_at_link(): void
    {
        $call = $this->makeCall(['duration' => 0, 'recording_duration' => 1800]);
        $this->link($call);
        $this->assertTrue($call->fresh()->is_billable);
        $this->assertNotDebited($call);
    }

    public function test_link_preserves_preset_true_even_when_classified_managed_covered(): void
    {
        TriageRun::create([
            'ticket_id' => $this->ticket->id, 'status' => 'completed', 'stages_completed' => ['classification'],
            'stage_results' => ['classification' => ['work_covered_by_managed' => true]],
        ]);
        $call = $this->makeCall(['is_billable' => true]);
        $this->link($call);
        $this->assertTrue($call->fresh()->is_billable);
        $this->assertDebited($call);
    }

    public function test_link_preserves_preset_false_despite_billable_default(): void
    {
        $call = $this->makeCall(['is_billable' => false]);
        $this->link($call);
        $this->assertFalse($call->fresh()->is_billable);
        $this->assertNotDebited($call);
    }

    public function test_public_pipeline_flips_link_default_true_to_false_and_reverses_debit(): void
    {
        $call = $this->makeCall();
        $this->link($call);
        $this->assertTrue($call->fresh()->is_billable);
        $this->assertDebited($call);
        $this->runPipeline(true);
        $this->assertFalse($call->fresh()->is_billable);
        $this->assertNotDebited($call);
    }

    public function test_public_pipeline_flips_false_to_true_and_debits(): void
    {
        $call = $this->makeCall(['is_billable' => false]);
        $this->link($call);
        $this->assertNotDebited($call);
        $this->runPipeline(false);
        $this->assertTrue($call->fresh()->is_billable);
        $this->assertDebited($call);
    }

    public function test_null_duration_call_with_recording_linked_to_unclassified_ticket_stays_unset_and_reconcile_debits_its_recording(): void
    {
        $call = $this->makeCall(['duration' => null, 'recording_duration' => 1800]);
        $this->link($call);
        $this->assertNull($call->fresh()->is_billable);
        $this->assertNotDebited($call);
        $this->runPipeline(false);
        $this->assertTrue($call->fresh()->is_billable);
        $this->assertDebited($call);
    }
}
