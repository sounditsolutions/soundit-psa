<?php

namespace Tests\Feature\ClientReport;

use App\Enums\PrepayTransactionSource;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Services\ClientReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClientReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $weekStart;

    protected function setUp(): void
    {
        parent::setUp();
        // A fixed, deterministic week (normalised to its Monday start).
        $this->weekStart = Carbon::parse('2026-03-04')->startOfWeek();
    }

    private function service(): ClientReportService
    {
        return app(ClientReportService::class);
    }

    private function weekEnd(): Carbon
    {
        return $this->weekStart->copy()->endOfWeek();
    }

    public function test_gathers_closed_tickets_within_the_week_with_metrics(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $start = $this->weekStart;

        // In-week #1: response 30m, resolution 24h, SLA met.
        Ticket::factory()->create([
            'client_id' => $client->id,
            'subject' => 'Printer offline',
            'category' => 'Hardware',
            'priority' => TicketPriority::P2->value,
            'status' => TicketStatus::Resolved->value,
            'opened_at' => $start->copy()->addDay()->setTime(9, 0),
            'responded_at' => $start->copy()->addDay()->setTime(9, 30),
            'resolved_at' => $start->copy()->addDays(2)->setTime(9, 0),
            'due_at' => $start->copy()->addDays(3),
        ]);

        // In-week #2: same theme, response 120m, resolution 240m, SLA breached.
        Ticket::factory()->create([
            'client_id' => $client->id,
            'subject' => 'Laptop will not boot',
            'category' => 'Hardware',
            'priority' => TicketPriority::P3->value,
            'status' => TicketStatus::Closed->value,
            'opened_at' => $start->copy()->addDays(2)->setTime(8, 0),
            'responded_at' => $start->copy()->addDays(2)->setTime(10, 0),
            'resolved_at' => $start->copy()->addDays(2)->setTime(12, 0),
            'due_at' => $start->copy()->addDays(2)->setTime(10, 0),
        ]);

        // Currently-open ticket, opened this week.
        Ticket::factory()->create([
            'client_id' => $client->id,
            'subject' => 'New request',
            'status' => TicketStatus::New->value,
            'opened_at' => $start->copy()->addDay()->setTime(11, 0),
            'resolved_at' => null,
        ]);

        // Resolved before the window → excluded from closed metrics.
        Ticket::factory()->create([
            'client_id' => $client->id,
            'status' => TicketStatus::Closed->value,
            'opened_at' => $start->copy()->subDays(5),
            'resolved_at' => $start->copy()->subDays(2),
        ]);

        // Different client, resolved in-window → excluded by scoping.
        $other = Client::create(['name' => 'Other Inc']);
        Ticket::factory()->create([
            'client_id' => $other->id,
            'status' => TicketStatus::Closed->value,
            'opened_at' => $start->copy()->addDay(),
            'resolved_at' => $start->copy()->addDay(),
        ]);

        $data = $this->service()->gatherData($client, $start, $this->weekEnd());

        $this->assertSame(2, $data['closed_count']);
        $this->assertSame(3, $data['opened_count']);   // two closed + the open one
        $this->assertSame(1, $data['currently_open']);
        $this->assertSame(2, $data['themes']['Hardware']);
        $this->assertSame(75, $data['avg_response_mins']);      // (30 + 120) / 2
        $this->assertSame(840, $data['avg_resolution_mins']);   // (1440 + 240) / 2
        $this->assertSame(2, $data['sla_tracked']);
        $this->assertSame(1, $data['sla_met']);
    }

    public function test_uncategorized_tickets_group_under_a_placeholder_theme(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $start = $this->weekStart;

        Ticket::factory()->create([
            'client_id' => $client->id,
            'category' => null,
            'status' => TicketStatus::Resolved->value,
            'opened_at' => $start->copy()->addDay(),
            'resolved_at' => $start->copy()->addDay()->addHour(),
        ]);

        $data = $this->service()->gatherData($client, $start, $this->weekEnd());

        $this->assertSame(1, $data['themes']['Uncategorized']);
    }

    public function test_prepay_burn_aggregates_consumption_ledger_within_week(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $start = $this->weekStart;

        $contract = Contract::create([
            'client_id' => $client->id,
            'name' => 'Managed MSA',
            'type' => 'managed',
            'status' => 'active',
            'start_date' => '2026-01-01',
            'prepay_as_amount' => false,
            'prepay_total' => 40,
            'prepay_used' => 8,
            'prepay_balance' => 32,
        ]);

        // In-week consumption (stored negative): -2.0 and -1.5 → burn 3.5.
        PrepayTransaction::create([
            'contract_id' => $contract->id,
            'source' => PrepayTransactionSource::TicketTime,
            'date' => $start->copy()->addDay(),
            'hours' => -2.0,
            'amount' => 0,
            'description' => 'ticket work',
        ]);
        PrepayTransaction::create([
            'contract_id' => $contract->id,
            'source' => PrepayTransactionSource::PhoneCallTime,
            'date' => $start->copy()->addDays(3),
            'hours' => -1.5,
            'amount' => 0,
            'description' => 'support call',
        ]);
        // Out-of-week consumption → excluded.
        PrepayTransaction::create([
            'contract_id' => $contract->id,
            'source' => PrepayTransactionSource::TicketTime,
            'date' => $start->copy()->subDays(3),
            'hours' => -5.0,
            'amount' => 0,
            'description' => 'prior week',
        ]);
        // In-week credit → excluded by the consumption() scope.
        PrepayTransaction::create([
            'contract_id' => $contract->id,
            'source' => PrepayTransactionSource::ManualCredit,
            'date' => $start->copy()->addDay(),
            'hours' => 10.0,
            'amount' => 0,
            'description' => 'top-up',
        ]);

        $data = $this->service()->gatherData($client, $start, $this->weekEnd());

        $this->assertCount(1, $data['prepay']);
        $this->assertSame('Managed MSA', $data['prepay'][0]['contract']);
        $this->assertSame(3.5, $data['prepay'][0]['burn']);
        $this->assertSame(32.0, $data['prepay'][0]['balance']);
        $this->assertSame(3.5, $data['total_burn_hours']);
    }

    public function test_contracts_without_prepay_tracking_are_skipped(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);

        // Bare contract: prepay_balance left null → not a prepay contract.
        Contract::create([
            'client_id' => $client->id,
            'name' => 'Break-Fix',
            'type' => 'breakfix',
            'status' => 'active',
            'start_date' => '2026-01-01',
        ]);

        $data = $this->service()->gatherData($client, $this->weekStart, $this->weekEnd());

        $this->assertSame([], $data['prepay']);
        $this->assertSame(0.0, $data['total_burn_hours']);
    }

    public function test_licenses_include_utilization_and_exclude_inactive(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $type = LicenseType::create([
            'name' => 'Microsoft 365 Business Premium',
            'vendor' => 'cipp_m365',
            'is_active' => true,
        ]);

        License::create([
            'license_type_id' => $type->id,
            'client_id' => $client->id,
            'quantity' => 20,
            'assigned_quantity' => 14,
            'status' => 'active',
        ]);
        // Suspended → excluded by the active() scope.
        License::create([
            'license_type_id' => $type->id,
            'client_id' => $client->id,
            'quantity' => 5,
            'assigned_quantity' => 5,
            'status' => 'suspended',
        ]);

        $data = $this->service()->gatherData($client, $this->weekStart, $this->weekEnd());

        $this->assertCount(1, $data['licenses']);
        $this->assertSame('Microsoft 365 Business Premium', $data['licenses'][0]['name']);
        $this->assertSame(14, $data['licenses'][0]['assigned']);
        $this->assertSame(6, $data['licenses'][0]['unassigned']);
        $this->assertSame(70.0, $data['licenses'][0]['utilization']);
    }

    public function test_builds_markdown_document_with_all_sections(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);
        $start = $this->weekStart;

        Ticket::factory()->create([
            'client_id' => $client->id,
            'subject' => 'VPN tunnel down',
            'category' => 'Network',
            'priority' => TicketPriority::P1->value,
            'status' => TicketStatus::Resolved->value,
            'opened_at' => $start->copy()->addDay(),
            'responded_at' => $start->copy()->addDay()->addMinutes(15),
            'resolved_at' => $start->copy()->addDay()->addHours(2),
            'due_at' => $start->copy()->addDays(2),
        ]);

        $report = $this->service()->weeklyReport($client, $start);
        $md = $report['markdown'];

        $this->assertStringContainsString('# Weekly Service Report — Acme Corp', $md);
        $this->assertStringContainsString('## Summary', $md);
        $this->assertStringContainsString('## Recurring Themes', $md);
        $this->assertStringContainsString('## Tickets Resolved', $md);
        $this->assertStringContainsString('VPN tunnel down', $md);
        $this->assertStringContainsString('## Contract Usage', $md);
        $this->assertStringContainsString('### License Assignment', $md);
        $this->assertStringContainsString('## Recommendations', $md);
    }

    public function test_ai_recommendations_absent_when_ai_not_configured(): void
    {
        $client = Client::create(['name' => 'Acme Corp']);

        $report = $this->service()->weeklyReport($client, $this->weekStart);

        // No AI key configured in the test environment → graceful fallback.
        $this->assertNull($report['recommendations']);
        $this->assertStringContainsString('AI recommendations are unavailable', $report['markdown']);
    }

    public function test_humanize_minutes_formats_durations(): void
    {
        $this->assertSame('—', ClientReportService::humanizeMinutes(null));
        $this->assertSame('0m', ClientReportService::humanizeMinutes(0));
        $this->assertSame('45m', ClientReportService::humanizeMinutes(45));
        $this->assertSame('1h', ClientReportService::humanizeMinutes(60));
        $this->assertSame('2h 30m', ClientReportService::humanizeMinutes(150));
        $this->assertSame('1d', ClientReportService::humanizeMinutes(1440));
        $this->assertSame('1d 1h', ClientReportService::humanizeMinutes(1500));
    }
}
