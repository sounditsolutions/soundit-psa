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
use Illuminate\Database\Events\QueryExecuted;
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

    public function test_later_submission_reuses_the_sole_open_form_ticket(): void
    {
        // Card JyBDeHPz: the ruled intake.webform_received signal routes for real; every other job stays faked.
        Bus::fake()->except(\App\Jobs\RouteSignalEvent::class);
        $processor = app(SubmissionProcessor::class);
        $one = $processor->process($this->accept()->id);
        $two = $processor->process($this->accept()->id);
        $this->assertSame('processed', $one->state);
        $this->assertSame('processed', $two->state);
        $this->assertSame($one->ticket_id, $two->ticket_id);
        $this->assertNotSame($one->ticket_note_id, $two->ticket_note_id);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertDatabaseCount('ticket_notes', 2);
        $this->assertSame(ClientStage::Prospect, Client::sole()->stage);
        $this->assertFalse(Person::sole()->portal_enabled);
        $this->assertNull(Person::sole()->password);
        $ticket = Ticket::findOrFail($one->ticket_id);
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $this->assertSame(\App\Enums\TicketSource::WebForm, $ticket->source);
        $this->assertStringContainsString('Inquiry: future-slug', $ticket->description);
        $this->assertSame($one->ticket_id, $processor->process($one->id)->ticket_id);
        $this->assertDatabaseCount('tickets', 1);
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

    /**
     * Card fs0tKV9e (MariaDB two-process canary): the loser of the conditional identity claim
     * runs inside a caller transaction whose REPEATABLE READ snapshot predates the winner's
     * commit. Its conditional UPDATE is a current read and correctly sees the owner, but a
     * plain re-read of the owner returned the old snapshot and threw ModelNotFoundException,
     * leaving the submission pending. SQLite serialises writers, so this is MariaDB/MySQL-only.
     */
    public function test_loser_with_snapshot_older_than_winner_commit_links_to_winner(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped("Needs two InnoDB connections with REPEATABLE READ; driver is [{$driver}].");
        }
        $default = DB::getDefaultConnection();
        config(['database.connections.contact_intake_winner' => config("database.connections.{$default}")]);
        $winnerDb = DB::connection('contact_intake_winner');
        $hash = hash('sha256', 'snapshot-loser-'.Str::uuid());
        $data = ['name' => 'Synthetic Snapshot '.Str::random(8), 'email' => 'snapshot-loser@example.test', 'phone' => null, 'company' => null];
        // Committed on the second connection, outside RefreshDatabase's wrapping transaction.
        $winnerDb->table('contact_intake_identities')->insert(['identity_hash' => $hash]);
        $this->beforeApplicationDestroyed(function () use ($winnerDb, $hash, $data) {
            $owner = $winnerDb->table('contact_intake_identities')->where('identity_hash', $hash)->first();
            $winnerDb->table('contact_intake_identities')->where('identity_hash', $hash)->delete();
            $winnerDb->table('people')->where('id', $owner?->person_id)->delete();
            $winnerDb->table('clients')->where('name', $data['name'])->delete();
        });
        // The loser's snapshot is fixed by its first consistent read, as a plain read earlier in a caller's transaction would.
        $this->assertSame(1, DB::table('contact_intake_identities')->where('identity_hash', $hash)->count());
        $service = app(ProspectIntakeService::class);
        DB::setDefaultConnection('contact_intake_winner');
        try {
            $winner = $service->provisionContactIdentity($hash, $data);
        } finally {
            DB::setDefaultConnection($default);
        }
        $loser = $service->provisionContactIdentity($hash, $data);
        $this->assertSame($winner['client']->id, $loser['client']->id);
        $this->assertSame($winner['person']->id, $loser['person']->id);
        $this->assertSame(1, $winnerDb->table('clients')->where('name', $data['name'])->count());
    }

    /**
     * Review context:1 (PR #4856): the losing processor must classify on the winner's committed
     * Person and ticket, as serial processing would, so its new ticket links the winner's. The
     * winner commits on a second connection after the loser read its submission and before it
     * takes the identity lock. The loser runs on a third connection outside RefreshDatabase's
     * wrapping transaction, so process() opens the transaction, as the drain does. MariaDB/MySQL-only.
     */
    public function test_losing_processor_links_the_ticket_the_winner_committed_after_its_claim(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped("Needs InnoDB connections with REPEATABLE READ; driver is [{$driver}].");
        }
        Bus::fake();
        $default = DB::getDefaultConnection();
        foreach (['contact_intake_winner', 'contact_intake_loser'] as $name) {
            config(["database.connections.{$name}" => config("database.connections.{$default}")]);
        }
        $winnerDb = DB::connection('contact_intake_winner');
        // The winner runs inside the loser's query listener, so a lock it waits on is a cycle
        // InnoDB cannot see. Fail in seconds, not after the 50s default.
        $winnerDb->statement('SET SESSION innodb_lock_wait_timeout = 5');
        $hash = hash('sha256', 'race-matcher-'.Str::uuid());
        $earlierHash = hash('sha256', 'race-matcher-earlier-'.Str::uuid());
        $email = 'race-matcher-'.Str::lower(Str::random(10)).'@example.test';
        $priorSetting = $winnerDb->table('settings')->where('key', 'contact_intake_enabled')->first();
        // Everything below commits outside RefreshDatabase's transaction; remove it in FK order.
        $this->beforeApplicationDestroyed(function () use ($winnerDb, $hash, $earlierHash, $email, $priorSetting) {
            $submissionIds = $winnerDb->table('contact_submissions')->whereIn('identity_hash', [$hash, $earlierHash])->pluck('id');
            $personIds = $winnerDb->table('people')->where('email', $email)->pluck('id');
            $clientIds = $winnerDb->table('people')->whereIn('id', $personIds)->pluck('client_id');
            $ticketIds = $winnerDb->table('tickets')->whereIn('client_id', $clientIds)->pluck('id');
            $winnerDb->table('contact_intake_notifications')->whereIn('contact_submission_id', $submissionIds)->delete();
            $winnerDb->table('contact_submissions')->whereIn('id', $submissionIds)->delete();
            $winnerDb->table('ticket_notes')->whereIn('ticket_id', $ticketIds)->delete();
            $winnerDb->table('tickets')->whereIn('id', $ticketIds)->delete();
            $winnerDb->table('contact_intake_identities')->where('identity_hash', $hash)->delete();
            $winnerDb->table('person_emails')->whereIn('person_id', $personIds)->delete();
            $winnerDb->table('people')->whereIn('id', $personIds)->delete();
            $winnerDb->table('clients')->whereIn('id', $clientIds)->delete();
            $priorSetting
                ? $winnerDb->table('settings')->where('id', $priorSetting->id)->update(['value' => $priorSetting->value])
                : $winnerDb->table('settings')->where('key', 'contact_intake_enabled')->delete();
            DB::purge('contact_intake_loser');
            DB::purge('contact_intake_winner');
        });
        // Two inquiries for one new requester, both accepted before either is processed.
        DB::setDefaultConnection('contact_intake_winner');
        try {
            Setting::setValue('contact_intake_enabled', '1');
            $winnerDb->table('contact_intake_identities')->insert(['identity_hash' => $hash]);
            // A ledger with history, as production has: an earlier, processed inquiry from another
            // requester. The loser's claim (`state = 'pending'`) scans the state index and gap-locks
            // up to the next entry; in an empty ledger that gap covers ('processed', first id), so
            // the winner's own state change would wait on the loser, which real processes resolve
            // as a deadlock and retry. This row bounds the gap below the winner's entry.
            ContactSubmission::create([
                'integration_id' => 'website', 'submission_id' => (string) Str::uuid(), 'receipt' => (string) Str::uuid(),
                'payload_hash' => hash('sha256', 'EARLIER'), 'identity_hash' => $earlierHash, 'state' => 'processed',
                'payload' => ['name' => 'Synthetic Earlier', 'email' => 'earlier-'.$email, 'message' => 'EARLIER', 'submitted_at' => '2026-09-24T11:00:00Z'],
            ]);
            [$first, $second] = array_map(fn (string $message) => ContactSubmission::create([
                'integration_id' => 'website', 'submission_id' => (string) Str::uuid(), 'receipt' => (string) Str::uuid(),
                'payload_hash' => hash('sha256', $message), 'identity_hash' => $hash, 'state' => 'pending',
                'payload' => ['name' => 'Synthetic Race', 'email' => $email, 'message' => $message, 'submitted_at' => '2026-09-24T12:00:00Z'],
            ]), ['FIRST', 'SECOND']);
        } finally {
            DB::setDefaultConnection($default);
        }
        $fired = false;
        $winnerError = null;
        DB::listen(function (QueryExecuted $query) use (&$fired, &$winnerError, $hash, $first, $second) {
            if ($fired || $query->connectionName !== 'contact_intake_loser'
                || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'contact_submissions')) {
                return;
            }
            $fired = true;
            // The winner commits what process() commits for the first inquiry: prospect, person,
            // open form ticket, and a watermark covering the second. It takes no lock the loser holds.
            $loser = DB::getDefaultConnection();
            DB::setDefaultConnection('contact_intake_winner');
            try {
                $identity = app(ProspectIntakeService::class)->provisionContactIdentity($hash, $first->payload);
                $ticket = new Ticket([
                    'client_id' => $identity['client']->id, 'contact_id' => $identity['person']->id,
                    'subject' => 'Web form inquiry', 'description' => 'SYNTHETIC_UNTRUSTED_TEXT',
                    'source' => \App\Enums\TicketSource::WebForm, 'type' => \App\Enums\TicketType::ServiceRequest,
                    'status' => TicketStatus::New, 'priority' => \App\Enums\TicketPriority::P3,
                    'opened_at' => $first->payload['submitted_at'],
                ]);
                $ticket->forceFill(['contact_intake_origin' => true])->save();
                ContactSubmission::whereKey($first->id)->update(['state' => 'processed', 'ticket_id' => $ticket->id, 'ticket_watermark' => $second->id]);
            } catch (\Throwable $e) {
                // Recorded, not thrown: thrown here it surfaces as the loser's query error and
                // process() retries the loser without a winner.
                $winnerError = $e;
            } finally {
                DB::setDefaultConnection($loser);
            }
        });
        DB::setDefaultConnection('contact_intake_loser');
        try {
            $row = app(SubmissionProcessor::class)->process($second->id);
        } finally {
            DB::setDefaultConnection($default);
        }
        $this->assertTrue($fired, 'The winner never committed inside the loser transaction.');
        $this->assertNull($winnerError, 'The winner failed: '.$winnerError?->getMessage());
        $winnerTicketId = (int) $winnerDb->table('contact_submissions')->where('id', $first->id)->value('ticket_id');
        $this->assertGreaterThan(0, $winnerTicketId, 'The winner committed no ticket on its submission.');
        $this->assertSame('processed', $row->state);
        $this->assertNotSame($winnerTicketId, (int) $row->ticket_id);
        $this->assertSame([$winnerTicketId], json_decode((string) $row->related_ticket_ids, true));
        $this->assertSame(1, $winnerDb->table('people')->where('email', $email)->count());
        $this->assertSame(
            $winnerDb->table('tickets')->where('id', $winnerTicketId)->value('client_id'),
            $winnerDb->table('tickets')->where('id', $row->ticket_id)->value('client_id'),
        );
    }
}
