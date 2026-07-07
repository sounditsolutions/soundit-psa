<?php

namespace Tests\Feature\Huntress;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\Alert;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Huntress\HuntressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization tests for the EXISTING Huntress CW-Manage webhook resolve path
 * (HuntressService::updateTicketFromCw). Huntress fires this when a remediation is
 * approved/rejected. Locked here so the reconcile work (bd psa-kq1u) keeps BOTH resolve
 * paths — webhook AND poll — working and regression-proof.
 */
class HuntressWebhookResolveTest extends TestCase
{
    use RefreshDatabase;

    private User $systemUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->systemUser = User::factory()->create();
        Setting::setValue('huntress_system_user_id', (string) $this->systemUser->id);
    }

    private function service(): HuntressService
    {
        return app(HuntressService::class);
    }

    private function openHuntressTicketWithAlert(): Ticket
    {
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Huntress->value,
            'status' => TicketStatus::InProgress->value,
            'closed_at' => null,
        ]);

        Alert::create([
            'source' => AlertSource::Huntress->value,
            'source_alert_id' => 'https://dashboard.huntress.io/org/42/infection_reports/900',
            'severity' => 'critical',
            'status' => AlertStatus::Ticketed->value,
            'title' => 'Huntress incident',
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'fired_at' => now()->subHour(),
        ]);

        return $ticket;
    }

    public function test_json_patch_resolved_status_resolves_ticket_and_alert(): void
    {
        $ticket = $this->openHuntressTicketWithAlert();

        // Huntress format: [{op:replace, path:status, value:{id,name}}]
        $this->service()->updateTicketFromCw($ticket, [
            ['op' => 'replace', 'path' => 'status', 'value' => ['id' => '5', 'name' => 'Resolved']],
        ]);

        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
        $this->assertSame(AlertStatus::Resolved, Alert::where('ticket_id', $ticket->id)->first()->status);
    }

    public function test_closed_status_is_downgraded_to_resolved_for_human_verification(): void
    {
        $ticket = $this->openHuntressTicketWithAlert();

        // CW status id 6 = Closed → deliberately downgraded to Resolved.
        $this->service()->updateTicketFromCw($ticket, [
            ['op' => 'replace', 'path' => 'status', 'value' => ['id' => '6', 'name' => 'Closed']],
        ]);

        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    public function test_flat_status_format_also_resolves(): void
    {
        $ticket = $this->openHuntressTicketWithAlert();

        $this->service()->updateTicketFromCw($ticket, ['status' => ['id' => 5]]);

        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    public function test_non_huntress_ticket_is_rejected(): void
    {
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Manual->value,
            'status' => TicketStatus::InProgress->value,
            'closed_at' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->updateTicketFromCw($ticket, ['status' => ['id' => 5]]);
    }
}
