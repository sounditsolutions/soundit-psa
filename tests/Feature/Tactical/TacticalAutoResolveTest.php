<?php

namespace Tests\Feature\Tactical;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Alert;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Tactical\TacticalAlertService;
use App\Support\TriageConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TDD: Auto-RESOLVE untouched auto-ticket on Tactical alert resolve (P7 Task 4 / G7).
 *
 * G7 gates (ALL must hold for auto-resolve):
 *   - Alert's linked ticket has source == TicketSource::Alert (auto-created)
 *   - Ticket is still open
 *   - Ticket::isUntouchedByHuman() is true
 *   - Ticket status → Resolved (NOT Closed) with a resolution string
 *   - Shared AlertService::resolve() is NOT modified (Ninja/Comet path unchanged)
 *
 * isUntouchedByHuman() is true iff ALL hold:
 *   - No TicketNote with note_type NOT IN NoteType::systemGenerated()
 *   - No portal reply (who_type == EndUser, author_id null)
 *   - responded_at is null
 *   - status is still New
 */
class TacticalAutoResolveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed a user so TriageConfig::systemUserId() (falls back to first user) is never null
        User::factory()->create();
    }

    private function resolvedFixture(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/tactical/alert_resolved.json')),
            true
        );
    }

    private function service(): TacticalAlertService
    {
        return app(TacticalAlertService::class);
    }

    /**
     * Create a Tactical alert with a linked auto-created ticket (TicketSource::Alert).
     */
    private function makeAlertWithAutoTicket(): Alert
    {
        $systemUserId = TriageConfig::systemUserId();

        $ticket = Ticket::factory()->create([
            'source'       => TicketSource::Alert,
            'status'       => TicketStatus::New,
            'responded_at' => null,
            'created_by'   => $systemUserId,
            'client_id'    => null,
            'resolution'   => null,
        ]);

        $alert = Alert::create([
            'source'          => AlertSource::Tactical,
            'source_alert_id' => '84213',  // matches alert_resolved.json alert_id
            'severity'        => AlertSeverity::Error,
            'status'          => AlertStatus::Ticketed,
            'title'           => 'Disk Space - C:',
            'hostname'        => 'WS-FINANCE-04',
            'fired_at'        => now(),
            'ticket_id'       => $ticket->id,
        ]);

        return $alert->fresh();
    }

    /**
     * Create a Tactical alert with a manually-created ticket (TicketSource::Manual).
     */
    private function makeAlertWithManualTicket(): Alert
    {
        $systemUserId = TriageConfig::systemUserId();

        $ticket = Ticket::factory()->create([
            'source'       => TicketSource::Manual,
            'status'       => TicketStatus::New,
            'responded_at' => null,
            'created_by'   => $systemUserId,
            'client_id'    => null,
            'resolution'   => null,
        ]);

        $alert = Alert::create([
            'source'          => AlertSource::Tactical,
            'source_alert_id' => '84213',
            'severity'        => AlertSeverity::Error,
            'status'          => AlertStatus::Ticketed,
            'title'           => 'Disk Space - C:',
            'hostname'        => 'WS-FINANCE-04',
            'fired_at'        => now(),
            'ticket_id'       => $ticket->id,
        ]);

        return $alert->fresh();
    }

    // ── Ticket::isUntouchedByHuman() ────────────────────────────────────────

    public function test_is_untouched_true_when_ticket_has_only_system_notes(): void
    {
        $systemUserId = TriageConfig::systemUserId();

        $ticket = Ticket::factory()->create([
            'status'       => TicketStatus::New,
            'responded_at' => null,
        ]);

        // System note — NOT human
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::System,
            'author_id' => $systemUserId,
            'who_type'  => WhoType::System,
            'body'      => 'Alert fired.',
            'is_private' => true,
            'noted_at'  => now(),
        ]);

        // AiTriage note — NOT human
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::AiTriage,
            'author_id' => $systemUserId,
            'who_type'  => WhoType::System,
            'body'      => 'AI triage output.',
            'is_private' => true,
            'noted_at'  => now(),
        ]);

        // StatusChange note — NOT human
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::StatusChange,
            'author_id' => $systemUserId,
            'who_type'  => WhoType::System,
            'body'      => 'Status changed.',
            'is_private' => true,
            'noted_at'  => now(),
        ]);

        $this->assertTrue($ticket->isUntouchedByHuman(),
            'System-only notes → ticket should be untouched by human');
    }

    public function test_is_untouched_false_when_ticket_has_human_note(): void
    {
        $user = User::factory()->create();

        $ticket = Ticket::factory()->create([
            'status'       => TicketStatus::New,
            'responded_at' => null,
        ]);

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::Note,
            'author_id' => $user->id,
            'who_type'  => WhoType::Agent,
            'body'      => 'Agent added a note.',
            'is_private' => false,
            'noted_at'  => now(),
        ]);

        $this->assertFalse($ticket->isUntouchedByHuman(),
            'Human Note → ticket should be human-touched');
    }

    public function test_is_untouched_false_when_ticket_has_human_reply(): void
    {
        $user = User::factory()->create();

        $ticket = Ticket::factory()->create([
            'status'       => TicketStatus::New,
            'responded_at' => null,
        ]);

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::Reply,
            'author_id' => $user->id,
            'who_type'  => WhoType::Agent,
            'body'      => 'Agent replied to the client.',
            'is_private' => false,
            'noted_at'  => now(),
        ]);

        $this->assertFalse($ticket->isUntouchedByHuman(),
            'Human Reply → ticket should be human-touched');
    }

    public function test_is_untouched_false_when_ticket_has_portal_reply(): void
    {
        $ticket = Ticket::factory()->create([
            'status'       => TicketStatus::New,
            'responded_at' => null,
        ]);

        // Portal reply: who_type = EndUser, author_id null (end-user with no PSA account)
        TicketNote::create([
            'ticket_id'   => $ticket->id,
            'note_type'   => NoteType::Reply,
            'author_id'   => null,
            'who_type'    => WhoType::EndUser,
            'author_name' => 'John Client',
            'body'        => 'Client replied via portal.',
            'is_private'  => false,
            'noted_at'    => now(),
        ]);

        $this->assertFalse($ticket->isUntouchedByHuman(),
            'Portal reply (EndUser who_type, null author_id) → ticket should be human-touched');
    }

    public function test_is_untouched_false_when_status_is_not_new(): void
    {
        $ticket = Ticket::factory()->create([
            'status'       => TicketStatus::InProgress,
            'responded_at' => null,
        ]);

        $this->assertFalse($ticket->isUntouchedByHuman(),
            'Non-New status → ticket should be human-touched');
    }

    public function test_is_untouched_false_when_responded_at_is_set(): void
    {
        $ticket = Ticket::factory()->create([
            'status'       => TicketStatus::New,
            'responded_at' => now(),
        ]);

        $this->assertFalse($ticket->isUntouchedByHuman(),
            'Non-null responded_at → ticket should be human-touched');
    }

    // ── Auto-resolve: untouched auto-ticket on Tactical alert resolve ────────

    public function test_tactical_alert_resolve_auto_resolves_untouched_auto_ticket(): void
    {
        $alert = $this->makeAlertWithAutoTicket();
        $ticket = $alert->ticket;

        $this->assertSame(TicketStatus::New, $ticket->status, 'Pre-condition: ticket is New');
        $this->assertTrue($ticket->isUntouchedByHuman(), 'Pre-condition: ticket is untouched');

        // Use the fixture; alert_id matches what makeAlertWithAutoTicket() used (84213)
        $payload = $this->resolvedFixture();

        $this->service()->handleAlertResolved($payload);

        $ticket->refresh();

        // Ticket must be RESOLVED, NOT Closed
        $this->assertSame(TicketStatus::Resolved, $ticket->status,
            'Auto-ticket should be Resolved (not Closed) after Tactical alert resolve');
        $this->assertNotSame(TicketStatus::Closed, $ticket->status,
            'Auto-ticket must NOT be Closed directly — only Resolved');

        // Resolution string must be set (so GenerateTicketResolution LLM job does NOT fire)
        $this->assertNotEmpty($ticket->resolution,
            'Resolution string must be set to suppress LLM draft job');

        // The system resolve note must also exist (from AlertService::resolve())
        $resolveNote = $ticket->notes()
            ->where('note_type', NoteType::System->value)
            ->first();
        $this->assertNotNull($resolveNote,
            'System resolve note from AlertService::resolve() must be present');
    }

    public function test_tactical_alert_resolve_does_not_auto_resolve_human_touched_ticket(): void
    {
        $user = User::factory()->create();
        $alert = $this->makeAlertWithAutoTicket();
        $ticket = $alert->ticket;

        // Add a human note to make it human-touched
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::Note,
            'author_id' => $user->id,
            'who_type'  => WhoType::Agent,
            'body'      => 'Agent investigated.',
            'is_private' => false,
            'noted_at'  => now(),
        ]);

        $this->assertFalse($ticket->isUntouchedByHuman(),
            'Pre-condition: ticket is human-touched');

        $payload = $this->resolvedFixture();

        $this->service()->handleAlertResolved($payload);

        $ticket->refresh();

        // Ticket must NOT be auto-resolved; still open
        $this->assertTrue($ticket->isOpen(),
            'Human-touched ticket must NOT be auto-resolved; should remain open');
    }

    public function test_tactical_alert_resolve_does_not_auto_resolve_manually_created_ticket(): void
    {
        $alert = $this->makeAlertWithManualTicket();
        $ticket = $alert->ticket;

        $this->assertSame(TicketStatus::New, $ticket->status,
            'Pre-condition: ticket is New');

        $payload = $this->resolvedFixture();

        $this->service()->handleAlertResolved($payload);

        $ticket->refresh();

        // Manually-created ticket must NOT be auto-resolved
        $this->assertTrue($ticket->isOpen(),
            'Manually-created ticket (TicketSource::Manual) must NOT be auto-resolved');
    }

    public function test_tactical_alert_resolve_does_not_auto_resolve_portal_reply_ticket(): void
    {
        $alert = $this->makeAlertWithAutoTicket();
        $ticket = $alert->ticket;

        // Portal reply makes it human-touched
        TicketNote::create([
            'ticket_id'   => $ticket->id,
            'note_type'   => NoteType::Reply,
            'author_id'   => null,
            'who_type'    => WhoType::EndUser,
            'author_name' => 'Portal User',
            'body'        => 'Client replied via portal.',
            'is_private'  => false,
            'noted_at'    => now(),
        ]);

        $this->assertFalse($ticket->isUntouchedByHuman(),
            'Pre-condition: portal reply makes ticket human-touched');

        $payload = $this->resolvedFixture();

        $this->service()->handleAlertResolved($payload);

        $ticket->refresh();

        $this->assertTrue($ticket->isOpen(),
            'Ticket with portal reply must NOT be auto-resolved');
    }

    // ── Resolve note added regardless (AlertService::resolve() is untouched) ─

    public function test_resolve_note_is_added_to_ticket_regardless_of_auto_resolve(): void
    {
        $user = User::factory()->create();
        $alert = $this->makeAlertWithAutoTicket();
        $ticket = $alert->ticket;

        // Make it human-touched so auto-resolve skips, but the resolve note should still be added
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'note_type' => NoteType::Note,
            'author_id' => $user->id,
            'who_type'  => WhoType::Agent,
            'body'      => 'Agent note.',
            'is_private' => false,
            'noted_at'  => now(),
        ]);

        $noteCountBefore = $ticket->notes()->count();

        $payload = $this->resolvedFixture();

        $this->service()->handleAlertResolved($payload);

        $noteCountAfter = $ticket->notes()->count();

        $this->assertGreaterThan($noteCountBefore, $noteCountAfter,
            'AlertService::resolve() must add a system note to the ticket regardless of auto-resolve');
    }

    // ── Shared AlertService::resolve() is untouched: Ninja path does NOT auto-resolve ─

    public function test_shared_alert_service_resolve_does_not_auto_resolve_ticket(): void
    {
        $systemUserId = TriageConfig::systemUserId();

        // Create an alert from Ninja (not Tactical) with a linked auto-ticket
        $ticket = Ticket::factory()->create([
            'source'       => TicketSource::Alert,
            'status'       => TicketStatus::New,
            'responded_at' => null,
            'created_by'   => $systemUserId,
            'client_id'    => null,
            'resolution'   => null,
        ]);

        $alert = Alert::create([
            'source'          => AlertSource::Ninja,  // ← Ninja, NOT Tactical
            'source_alert_id' => 'ninja-99',
            'severity'        => AlertSeverity::Error,
            'status'          => AlertStatus::Ticketed,
            'title'           => 'Ninja alert',
            'hostname'        => 'WS-TEST-01',
            'fired_at'        => now(),
            'ticket_id'       => $ticket->id,
        ]);

        // Invoke shared AlertService::resolve() directly (not via TacticalAlertService)
        $alertService = app(AlertService::class);
        $alertService->resolve($alert, 'Cleared in Ninja monitoring.');

        $ticket->refresh();

        // Ticket must NOT be auto-resolved by the shared path
        $this->assertTrue($ticket->isOpen(),
            'Shared AlertService::resolve() must NOT auto-resolve tickets (Ninja path unchanged)');

        // But the resolve note IS added (shared path still adds the system note)
        $this->assertTrue(
            $ticket->notes()->where('note_type', NoteType::System->value)->exists(),
            'Shared resolve() must still add a system note to the linked ticket'
        );
    }
}
