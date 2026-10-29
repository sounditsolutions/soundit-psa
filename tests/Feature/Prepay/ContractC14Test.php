<?php

namespace Tests\Feature\Prepay;

use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\ContractResolution;
use App\Services\ContractResolver;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Tests\TestCase;

class ContractC14Test extends TestCase
{
    use RefreshDatabase;

    public function test_h4_1_held_note_contract_queries_do_not_grow_per_badge(): void
    {
        [$ticket, $user] = $this->h4HeldFixture();
        $spy = $this->h11ResolverSpy();
        $queries = 0;
        $clientQueries = 0;
        $entryClientQueries = 0;
        $counting = false;
        DB::listen(function ($query) use (&$queries, &$clientQueries, &$entryClientQueries, &$counting, $spy) {
            if ($counting && preg_match('/^select .* from [`"]contracts[`"]/i', $query->sql)) {
                $queries++;
            }
            if ($counting && preg_match('/^select .* from [`"]clients[`"]/i', $query->sql)) {
                $clientQueries++;
                // Separate count that excludes controller eager loads: only reads while a real forEntry() call
                // is on the stack (in this fixture each call reaches forClient() on the fallback arm).
                if ($spy->entryDepth > 0) {
                    $entryClientQueries++;
                }
            }
        });
        $this->actingAs($user);
        $counting = true;
        $this->get(route('tickets.show', $ticket))->assertOk();
        $counting = false;
        $one = $queries;
        $oneClients = $clientQueries;
        $oneEntryClients = $entryClientQueries;
        $this->assertGreaterThan(0, $one);
        $this->assertGreaterThan(0, $oneClients);
        $this->assertGreaterThan(0, $oneEntryClients, "H4-1: the page's forEntry() calls must select clients");
        $this->h4HeldNote($ticket, $user);
        $this->h4HeldNote($ticket, $user);
        $this->assertSame(3, $ticket->notes()->whereNotNull('contract_held_at')->count());
        $queries = 0;
        $clientQueries = 0;
        $entryClientQueries = 0;
        $counting = true;
        $this->get(route('tickets.show', $ticket))->assertOk();
        $counting = false;
        $this->assertSame($one, $queries, 'H4-1: three held notes must select contracts exactly as often as one held note');
        $this->assertSame($oneClients, $clientQueries, 'H4-1: three held notes must select clients exactly as often as one held note');
        $this->assertSame($oneEntryClients, $entryClientQueries, 'H4-1: three held notes must select clients inside forEntry() exactly as often as one held note');
    }

    public function test_h4_2_held_badge_text_pins_ambiguous_and_single_contract(): void
    {
        [$ticket, $user, $second] = $this->h4HeldFixture();
        $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('Needs contract')->assertDontSee('Awaiting verification');
        $second->delete();
        $this->assertSame(1, Contract::where('client_id', $ticket->client_id)->active()->count());
        $this->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('Awaiting verification')->assertDontSee('Needs contract');
    }

    private function h4HeldFixture(): array
    {
        Http::fake();
        Http::preventStrayRequests();
        $user = \App\Models\User::factory()->admin()->create(['is_active' => true]);
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null, 'contract_id' => null]);
        $ticket->client->forceFill(['default_contract_id' => null])->saveQuietly();
        for ($i = 0; $i < 2; $i++) {
            $contract = Contract::create([
                'client_id' => $ticket->client_id, 'name' => 'Synthetic H4 '.$i,
                'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
                'prepay_as_amount' => false, 'prepay_total' => 10,
                'prepay_used' => 0, 'prepay_balance' => 10,
            ]);
            $this->assertTrue(app(\App\Services\PrepayService::class)->isHoursPrepay($contract));
        }
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $this->assertTrue(app(ContractResolver::class)->forEntry($ticket)->isAmbiguous());
        $this->h4HeldNote($ticket, $user);

        return [$ticket, $user, $contract];
    }

    private function h4HeldNote(Ticket $ticket, \App\Models\User $user): TicketNote
    {
        return TicketNote::withoutEvents(fn () => TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'author_id' => $user->id, 'note_type' => 'note',
            'body' => 'Synthetic H4 held time', 'is_private' => true, 'is_billable' => true,
            'time_minutes' => 60, 'noted_at' => now(), 'contract_held_at' => now(), 'contract_id' => null,
        ]));
    }

    public function test_h11_call_badge_held_reason_does_not_call_for_entry_itself(): void
    {
        [$ticket, $user] = $this->h4HeldFixture();
        $ticket->notes()->update(['contract_held_at' => null]);
        $spy = $this->h11ResolverSpy();
        $this->actingAs($user);
        $one = null;
        for ($i = 0; $i < 2; $i++) {
            PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
                'ticket_id' => $ticket->id, 'call_uuid' => 'synthetic-h11-badge-'.$i,
                'direction' => 'inbound', 'from_number' => '+15555550142', 'status' => 'completed',
                'is_billable' => true, 'duration' => 3600, 'started_at' => now(), 'contract_held_at' => now(),
            ]));
            $spy->totalForEntry = 0;
            $spy->heldReasonCalls = 0;
            $html = $this->get(route('tickets.show', $ticket))->assertOk()->getContent();
            $this->assertSame($i + 1, substr_count($html, 'Needs contract'), 'each held call must render its held badge');
            $this->assertGreaterThan(0, $spy->totalForEntry, 'the resolver spy must observe the page');
            $this->assertSame($i + 1, $spy->heldReasonCalls, 'the service spy must observe every held call badge');
            $this->assertSame(0, $spy->fromHeldReason, 'PrepayService::heldReason must not call forEntry() itself');
            if ($one !== null) {
                $this->assertSame($one, $spy->totalForEntry, 'two held calls must resolve exactly as often as one');
            }
            $one = $spy->totalForEntry;
        }
    }

    public function test_h11_log_held_reason_does_not_call_for_entry_itself(): void
    {
        [$ticket, $user] = $this->h4HeldFixture();
        $note = TicketNote::withoutEvents(fn () => TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'author_id' => $user->id, 'note_type' => 'note',
            'body' => 'Synthetic H11 log time', 'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now(),
        ]));
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'ticket_id' => $ticket->id, 'call_uuid' => 'synthetic-h11-log', 'direction' => 'inbound',
            'from_number' => '+15555550142', 'status' => 'completed', 'is_billable' => true,
            'duration' => 3600, 'started_at' => now(),
        ]));
        $logs = new TestHandler(Level::Debug);
        $logger = Log::getLogger();
        $channel = $logger->getName();
        $logger->pushHandler($logs);
        $spy = $this->h11ResolverSpy();
        $service = app(\App\Services\PrepayService::class);
        $service->debitFromTicketNote($note);
        $service->debitFromPhoneCall($call);
        $this->assertSame(0, PrepayTransaction::count());
        foreach (['Ticket note', 'Phone call'] as $label) {
            $records = array_values(array_filter($logs->getRecords(), fn ($r) => $r->message === '[Prepay] '.$label.' debit held: needs contract'));
            $this->assertCount(1, $records);
            $this->assertSame(Level::Info, $records[0]->level);
            $this->assertSame($channel, $records[0]->channel);
        }
        $this->assertGreaterThan(0, $spy->totalForEntry, 'the resolver spy must observe the debit paths');
        $this->assertSame(4, $spy->totalForEntry, 'the unstamped held note and call debit paths together make four forEntry calls');
        $this->assertSame(2, $spy->heldReasonCalls, 'the service spy must observe both hold reasons');
        $this->assertSame(0, $spy->fromHeldReason, 'PrepayService::heldReason must not call forEntry() itself');
    }

    /**
     * Counts all observed forEntry() calls and, separately, calls immediately
     * from PrepayService::heldReason(). Helpers/closures are not immediate callers.
     * A delegating service spy counts heldReason invocations even when no resolver
     * method runs. Both spies execute their real parent implementations.
     */
    private function h11ResolverSpy(): ContractResolver
    {
        $spy = new class extends ContractResolver
        {
            public int $fromHeldReason = 0;

            public int $totalForEntry = 0;

            public int $heldReasonCalls = 0;

            public int $entryDepth = 0;

            public function forEntry(Ticket $ticket, ?int $pickedContractId = null): ContractResolution
            {
                $this->totalForEntry++;
                $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
                if (($caller['function'] ?? null) === 'heldReason' && ($caller['class'] ?? null) === \App\Services\PrepayService::class) {
                    $this->fromHeldReason++;
                }

                $this->entryDepth++;
                try {
                    return parent::forEntry($ticket, $pickedContractId);
                } finally {
                    $this->entryDepth--;
                }
            }
        };
        $this->app->instance(ContractResolver::class, $spy);
        $service = new class($spy) extends \App\Services\PrepayService
        {
            public function __construct(private ContractResolver $spy) {}

            public function heldReason(TicketNote|PhoneCall $entry, Ticket $ticket, ?ContractResolution $resolution = null): string
            {
                $this->spy->heldReasonCalls++;

                return parent::heldReason($entry, $ticket, $resolution);
            }
        };
        $this->app->instance(\App\Services\PrepayService::class, $service);

        return $spy;
    }

    public function test_inactive_stamp_on_unverified_ambiguous_ticket_needs_contract(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $ticket->client->forceFill(['default_contract_id' => null])->saveQuietly();
        foreach (['active', 'active', 'active'] as $i => $status) {
            $contract = Contract::create([
                'client_id' => $ticket->client_id, 'name' => 'Synthetic C16 r2 '.$i,
                'type' => 'managed', 'status' => $status, 'start_date' => '2026-01-01',
                'prepay_as_amount' => false, 'prepay_total' => 10,
                'prepay_used' => 0, 'prepay_balance' => 10,
            ]);
        }
        $contract->delete();
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $this->assertSame(2, Contract::where('client_id', $ticket->client_id)->active()->count());
        $this->assertTrue(app(ContractResolver::class)->forEntry($ticket)->isAmbiguous());
        $this->assertFalse(Contract::where('client_id', $ticket->client_id)->active()->whereKey($contract->id)->exists());
        $note = TicketNote::withoutEvents(fn () => TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'contract_id' => $contract->id,
            'body' => 'Synthetic inactive stamped time', 'is_billable' => true,
            'time_minutes' => 60, 'noted_at' => now(),
        ]));
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        app(\App\Services\PrepayService::class)->debitFromTicketNote($note);
        $this->assertNotNull($note->fresh()->contract_held_at);
        $this->assertSame(0, PrepayTransaction::count());
        $held = array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'Ticket note debit held:')));
        $this->assertCount(1, $held);
        $this->assertSame('[Prepay] Ticket note debit held: needs contract', $held[0]->message);
        $this->assertSame([], array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'awaiting verification'))));
    }

    public function test_moved_call_on_unverified_ambiguous_ticket_logs_verification_hold(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $user = \App\Models\User::factory()->create();
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $ticket->client->forceFill(['default_contract_id' => null])->saveQuietly();
        $contracts = [];
        for ($i = 0; $i < 2; $i++) {
            $contracts[] = Contract::create([
                'client_id' => $ticket->client_id, 'name' => 'Synthetic C16 call '.$i,
                'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
                'prepay_as_amount' => false, 'prepay_total' => 10,
                'prepay_used' => 0, 'prepay_balance' => 10,
            ]);
        }
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $this->assertTrue(app(ContractResolver::class)->forEntry($ticket)->isAmbiguous());
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'ticket_id' => $ticket->id, 'call_uuid' => 'synthetic-c16-move',
            'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
        ]));
        $this->assertSame(0, PrepayTransaction::count());
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        \App\Models\Setting::setValue('triage_system_user_id', (string) $user->id);
        $result = app(\App\Services\TimeEntryContractMoveService::class)->agentMove([
            'entry_type' => 'call', 'entry_id' => $call->id,
            'contract_id' => $contracts[0]->id, 'reason' => 'Synthetic move',
        ], 'synthetic-c17-actor', false);
        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertFalse($result['staged']);
        $this->assertSame($contracts[0]->id, $call->fresh()->contract_id);
        $this->assertNotNull($call->fresh()->contract_held_at);
        $this->assertSame(0, PrepayTransaction::count());
        $held = array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'Phone call debit held:')));
        $this->assertCount(1, $held);
        $this->assertSame('[Prepay] Phone call debit held: awaiting verification', $held[0]->message);
        $this->assertSame(Level::Info, $held[0]->level);
    }

    public function test_valid_stamp_on_unverified_ambiguous_ticket_logs_verification_hold(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $user = \App\Models\User::factory()->create();
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $ticket->client->forceFill(['default_contract_id' => null])->saveQuietly();
        $contracts = [];
        for ($i = 0; $i < 2; $i++) {
            $contracts[] = Contract::create([
                'client_id' => $ticket->client_id, 'name' => 'Synthetic C16 '.$i,
                'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
                'prepay_as_amount' => false, 'prepay_total' => 10,
                'prepay_used' => 0, 'prepay_balance' => 10,
            ]);
        }
        $this->assertTrue(app(ContractResolver::class)->forEntry($ticket)->isAmbiguous());
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        $this->actingAs($user)->post(route('tickets.notes.store', $ticket), [
            'body' => 'Synthetic C16 stamped time', 'note_type' => 'note',
            'time' => '60', 'is_billable' => '1', 'contract_id' => $contracts[0]->id,
        ])->assertSessionHasNoErrors();
        $note = TicketNote::where('ticket_id', $ticket->id)->where('body', 'Synthetic C16 stamped time')->sole();
        $this->assertSame($contracts[0]->id, $note->contract_id);
        $this->assertNotNull($note->contract_held_at);
        $this->assertSame(0, PrepayTransaction::count());
        $held = array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'Ticket note debit held:')));
        $this->assertCount(1, $held);
        $this->assertSame('[Prepay] Ticket note debit held: awaiting verification', $held[0]->message);
        $this->assertSame(Level::Info, $held[0]->level);
        $this->assertSame([], array_values(array_filter($logs->getRecords(), fn ($r) => $r->message === '[Prepay] Ticket note debit held: needs contract')));
    }

    public function test_ambiguous_contracts_take_precedence_over_unverified_intake(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $ticket->client->forceFill(['default_contract_id' => null])->saveQuietly();
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $contract = Contract::create([
                'client_id' => $ticket->client_id, 'name' => 'Synthetic C15 '.$i,
                'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
                'prepay_as_amount' => false, 'prepay_total' => 10,
                'prepay_used' => 0, 'prepay_balance' => 10,
            ]);
            $ids[] = $contract->id;
            $this->assertTrue(app(\App\Services\PrepayService::class)->isHoursPrepay($contract));
        }
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $this->assertTrue(app(ContractResolver::class)->forEntry($ticket)->isAmbiguous());
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-c15-ambiguous', 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
        ]));
        app(PhoneCallService::class)->linkCallToTicket($call, $ticket->id);
        $note = TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'body' => 'Synthetic ambiguous time',
            'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now(),
        ]);
        $this->assertNotNull($call->fresh()->contract_held_at);
        $this->assertNotNull($note->fresh()->contract_held_at);
        $this->assertSame(0, PrepayTransaction::count());
        $held = array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'debit held:')));
        $this->assertCount(2, $held);
        foreach (['Phone call' => ['phone_call_id', $call->id], 'Ticket note' => ['ticket_note_id', $note->id]] as $label => [$key, $id]) {
            $records = array_values(array_filter($held, fn ($r) => $r->message === '[Prepay] '.$label.' debit held: needs contract'));
            $this->assertCount(1, $records);
            $this->assertSame(Level::Info, $records[0]->level);
            $this->assertSame([$key, 'ticket_id', 'candidate_contract_ids'], array_keys($records[0]->context));
            $this->assertSame($id, $records[0]->context[$key]);
            $this->assertSame($ticket->id, $records[0]->context['ticket_id']);
            $this->assertEqualsCanonicalizing($ids, $records[0]->context['candidate_contract_ids']);
        }
        $this->assertSame([], array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'awaiting verification'))));
    }

    public function test_no_contract_intake_logs_verification_hold_for_note_and_call(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $this->assertSame(0, Contract::where('client_id', $ticket->client_id)->active()->count());
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $resolution = app(ContractResolver::class)->forEntry($ticket);
        $this->assertFalse($resolution->isResolved());
        $this->assertFalse($resolution->isAmbiguous());
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-c15-none', 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
        ]));
        app(PhoneCallService::class)->linkCallToTicket($call, $ticket->id);
        $note = TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'body' => 'Synthetic no-contract time',
            'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now(),
        ]);
        $this->assertNotNull($call->fresh()->contract_held_at);
        $this->assertNotNull($note->fresh()->contract_held_at);
        $this->assertSame(0, PrepayTransaction::count());
        $held = array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'debit held:')));
        $this->assertCount(2, $held);
        foreach (['Phone call' => ['phone_call_id', $call->id], 'Ticket note' => ['ticket_note_id', $note->id]] as $label => [$key, $id]) {
            $records = array_values(array_filter($held, fn ($r) => $r->message === '[Prepay] '.$label.' debit held: awaiting verification'));
            $this->assertCount(1, $records);
            $this->assertSame(Level::Info, $records[0]->level);
            $this->assertSame([$key => $id, 'ticket_id' => $ticket->id, 'candidate_contract_ids' => []], $records[0]->context);
        }
        $this->assertSame([], array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'debit held: needs contract'))));
    }

    public function test_verification_releases_held_call_on_preassigned_contract(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $contract = Contract::create([
            'client_id' => $ticket->client_id, 'name' => 'Synthetic C14',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $ticket->forceFill(['contract_id' => $contract->id])->saveQuietly();
        $second = Contract::create([
            'client_id' => $ticket->client_id, 'name' => 'Synthetic C19 second',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $this->assertTrue(app(\App\Services\PrepayService::class)->isHoursPrepay($second));
        $this->assertTrue(app(ContractResolver::class)->forClient((int) $ticket->client_id)->isAmbiguous(), 'the second contract makes the ticket tier distinguishable');
        $this->assertTrue($ticket->isUnverifiedContactIntake());
        $this->assertTrue(app(\App\Services\PrepayService::class)->isHoursPrepay($contract));
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-c14', 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
        ]));
        app(PhoneCallService::class)->linkCallToTicket($call, $ticket->id);
        $this->assertNotNull($call->fresh()->contract_held_at);
        $this->assertSame(0, PrepayTransaction::count());
        $user = \App\Models\User::factory()->admin()->create(['is_active' => true]);
        $formNote = TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'body' => 'Synthetic C17 form', 'note_type' => 'note',
            'contact_intake_origin' => true, 'contact_intake_verified_at' => null, 'noted_at' => now(),
        ]);
        $row = \App\Models\ContactSubmission::forceCreate([
            'integration_id' => 'website', 'submission_id' => 'synthetic-c17-verify',
            'payload_hash' => hash('sha256', 'synthetic-c17-verify'), 'identity_hash' => hash('sha256', 'synthetic-c17-verify'),
            'receipt' => 'synthetic-c17-verify', 'payload' => [], 'state' => 'processed',
            'ticket_id' => $ticket->id, 'ticket_note_id' => $formNote->id,
        ]);
        $contractWrites = 0;
        DB::listen(function ($query) use (&$contractWrites, $ticket) {
            $contractWrites += (int) $this->h15WritesContract($query->sql, $query->bindings, (int) $ticket->id);
        });
        app(\App\Services\ContactIntake\StaffWorkflow::class)->act($row->id, $user, 'verify', 'Synthetic verification');
        $this->assertFalse($ticket->fresh()->isUnverifiedContactIntake());
        $this->assertSame($contract->id, $ticket->fresh()->contract_id);
        $this->assertSame(0, $contractWrites);
        $this->assertTrue(PrepayTransaction::where('phone_call_id', $call->id)->exists(), 'the preassigned contract debits the released call');
        $debit = PrepayTransaction::where('phone_call_id', $call->id)->sole();
        $this->assertSame($contract->id, $debit->contract_id);
        $this->assertNull($call->fresh()->contract_held_at);
    }

    /**
     * Test detector, not a general SQL parser. Parses known quoted/unquoted ticket
     * updates; unparsed UPDATE tickets and REPLACE INTO tickets shapes count as
     * bulk, as do contract-writing upserts. Plain inserts retain their non-write result.
     * SQLite wraps LIMIT/join updates in a rowid SELECT; MySQL/MariaDB keep LIMIT
     * on the UPDATE and put joins before SET. Only the known SQLite wrapper is
     * unwrapped; other nested/grouped keys remain conservatively bulk writes.
     * The first top-level ticket id = ?, IN placeholders or IN digits selects
     * the relevant bindings unless the WHERE has a top-level NOT or OR keyword in any case (even on another column, or written flush against a quoted identifier), which counts as bulk; backticks and double quotes are stripped to a space and spaces around a dot are dropped before the scan; "top-level" counts parentheses naively, so string literals, comments, MySQL '!' and text after the SQLite rowid wrapper are not parsed;
     * numeric equality includes floats/padded strings and
     * Laravel's Connection::prepareBindings() bool-to-int conversion. Non-key
     * predicates remain bulk, including empty whereIn. SET subqueries and other
     * tables' ids never supply the key.
     * MySQL/MariaDB cast a string's leading number when comparing it with an INT
     * column, and SQLite does not; the detector follows the production drivers.
     */
    private function h15WritesContract(string $sql, array $bindings, int $ticketId): bool
    {
        $sql = trim(preg_replace('/\s*\.\s*/', '.', preg_replace('/\s+/', ' ', str_replace(['`', '"'], ' ', $sql))));
        $outside = fn (string $text, int $at) => substr_count(substr($text, 0, $at), '(') === substr_count(substr($text, 0, $at), ')');
        if (preg_match('/^insert into tickets\b.*? (?:on duplicate key update|on conflict\s*\([^)]*\) do update set) (.*)$/i', $sql, $upsert)) {
            return (bool) preg_match('/(?:^|,\s*)(?:tickets\.)?contract_id\s*=/i', $upsert[1]);
        }
        if (! preg_match('/^update tickets\s+(?:(?:inner|left|right) join .*? )?set (.*)$/i', $sql, $update)) {
            return (bool) preg_match('/^(?:update tickets|replace into tickets)\b/i', $sql);
        }
        $set = $update[1];
        $where = '';
        $whereOffset = strlen($sql);
        preg_match_all('/ where /i', $sql, $splits, PREG_OFFSET_CAPTURE);
        foreach ($splits[0] as [$split, $offset]) {
            if ($outside($sql, $offset)) {
                $set = substr($update[1], 0, $offset - (strlen($sql) - strlen($update[1])));
                $whereOffset = $offset + strlen($split);
                $where = substr($sql, $whereOffset);
                break;
            }
        }
        preg_match_all('/(?<![\w.])(?:tickets\.)?contract_id\s*=/i', $set, $writes, PREG_OFFSET_CAPTURE);
        if (! array_filter($writes[0], fn ($write) => $outside($set, $write[1]))) {
            return false;
        }
        // SQLiteGrammar::compileUpdateWithJoinsOrLimit selects tickets.rowid.
        if (preg_match('/^rowid in \(select tickets\.rowid from tickets\b.*\)$/i', $where)) {
            $inner = substr($where, strlen('rowid in ('), -1);
            preg_match_all('/ where /i', $inner, $splits, PREG_OFFSET_CAPTURE);
            foreach ($splits[0] as [$split, $offset]) {
                if ($outside($inner, $offset)) {
                    $whereOffset += strlen('rowid in (') + $offset + strlen($split);
                    $where = substr($inner, $offset + strlen($split));
                    break;
                }
            }
        }
        preg_match_all('/\bnot\b/i', $where, $negations, PREG_OFFSET_CAPTURE);
        if (array_filter($negations[0], fn ($match) => $outside($where, $match[1]))) {
            return true;
        }
        preg_match_all('/\bor\b/i', $where, $disjunctions, PREG_OFFSET_CAPTURE);
        if (array_filter($disjunctions[0], fn ($match) => $outside($where, $match[1]))) {
            return true;
        }
        preg_match_all('/(?<![\w.])(?:tickets\.)?id\s*(?:=\s*\?|in\s*\(([?, ]+|[0-9, ]+)\))/i', $where, $keys, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($keys as $key) {
            if (! $outside($where, $key[0][1])) {
                continue;
            }
            if (isset($key[1]) && ctype_digit(str_replace([',', ' '], '', $key[1][0]))) {
                $ids = array_map('trim', explode(',', $key[1][0]));
            } else {
                $first = substr_count(substr($sql, 0, $whereOffset + $key[0][1]), '?');
                $ids = array_slice($bindings, $first, substr_count($key[0][0], '?'));
            }

            return (bool) array_filter($ids, function ($id) use ($ticketId) {
                if (is_string($id)) {
                    preg_match('/^\s*([+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?)/', $id, $number);
                    $id = isset($number[1]) ? (float) $number[1] : 0;
                }

                return (is_int($id) || is_float($id) || is_bool($id)) && (is_bool($id) ? (int) $id : $id) == $ticketId;
            });
        }

        return true;
    }

    public function test_h15_detector_counts_flush_or_not_and_spaced_dot_as_bulk(): void
    {
        foreach (['`', '"'] as $q) {
            $this->assertTrue($this->h15WritesContract("update {$q}tickets{$q} set {$q}contract_id{$q} = ? where {$q}id{$q} = ? or{$q}client_id{$q} = ?", [99, 9, 5], 8), 'a top-level OR flush against a quoted identifier is bulk');
            $this->assertTrue($this->h15WritesContract("update {$q}tickets{$q} set {$q}contract_id{$q} = ? where {$q}id{$q} = ? and not{$q}closed_at{$q} is null", [99, 9], 8), 'a top-level NOT flush against a quoted identifier is bulk');
        }
        $this->assertTrue($this->h15WritesContract('update `tickets` set `contract_id` = ? where `id` = ? OR`client_id` = ?', [99, 9, 5], 8), 'uppercase flush OR is bulk');
        $this->assertTrue($this->h15WritesContract('update `tickets` inner join `clients` on `clients`.`id` = `tickets`.`client_id` set `contract_id` = ? where `clients` . `id` = ?', [99, 7], 8), "another table's id never supplies the key, even with spaces around the dot");
    }

    public function test_h15_counter_pins_both_driver_limit_and_join_shapes(): void
    {
        $connection = DB::connection();
        foreach ([new \Illuminate\Database\Query\Grammars\SQLiteGrammar($connection), new \Illuminate\Database\Query\Grammars\MySqlGrammar($connection), new \Illuminate\Database\Query\Grammars\MariaDbGrammar($connection)] as $grammar) {
            foreach ([false, true] as $join) {
                foreach ([7, 8] as $id) {
                    $query = new \Illuminate\Database\Query\Builder($connection, $grammar);
                    $query->from('tickets')->where('tickets.id', $id);
                    if ($join) {
                        $query->join('clients', 'clients.id', '=', 'tickets.client_id');
                    } else {
                        $query->limit(1);
                    }
                    $sql = $grammar->compileUpdate(clone $query, ['contract_id' => 99]);
                    $bindings = $grammar->prepareBindingsForUpdate($query->getRawBindings(), ['contract_id' => 99]);
                    $this->assertSame($id === 7, $this->h15WritesContract($sql, $bindings, 7), get_class($grammar).': '.$sql);
                }
            }
        }
        foreach ([
            'update "tickets" set "contract_id" = ? where "rowid" in (select "tickets"."rowid" from "tickets" where "tickets"."id" = ? limit 1)',
            'update `tickets` set `contract_id` = ? where `tickets`.`id` = ? limit 1',
            'update "tickets" set "contract_id" = ? where "rowid" in (select "tickets"."rowid" from "tickets" inner join "clients" on "clients"."id" = "tickets"."client_id" where "tickets"."id" = ?)',
            'update `tickets` inner join `clients` on `clients`.`id` = `tickets`.`client_id` set `contract_id` = ? where `tickets`.`id` = ?',
        ] as $sql) {
            $this->assertTrue($this->h15WritesContract($sql, [99, 7], 7), $sql);
            $this->assertFalse($this->h15WritesContract($sql, [99, 8], 7), $sql);
        }
    }

    public function test_h17_leading_number_binding_counts_as_a_write(): void
    {
        $this->assertTrue($this->h15WritesContract('update tickets set contract_id=? where id=?', [99, '8abc'], 8), 'the production-driver leading number matches ticket 8');
    }

    public function test_h15_counter_pins_numeric_id_bindings(): void
    {
        foreach ([7, 7.0, '07', '7 ', '7.0', '7e0'] as $id) {
            $sql = 'update "tickets" set "contract_id" = ? where "tickets"."id" = ?';
            $this->assertTrue($this->h15WritesContract($sql, [99, $id], 7));
            $this->assertFalse($this->h15WritesContract($sql, [7, $id], 8));
        }
        $this->assertTrue($this->h15WritesContract('update tickets set contract_id=? where id=?', [99, true], 1));
        foreach (['8abc', ' 8 x'] as $id) {
            $this->assertTrue($this->h15WritesContract('update tickets set contract_id=? where id=?', [99, $id], 8));
        }
    }

    public function test_h17_matching_contract_binding_does_not_hide_near_miss_key(): void
    {
        foreach (['8.5', 8.2, '9abc'] as $id) {
            $this->assertFalse($this->h15WritesContract('update tickets set contract_id=? where id=?', [8, $id], 8), 'the key binding, not the matching contract binding, must match the ticket id');
        }
    }

    public function test_h17_negated_and_disjunctive_keys_are_bulk(): void
    {
        $connection = DB::connection();
        foreach ([new \Illuminate\Database\Query\Grammars\SQLiteGrammar($connection), new \Illuminate\Database\Query\Grammars\MySqlGrammar($connection), new \Illuminate\Database\Query\Grammars\MariaDbGrammar($connection)] as $grammar) {
            foreach (['negated', 'qualified negated', 'disjunctive'] as $shape) {
                foreach ([false, true] as $limit) {
                    $query = new \Illuminate\Database\Query\Builder($connection, $grammar);
                    $query->from('tickets');
                    if ($shape === 'disjunctive') {
                        $query->where('id', 8)->orWhere('client_id', 5);
                    } else {
                        $query->whereNot($shape === 'negated' ? 'id' : 'tickets.id', 8);
                    }
                    if ($limit) {
                        $query->limit(1);
                    }
                    $sql = $grammar->compileUpdate(clone $query, ['contract_id' => 99]);
                    $bindings = $grammar->prepareBindingsForUpdate($query->getRawBindings(), ['contract_id' => 99]);
                    $this->assertTrue($this->h15WritesContract($sql, $bindings, 7), get_class($grammar).': '.$sql);
                }
            }
            $query = new \Illuminate\Database\Query\Builder($connection, $grammar);
            $query->from('tickets')->where('id', 8)->where(fn ($nested) => $nested->where('client_id', 5)->orWhere('client_id', 6));
            $sql = $grammar->compileUpdate(clone $query, ['contract_id' => 99]);
            $bindings = $grammar->prepareBindingsForUpdate($query->getRawBindings(), ['contract_id' => 99]);
            $this->assertFalse($this->h15WritesContract($sql, $bindings, 7), 'a nested OR does not widen the top-level ticket key: '.$sql);
        }
    }

    public function test_h17_r3_or_without_space_is_bulk(): void
    {
        $this->assertTrue($this->h15WritesContract('update tickets set contract_id = ? where id = ? or(status = ?)', [99, 9, 'x'], 8), 'any top-level OR is conservatively bulk');
    }

    public function test_h17_r3_signed_exponent_matches_ticket_id(): void
    {
        $this->assertTrue($this->h15WritesContract('update tickets set contract_id=? where id=?', [99, '80e-1'], 8));
    }

    public function test_h17_r3_uppercase_exponent_matches_ticket_id(): void
    {
        $this->assertTrue($this->h15WritesContract('update tickets set contract_id=? where id=?', [99, '0.8E1'], 8));
    }

    public function test_h17_r3_trailing_decimal_point_matches_ticket_id(): void
    {
        $this->assertTrue($this->h15WritesContract('update tickets set contract_id=? where id=?', [99, '8.e1'], 80));
    }

    public function test_h17_r3_number_after_non_numeric_prefix_is_not_a_write(): void
    {
        $this->assertFalse($this->h15WritesContract('update tickets set contract_id=? where id=?', [99, 'x8'], 8));
    }

    public function test_h17_r2_top_level_not_on_another_column_is_bulk(): void
    {
        $this->assertTrue($this->h15WritesContract('update tickets set contract_id = ? where id = ? and closed_at is not null', [99, 8], 7), 'any top-level NOT is conservatively bulk, even on another column');
    }

    public function test_h17_r2_nested_not_preserves_the_top_level_ticket_key(): void
    {
        $connection = DB::connection();
        foreach ([new \Illuminate\Database\Query\Grammars\SQLiteGrammar($connection), new \Illuminate\Database\Query\Grammars\MySqlGrammar($connection), new \Illuminate\Database\Query\Grammars\MariaDbGrammar($connection)] as $grammar) {
            $query = new \Illuminate\Database\Query\Builder($connection, $grammar);
            $query->from('tickets')->where('id', 8)->where(fn ($q) => $q->whereNot('client_id', 5));
            $sql = $grammar->compileUpdate(clone $query, ['contract_id' => 99]);
            $bindings = $grammar->prepareBindingsForUpdate($query->getRawBindings(), ['contract_id' => 99]);
            $this->assertFalse($this->h15WritesContract($sql, $bindings, 7), get_class($grammar).': a nested NOT does not widen the top-level ticket key: '.$sql);
        }
    }

    public function test_h17_r2_ticket_prefix_tables_are_not_ticket_writes(): void
    {
        $this->assertFalse($this->h15WritesContract('update tickets_archive set contract_id = ? where client_id = ?', [99, 5], 7));
        $this->assertFalse($this->h15WritesContract('replace into tickets_history (id, contract_id) values (?, ?)', [7, 99], 7));
    }

    public function test_h17_r2_signed_and_exponent_leading_numbers(): void
    {
        foreach (['+8', '.8e1', '0.8e1'] as $id) {
            $this->assertTrue($this->h15WritesContract('update tickets set contract_id=? where id=?', [99, $id], 8), $id.' has leading numeric value 8');
        }
        foreach (['8e1', '-8'] as $id) {
            $this->assertFalse($this->h15WritesContract('update tickets set contract_id=? where id=?', [99, $id], 8), $id.' has a leading numeric value different from 8');
        }
    }

    public function test_h17_unparsed_alias_is_bulk(): void
    {
        $connection = DB::connection();
        foreach ([new \Illuminate\Database\Query\Grammars\SQLiteGrammar($connection), new \Illuminate\Database\Query\Grammars\MySqlGrammar($connection), new \Illuminate\Database\Query\Grammars\MariaDbGrammar($connection)] as $grammar) {
            $query = new \Illuminate\Database\Query\Builder($connection, $grammar);
            $query->from('tickets as t')->where('t.id', 8);
            $sql = $grammar->compileUpdate(clone $query, ['contract_id' => 99]);
            $bindings = $grammar->prepareBindingsForUpdate($query->getRawBindings(), ['contract_id' => 99]);
            $this->assertTrue($this->h15WritesContract($sql, $bindings, 7), get_class($grammar).': '.$sql);
        }
    }

    public function test_h17_unparsed_cross_join_is_bulk(): void
    {
        $connection = DB::connection();
        foreach ([new \Illuminate\Database\Query\Grammars\MySqlGrammar($connection), new \Illuminate\Database\Query\Grammars\MariaDbGrammar($connection)] as $grammar) {
            $query = new \Illuminate\Database\Query\Builder($connection, $grammar);
            $query->from('tickets')->crossJoin('clients')->where('tickets.id', 8);
            $sql = $grammar->compileUpdate(clone $query, ['contract_id' => 99]);
            $bindings = $grammar->prepareBindingsForUpdate($query->getRawBindings(), ['contract_id' => 99]);
            $this->assertTrue($this->h15WritesContract($sql, $bindings, 7), get_class($grammar).': '.$sql);
        }
    }

    public function test_h17_replace_is_bulk_but_plain_insert_is_not(): void
    {
        $this->assertTrue($this->h15WritesContract('replace into tickets (id, contract_id) values (?, ?)', [8, 99], 7));
        $this->assertFalse($this->h15WritesContract('insert into tickets (id, contract_id) values (?, ?)', [8, 99], 7));
        $this->assertFalse($this->h15WritesContract('replace into clients (id, contract_id) values (?, ?)', [8, 99], 7));
    }

    public function test_h15_counter_pins_raw_updates_and_upserts(): void
    {
        foreach ([
            'update tickets set contract_id = ? where id = ?',
            "\n update tickets set contract_id=? where id=?",
            'update "tickets" set "contract_id"=? where "id"=?',
        ] as $sql) {
            $this->assertTrue($this->h15WritesContract($sql, [99, 7], 7));
            $this->assertFalse($this->h15WritesContract($sql, [7, 8], 7));
        }
        foreach ([
            'insert into tickets (id, contract_id) values (?, ?) on duplicate key update contract_id = values(contract_id)',
            'insert into "tickets" ("id", "contract_id") values (?, ?) on conflict ("id") do update set "contract_id" = "excluded"."contract_id"',
        ] as $sql) {
            $this->assertTrue($this->h15WritesContract($sql, [7, 99], 7));
        }
        $connection = DB::connection();
        foreach ([new \Illuminate\Database\Query\Grammars\SQLiteGrammar($connection), new \Illuminate\Database\Query\Grammars\MySqlGrammar($connection), new \Illuminate\Database\Query\Grammars\MariaDbGrammar($connection)] as $grammar) {
            $query = new \Illuminate\Database\Query\Builder($connection, $grammar);
            $query->from('tickets');
            $sql = $grammar->compileUpsert($query, [['contract_id' => 99, 'id' => 7]], ['id'], ['contract_id']);
            $this->assertTrue($this->h15WritesContract($sql, [99, 7], 7), $sql);
        }
        foreach ([
            ['update tickets set subject=? where contract_id=?', [7, 7]],
            ['update clients set contract_id=? where id=?', [99, 7]],
            ['insert into tickets (id, contract_id) values (?, ?) on duplicate key update subject=?', [7, 99, 'Synthetic']],
            ['update tickets set subject=(select contract_id=? from tickets where id=?) where id=?', [99, 7, 7]],
            ['update tickets set contract_id=(select id from contracts where id=?) where id=?', [7, 8]],
        ] as [$sql, $bindings]) {
            $this->assertFalse($this->h15WritesContract($sql, $bindings, 7), $sql);
        }
        foreach ([
            ['update tickets set contract_id=?', [99]],
            ['update tickets set contract_id=? where id in (?, ?)', [99, 8, 7]],
            ['update tickets set contract_id=? where id in (8, 7)', [99]],
            ['update tickets set contract_id=? where (id=?)', [99, 8]],
            ['update tickets set contract_id=? where exists (select * from clients where clients.id=?)', [99, 8]],
            ['update tickets set contract_id=? where 0 = 1', [99]],
        ] as [$sql, $bindings]) {
            $this->assertTrue($this->h15WritesContract($sql, $bindings, 7), $sql);
        }
    }

    public function test_unverified_intake_logs_verification_hold_for_note_and_call(): void
    {
        // Synthetic intake fixture follows ContractC10Test::linkedCall.
        Http::fake();
        Http::preventStrayRequests();
        $ticket = Ticket::factory()->create(['contact_intake_origin' => true, 'contact_intake_verified_at' => null]);
        $contract = Contract::create([
            'client_id' => $ticket->client_id, 'name' => 'Synthetic C14',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $resolver = app(ContractResolver::class);
        $this->assertSame($contract->id, $resolver->forClient($ticket->client_id)->contract?->id);
        $resolution = $resolver->forEntry($ticket);
        $this->assertFalse($resolution->isResolved());
        $this->assertFalse($resolution->isAmbiguous());
        $logs = new TestHandler;
        Log::getLogger()->pushHandler($logs);
        $call = PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate([
            'call_uuid' => 'synthetic-c14', 'direction' => 'inbound', 'from_number' => '+15555550142',
            'status' => 'completed', 'is_billable' => true, 'duration' => 3600, 'started_at' => now(),
        ]));
        app(PhoneCallService::class)->linkCallToTicket($call, $ticket->id);
        $note = TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'body' => 'Synthetic time',
            'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now(),
        ]);
        $this->assertNotNull($call->fresh()->contract_held_at);
        $this->assertNotNull($note->fresh()->contract_held_at);
        $this->assertSame(0, PrepayTransaction::count());
        $this->assertEquals(10, $contract->fresh()->prepay_balance);
        $held = array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'debit held:')));
        $this->assertCount(2, $held);
        foreach (['Phone call' => ['phone_call_id', $call->id], 'Ticket note' => ['ticket_note_id', $note->id]] as $label => [$key, $id]) {
            $records = array_values(array_filter($held, fn ($r) => $r->message === '[Prepay] '.$label.' debit held: awaiting verification'));
            $this->assertCount(1, $records);
            $this->assertSame(Level::Info, $records[0]->level);
            $this->assertSame([$key => $id, 'ticket_id' => $ticket->id, 'candidate_contract_ids' => []], $records[0]->context);
        }
        $this->assertSame([], array_values(array_filter($logs->getRecords(), fn ($r) => str_contains($r->message, 'debit held: needs contract'))));

        $user = \App\Models\User::factory()->admin()->create(['is_active' => true]);
        $formNote = TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'body' => 'Synthetic C17 form', 'note_type' => 'note',
            'contact_intake_origin' => true, 'contact_intake_verified_at' => null, 'noted_at' => now(),
        ]);
        $row = \App\Models\ContactSubmission::forceCreate([
            'integration_id' => 'website', 'submission_id' => 'synthetic-c17-verify',
            'payload_hash' => hash('sha256', 'synthetic-c17-verify'), 'identity_hash' => hash('sha256', 'synthetic-c17-verify'),
            'receipt' => 'synthetic-c17-verify', 'payload' => [], 'state' => 'processed',
            'ticket_id' => $ticket->id, 'ticket_note_id' => $formNote->id,
        ]);
        app(\App\Services\ContactIntake\StaffWorkflow::class)->act($row->id, $user, 'verify', 'Synthetic verification');
        $this->assertFalse($ticket->fresh()->isUnverifiedContactIntake());
        $this->assertSame($contract->id, $resolver->forEntry($ticket->fresh())->contract?->id);
        $service = app(\App\Services\PrepayService::class);
        $unsaved = (new PhoneCall)->forceFill(['ticket_id' => $ticket->id]);
        $this->assertFalse($unsaved->exists);
        $this->assertSame($contract->id, $service->contractForPhoneCall($unsaved)?->id);
        $otherTicket = Ticket::factory()->create();
        $this->assertNotSame($ticket->client_id, $otherTicket->client_id);
        $otherContract = Contract::create([
            'client_id' => $otherTicket->client_id, 'name' => 'Synthetic C19 other client',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10,
            'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $this->assertTrue(PrepayTransaction::where('contract_id', $contract->id)->whereNull('phone_call_id')->exists(), 'the released note row the M7074 kill relies on');
        $unsaved = (new PhoneCall)->forceFill(['ticket_id' => $otherTicket->id]);
        $this->assertFalse($unsaved->exists);
        $this->assertSame($otherContract->id, $service->contractForPhoneCall($unsaved)?->id);
        $callDebit = PrepayTransaction::where('phone_call_id', $call->id)->sole();
        $this->assertSame($contract->id, $callDebit->contract_id);
        $this->assertNull($call->fresh()->contract_held_at);
        $this->assertSame($contract->id, $service->contractForPhoneCall($call->fresh())?->id);
        $this->assertTrue($service->isHoursPrepay($contract->fresh()));
        $freshNote = TicketNote::forceCreate([
            'ticket_id' => $ticket->id, 'body' => 'Synthetic C17 verified time',
            'is_billable' => true, 'time_minutes' => 60, 'noted_at' => now(),
        ]);
        $debit = PrepayTransaction::where('ticket_note_id', $freshNote->id)->sole();
        $this->assertSame($contract->id, $debit->contract_id);
        $this->assertEquals(-1, $debit->hours);
    }

    public function test_h17_r4_parse_and_case_controls(): void
    {
        foreach (['80e-01', '0.8e+1', '0.80e1', '.80e1', '  8'] as $id) {
            $this->assertTrue($this->h15WritesContract('update tickets set contract_id=? where id=?', [99, $id], 8), "leading number of '{$id}' must match the ticket id");
        }
        $this->assertTrue($this->h15WritesContract('update tickets set contract_id = ? where id = ? OR client_id = ?', [99, 9, 5], 8), 'uppercase top-level OR is conservatively bulk');
        $this->assertTrue($this->h15WritesContract('update tickets set contract_id = ? where id = ? and closed_at is NOT null', [99, 9], 8), 'uppercase top-level NOT is conservatively bulk');
    }
}
