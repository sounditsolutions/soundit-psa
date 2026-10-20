<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Card 6ad7c912: prepay:unset-call-billable-report counts linked completed calls left
 * is_billable=null, per client and stamped contract, writes nothing and prints no caller
 * numbers or names. Synthetic data only (G-13).
 */
class PhoneCallsUnsetBillableReportTest extends TestCase
{
    use RefreshDatabase;

    private const CALLER = '+15555550177';

    private const CALLER_NAME = 'Synthetic Callername';

    private Client $clientA;

    private Client $clientB;

    private Contract $contractA;

    private Ticket $ticketA;

    private Ticket $ticketB;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();

        $this->clientA = Client::create(['name' => 'Synthetic Report Client A']);
        $this->clientB = Client::create(['name' => 'Synthetic Report Client B']);
        $this->contractA = Contract::create([
            'client_id' => $this->clientA->id, 'name' => 'Synthetic Prepay RA',
            'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01',
            'prepay_as_amount' => false, 'prepay_total' => 10, 'prepay_used' => 0, 'prepay_balance' => 10,
        ]);
        $this->ticketA = Ticket::factory()->create(['client_id' => $this->clientA->id, 'subject' => 'Synthetic RA']);
        $this->ticketB = Ticket::factory()->create(['client_id' => $this->clientB->id, 'subject' => 'Synthetic RB']);
    }

    private function row(array $attrs): int
    {
        return PhoneCall::withoutEvents(fn () => PhoneCall::forceCreate(array_merge([
            'call_uuid' => 'synthetic-'.uniqid(), 'direction' => 'inbound', 'from_number' => self::CALLER,
            'caller_identified_name' => self::CALLER_NAME, 'status' => 'completed', 'is_billable' => null,
            'duration' => 1800, 'started_at' => now()->subDays(3),
        ], $attrs)))->id;
    }

    /** @return array<string, int> */
    private function seedCalls(): array
    {
        $old = now()->subDays(3);
        $ids = [
            'a_stamped_1' => $this->row(['ticket_id' => $this->ticketA->id, 'contract_id' => $this->contractA->id, 'duration' => 1800]),
            'a_stamped_2' => $this->row(['ticket_id' => $this->ticketA->id, 'contract_id' => $this->contractA->id, 'duration' => null, 'recording_duration' => 900]),
            'a_unstamped' => $this->row(['ticket_id' => $this->ticketA->id, 'duration' => 3600]),
            'b_unstamped' => $this->row(['ticket_id' => $this->ticketB->id, 'duration' => 360]),
            // Excluded rows: recent, unlinked, voicemail, already set.
            'recent' => $this->row(['ticket_id' => $this->ticketA->id, 'created_at' => now()->subHour()]),
            'unlinked' => $this->row(['ticket_id' => null]),
            'voicemail' => $this->row(['ticket_id' => $this->ticketA->id, 'status' => 'voicemail', 'recording_duration' => 60]),
            'set' => $this->row(['ticket_id' => $this->ticketA->id, 'is_billable' => true]),
        ];
        foreach ($ids as $name => $id) {
            if ($name !== 'recent') {
                DB::table('phone_calls')->where('id', $id)->update(['created_at' => $old]);
            }
        }

        return $ids;
    }

    private function runReport(array $args = []): string
    {
        $rc = Artisan::call('prepay:unset-call-billable-report', $args);
        $this->assertSame(0, $rc);

        return Artisan::output();
    }

    public function test_report_counts_per_client_and_contract_and_totals(): void
    {
        $this->seedCalls();
        $out = $this->runReport();

        $a = $this->clientA->id;
        $b = $this->clientB->id;
        $c = $this->contractA->id;
        $this->assertMatchesRegularExpression("/\\|\\s*{$a}\\s*\\|\\s*{$c}\\s*\\|\\s*2\\s*\\|\\s*0\\.75\\s*\\|/", $out);
        $this->assertMatchesRegularExpression("/\\|\\s*{$a}\\s*\\|\\s*unstamped\\s*\\|\\s*1\\s*\\|\\s*1\\.00\\s*\\|/", $out);
        $this->assertMatchesRegularExpression("/\\|\\s*{$b}\\s*\\|\\s*unstamped\\s*\\|\\s*1\\s*\\|\\s*0\\.10\\s*\\|/", $out);
        $this->assertStringContainsString('TOTAL calls=4 effective_hours=1.85', $out);
    }

    public function test_older_than_and_client_options_narrow_the_set(): void
    {
        $this->seedCalls();

        $this->assertStringContainsString('TOTAL calls=5 effective_hours=2.35', $this->runReport(['--older-than' => 0]));
        $this->assertStringContainsString('TOTAL calls=1 effective_hours=0.10', $this->runReport(['--client' => $this->clientB->id]));
        $this->assertStringContainsString('TOTAL calls=0 effective_hours=0.00', $this->runReport(['--older-than' => 24 * 30]));
    }

    public function test_report_writes_nothing_and_prints_no_caller_identity(): void
    {
        $ids = $this->seedCalls();
        $tables = ['phone_calls', 'prepay_transactions', 'contracts', 'tickets'];
        $before = [];
        foreach ($tables as $t) {
            $before[$t] = DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        $out = $this->runReport(['--older-than' => 0]);

        foreach ($tables as $t) {
            $this->assertSame($before[$t], DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), $t);
        }
        $this->assertSame(0, DB::table('prepay_transactions')->count());
        foreach (['a_stamped_1', 'a_stamped_2', 'a_unstamped', 'b_unstamped', 'recent'] as $name) {
            $this->assertNull(DB::table('phone_calls')->where('id', $ids[$name])->value('is_billable'));
        }
        $this->assertStringNotContainsString(self::CALLER, $out);
        $this->assertStringNotContainsString('5555550177', $out);
        $this->assertStringNotContainsString(self::CALLER_NAME, $out);
        $this->assertStringNotContainsString('Synthetic Report Client', $out);
    }
}
