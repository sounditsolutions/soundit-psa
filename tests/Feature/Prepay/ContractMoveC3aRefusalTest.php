<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\TimeEntryMoveProposal;
use App\Models\User;
use App\Services\TimeEntryContractMoveService;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractMoveC3aRefusalTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private Contract $a;

    private Contract $b;

    private User $user;

    private TicketNote $note;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $this->user->id);
        $client = Client::create(['name' => 'Synthetic C3a']);
        foreach (['a', 'b'] as $key) {
            $this->$key = Contract::create(['client_id' => $client->id, 'name' => 'Synthetic '.$key, 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01']);
        }
        $this->ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $this->a->id]);
        $this->note = TicketNote::withoutEvents(fn () => TicketNote::forceCreate(['id' => 100, 'ticket_id' => $this->ticket->id, 'contract_id' => $this->a->id, 'body' => 'Synthetic time', 'note_type' => 'note', 'is_billable' => true, 'time_minutes' => 30]));
    }

    private function move(bool $staged = true): array
    {
        $token = McpConfig::rotateStaffToken(allowedTools: ['move_time_entry_contract'.($staged ? '' : ':immediate')]);
        $r = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $staged ? 'stage_move_time_entry_contract' : 'move_time_entry_contract', 'arguments' => ['entry_type' => 'note', 'entry_id' => $this->note->id, 'contract_id' => $this->b->id, 'reason' => 'Synthetic correction']]]);
        $r->assertOk();

        return json_decode($r->json('result.content.0.text'), true);
    }

    private function unchanged(int $count = 0): void
    {
        $this->assertSame($this->a->id, $this->note->fresh()->contract_id);
        $this->assertDatabaseCount('time_entry_move_proposals', $count);
    }

    public function test_web_pending_entry_is_refused(): void
    {
        $this->assertTrue($this->move()['staged']);
        $r = app(TimeEntryContractMoveService::class)->changeTicketContract($this->ticket, $this->b->id, [['type' => 'note', 'id' => $this->note->id]], 'Synthetic', $this->user);
        $this->assertSame(['A staged contract move on this entry is awaiting approval.'], $r['errors']);
        $this->assertSame(0, $r['moved']);
        $this->unchanged(1);
    }

    public function test_web_unblocked_entry_moves(): void
    {
        $r = app(TimeEntryContractMoveService::class)->changeTicketContract($this->ticket, $this->b->id, [['type' => 'note', 'id' => $this->note->id]], 'Synthetic', $this->user);
        $this->assertSame([], $r['errors']);
        $this->assertSame(1, $r['moved']);
        $this->assertSame($this->b->id, $this->note->fresh()->contract_id);
    }

    public function test_mcp_pending_entry_is_refused(): void
    {
        $this->assertTrue($this->move()['staged']);
        foreach ([true, false] as $staged) {
            $r = $this->move($staged);
            $this->assertSame('A staged contract move on this entry is awaiting approval.', $r['error'] ?? null);
            $this->assertArrayNotHasKey('error_code', $r);
            $this->unchanged(1);
        }
    }

    public function test_other_entry_and_denied_proposals_do_not_block(): void
    {
        $this->assertTrue($this->move()['staged']);
        $first = $this->note;
        $this->note = TicketNote::withoutEvents(fn () => $first->replicate());
        $this->note->saveQuietly();
        $this->assertTrue($this->move()['staged']);
        TimeEntryMoveProposal::query()->update(['state' => 'denied']);
        $this->assertTrue($this->move(false)['success']);
        $this->assertSame($this->b->id, $this->note->fresh()->contract_id);
    }

    public function test_system_note_is_refused(): void
    {
        $this->note->updateQuietly(['note_type' => 'system']);
        $r = $this->move();
        $this->assertSame('System notes cannot be moved.', $r['error'] ?? null);
        $this->assertArrayNotHasKey('error_code', $r);
        $this->unchanged();
    }

    public function test_zero_priced_minutes_is_refused(): void
    {
        $this->note->forceFill(['time_adjustment_minutes' => -30])->saveQuietly();
        $r = $this->move();
        $this->assertSame('This note has no billed minutes to move.', $r['error'] ?? null);
        $this->assertArrayNotHasKey('error_code', $r);
        $this->unchanged();
    }

    public function test_non_billable_note_is_refused(): void
    {
        $this->note->updateQuietly(['is_billable' => false]);
        $r = $this->move();
        $this->assertSame('This note is not billable.', $r['error'] ?? null);
        $this->assertArrayNotHasKey('error_code', $r);
        $this->unchanged();
    }

    public function test_positive_adjusted_minutes_are_accepted(): void
    {
        $this->note->forceFill(['time_minutes' => 0, 'time_adjustment_minutes' => 15])->saveQuietly();
        $this->assertTrue($this->move(false)['success'] ?? false);
        $this->assertSame($this->b->id, $this->note->fresh()->contract_id);
    }

    public function test_change_only_inactive_is_refused(): void
    {
        $this->b->update(['status' => 'expired']);
        try {
            app(TimeEntryContractMoveService::class)->changeTicketContract($this->ticket, $this->b->id, [], null, $this->user);
            $this->fail('Expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame("Contract {$this->b->id} is not an ACTIVE contract of this ticket's client.", $e->getMessage());
        }
        $this->assertSame($this->a->id, $this->ticket->fresh()->contract_id);
        $this->unchanged();
    }

    public function test_same_client_trashed_active_without_moves_is_refused(): void
    {
        $this->b->delete();
        $this->assertTrue($this->b->trashed());
        $this->assertSame(\App\Enums\ContractStatus::Active, $this->b->status);
        try {
            app(TimeEntryContractMoveService::class)->changeTicketContract($this->ticket, $this->b->id, [], null, $this->user);
            $this->fail('Expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame("Contract {$this->b->id} is not an ACTIVE contract of this ticket's client.", $e->getMessage());
        }
        $this->assertSame($this->a->id, $this->ticket->fresh()->contract_id);
    }

    public function test_same_client_trashed_active_with_moves_is_refused(): void
    {
        $this->b->delete();
        $this->assertTrue($this->b->trashed());
        $this->assertSame(\App\Enums\ContractStatus::Active, $this->b->status);
        try {
            app(TimeEntryContractMoveService::class)->changeTicketContract($this->ticket, $this->b->id, [['type' => 'note', 'id' => $this->note->id]], 'Synthetic', $this->user);
            $this->fail('Expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Entries can only be moved to an active contract.', $e->getMessage());
        }
        $this->assertSame($this->a->id, $this->ticket->fresh()->contract_id);
    }

    public function test_active_and_null_change_only_are_accepted(): void
    {
        foreach ([$this->b->id, null] as $id) {
            try {
                $r = app(TimeEntryContractMoveService::class)->changeTicketContract($this->ticket->fresh(), $id, [], null, $this->user);
            } catch (\InvalidArgumentException $e) {
                $this->fail($e->getMessage());
            }
            $this->assertSame([], $r['errors']);
            $this->assertSame($id, $this->ticket->fresh()->contract_id);
            $this->unchanged();
        }
    }

    public function test_unchanged_inactive_without_moves_is_a_no_op(): void
    {
        $this->a->update(['status' => 'expired']);
        $before = $this->ticket->fresh()->getAttributes();
        $notes = TicketNote::where('ticket_id', $this->ticket->id)->count();
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        try {
            $result = app(TimeEntryContractMoveService::class)->changeTicketContract($this->ticket, $this->a->id, [], null, $this->user);
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage());
        }
        $this->assertSame(['moved' => 0, 'hours' => 0.0, 'errors' => []], $result);
        $this->assertSame($before, $this->ticket->fresh()->getAttributes());
        $this->assertSame($notes, TicketNote::where('ticket_id', $this->ticket->id)->count());
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => preg_match('/^\s*(update|insert|delete)\b/i', $sql))));
        $this->unchanged();
    }

    public function test_unchanged_inactive_with_moves_is_refused(): void
    {
        $this->a->update(['status' => 'expired']);
        try {
            app(TimeEntryContractMoveService::class)->changeTicketContract($this->ticket, $this->a->id, [['type' => 'note', 'id' => $this->note->id]], 'Synthetic', $this->user);
            $this->fail('Expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Entries can only be moved to an active contract.', $e->getMessage());
        }
        $this->unchanged();
    }

    public function test_foreign_active_contract_is_refused_for_client_ownership(): void
    {
        $other = Client::create(['name' => 'Synthetic other']);
        $foreign = Contract::create(['client_id' => $other->id, 'name' => 'Synthetic foreign', 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01']);
        foreach ([[], [['type' => 'note', 'id' => $this->note->id]]] as $moves) {
            try {
                app(TimeEntryContractMoveService::class)->changeTicketContract($this->ticket, $foreign->id, $moves, 'Synthetic', $this->user);
                $this->fail('Expected refusal');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame("Contract {$foreign->id} is not a contract of this ticket's client.", $e->getMessage());
            }
        }
        $this->assertSame($this->a->id, $this->ticket->fresh()->contract_id);
        $this->unchanged();
    }

    private function selectorOptions(): array
    {
        $response = $this->actingAs($this->user)->get(route('tickets.show', $this->ticket));
        $response->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $options = (new \DOMXPath($dom))->query('//select[@id="ticketContractSelect"]/option');
        $this->assertGreaterThan(0, $options->length);
        $result = [];
        foreach ($options as $option) {
            $result[$option->getAttribute('value')] = [trim($option->textContent), $option->hasAttribute('selected')];
        }

        return $result;
    }

    public function test_selector_keeps_current_inactive_and_filters_other_inactive(): void
    {
        $this->a->update(['status' => 'expired']);
        $other = Contract::create(['client_id' => $this->ticket->client_id, 'name' => 'Synthetic omitted', 'type' => 'managed', 'status' => 'expired', 'start_date' => '2026-01-01']);
        $options = $this->selectorOptions();
        $this->assertSame(['None', false], $options['']);
        $this->assertSame([$this->a->name.' (inactive)', true], $options[$this->a->id] ?? null);
        $this->assertSame([$this->b->name, false], $options[$this->b->id] ?? null);
        $this->assertArrayNotHasKey($other->id, $options);
        $this->assertCount(3, $options);
    }

    public function test_selector_active_current_has_no_suffix(): void
    {
        $options = $this->selectorOptions();
        $this->assertSame([$this->a->name, true], $options[$this->a->id] ?? null);
        $this->assertSame([$this->b->name, false], $options[$this->b->id] ?? null);
        $this->assertCount(3, $options);
    }

    public function test_modal_prepay_data_keeps_inactive_contract_flagged_not_active(): void
    {
        $this->a->update(['prepay_total' => 10, 'prepay_balance' => 10]);
        $this->b->update(['status' => 'expired', 'prepay_total' => 10, 'prepay_balance' => 10]);
        $html = view('tickets._contract-change-modal', ['ticket' => $this->ticket->fresh(), 'contractEntries' => collect()])->render();
        preg_match("/data-prepay='([^']*)'/", $html, $match);
        $data = json_decode(html_entity_decode($match[1]), true);
        $this->assertTrue($data[$this->a->id]['active'] ?? null);
        $this->assertFalse($data[$this->b->id]['active'] ?? null);
        $this->assertSame($this->b->name, $data[$this->b->id]['name'] ?? null);
    }
}
