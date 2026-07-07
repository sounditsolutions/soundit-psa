<?php

namespace Tests\Feature\Huntress;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\NoteType;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\WhoType;
use App\Models\Alert;
use App\Models\Client;
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
 * REAL DATA SHAPE: for most bridged tickets `source_alert_id` is a synth hash (not a URL),
 * so no incident id is recoverable from our records — those tickets (incl. the ones that
 * motivated the bead) MUST be resolved via LIST-AND-MATCH: list the org's incident reports
 * and match to the open ticket by organization_id→client + agent hostname + sent_at≈created.
 * The id-bearing minority (source_alert_id/description carries an incident URL) uses the
 * exact getIncidentReport(id) fast path.
 *
 * Only the Huntress HTTP boundary is faked; the reconcile/match/guard logic is real.
 */
class HuntressIncidentReconcileTest extends TestCase
{
    use RefreshDatabase;

    private User $systemUser;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->systemUser = User::factory()->create();
        Setting::setValue('huntress_system_user_id', (string) $this->systemUser->id);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function mappedClient(int $orgId = 42): Client
    {
        return Client::factory()->create(['huntress_organization_id' => $orgId]);
    }

    /**
     * A bridged Huntress ticket. Defaults to the REAL majority shape: hash source_alert_id
     * (no recoverable incident id), no description URL, agent hostname in alert metadata.
     * Pass sourceAlertUrl to get the id-bearing minority shape. Also writes the standard
     * system audit note (proves system notes are NOT counted as human touch).
     */
    private function bridgedTicket(
        Client $client,
        TicketStatus $status = TicketStatus::InProgress,
        string $hostname = 'DESKTOP-ARL0EQ1',
        ?string $sourceAlertUrl = null,
    ): Ticket {
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Huntress->value,
            'status' => $status->value,
            'client_id' => $client->id,
            'description' => 'Huntress incident on '.$hostname.'.',
            'closed_at' => null,
        ]);

        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->systemUser->id,
            'body' => 'Submitted via Huntress incident report.',
            'note_type' => NoteType::StatusChange->value, // system-generated — not a human touch
            'is_private' => true,
            'noted_at' => now(),
        ]);

        Alert::create([
            'source' => AlertSource::Huntress->value,
            'source_alert_id' => $sourceAlertUrl ?? md5('synth-'.$ticket->id), // hash by default
            'severity' => 'critical',
            'status' => AlertStatus::Ticketed->value,
            'title' => 'Huntress incident',
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'fired_at' => $ticket->created_at,
            'metadata' => ['agent' => $hostname, 'organization' => 'Some Org'],
        ]);

        return $ticket;
    }

    private function incident(int $id, int $agentId, int $orgId, string $status, \Carbon\CarbonInterface $sentAt): array
    {
        return [
            'id' => $id,
            'agent_id' => $agentId,
            'organization_id' => $orgId,
            'status' => $status,
            'sent_at' => $sentAt->toIso8601String(),
            'closed_at' => $status === 'closed' ? now()->toIso8601String() : null,
            'severity' => 'critical',
        ];
    }

    /**
     * Reconcile service with a fully-faked HuntressClient:
     *   $incidentsByOrg[org]  → getIncidentReports(['organization_id'=>org])
     *   $agentsByOrg[org]     → getAgents(['organization_id'=>org])
     *   $reportsById[id]      → getIncidentReport(id)  (id-path minority)
     */
    private function service(array $incidentsByOrg = [], array $agentsByOrg = [], array $reportsById = []): HuntressIncidentReconcileService
    {
        $client = Mockery::mock(HuntressClient::class);
        $client->shouldReceive('getIncidentReports')
            ->andReturnUsing(fn (array $p) => $incidentsByOrg[$p['organization_id'] ?? 0] ?? []);
        $client->shouldReceive('getAgents')
            ->andReturnUsing(fn (array $p = []) => $agentsByOrg[$p['organization_id'] ?? 0] ?? []);
        $client->shouldReceive('getIncidentReport')
            ->andReturnUsing(fn (int $id) => $reportsById[$id] ?? ['id' => $id, 'status' => 'sent']);

        return new HuntressIncidentReconcileService(
            $client,
            app(TicketService::class),
            app(AlertService::class),
        );
    }

    private function assertResolved(Ticket $ticket): void
    {
        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
        $note = TicketNote::where('ticket_id', $ticket->id)
            ->where('note_type', NoteType::StatusChange->value)
            ->where('status_to', TicketStatus::Resolved->value)
            ->first();
        $this->assertNotNull($note, 'expected an attributed resolve status-change note');
        $this->assertSame($this->systemUser->id, $note->author_id);
        $this->assertStringContainsStringIgnoringCase('huntress', $note->body);
    }

    // ── list-and-match: the REAL majority shape (hash source_alert_id, no URL) ──

    public function test_resolves_majority_shape_ticket_via_list_and_match(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->bridgedTicket($client, hostname: 'DESKTOP-ARL0EQ1'); // hash source_alert_id, no URL

        $incidents = [42 => [$this->incident(777, 9001, 42, 'closed', $ticket->created_at)]];
        $agents = [42 => [['id' => 9001, 'hostname' => 'DESKTOP-ARL0EQ1']]];

        $result = $this->service($incidents, $agents)->reconcile();

        $this->assertResolved($ticket);
        $this->assertSame(1, $result->updated);
        $this->assertSame(AlertStatus::Resolved, Alert::where('ticket_id', $ticket->id)->first()->status);
    }

    public function test_agent_hostname_disambiguates_two_incidents_in_the_same_window(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->bridgedTicket($client, hostname: 'HOST-B');

        // Two closed incidents in the sent_at window; without agent matching this is ambiguous.
        $incidents = [42 => [
            $this->incident(701, 9001, 42, 'closed', $ticket->created_at),
            $this->incident(702, 9002, 42, 'closed', $ticket->created_at),
        ]];
        $agents = [42 => [
            ['id' => 9001, 'hostname' => 'HOST-A'],
            ['id' => 9002, 'hostname' => 'HOST-B'],
        ]];

        $result = $this->service($incidents, $agents)->reconcile();

        // Agent hostname (HOST-B → agent 9002) picks a unique incident → resolved.
        $this->assertResolved($ticket);
        $this->assertSame(1, $result->updated);
    }

    public function test_ambiguous_window_without_host_resolution_is_skipped_safely(): void
    {
        $client = $this->mappedClient(42);
        // No hostname anywhere (no alert agent metadata, no linked asset) → can't agent-match.
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Huntress->value,
            'status' => TicketStatus::InProgress->value,
            'client_id' => $client->id,
            'description' => 'Huntress incident.',
            'closed_at' => null,
        ]);
        Alert::create([
            'source' => AlertSource::Huntress->value,
            'source_alert_id' => md5('synth-'.$ticket->id),
            'severity' => 'critical',
            'status' => AlertStatus::Ticketed->value,
            'title' => 'Huntress incident',
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'fired_at' => $ticket->created_at,
            'metadata' => ['organization' => 'Some Org'], // no 'agent'
        ]);

        $incidents = [42 => [
            $this->incident(701, 9001, 42, 'closed', $ticket->created_at),
            $this->incident(702, 9002, 42, 'closed', $ticket->created_at),
        ]];

        $result = $this->service($incidents, [])->reconcile();

        // Two window candidates, no way to disambiguate → skip (never mis-close).
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame(0, $result->updated);
    }

    public function test_incident_sent_at_outside_the_window_is_not_matched(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->bridgedTicket($client, hostname: 'HOST-X');

        // Closed incident for the right host, but sent 3h from the ticket's creation.
        $incidents = [42 => [$this->incident(710, 9001, 42, 'closed', $ticket->created_at->copy()->addHours(3))]];
        $agents = [42 => [['id' => 9001, 'hostname' => 'HOST-X']]];

        $this->service($incidents, $agents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_dismissed_incident_resolves(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->bridgedTicket($client, TicketStatus::New, 'HOST-D');

        $incidents = [42 => [$this->incident(720, 9003, 42, 'dismissed', $ticket->created_at)]];
        $agents = [42 => [['id' => 9003, 'hostname' => 'HOST-D']]];

        $this->service($incidents, $agents)->reconcile();

        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    public function test_still_sent_incident_leaves_ticket_open(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->bridgedTicket($client, hostname: 'HOST-S');

        $incidents = [42 => [$this->incident(730, 9004, 42, 'sent', $ticket->created_at)]];
        $agents = [42 => [['id' => 9004, 'hostname' => 'HOST-S']]];

        $result = $this->service($incidents, $agents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame(0, $result->updated);
        $this->assertSame(AlertStatus::Ticketed, Alert::where('ticket_id', $ticket->id)->first()->status);
    }

    // ── id-path: the minority shape (source_alert_id carries an incident URL) ──

    public function test_id_bearing_ticket_resolves_via_exact_get_incident_report(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->bridgedTicket(
            $client,
            sourceAlertUrl: 'https://dashboard.huntress.io/org/42/infection_reports/555',
        );

        // No org list needed — exact id path.
        $result = $this->service([], [], [555 => ['id' => 555, 'status' => 'closed']])->reconcile();

        $this->assertResolved($ticket);
        $this->assertSame(1, $result->updated);
    }

    // ── guards (approved; exercised on the real majority shape) ──

    public function test_is_idempotent_across_runs(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->bridgedTicket($client, hostname: 'HOST-I');
        $incidents = [42 => [$this->incident(740, 9005, 42, 'closed', $ticket->created_at)]];
        $agents = [42 => [['id' => 9005, 'hostname' => 'HOST-I']]];

        $this->service($incidents, $agents)->reconcile();
        $this->service($incidents, $agents)->reconcile();

        $resolveNotes = TicketNote::where('ticket_id', $ticket->id)
            ->where('status_to', TicketStatus::Resolved->value)
            ->count();
        $this->assertSame(1, $resolveNotes);
        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    public function test_skips_a_ticket_a_human_has_taken_over(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->bridgedTicket($client, hostname: 'HOST-H');
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->systemUser->id,
            'body' => 'I am handling this one manually.',
            'note_type' => NoteType::Note->value, // human note
            'is_private' => true,
            'noted_at' => now(),
        ]);

        $incidents = [42 => [$this->incident(750, 9006, 42, 'closed', $ticket->created_at)]];
        $agents = [42 => [['id' => 9006, 'hostname' => 'HOST-H']]];

        $result = $this->service($incidents, $agents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame(0, $result->updated);
    }

    public function test_skips_a_ticket_with_an_end_user_reply(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->bridgedTicket($client, hostname: 'HOST-E');
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

        $incidents = [42 => [$this->incident(760, 9007, 42, 'closed', $ticket->created_at)]];
        $agents = [42 => [['id' => 9007, 'hostname' => 'HOST-E']]];

        $this->service($incidents, $agents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_ignores_non_huntress_tickets(): void
    {
        $client = $this->mappedClient(42);
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Manual->value,
            'status' => TicketStatus::InProgress->value,
            'client_id' => $client->id,
            'closed_at' => null,
        ]);

        $incidents = [42 => [$this->incident(770, 9008, 42, 'closed', $ticket->created_at)]];
        $this->service($incidents, [42 => [['id' => 9008, 'hostname' => 'X']]])->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_gracefully_skips_when_client_has_no_org_mapping_and_no_id(): void
    {
        // Unmapped client + hash source_alert_id → nothing to list against → skip.
        $unmapped = Client::factory()->create(['huntress_organization_id' => null]);
        $stuck = $this->bridgedTicket($unmapped, hostname: 'HOST-U');

        // A second, resolvable ticket (mapped org) must still be processed.
        $client = $this->mappedClient(42);
        $resolvable = $this->bridgedTicket($client, hostname: 'HOST-R');
        $incidents = [42 => [$this->incident(780, 9009, 42, 'closed', $resolvable->created_at)]];
        $agents = [42 => [['id' => 9009, 'hostname' => 'HOST-R']]];

        $result = $this->service($incidents, $agents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $stuck->fresh()->status);
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
