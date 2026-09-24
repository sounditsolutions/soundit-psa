<?php

namespace Tests\Feature\ContactIntake;

use App\Enums\ClientStage;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\ContactSubmission;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\ContactIntake\SubmissionLedger;
use App\Services\ContactIntake\SubmissionProcessor;
use App\Services\Prospect\ProspectIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubmissionProcessorTest extends TestCase
{
    use RefreshDatabase;

    private function accept(): ContactSubmission
    {
        Setting::setValue('contact_intake_enabled', '1');
        app(SubmissionLedger::class)->accept('website', [
            'submission_id' => (string) Str::uuid(), 'name' => 'Synthetic Visitor',
            'email' => 'visitor@example.test', 'message' => 'SYNTHETIC_UNTRUSTED_TEXT',
            'inquiry' => 'future-slug', 'submitted_at' => '2026-09-24T12:00:00Z',
        ]);

        return ContactSubmission::latest('id')->firstOrFail();
    }

    public function test_distinct_submissions_reuse_one_prospect_with_two_contained_tickets(): void
    {
        Bus::fake();
        $processor = app(SubmissionProcessor::class);
        $one = $processor->process($this->accept()->id);
        $two = $processor->process($this->accept()->id);
        $this->assertSame('processed', $one->state);
        $this->assertSame('processed', $two->state);
        $this->assertNotSame($one->ticket_id, $two->ticket_id);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseCount('tickets', 2);
        $this->assertSame(ClientStage::Prospect, Client::sole()->stage);
        $this->assertFalse(Person::sole()->portal_enabled);
        $this->assertNull(Person::sole()->password);
        $ticket = Ticket::findOrFail($one->ticket_id);
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $this->assertStringContainsString('Inquiry: future-slug', $ticket->description);
        $this->assertSame($one->ticket_id, $processor->process($one->id)->ticket_id);
        $this->assertDatabaseCount('tickets', 2);
        Bus::assertNothingDispatched();
    }

    public function test_existing_ticket_receives_only_a_contained_note(): void
    {
        Bus::fake();
        $client = Client::factory()->create();
        $person = Person::create(['client_id' => $client->id, 'first_name' => 'Synthetic', 'email' => 'visitor@example.test', 'is_active' => true]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contact_id' => $person->id, 'status' => TicketStatus::New]);
        $row = app(SubmissionProcessor::class)->process($this->accept()->id);
        $this->assertSame($ticket->id, $row->ticket_id);
        $this->assertFalse($ticket->fresh()->isUnverifiedContactIntake());
        $this->assertTrue(\App\Models\TicketNote::findOrFail($row->ticket_note_id)->isUnverifiedContactIntake());
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_losing_conditional_identity_candidate_rolls_back_without_orphans(): void
    {
        Bus::fake();
        $row = $this->accept();
        $service = app(ProspectIntakeService::class);
        $first = $service->provisionContactIdentity($row->identity_hash, $row->payload);
        $second = $service->provisionContactIdentity($row->identity_hash, $row->payload);
        $this->assertSame($first['client']->id, $second['client']->id);
        $this->assertSame($first['person']->id, $second['person']->id);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('people', 1);
        $this->assertSame($first['client']->id, DB::table('contact_intake_identities')->value('prospect_client_id'));
    }
}
