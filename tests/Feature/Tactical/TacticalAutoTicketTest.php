<?php

namespace Tests\Feature\Tactical;

use App\Enums\AlertStatus;
use App\Enums\TicketSource;
use App\Models\Alert;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\TacticalAlertService;
use App\Support\TriageConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TDD: Opt-in auto-ticket wiring in TacticalAlertService (P7 Task 3 / G6).
 *
 * G6 gates (ALL must hold):
 *   - TacticalConfig::autoTicket() is ON (default OFF)
 *   - severity level ≥ level of TacticalConfig::autoTicketMinSeverity() (default 'error')
 *   - !$alert->ticket_id  (no second ticket on re-fire)
 *   - created_by == TriageConfig::systemUserId()
 *
 * Burst guard (bound the flood):
 *   - if ≥ BURST_CAP auto-created Alert-source tickets for a client within BURST_WINDOW_MINUTES,
 *     stop creating normal tickets and ensure exactly ONE "alert storm" ticket is open for the client.
 */
class TacticalAutoTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed a user so TriageConfig::systemUserId() (falls back to first user) is never null
        User::factory()->create();
    }

    private function fixture(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/tactical/alert_failure.json')),
            true
        );
    }

    private function service(): TacticalAlertService
    {
        return app(TacticalAlertService::class);
    }

    /**
     * Set up a client with the given name so the service can resolve client_id.
     * The alert_failure fixture has no agent_id that maps to an asset, so client_id
     * will always be null unless we plant a different payload. We test ticketing
     * against null client_id which is fine — createTicket() accepts null client_id.
     */
    private function enableAutoTicket(string $minSeverity = 'error'): void
    {
        Setting::setValue('tactical_auto_ticket', '1');
        Setting::setValue('tactical_auto_ticket_min_severity', $minSeverity);
    }

    // ── (a) OFF by default → no ticket even above threshold ─────────────────

    public function test_auto_ticket_off_by_default_creates_no_ticket_even_for_error_alert(): void
    {
        // Explicitly do NOT call enableAutoTicket() — verify the default is OFF.
        $payload = $this->fixture(); // severity: error — would qualify if ON

        $alert = $this->service()->handleAlertFailure($payload);

        $this->assertNotNull($alert, 'Alert should still be upserted');
        $this->assertNull($alert->ticket_id, 'No ticket should be created when auto_ticket is OFF');
        $this->assertDatabaseCount('tickets', 0);
    }

    // ── (b) ON + severity ≥ threshold → ticket created with correct created_by ─

    public function test_auto_ticket_on_creates_ticket_for_error_alert_with_system_user(): void
    {
        $this->enableAutoTicket('error'); // threshold = error
        $payload = $this->fixture();      // severity: error — qualifies

        $alert = $this->service()->handleAlertFailure($payload);

        $this->assertNotNull($alert);
        $this->assertNotNull($alert->ticket_id, 'Ticket should be created');
        $this->assertSame(AlertStatus::Ticketed, $alert->status);

        $ticket = $alert->ticket;
        $this->assertNotNull($ticket);
        $this->assertSame(TicketSource::Alert, $ticket->source);

        $expectedUserId = TriageConfig::systemUserId();
        $this->assertNotNull($expectedUserId, 'systemUserId must not be null for this test');
        $this->assertSame($expectedUserId, $ticket->created_by, 'Ticket created_by must be systemUserId');
    }

    // ── (c) ON + severity below threshold → no ticket ───────────────────────

    public function test_auto_ticket_on_creates_no_ticket_when_severity_below_threshold(): void
    {
        $this->enableAutoTicket('error'); // threshold = error
        $payload = $this->fixture();
        $payload['severity'] = 'warning'; // warning < error threshold

        $alert = $this->service()->handleAlertFailure($payload);

        // Alert must still be upserted (it passes the inbound warning gate,
        // since alertMinSeverity defaults to 'warning').
        $this->assertNotNull($alert);
        $this->assertNull($alert->ticket_id, 'No ticket for severity below auto-ticket threshold');
        $this->assertDatabaseCount('tickets', 0);
    }

    // ── (d) ON + re-fired/already-ticketed alert → no second ticket ─────────

    public function test_auto_ticket_on_does_not_create_second_ticket_for_refired_alert(): void
    {
        $this->enableAutoTicket('error');
        $payload = $this->fixture();

        // First fire — creates ticket
        $alert = $this->service()->handleAlertFailure($payload);
        $this->assertNotNull($alert->ticket_id, 'First fire should create ticket');
        $firstTicketId = $alert->ticket_id;

        $ticketCountAfterFirst = Ticket::count();

        // Re-fire (same alert_id, same key) — upsert updates existing alert
        $this->service()->handleAlertFailure($payload);

        // No second ticket should have been created
        $this->assertSame($ticketCountAfterFirst, Ticket::count(), 'Re-fire must not create a second ticket');
        $this->assertSame($firstTicketId, $alert->fresh()->ticket_id, 'Ticket ID must not change on re-fire');
    }

    // ── (e) Burst guard: >cap auto-tickets → stop normal, ensure ONE storm ticket ─

    public function test_burst_guard_stops_normal_tickets_at_cap_and_ensures_single_storm_ticket(): void
    {
        $this->enableAutoTicket('error');
        $payload = $this->fixture();

        $cap = \App\Services\Tactical\TacticalAlertService::BURST_CAP;

        // Fire $cap distinct alerts for the same client (null client_id here —
        // the burst guard keys on client_id which may be null, treated as a single bucket).
        // Use distinct alert_id values so each upsert creates a fresh alert.
        for ($i = 1; $i <= $cap; $i++) {
            $p = $payload;
            $p['alert_id'] = 90000 + $i;
            $p['check_name'] = "Check-{$i}";
            $this->service()->handleAlertFailure($p);
        }

        // All $cap alerts should have tickets (we haven't exceeded yet)
        $this->assertSame($cap, Ticket::where('source', TicketSource::Alert->value)->count(),
            "Should have exactly {$cap} alert tickets at the cap");

        // Now fire the (cap+1)th alert — should NOT create a normal ticket, should create/reuse storm ticket
        $stormPayload = $payload;
        $stormPayload['alert_id'] = 99999;
        $stormPayload['check_name'] = 'Storm-Trigger';
        $stormAlert = $this->service()->handleAlertFailure($stormPayload);

        // The (cap+1)th alert: no ticket linked on the alert itself
        $this->assertNull($stormAlert->ticket_id, 'Over-cap alert must NOT get a normal ticket');

        // Exactly ONE storm ticket exists for the client (subject contains "alert storm")
        $stormTickets = Ticket::where('subject', 'like', '%alert storm%')
            ->orWhere('subject', 'like', '%Alert Storm%')
            ->get();
        $this->assertCount(1, $stormTickets, 'Exactly one storm ticket should exist after burst cap exceeded');

        // Fire another alert over the cap — storm ticket must NOT be duplicated
        $stormPayload2 = $payload;
        $stormPayload2['alert_id'] = 99998;
        $stormPayload2['check_name'] = 'Storm-Trigger-2';
        $this->service()->handleAlertFailure($stormPayload2);

        $stormTicketsAfter = Ticket::where('subject', 'like', '%alert storm%')
            ->orWhere('subject', 'like', '%Alert Storm%')
            ->get();
        $this->assertCount(1, $stormTicketsAfter, 'Storm ticket must not be duplicated on subsequent over-cap alerts');
    }
}
