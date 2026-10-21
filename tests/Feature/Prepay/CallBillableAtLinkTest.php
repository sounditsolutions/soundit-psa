<?php

namespace Tests\Feature\Prepay;

use App\Enums\CallStatus;
use App\Enums\TicketSource;
use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TriageRun;
use App\Models\User;
use App\Services\PhoneCallService;
use App\Services\PrepayService;
use App\Services\Triage\TriagePipeline;
use App\Support\TriageConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Card 6ad7c912: a completed call linked to an existing ticket gets its billability at link
 * time, so a ticket triage never classifies no longer leaves such calls is_billable=null and
 * undebited. On an unclassified ticket a call still ringing or in progress stays unset at
 * link and takes the default once a webhook records it Completed. Missed and voicemail calls
 * stay non-billable, at link and through triage's reconcile. Synthetic data only (G-13).
 */
class CallBillableAtLinkTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        // Triage off, as on prod: no ticket is triaged automatically.
        Setting::setValue('triage_auto_new_tickets', '0');

        $this->client = Client::create(['name' => 'Synthetic Client B']);
        $this->contract = Contract::create([
            'client_id' => $this->client->id, 'name' => 'Synthetic Prepay B',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
    }

    private function ticket(array $attrs = []): Ticket
    {
        return Ticket::factory()->create(array_merge([
            'client_id' => $this->client->id, 'contract_id' => $this->contract->id,
            'subject' => 'Synthetic B ticket',
        ], $attrs));
    }

    private function makeCall(array $attrs = []): PhoneCall
    {
        return PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate(array_merge([
            'call_uuid' => 'synthetic-'.uniqid(), 'direction' => 'inbound', 'from_number' => '+15555550143',
            'status' => 'completed', 'is_billable' => null, 'duration' => 1800, 'started_at' => now(),
        ], $attrs)));
    }

    private function link(PhoneCall $call, Ticket $ticket): PhoneCall
    {
        app(PhoneCallService::class)->linkCallToTicket($call, $ticket->id);

        return $call->fresh();
    }

    private function classify(Ticket $ticket, bool $managedCovered): void
    {
        TriageRun::create([
            'ticket_id' => $ticket->id, 'status' => 'completed', 'stages_completed' => ['classification'],
            'stage_results' => ['classification' => ['work_covered_by_managed' => $managedCovered]],
        ]);
    }

    private function assertDebited(PhoneCall $call, float $hours): void
    {
        $row = PrepayTransaction::where('phone_call_id', $call->id)->sole();
        $this->assertSame($this->contract->id, $row->contract_id);
        $this->assertEquals(-$hours, (float) $row->hours);
        $this->assertEquals(10 - $hours, (float) $this->contract->fresh()->prepay_balance);
    }

    private function assertNotDebited(PhoneCall $call): void
    {
        $this->assertFalse(PrepayTransaction::where('phone_call_id', $call->id)->exists());
        $this->assertEquals(10.0, (float) $this->contract->fresh()->prepay_balance);
    }

    public function test_call_on_ai_actor_ticket_with_no_triage_run_is_billable_and_debited(): void
    {
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $this->assertSame($actor->id, TriageConfig::systemUserId());
        $this->assertFalse(TriageConfig::autoTriageEnabled());
        $ticket = $this->ticket(['created_by' => $actor->id, 'source' => TicketSource::Assistant->value]);
        $this->assertNull($ticket->latestTriageRun);

        $call = $this->link($this->makeCall(), $ticket);

        $this->assertTrue($call->is_billable);
        $this->assertDebited($call, 0.5);
    }

    public function test_call_on_human_ticket_with_triage_off_is_billable_and_debited(): void
    {
        $actor = User::factory()->create();
        $human = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $this->assertNotSame($human->id, TriageConfig::systemUserId());
        $this->assertFalse(TriageConfig::autoTriageEnabled());
        $ticket = $this->ticket(['created_by' => $human->id, 'source' => TicketSource::Email->value]);
        $this->assertNull($ticket->latestTriageRun);

        $call = $this->link($this->makeCall(), $ticket);

        $this->assertTrue($call->is_billable);
        $this->assertDebited($call, 0.5);
    }

    public function test_call_on_ticket_classified_managed_covered_is_not_billable_and_not_debited(): void
    {
        $ticket = $this->ticket();
        $this->classify($ticket, true);

        $call = $this->link($this->makeCall(), $ticket);

        $this->assertFalse($call->is_billable);
        $this->assertNotDebited($call);
    }

    public function test_voicemail_with_recording_duration_is_not_billable_and_not_debited(): void
    {
        $ticket = $this->ticket();
        $call = $this->link($this->makeCall(['status' => 'voicemail', 'duration' => null, 'recording_duration' => 45]), $ticket);

        $this->assertSame(45, $call->effectiveDurationSeconds());
        $this->assertFalse($call->is_billable);
        $this->assertNotDebited($call);
    }

    public function test_missed_call_is_not_billable_and_not_debited(): void
    {
        $ticket = $this->ticket();
        $call = $this->link($this->makeCall(['status' => 'missed', 'duration' => 20]), $ticket);

        $this->assertFalse($call->is_billable);
        $this->assertNotDebited($call);
    }

    /** @return array<string, array{?int, ?bool}> */
    public static function emptyDurations(): array
    {
        // Both take the default; with no recording neither has a length to debit.
        return ['zero' => [0, true], 'null' => [null, true]];
    }

    #[DataProvider('emptyDurations')]
    public function test_completed_call_with_no_duration_is_not_debited(?int $duration, ?bool $billable): void
    {
        $ticket = $this->ticket();
        $call = $this->link($this->makeCall(['duration' => $duration, 'recording_duration' => null]), $ticket);

        $this->assertSame($billable, $call->is_billable);
        $this->assertNotDebited($call);

        // A later debit attempt on the same row still records nothing.
        app(PrepayService::class)->debitFromPhoneCall($call);
        $this->assertNotDebited($call);
    }

    public function test_a_call_with_billability_already_set_keeps_it(): void
    {
        $ticket = $this->ticket();
        $call = $this->link($this->makeCall(['is_billable' => false]), $ticket);

        $this->assertFalse($call->is_billable);
        $this->assertNotDebited($call);
    }

    /**
     * A call linked while still ringing to an unclassified ticket stays unset, so when it ends
     * unanswered (a hangup whose Duration includes voicemail recording time) and is then marked
     * voicemail, nothing is debited.
     */
    public function test_call_linked_while_ringing_stays_unset_and_is_not_debited_when_it_ends_as_voicemail(): void
    {
        Queue::fake();
        $ticket = $this->ticket();
        $call = $this->link($this->makeCall(['status' => 'ringing', 'duration' => null]), $ticket);
        $this->assertNull($call->is_billable);

        $service = app(PhoneCallService::class);
        $service->handleCallEnded($call->call_uuid, ['Duration' => '45']);
        $this->assertSame(CallStatus::Missed, $call->fresh()->status);
        $this->assertNotDebited($call);

        $service->markAsVoicemail($call->call_uuid);

        $this->assertSame(CallStatus::Voicemail, $call->fresh()->status);
        $this->assertNull($call->fresh()->is_billable);
        $this->assertNotDebited($call);
    }

    /**
     * A call linked while in progress to an unclassified ticket stays unset at link, then takes
     * the default when the hangup records it Completed, and its duration is debited.
     */
    public function test_call_linked_while_in_progress_takes_default_when_it_ends_completed_and_is_debited(): void
    {
        Queue::fake();
        $ticket = $this->ticket();
        $call = $this->link($this->makeCall([
            'status' => 'in-progress', 'duration' => null, 'answered_at' => now()->subMinutes(30),
        ]), $ticket);
        $this->assertNull($call->is_billable);
        $this->assertNotDebited($call);

        app(PhoneCallService::class)->handleCallEnded($call->call_uuid, ['Duration' => '1800']);

        $this->assertSame(CallStatus::Completed, $call->fresh()->status);
        $this->assertTrue($call->fresh()->is_billable);
        $this->assertDebited($call, 0.5);
    }

    /**
     * A call linked while ringing that the hangup records Missed stays unset; a late answer then
     * records it Completed with no duration, and the recording callback gives it the default and
     * debits the recording's length. recording_disk_path is preset so no download is attempted.
     */
    public function test_call_completed_by_a_late_answer_takes_default_when_the_recording_lands_and_is_debited(): void
    {
        Queue::fake();
        $ticket = $this->ticket();
        $call = $this->link($this->makeCall([
            'status' => 'ringing', 'duration' => null, 'recording_disk_path' => 'call-recordings/synthetic.mp3',
        ]), $ticket);
        $service = app(PhoneCallService::class);
        $service->handleCallEnded($call->call_uuid, []);
        $this->assertSame(CallStatus::Missed, $call->fresh()->status);
        $service->handleCallAnswered($call->call_uuid, ['DialBLegUUID' => 'synthetic-bleg']);
        $this->assertSame(CallStatus::Completed, $call->fresh()->status);
        $this->assertNull($call->fresh()->is_billable);
        $this->assertNotDebited($call);

        $service->handleRecordingReady($call->call_uuid, 'https://example.invalid/synthetic.mp3', 1800);

        $this->assertTrue($call->fresh()->is_billable);
        $this->assertDebited($call, 0.5);
    }

    public function test_reconcile_after_billable_classification_leaves_voicemail_false_and_undebited(): void
    {
        $ticket = $this->ticket();
        $voicemail = $this->link($this->makeCall(['status' => 'voicemail', 'duration' => null, 'recording_duration' => 45]), $ticket);
        // A completed call on the same ticket set non-billable: the control that reconcile still flips.
        $completed = $this->link($this->makeCall(['is_billable' => false]), $ticket);
        $this->assertFalse($voicemail->is_billable);

        $pipeline = app(TriagePipeline::class);
        $ref = new ReflectionClass($pipeline);
        $ref->getProperty('stageResults')->setValue($pipeline, ['classification' => ['work_covered_by_managed' => false]]);
        $ref->getMethod('reconcileBillabilityAfterClassification')->invoke($pipeline, $ticket->fresh());

        $this->assertFalse($voicemail->fresh()->is_billable);
        $this->assertFalse(PrepayTransaction::where('phone_call_id', $voicemail->id)->exists());
        $this->assertTrue($completed->fresh()->is_billable);
        $this->assertDebited($completed, 0.5);
    }
}
