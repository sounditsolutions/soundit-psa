<?php

namespace Tests\Feature\Huntress;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Alert;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Huntress\HuntressClient;
use App\Services\Huntress\HuntressIncidentReconcileService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * HuntressIncidentReconcileService — the poll/reconcile resolve path (bd psa-kq1u).
 *
 * Huntress auto-resolves an incident (→ status `closed`/`dismissed` on the API) WITHOUT
 * firing the CW-Manage status webhook, so the bridged PSA ticket stays open. This service
 * polls the authoritative incident state and resolves the stranded ticket.
 *
 * Only the Huntress HTTP boundary (HuntressClient::getIncidentReport) is faked; the
 * reconcile logic, status change, attribution, alert-resolve, and guards are real.
 */
class HuntressIncidentReconcileTest extends TestCase
{
    use RefreshDatabase;

    private User $systemUser;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // capture any portal/status notifications dispatched on resolve
        $this->systemUser = User::factory()->create();
        Setting::setValue('huntress_system_user_id', (string) $this->systemUser->id);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private const REPORT_URL = 'https://dashboard.huntress.io/org/42/infection_reports/555';

    /**
     * A bridged Huntress ticket in an open status, with a linked Ticketed alert whose
     * source_alert_id is the incident report URL — plus the standard system audit note
     * that createTicketFromCw writes (proves system notes are NOT "human touch").
     */
    private function bridgedTicket(
        TicketStatus $status = TicketStatus::InProgress,
        string $reportUrl = self::REPORT_URL,
    ): Ticket {
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Huntress->value,
            'status' => $status->value,
            'description' => "Huntress incident. Report: {$reportUrl}",
            'closed_at' => null,
        ]);

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->systemUser->id,
            'body' => 'Submitted via Huntress incident report.',
            'note_type' => NoteType::StatusChange, // system-generated — must NOT count as human touch
            'is_private' => true,
            'noted_at' => now(),
        ]);

        Alert::create([
            'source' => AlertSource::Huntress->value,
            'source_alert_id' => $reportUrl,
            'severity' => 'critical',
            'status' => AlertStatus::Ticketed->value,
            'title' => 'Huntress incident',
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'fired_at' => now()->subHour(),
        ]);

        return $ticket;
    }

    /** Reconcile service with a HuntressClient stubbed to return $reports keyed by incident id. */
    private function serviceReturning(array $reportsById): HuntressIncidentReconcileService
    {
        $client = Mockery::mock(HuntressClient::class);
        $client->shouldReceive('getIncidentReport')
            ->andReturnUsing(fn (int $id) => $reportsById[$id] ?? ['id' => $id, 'status' => 'sent']);

        return new HuntressIncidentReconcileService(
            $client,
            app(TicketService::class),
            app(AlertService::class),
        );
    }

    // ── tests ──────────────────────────────────────────────────────────────

    public function test_resolves_an_open_ticket_when_its_incident_is_closed_upstream(): void
    {
        $ticket = $this->bridgedTicket(TicketStatus::InProgress);

        $result = $this->serviceReturning([555 => ['id' => 555, 'status' => 'closed']])->reconcile();

        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
        $this->assertSame(1, $result->updated);

        // Attributed status-change note authored by the Huntress system user.
        $note = TicketNote::where('ticket_id', $ticket->id)
            ->where('note_type', NoteType::StatusChange->value)
            ->where('status_to', TicketStatus::Resolved->value)
            ->first();
        $this->assertNotNull($note);
        $this->assertSame($this->systemUser->id, $note->author_id);
        $this->assertStringContainsStringIgnoringCase('huntress', $note->body);

        // Linked alert resolved.
        $this->assertSame(AlertStatus::Resolved, Alert::where('ticket_id', $ticket->id)->first()->status);
    }

    public function test_resolves_when_the_incident_is_dismissed_upstream(): void
    {
        $ticket = $this->bridgedTicket(TicketStatus::New);

        $this->serviceReturning([555 => ['id' => 555, 'status' => 'dismissed']])->reconcile();

        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    public function test_leaves_the_ticket_open_when_the_incident_is_still_sent(): void
    {
        $ticket = $this->bridgedTicket(TicketStatus::InProgress);

        $result = $this->serviceReturning([555 => ['id' => 555, 'status' => 'sent']])->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame(0, $result->updated);
        $this->assertSame(AlertStatus::Ticketed, Alert::where('ticket_id', $ticket->id)->first()->status);
    }

    public function test_is_idempotent_across_runs(): void
    {
        $ticket = $this->bridgedTicket(TicketStatus::InProgress);

        $this->serviceReturning([555 => ['id' => 555, 'status' => 'closed']])->reconcile();
        $this->serviceReturning([555 => ['id' => 555, 'status' => 'closed']])->reconcile();

        // Exactly one resolve status-change note — the second run is a no-op.
        $resolveNotes = TicketNote::where('ticket_id', $ticket->id)
            ->where('status_to', TicketStatus::Resolved->value)
            ->count();
        $this->assertSame(1, $resolveNotes);
        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    public function test_skips_a_ticket_a_human_has_taken_over(): void
    {
        $ticket = $this->bridgedTicket(TicketStatus::InProgress);

        // A human-authored reply (non-system note_type) = human takeover.
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->systemUser->id,
            'body' => 'I am handling this one manually.',
            'note_type' => NoteType::Note->value,
            'is_private' => true,
            'noted_at' => now(),
        ]);

        $result = $this->serviceReturning([555 => ['id' => 555, 'status' => 'closed']])->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame(0, $result->updated);
    }

    public function test_skips_a_ticket_with_an_end_user_reply(): void
    {
        $ticket = $this->bridgedTicket(TicketStatus::InProgress);

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'author_name' => 'Client Person',
            'body' => 'Any update?',
            'note_type' => NoteType::Reply->value,
            'who_type' => WhoType::EndUser->value,
            'is_private' => false,
            'noted_at' => now(),
        ]);

        $this->serviceReturning([555 => ['id' => 555, 'status' => 'closed']])->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_ignores_non_huntress_tickets(): void
    {
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Manual->value,
            'status' => TicketStatus::InProgress->value,
            'description' => 'Report: '.self::REPORT_URL,
            'closed_at' => null,
        ]);

        $this->serviceReturning([555 => ['id' => 555, 'status' => 'closed']])->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_gracefully_skips_a_ticket_with_no_resolvable_incident_id(): void
    {
        // Alert source_alert_id is a synthesized hash (no URL) and the description has no URL.
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Huntress->value,
            'status' => TicketStatus::InProgress->value,
            'description' => 'Huntress incident with no report link.',
            'closed_at' => null,
        ]);
        Alert::create([
            'source' => AlertSource::Huntress->value,
            'source_alert_id' => md5('no-url'),
            'severity' => 'critical',
            'status' => AlertStatus::Ticketed->value,
            'title' => 'Huntress incident',
            'ticket_id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'fired_at' => now(),
        ]);

        // A second, resolvable ticket must still be processed despite the first being unresolvable.
        $resolvable = $this->bridgedTicket(TicketStatus::InProgress);

        $result = $this->serviceReturning([555 => ['id' => 555, 'status' => 'closed']])->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame(TicketStatus::Resolved, $resolvable->fresh()->status);
        $this->assertSame(1, $result->updated);
    }

    public function test_command_fails_cleanly_when_huntress_is_not_configured(): void
    {
        // setUp sets no api_key/api_secret → HuntressConfig::isConfigured() is false, so the
        // command must exit non-zero without constructing a client or touching the API.
        $this->artisan('huntress:reconcile-incidents')->assertExitCode(1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
