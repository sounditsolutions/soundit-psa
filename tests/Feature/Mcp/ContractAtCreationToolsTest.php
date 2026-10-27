<?php

namespace Tests\Feature\Mcp;

use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\EmailDirection;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Email;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Assistant\AssistantToolDefinitions;
use App\Services\Assistant\AssistantToolExecutor;
use App\Services\Mcp\PortalMcpToolDefinitions;
use App\Support\McpConfig;
use App\Support\McpToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Card I3EvQKUV PR 3 (spec §7, §9 C1-C6): contract_id on every agent
 * ticket-creating tool, the refusal shape, the reported outcome, is_default on
 * list_client_contracts, and no contract_id on the portal. Synthetic data only.
 */
class ContractAtCreationToolsTest extends TestCase
{
    use RefreshDatabase;

    private const LINE = 'Optional. Must be an active contract of this ticket\'s client. For a new ticket, omit it to use the client\'s active default contract, else its only active contract; with no active contracts the ticket gets none, and with several and no active default the outcome is ambiguous. An ambiguous outcome returns contract: null plus candidate_contracts. When the call returns an existing ticket (an idempotent replay or a linked email), contract_id is not applied: contract and contract_rule report where new time resolves, and ticket_contract_id reports the stored contract when it differs from the resolved contract. Rule ticket identifies the ticket contract. On create_ticket, a replay of a soft-deleted ticket still under this client instead reports its stored contract (rule ticket, or none if unset), ticket_contract_id and ticket_deleted: true.';

    private Client $client;

    private Client $other;

    private Contract $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::fake();
        Http::preventStrayRequests();
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $this->client = Client::create(['name' => 'Synthetic Client M']);
        $this->other = Client::create(['name' => 'Synthetic Other M']);
        $this->foreign = $this->contract('Synthetic Foreign', [], $this->other);
    }

    private function contract(string $name, array $o = [], ?Client $client = null): Contract
    {
        return Contract::create(array_merge([
            'client_id' => ($client ?? $this->client)->id, 'name' => $name, 'type' => 'managed',
            'status' => 'active', 'start_date' => '2026-01-01', 'prepay_as_amount' => false,
            'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ], $o));
    }

    private function callTool(array $tools, string $name, array $args): array
    {
        $token = McpConfig::rotateStaffToken(allowedTools: $tools, label: 'chet');
        $r = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args],
        ]);
        $r->assertOk();

        return ['isError' => (bool) $r->json('result.isError')]
            + (json_decode((string) $r->json('result.content.0.text'), true) ?? ['raw' => $r->json('result.content.0.text')]);
    }

    private function createArgs(array $o = []): array
    {
        return array_merge([
            'client_id' => $this->client->id, 'subject' => 'Synthetic subject',
            'description' => 'Synthetic description', 'reason' => 'Synthetic reason',
        ], $o);
    }

    private function email(): Email
    {
        return Email::create([
            'direction' => EmailDirection::Inbound, 'from_address' => 'someone@example.test',
            'subject' => 'Synthetic email', 'body_text' => 'Synthetic', 'received_at' => now(),
            'client_id' => $this->client->id,
        ]);
    }

    private function phoneCall(string $uuid = 'synthetic-call-1'): PhoneCall
    {
        $call = PhoneCall::create([
            'call_uuid' => $uuid, 'direction' => CallDirection::Inbound, 'from_number' => '+15555550123',
            'status' => CallStatus::Completed, 'started_at' => now(),
        ]);
        $call->client_id = $this->client->id;
        $call->save();

        return $call;
    }

    private function assistant(array $input): array
    {
        return (new AssistantToolExecutor(clientId: $this->client->id, userId: User::factory()->create()->id))
            ->execute('create_ticket', $input);
    }

    private function assertRefused(array $r, Contract|int $contract): void
    {
        $id = $contract instanceof Contract ? $contract->id : $contract;
        $this->assertSame('contract_not_allowed', $r['error_code'] ?? null, json_encode($r));
        $this->assertSame(
            "contract_id {$id} cannot be used for this ticket. Use an active contract of this ticket's client (list_client_contracts lists them), or omit contract_id for automatic selection (which may leave the contract unset).",
            $r['error'],
        );
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, Ticket::withTrashed()->count(), 'no ticket row');
        $this->assertSame(0, TechnicianActionLog::count(), 'no audit row');
        $this->assertDatabaseCount('ticket_notes', 0);
        $this->assertDatabaseCount('ticket_category_change_logs', 0);
        $this->assertDatabaseCount('contract_activities', 0);
        Bus::assertNotDispatched(\App\Jobs\SendTicketNotification::class);
        foreach (Email::all() as $email) {
            $this->assertNull($email->ticket_id);
        }
        foreach (PhoneCall::all() as $call) {
            $this->assertNull($call->ticket_id);
            $this->assertNull($call->contract_id);
        }
    }

    // ── C1: another client's contract is refused on every creating surface ───

    public function test_c1_staff_create_ticket_refuses_a_foreign_contract(): void
    {
        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['contract_id' => $this->foreign->id]));
        $this->assertTrue($r['isError']);
        $this->assertRefused($r, $this->foreign);
        $this->assertNothingWritten();
    }

    public function test_c1_assistant_create_ticket_refuses_a_foreign_contract(): void
    {
        $r = $this->assistant(['subject' => 'Synthetic', 'description' => 'Synthetic', 'contract_id' => $this->foreign->id]);
        $this->assertRefused($r, $this->foreign);
        $this->assertNothingWritten();
    }

    public function test_c1_create_ticket_from_email_refuses_a_foreign_contract(): void
    {
        $email = $this->email();
        $r = $this->callTool(['create_ticket_from_email'], 'create_ticket_from_email', [
            'email_id' => $email->id, 'reason' => 'Synthetic', 'contract_id' => $this->foreign->id,
        ]);
        $this->assertTrue($r['isError']);
        $this->assertRefused($r, $this->foreign);
        $this->assertNothingWritten();
        $this->assertNull($email->fresh()->ticket_id);
    }

    public function test_c1_create_ticket_from_call_refuses_a_foreign_contract(): void
    {
        $call = $this->phoneCall();
        $r = $this->callTool(['create_ticket_from_call'], 'create_ticket_from_call', [
            'phone_call_id' => $call->id, 'reason' => 'Synthetic', 'contract_id' => $this->foreign->id,
        ]);
        $this->assertTrue($r['isError']);
        $this->assertRefused($r, $this->foreign);
        $this->assertNothingWritten();
        $this->assertNull($call->fresh()->ticket_id);
        $this->assertNull($call->fresh()->contract_id);
    }

    private function expireBeforeCreate(Contract $contract): void
    {
        $service = app(\App\Services\TicketService::class);
        $mock = \Mockery::mock(\App\Services\TicketService::class)->makePartial();
        $mock->shouldReceive('createTicket')->once()->andReturnUsing(function (array $data, ?int $actorId) use ($service, $contract) {
            Contract::whereKey($contract->id)->update(['status' => 'expired']);

            return $service->createTicket($data, $actorId);
        });
        $this->app->instance(\App\Services\TicketService::class, $mock);
    }

    public function test_staff_create_refuses_contract_expired_after_precheck(): void
    {
        $contract = $this->contract('Synthetic Race');
        $this->expireBeforeCreate($contract);

        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['contract_id' => $contract->id]));

        $this->assertRefused($r, $contract);
        $this->assertTrue($r['isError']);
        $this->assertNothingWritten();
        $this->assertDatabaseCount('ticket_notes', 0);
        $this->assertDatabaseCount('ticket_category_change_logs', 0);
        $this->assertSame('active', $contract->fresh()->status->value);
    }

    public function test_call_create_refuses_contract_expired_after_precheck(): void
    {
        $contract = $this->contract('Synthetic Race');
        $call = $this->phoneCall();
        $before = $call->fresh()->getAttributes();
        $this->expireBeforeCreate($contract);

        $r = $this->callTool(['create_ticket_from_call'], 'create_ticket_from_call', [
            'phone_call_id' => $call->id, 'reason' => 'Synthetic', 'contract_id' => $contract->id,
        ]);

        $this->assertRefused($r, $contract);
        $this->assertTrue($r['isError']);
        $this->assertNothingWritten();
        $this->assertDatabaseCount('ticket_notes', 0);
        $this->assertSame($before, $call->fresh()->getAttributes());
        $this->assertSame('active', $contract->fresh()->status->value);
    }

    public function test_email_vendor_burst_flags_link_and_does_not_apply_passed_contract(): void
    {
        $stored = $this->contract('Synthetic Stored');
        $passed = $this->contract('Synthetic Passed');
        $ticket = Ticket::factory()->create([
            'client_id' => $this->client->id, 'contract_id' => $stored->id,
            'subject' => 'Email Delivery Request: user@example.test', 'status' => \App\Enums\TicketStatus::New,
        ]);
        $email = $this->email();
        $email->update(['from_address' => 'noreply@emailsecurity.app', 'subject' => $ticket->subject]);

        $r = $this->callTool(['create_ticket_from_email'], 'create_ticket_from_email', [
            'email_id' => $email->id, 'reason' => 'Synthetic', 'contract_id' => $passed->id,
        ]);

        $this->assertSame(true, $r['linked_existing'] ?? null);
        $this->assertSame($ticket->id, $r['ticket_id']);
        $this->assertSame(['id' => $stored->id, 'name' => 'Synthetic Stored', 'rule' => 'ticket'], $r['contract']);
        $this->assertSame('ticket', $r['contract_rule']);
        $this->assertSame($stored->id, $ticket->fresh()->contract_id);
        $this->assertSame($ticket->id, $email->fresh()->ticket_id);
        $this->assertSame(1, Ticket::count());
    }

    public function test_email_fresh_create_flags_not_linked(): void
    {
        $passed = $this->contract('Synthetic Passed');
        $email = $this->email();

        $r = $this->callTool(['create_ticket_from_email'], 'create_ticket_from_email', [
            'email_id' => $email->id, 'reason' => 'Synthetic', 'contract_id' => $passed->id,
        ]);

        $this->assertSame(false, $r['linked_existing'] ?? null);
        $this->assertSame(['id' => $passed->id, 'name' => 'Synthetic Passed', 'rule' => 'picked'], $r['contract']);
        $this->assertSame($passed->id, Ticket::sole()->contract_id);
        $this->assertSame(Ticket::sole()->id, $email->fresh()->ticket_id);
    }

    public function test_create_ticket_replay_of_a_deleted_ticket_reports_stored_contract(): void
    {
        $contract = $this->contract('Synthetic Replay');
        $first = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['contract_id' => $contract->id]));
        $this->assertTrue($first['success'] ?? false, json_encode($first));
        $ticket = Ticket::sole();
        $this->assertSame($contract->id, $ticket->contract_id);
        $ticket->delete();
        $contract->update(['status' => 'expired']);
        $this->contract('Synthetic replacement');

        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs());

        $this->assertTrue($r['idempotent'] ?? false, json_encode($r));
        $this->assertSame($ticket->id, $r['ticket_id']);
        $this->assertArrayHasKey('contract', $r);
        $this->assertSame($contract->id, $r['contract']['id'] ?? null);
        $this->assertSame('ticket', $r['contract_rule']);
        $this->assertTrue($r['ticket_deleted']);
        $this->assertArrayHasKey('url', $r);
        $this->assertNull($r['url']);
        $this->assertSame($contract->id, $r['ticket_contract_id']);
        $this->assertSame(1, Ticket::withTrashed()->count());

        Ticket::withTrashed()->whereKey($ticket->id)->update(['contract_id' => null]);
        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs());
        $this->assertTrue($r['ticket_deleted']);
        $this->assertNull($r['contract']);
        $this->assertNull($r['ticket_contract_id']);
        $this->assertSame('none', $r['contract_rule']);
    }

    // ── C2: an inactive contract of the same client ─────────────────────────

    public function test_c2_inactive_contract_of_the_same_client_is_refused_on_every_tool(): void
    {
        $expired = $this->contract('Synthetic Expired', ['status' => 'expired']);

        $this->assertRefused($this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['contract_id' => $expired->id])), $expired);
        $this->assertRefused($this->assistant(['subject' => 'S', 'description' => 'D', 'contract_id' => $expired->id]), $expired);
        $this->assertRefused($this->callTool(['create_ticket_from_email'], 'create_ticket_from_email', [
            'email_id' => $this->email()->id, 'reason' => 'Synthetic', 'contract_id' => $expired->id,
        ]), $expired);
        $this->assertRefused($this->callTool(['create_ticket_from_call'], 'create_ticket_from_call', [
            'phone_call_id' => $this->phoneCall()->id, 'reason' => 'Synthetic', 'contract_id' => $expired->id,
        ]), $expired);
        $this->assertNothingWritten();
    }

    // ── Validate before dedup; dedup hash unchanged ─────────────────────────

    public function test_create_ticket_validates_the_contract_before_dedup_and_the_hash_ignores_it(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');

        $first = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['contract_id' => $a->id]));
        $this->assertTrue($first['success'] ?? false, json_encode($first));
        $hash = TechnicianActionLog::sole()->content_hash;
        $this->assertSame(
            app(\App\Services\Assistant\AssistantTicketCreator::class)->contentHashFromPayload(
                ['client_id' => $this->client->id, 'subject' => 'Synthetic subject', 'description' => 'Synthetic description']
            ),
            $hash,
        );

        // Same identity, a refused contract: refused, never an idempotent success.
        $bad = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['contract_id' => $this->foreign->id]));
        $this->assertRefused($bad, $this->foreign);
        $this->assertArrayNotHasKey('idempotent', $bad);

        // Same identity, another valid contract: the dedup still answers.
        $dup = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['contract_id' => $b->id]));
        $this->assertTrue($dup['idempotent'] ?? false, json_encode($dup));
        // The idempotent answer reports the existing ticket's contract, not the one asked for.
        $this->assertSame(['id' => $a->id, 'name' => 'Synthetic A', 'rule' => 'ticket'], $dup['contract']);
        // Positive control (r2 diff:8): an ACTIVE ticket contract still resolves by rule
        // "ticket", and the stored id equals the resolved one, so it is not repeated.
        $this->assertSame('ticket', $dup['contract_rule']);
        $this->assertArrayNotHasKey('ticket_contract_id', $dup);
        $this->assertArrayNotHasKey('candidate_contracts', $dup);
        $this->assertSame(1, Ticket::count());
        $this->assertSame($a->id, Ticket::sole()->contract_id);
    }

    // ── r2 diff:8: an existing ticket's block is ContractResolver::forEntry ──

    private function vendorBurst(?Contract $ticketContract): Ticket
    {
        $ticket = Ticket::factory()->create([
            'client_id' => $this->client->id, 'contract_id' => $ticketContract?->id,
            'subject' => 'Email Delivery Request: user@example.test', 'status' => \App\Enums\TicketStatus::New,
        ]);
        $email = Email::create([
            'direction' => EmailDirection::Inbound, 'from_address' => 'noreply@emailsecurity.app',
            'subject' => 'Email Delivery Request: user@example.test', 'body_text' => 'Synthetic delivery request.',
            'received_at' => now(), 'client_id' => $this->client->id,
        ]);
        $this->linked = $this->callTool(['create_ticket_from_email'], 'create_ticket_from_email', [
            'email_id' => $email->id, 'reason' => 'Synthetic',
        ]);
        $this->assertSame($ticket->id, $this->linked['ticket_id'] ?? null, json_encode($this->linked));
        $this->assertSame(1, Ticket::count(), 'linked onto the existing ticket, no new one');

        return $ticket;
    }

    /** @var array<string, mixed> */
    private array $linked = [];

    public function test_replay_on_a_lapsed_ticket_contract_reports_the_client_default(): void
    {
        $a = $this->contract('Synthetic A');
        $first = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['contract_id' => $a->id]));
        $this->assertSame('picked', $first['contract_rule'] ?? null, json_encode($first));

        $a->update(['status' => 'expired']);
        $b = $this->contract('Synthetic B');
        $this->contract('Synthetic C');
        Client::whereKey($this->client->id)->update(['default_contract_id' => $b->id]);

        $dup = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs());
        $this->assertTrue($dup['idempotent'] ?? false, json_encode($dup));
        $this->assertSame(['id' => $b->id, 'name' => 'Synthetic B', 'rule' => 'client_default'], $dup['contract']);
        $this->assertSame('client_default', $dup['contract_rule']);
        $this->assertSame($a->id, $dup['ticket_contract_id']);
        $this->assertArrayNotHasKey('candidate_contracts', $dup);
        $this->assertSame($a->id, Ticket::sole()->contract_id, 'the replay writes nothing');
    }

    public function test_replay_on_a_null_ticket_contract_reports_a_live_client_default(): void
    {
        $first = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs());
        $this->assertSame('none', $first['contract_rule'] ?? null, json_encode($first));

        $b = $this->contract('Synthetic B');
        $this->contract('Synthetic C');
        Client::whereKey($this->client->id)->update(['default_contract_id' => $b->id]);

        $dup = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs());
        $this->assertTrue($dup['idempotent'] ?? false, json_encode($dup));
        $this->assertSame(['id' => $b->id, 'name' => 'Synthetic B', 'rule' => 'client_default'], $dup['contract']);
        $this->assertSame('client_default', $dup['contract_rule']);
        $this->assertArrayHasKey('ticket_contract_id', $dup);
        $this->assertNull($dup['ticket_contract_id']);
        $this->assertNull(Ticket::sole()->contract_id);
    }

    public function test_vendor_burst_on_a_trashed_ticket_contract_reports_the_client_default(): void
    {
        $a = $this->contract('Synthetic A');
        $a->delete();
        $b = $this->contract('Synthetic B');
        $this->contract('Synthetic C');
        Client::whereKey($this->client->id)->update(['default_contract_id' => $b->id]);

        $this->vendorBurst($a);

        $this->assertSame(['id' => $b->id, 'name' => 'Synthetic B', 'rule' => 'client_default'], $this->linked['contract']);
        $this->assertSame('client_default', $this->linked['contract_rule']);
        $this->assertSame($a->id, $this->linked['ticket_contract_id']);
    }

    public function test_vendor_burst_on_an_expired_ticket_contract_reports_ambiguous_with_candidates(): void
    {
        $a = $this->contract('Synthetic A', ['status' => 'expired']);
        $b = $this->contract('Synthetic B');
        $c = $this->contract('Synthetic C');

        $this->vendorBurst($a);

        $this->assertNull($this->linked['contract']);
        $this->assertSame('ambiguous', $this->linked['contract_rule']);
        $this->assertSame([
            ['id' => $b->id, 'name' => 'Synthetic B', 'type' => 'managed'],
            ['id' => $c->id, 'name' => 'Synthetic C', 'type' => 'managed'],
        ], $this->linked['candidate_contracts']);
        $this->assertSame($a->id, $this->linked['ticket_contract_id']);
    }

    public function test_vendor_burst_on_an_expired_ticket_contract_reports_none_when_nothing_is_active(): void
    {
        $a = $this->contract('Synthetic A', ['status' => 'expired']);

        $this->vendorBurst($a);

        $this->assertNull($this->linked['contract']);
        $this->assertSame('none', $this->linked['contract_rule']);
        $this->assertArrayNotHasKey('candidate_contracts', $this->linked);
        $this->assertSame($a->id, $this->linked['ticket_contract_id']);
    }

    public function test_vendor_burst_on_an_active_ticket_contract_still_reports_ticket(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        Client::whereKey($this->client->id)->update(['default_contract_id' => $b->id]);

        $this->vendorBurst($a);

        $this->assertSame(['id' => $a->id, 'name' => 'Synthetic A', 'rule' => 'ticket'], $this->linked['contract']);
        $this->assertSame('ticket', $this->linked['contract_rule']);
        $this->assertArrayNotHasKey('ticket_contract_id', $this->linked);
    }

    // ── C3: outcome reported in every create response ───────────────────────

    public function test_c3_staff_create_ticket_reports_each_rule(): void
    {
        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['subject' => 'S none']));
        $this->assertNull($r['contract']);
        $this->assertSame('none', $r['contract_rule']);

        $only = $this->contract('Synthetic Only');
        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['subject' => 'S only']));
        $this->assertSame(['id' => $only->id, 'name' => 'Synthetic Only', 'rule' => 'only_active'], $r['contract']);
        $this->assertSame('only_active', $r['contract_rule']);

        $second = $this->contract('Synthetic Second');
        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['subject' => 'S amb']));
        $this->assertNull($r['contract']);
        $this->assertSame('ambiguous', $r['contract_rule']);
        $this->assertSame([
            ['id' => $only->id, 'name' => 'Synthetic Only', 'type' => 'managed'],
            ['id' => $second->id, 'name' => 'Synthetic Second', 'type' => 'managed'],
        ], $r['candidate_contracts']);
        $this->assertNull(Ticket::where('subject', 'S amb')->value('contract_id'));

        Client::whereKey($this->client->id)->update(['default_contract_id' => $second->id]);
        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['subject' => 'S def']));
        $this->assertSame(['id' => $second->id, 'name' => 'Synthetic Second', 'rule' => 'client_default'], $r['contract']);
        $this->assertSame($second->id, Ticket::where('subject', 'S def')->value('contract_id'));

        $r = $this->callTool(['create_ticket'], 'create_ticket', $this->createArgs(['subject' => 'S pick', 'contract_id' => $only->id]));
        $this->assertSame(['id' => $only->id, 'name' => 'Synthetic Only', 'rule' => 'picked'], $r['contract']);
        $this->assertSame('picked', $r['contract_rule']);
        $this->assertArrayNotHasKey('candidate_contracts', $r);
    }

    public function test_c3_assistant_email_and_call_creates_report_the_outcome(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');

        $r = $this->assistant(['subject' => 'S', 'description' => 'D']);
        $this->assertSame('ambiguous', $r['contract_rule']);
        $this->assertCount(2, $r['candidate_contracts']);

        $r = $this->assistant(['subject' => 'S2', 'description' => 'D', 'contract_id' => $b->id]);
        $this->assertSame(['id' => $b->id, 'name' => 'Synthetic B', 'rule' => 'picked'], $r['contract']);

        $r = $this->callTool(['create_ticket_from_email'], 'create_ticket_from_email', [
            'email_id' => $this->email()->id, 'reason' => 'Synthetic', 'contract_id' => $a->id,
        ]);
        $this->assertSame(['id' => $a->id, 'name' => 'Synthetic A', 'rule' => 'picked'], $r['contract']);
        $this->assertSame($a->id, Ticket::find($r['ticket_id'])->contract_id);

        $r = $this->callTool(['create_ticket_from_email'], 'create_ticket_from_email', [
            'email_id' => $this->email()->id, 'reason' => 'Synthetic',
        ]);
        $this->assertSame('ambiguous', $r['contract_rule']);
        $this->assertCount(2, $r['candidate_contracts']);
    }

    public function test_create_ticket_from_call_stamps_the_picked_contract_on_the_call(): void
    {
        $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $call = $this->phoneCall();

        $r = $this->callTool(['create_ticket_from_call'], 'create_ticket_from_call', [
            'phone_call_id' => $call->id, 'reason' => 'Synthetic', 'contract_id' => $b->id,
        ]);

        $this->assertSame(['id' => $b->id, 'name' => 'Synthetic B', 'rule' => 'picked'], $r['contract']);
        $this->assertSame($b->id, Ticket::find($r['ticket_id'])->contract_id);
        $this->assertSame($b->id, $call->fresh()->contract_id);
        $this->assertSame($b->id, $r['call_contract_id']);
    }

    // ── C4 (PR 2 pins the money side): the update response reports the contract ─

    public function test_c4_update_ticket_reports_the_contract_block(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $ticket = Ticket::factory()->create(['client_id' => $this->client->id, 'contract_id' => $a->id]);

        $r = $this->callTool(['update_ticket'], 'update_ticket', ['ticket_id' => $ticket->id, 'contract_id' => $b->id]);
        $this->assertSame(['id' => $b->id, 'name' => 'Synthetic B', 'rule' => 'ticket'], $r['contract']);
        $this->assertSame('ticket', $r['contract_rule']);
        $this->assertSame([], $r['entries_on_other_contracts']);

        $r = $this->callTool(['update_ticket'], 'update_ticket', ['ticket_id' => $ticket->id, 'contract_id' => null]);
        $this->assertNull($r['contract']);
        $this->assertSame('ambiguous', $r['contract_rule']);

        Client::whereKey($this->client->id)->update(['default_contract_id' => $b->id]);
        $r = $this->callTool(['update_ticket'], 'update_ticket', ['ticket_id' => $ticket->id, 'contract_id' => null]);
        $this->assertSame($b->id, $r['contract']['id']);
        $this->assertSame('client_default', $r['contract_rule']);
        $this->assertNull($r['ticket_contract_id']);
        $this->assertNull($ticket->fresh()->contract_id);

        $r = $this->callTool(['update_ticket'], 'update_ticket', ['ticket_id' => $ticket->id, 'contract_id' => $this->foreign->id]);
        $this->assertSame('contract_not_allowed', $r['error_code']);
        $this->assertNull($ticket->fresh()->contract_id);
    }

    public function test_c13_update_ticket_refuses_an_inactive_contract_of_the_same_client(): void
    {
        $expired = $this->contract('Synthetic Expired', ['status' => 'expired']);
        $ticket = Ticket::factory()->create(['client_id' => $this->client->id, 'contract_id' => null]);

        $r = $this->callTool(['update_ticket'], 'update_ticket', ['ticket_id' => $ticket->id, 'contract_id' => $expired->id]);

        $this->assertSame('contract_not_allowed', $r['error_code'] ?? null);
        $this->assertNull($ticket->fresh()->contract_id);
    }

    // ── list_client_contracts: is_default per row ──────────────────────────

    public function test_list_client_contracts_flags_the_live_default_only(): void
    {
        $a = $this->contract('Synthetic A');
        $b = $this->contract('Synthetic B');
        $expired = $this->contract('Synthetic Expired', ['status' => 'expired']);
        Client::whereKey($this->client->id)->update(['default_contract_id' => $b->id]);

        $r = $this->callTool(['list_client_contracts'], 'list_client_contracts', ['client_id' => $this->client->id]);
        $flags = array_column($r['contracts'], 'is_default', 'id');
        $this->assertSame([$a->id => false, $b->id => true, $expired->id => false], [$a->id => $flags[$a->id], $b->id => $flags[$b->id], $expired->id => $flags[$expired->id]]);

        // A stale (expired) default flags nothing.
        Client::whereKey($this->client->id)->update(['default_contract_id' => $expired->id]);
        $r = $this->callTool(['list_client_contracts'], 'list_client_contracts', ['client_id' => $this->client->id]);
        $this->assertSame([false, false, false], array_values(array_column($r['contracts'], 'is_default')));
    }

    // ── C5: the portal create_ticket has no contract_id ─────────────────────

    public function test_c5_portal_create_ticket_schema_has_no_contract_id(): void
    {
        $tool = collect(PortalMcpToolDefinitions::tools())->firstWhere('name', 'create_ticket');
        $this->assertNotNull($tool);
        $this->assertArrayNotHasKey('contract_id', $tool['input_schema']['properties']);
        $this->assertStringNotContainsString('contract', json_encode($tool));
    }

    public function test_portal_mcp_ignores_a_smuggled_contract_id(): void
    {
        $default = $this->contract('Synthetic Default');
        $smuggled = $this->contract('Synthetic Smuggled');
        Client::whereKey($this->client->id)->update(['default_contract_id' => $default->id]);
        $person = \App\Models\Person::create([
            'client_id' => $this->client->id, 'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Synthetic', 'last_name' => 'Portal', 'email' => 'portal@example.test',
        ]);
        $executor = new \App\Services\Mcp\PortalMcpToolExecutor($person);

        foreach ([[], ['contract_id' => $smuggled->id]] as $extra) {
            $result = $executor->execute('create_ticket', $extra + [
                'subject' => 'Synthetic portal request', 'body' => 'Synthetic portal body',
            ]);
            $this->assertTrue($result['success'] ?? false, json_encode($result));
            $this->assertSame($default->id, Ticket::findOrFail($result['ticket_id'])->contract_id);
        }
        $this->assertSame(2, Ticket::count());
    }

    public function test_portal_web_ignores_a_smuggled_contract_id(): void
    {
        Setting::setValue('portal_enabled', '1');
        $default = $this->contract('Synthetic Default');
        $smuggled = $this->contract('Synthetic Smuggled');
        Client::whereKey($this->client->id)->update(['default_contract_id' => $default->id]);
        $person = \App\Models\Person::create([
            'client_id' => $this->client->id, 'is_active' => true, 'portal_enabled' => true,
            'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Synthetic', 'last_name' => 'Portal', 'email' => 'portal@example.test',
        ]);

        foreach ([[], ['contract_id' => $smuggled->id]] as $extra) {
            $this->actingAs($person, 'portal')->post(route('portal.tickets.store'), $extra + [
                'subject' => 'Synthetic portal request', 'description' => 'Synthetic portal body',
            ])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame($default->id, Ticket::latest('id')->firstOrFail()->contract_id);
        }
        $this->assertSame(2, Ticket::count());
    }

    // ── C6: the description line on ticket-creating tools found by the sweep ─

    /**
     * Probe staff general, client-scoped, PSA-action and intake definitions
     * (including internal staged variants), and assistant definitions, not portal tools.
     * Select by observed Ticket inserts, not tool name, using synthetic payloads.
     * Each probe's rows are rolled back on the default connection. Jobs are faked,
     * so tickets created inside queued jobs would not be observed.
     * This probes these payloads, not every possible branch of every executor.
     *
     * @return array<string, array<string, mixed>>
     */
    private function creatingSchemas(): array
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        // The sweep makes one HTTP call per staff definition and outgrows the production 120/min limit, which this test does not measure.
        $tools = [];
        // Use the live schema assembly, not the grant catalog's descriptions.
        $staff = array_merge(
            \App\Support\McpToolSurface::liveGeneralToolDefinitions(),
            \App\Support\McpToolSurface::liveClientScopedToolDefinitions(),
            McpToolRegistry::psaActionTools(),
            McpToolRegistry::intakeManageTools(),
        );
        foreach ($staff as $tool) {
            $tools['staff:'.$tool['name']] = $tool;
        }
        foreach (AssistantToolDefinitions::getTools(true) as $tool) {
            $tools['assistant:'.$tool['name']] = $tool;
        }

        $creating = [];
        $actor = User::factory()->create(['name' => 'Synthetic Sweep']);
        foreach ($tools as $key => $tool) {
            \Illuminate\Support\Facades\DB::beginTransaction();
            try {
                $email = $this->email();
                $call = $this->phoneCall();
                $schema = $tool['input_schema'];
                $values = $this->createArgs([
                    'email_id' => $email->id, 'phone_call_id' => $call->id,
                ]);
                $input = array_intersect_key($values, (array) $schema['properties']);
                foreach ($schema['required'] ?? [] as $field) {
                    $property = $schema['properties'][$field];
                    $input[$field] = $values[$field] ?? ($property['enum'][0] ?? match ($property['type'] ?? 'string') {
                        'integer', 'number' => 1,
                        'boolean' => false,
                        'array' => [],
                        'object' => new \stdClass,
                        default => 'Synthetic sweep',
                    });
                }
                $before = Ticket::withTrashed()->count();
                if (str_starts_with($key, 'staff:')) {
                    $this->callTool([$tool['name']], $tool['name'], $input + ['client_id' => $this->client->id]);
                } else {
                    (new AssistantToolExecutor(clientId: $this->client->id, userId: $actor->id))
                        ->execute($tool['name'], $input);
                }
                if (Ticket::withTrashed()->count() > $before) {
                    $creating[$key] = $tool;
                }
            } finally {
                \Illuminate\Support\Facades\DB::rollBack();
            }
        }

        return $creating;
    }

    public function test_c6_ticket_creating_tools_found_by_the_sweep_carry_the_contract_line(): void
    {
        $schemas = $this->creatingSchemas();
        $this->assertEqualsCanonicalizing(
            ['staff:create_ticket', 'staff:create_ticket_from_email', 'staff:create_ticket_from_call', 'assistant:create_ticket'],
            array_keys($schemas),
        );

        foreach ($schemas as $key => $tool) {
            $schema = $tool['input_schema'] ?? $tool['inputSchema'] ?? [];
            $this->assertArrayHasKey('contract_id', $schema['properties'] ?? [], $key);
            $this->assertSame(['integer', 'null'], $schema['properties']['contract_id']['type'], $key);
            $expected = $key === 'assistant:create_ticket' ? explode(' When the call returns an existing ticket', self::LINE)[0] : self::LINE;
            $this->assertSame($expected, $schema['properties']['contract_id']['description'], $key);
            $this->assertNotContains('contract_id', $schema['required'] ?? [], $key);
        }
    }

    public function test_c6_the_staff_tools_list_publishes_the_line(): void
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['create_ticket', 'create_ticket_from_email', 'create_ticket_from_call']);
        $tools = collect($this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()->json('result.tools'))->keyBy('name');

        foreach (['create_ticket', 'create_ticket_from_email', 'create_ticket_from_call'] as $name) {
            $this->assertSame(self::LINE, $tools[$name]['inputSchema']['properties']['contract_id']['description'] ?? null, $name);
        }
    }
}
