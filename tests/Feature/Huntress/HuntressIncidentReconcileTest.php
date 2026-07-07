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
 * HuntressIncidentReconcileService — poll/reconcile resolve path (bd psa-kq1u).
 *
 * Auto-resolved Huntress incidents (→ status closed/dismissed on the API) don't fire the
 * CW-Manage status webhook, so the bridged PSA ticket strands open. This service resolves it.
 *
 * SAFETY (from real prod data):
 *  - SCOPE: only INCIDENT-backed source=Huntress tickets are eligible. Escalations / ISPM /
 *    ITDR / product-notices share the source but have no incident report — they must never
 *    be touched. We detect an incident ticket by parsing "Incident on <host>" from its
 *    subject (escalations/notices don't carry it).
 *  - CORRESPONDENCE: resolve ONLY on positive ticket↔incident correspondence — an exact
 *    incident id (minority), or the closed incident's body mentioning the ticket's host,
 *    within the sent_at window. Bare time-window matching is a mis-close vector (a
 *    coincidental sibling incident close) and is NOT a resolve trigger.
 *
 * Only the Huntress HTTP boundary is faked; reconcile/scope/correspondence/guards are real.
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

    /** A real INCIDENT ticket: subject carries "Incident on <host> (<org>)". */
    private function incidentTicket(
        Client $client,
        string $host = 'DESKTOP-ARL0EQ1',
        TicketStatus $status = TicketStatus::InProgress,
        ?string $sourceAlertUrl = null,
    ): Ticket {
        return $this->makeTicket(
            $client,
            "Huntress EDR Critical Incident Report | Incident on {$host} (Blue Org)",
            "Threat surfaced on host {$host}. Remediation pending.",
            $status,
            $sourceAlertUrl,
        );
    }

    /** A NON-incident Huntress ticket (escalation / ISPM / ITDR / notice) — no "Incident on". */
    private function escalationTicket(Client $client, string $subject = 'Huntress EDR High Escalation | Endpoints Missing Key EDR Functionality'): Ticket
    {
        return $this->makeTicket($client, $subject, 'Please review affected endpoints.', TicketStatus::InProgress, null);
    }

    private function makeTicket(Client $client, string $subject, string $description, TicketStatus $status, ?string $sourceAlertUrl): Ticket
    {
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Huntress->value,
            'status' => $status->value,
            'client_id' => $client->id,
            'subject' => $subject,
            'description' => $description,
            'closed_at' => null,
        ]);

        // Standard system audit note (system-generated — not a human touch).
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->systemUser->id,
            'body' => 'Submitted via Huntress incident report.',
            'note_type' => NoteType::StatusChange->value,
            'is_private' => true,
            'noted_at' => now(),
        ]);

        // Linked alert — source_alert_id is a synth hash for the majority (no recoverable id),
        // or the incident URL for the minority. metadata['agent'] is intentionally absent
        // (empty in real prod data), so correspondence must come from the incident body.
        Alert::create([
            'source' => AlertSource::Huntress->value,
            'source_alert_id' => $sourceAlertUrl ?? md5('synth-'.$ticket->id),
            'severity' => 'critical',
            'status' => AlertStatus::Ticketed->value,
            'title' => $subject,
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'fired_at' => $ticket->created_at,
            'metadata' => ['organization' => 'Blue Org'],
        ]);

        return $ticket;
    }

    /** An incident report row as returned by getIncidentReports (host lives in body text). */
    private function incidentRow(int $id, int $orgId, string $status, \Carbon\CarbonInterface $sentAt, string $body): array
    {
        return [
            'id' => $id,
            'agent_id' => 9000 + $id,
            'organization_id' => $orgId,
            'status' => $status,
            'sent_at' => $sentAt->toIso8601String(),
            'closed_at' => $status === 'closed' ? now()->toIso8601String() : null,
            'severity' => 'critical',
            'body' => $body,
        ];
    }

    private function service(array $incidentsByOrg = [], array $reportsById = []): HuntressIncidentReconcileService
    {
        $client = Mockery::mock(HuntressClient::class);
        $client->shouldReceive('getIncidentReports')
            ->andReturnUsing(fn (array $p) => $incidentsByOrg[$p['organization_id'] ?? 0] ?? []);
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

    // ── correspondence: host + window (the majority, hash source_alert_id) ──

    public function test_resolves_incident_ticket_on_host_and_window_correspondence(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->incidentTicket($client, 'DESKTOP-ARL0EQ1');

        $incidents = [42 => [
            $this->incidentRow(701, 42, 'closed', $ticket->created_at, 'Remediation complete on DESKTOP-ARL0EQ1.'),
        ]];

        $result = $this->service($incidents)->reconcile();

        $this->assertResolved($ticket);
        $this->assertSame(1, $result->updated);
        $this->assertSame(AlertStatus::Resolved, Alert::where('ticket_id', $ticket->id)->first()->status);
    }

    /** BLOCKER 1: escalation / ISPM / ITDR / notice tickets are never incident-backed. */
    public function test_escalation_ticket_is_never_touched(): void
    {
        $client = $this->mappedClient(42);
        $escalation = $this->escalationTicket($client);

        // A closed incident sits in the same org and window — must NOT match the escalation.
        $incidents = [42 => [
            $this->incidentRow(710, 42, 'closed', $escalation->created_at, 'Remediation complete on SOME-HOST.'),
        ]];

        $result = $this->service($incidents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $escalation->fresh()->status);
        $this->assertSame(0, $result->updated);
    }

    /** BLOCKER 2: a coincidental sibling close on a DIFFERENT host must not resolve the ticket. */
    public function test_coincidental_sibling_close_on_different_host_does_not_resolve(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->incidentTicket($client, 'HOST-A'); // its own incident is still open (sent)

        // The only closed incident in the window is for HOST-B — body does not mention HOST-A.
        $incidents = [42 => [
            $this->incidentRow(720, 42, 'closed', $ticket->created_at, 'Remediation complete on HOST-B.'),
        ]];

        $result = $this->service($incidents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame(0, $result->updated);
    }

    public function test_two_closed_incidents_for_the_same_host_in_window_are_ambiguous_skip(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->incidentTicket($client, 'HOST-A');

        $incidents = [42 => [
            $this->incidentRow(730, 42, 'closed', $ticket->created_at, 'Remediation complete on HOST-A.'),
            $this->incidentRow(731, 42, 'dismissed', $ticket->created_at, 'Dismissed finding on HOST-A.'),
        ]];

        $result = $this->service($incidents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame(0, $result->updated);
    }

    public function test_host_match_outside_the_window_is_not_resolved(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->incidentTicket($client, 'HOST-A');

        // Correct host, but sent 3h from the ticket's creation → a later re-infection, not this incident.
        $incidents = [42 => [
            $this->incidentRow(740, 42, 'closed', $ticket->created_at->copy()->addHours(3), 'Remediation complete on HOST-A.'),
        ]];

        $this->service($incidents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_dismissed_incident_resolves_on_correspondence(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->incidentTicket($client, 'HOST-D', TicketStatus::New);

        $incidents = [42 => [
            $this->incidentRow(750, 42, 'dismissed', $ticket->created_at, 'Dismissed benign detection on HOST-D.'),
        ]];

        $this->service($incidents)->reconcile();

        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    public function test_still_sent_incident_leaves_ticket_open(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->incidentTicket($client, 'HOST-S');

        // The host's incident is present but still open upstream (sent) → excluded from candidates.
        $incidents = [42 => [
            $this->incidentRow(760, 42, 'sent', $ticket->created_at, 'Active investigation on HOST-S.'),
        ]];

        $result = $this->service($incidents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame(0, $result->updated);
    }

    // ── exact-id fast path (the minority: source_alert_id carries an incident URL) ──

    public function test_id_bearing_ticket_resolves_via_exact_get_incident_report(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->incidentTicket(
            $client,
            'HOST-ID',
            sourceAlertUrl: 'https://dashboard.huntress.io/org/42/infection_reports/555',
        );

        $result = $this->service([], [555 => ['id' => 555, 'status' => 'closed']])->reconcile();

        $this->assertResolved($ticket);
        $this->assertSame(1, $result->updated);
    }

    public function test_id_bearing_ticket_with_still_open_incident_stays_open(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->incidentTicket(
            $client,
            'HOST-ID2',
            sourceAlertUrl: 'https://dashboard.huntress.io/org/42/infection_reports/556',
        );

        $this->service([], [556 => ['id' => 556, 'status' => 'sent']])->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    // ── guards (approved; exercised on the real correspondence path) ──

    public function test_is_idempotent_across_runs(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->incidentTicket($client, 'HOST-I');
        $incidents = [42 => [$this->incidentRow(770, 42, 'closed', $ticket->created_at, 'Done on HOST-I.')]];

        $this->service($incidents)->reconcile();
        $this->service($incidents)->reconcile();

        $resolveNotes = TicketNote::where('ticket_id', $ticket->id)
            ->where('status_to', TicketStatus::Resolved->value)
            ->count();
        $this->assertSame(1, $resolveNotes);
        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    public function test_skips_a_ticket_a_human_has_taken_over(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->incidentTicket($client, 'HOST-H');
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->systemUser->id,
            'body' => 'Handling this manually.',
            'note_type' => NoteType::Note->value,
            'is_private' => true,
            'noted_at' => now(),
        ]);

        $incidents = [42 => [$this->incidentRow(780, 42, 'closed', $ticket->created_at, 'Done on HOST-H.')]];
        $result = $this->service($incidents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertSame(0, $result->updated);
    }

    public function test_skips_a_ticket_with_an_end_user_reply(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->incidentTicket($client, 'HOST-E');
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

        $incidents = [42 => [$this->incidentRow(790, 42, 'closed', $ticket->created_at, 'Done on HOST-E.')]];
        $this->service($incidents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_ignores_non_huntress_tickets(): void
    {
        $client = $this->mappedClient(42);
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Manual->value,
            'status' => TicketStatus::InProgress->value,
            'client_id' => $client->id,
            'subject' => 'Incident on HOST-A (Blue Org)',
            'closed_at' => null,
        ]);

        $incidents = [42 => [$this->incidentRow(800, 42, 'closed', $ticket->created_at, 'Done on HOST-A.')]];
        $this->service($incidents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_gracefully_skips_when_client_has_no_org_mapping(): void
    {
        $unmapped = Client::factory()->create(['huntress_organization_id' => null]);
        $stuck = $this->incidentTicket($unmapped, 'HOST-U');

        $client = $this->mappedClient(42);
        $resolvable = $this->incidentTicket($client, 'HOST-R');
        $incidents = [42 => [$this->incidentRow(810, 42, 'closed', $resolvable->created_at, 'Done on HOST-R.')]];

        $result = $this->service($incidents)->reconcile();

        $this->assertSame(TicketStatus::InProgress, $stuck->fresh()->status);
        $this->assertSame(TicketStatus::Resolved, $resolvable->fresh()->status);
        $this->assertSame(1, $result->updated);
    }

    public function test_command_fails_cleanly_when_huntress_is_not_configured(): void
    {
        $this->artisan('huntress:reconcile-incidents')->assertExitCode(1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
