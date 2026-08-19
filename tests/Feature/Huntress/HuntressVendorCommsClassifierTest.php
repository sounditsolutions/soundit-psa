<?php

namespace Tests\Feature\Huntress;

use App\Enums\AlertSource;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketType;
use App\Models\Alert;
use App\Models\Client;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Huntress\HuntressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The CW-compat ingest hard-coded type=incident and failed open to HIGH/P2 for
 * every payload, so vendor marketing bulletins entered the queue at the same
 * type and priority as a real identity incident (five instances, 2026-07-29 →
 * 2026-08-18). A payload with ZERO incident signals — no word-bounded severity
 * token, no "Incident on agent (org)" title shape, no incident-report or
 * escalation URL in the body — is vendor comms: service_request/p4, no Alert.
 * Any payload with at least one signal keeps the fail-open incident default.
 */
class HuntressVendorCommsClassifierTest extends TestCase
{
    use RefreshDatabase;

    private User $systemUser;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->systemUser = User::factory()->create();
        Setting::setValue('huntress_system_user_id', (string) $this->systemUser->id);
        $this->client = Client::factory()->create([
            'is_active' => true,
            'huntress_organization_id' => 42,
        ]);
    }

    private function ingest(string $subject, string $description = 'Vendor announcement body with no links.'): array
    {
        return app(HuntressService::class)->createTicketFromCw([
            'summary' => $subject,
            'initialDescription' => $description,
            'company' => ['id' => $this->client->id],
        ]);
    }

    private function latestTicket(): Ticket
    {
        return Ticket::where('source', TicketSource::Huntress->value)->latest('id')->firstOrFail();
    }

    // ── vendor comms: zero-signal payloads ─────────────────────────────────

    public function test_marketing_bulletin_creates_service_request_p4_without_alert(): void
    {
        $this->ingest('Per-Identity ITDR Notifications Begin Tomorrow');

        $ticket = $this->latestTicket();
        $this->assertSame(TicketType::ServiceRequest, $ticket->type);
        $this->assertSame(TicketPriority::P4, $ticket->priority);
        $this->assertSame(0, Alert::where('source', AlertSource::Huntress->value)->count());
        $this->assertStringContainsString('vendor communication', $ticket->notes()->first()->body);
    }

    public function test_all_five_observed_bulletin_subjects_classify_as_vendor_comms(): void
    {
        $subjects = [
            'Huntress Managed ITDR: Trusted Device Suppression Live Tomorrow',
            'TOMORROW: Executive Summaries in ITDR Incident Reports',
            'Now available: Early Access to the new Huntress ITDR dashboard',
            'Coming August 19: Per-Identity Escalations for ITDR Unexpected Login Activity',
            'Per-Identity ITDR Notifications Begin Tomorrow',
        ];

        foreach ($subjects as $subject) {
            $this->ingest($subject);
            $ticket = $this->latestTicket();
            $this->assertSame(TicketType::ServiceRequest, $ticket->type, $subject);
            $this->assertSame(TicketPriority::P4, $ticket->priority, $subject);
        }

        $this->assertSame(0, Alert::where('source', AlertSource::Huntress->value)->count());
    }

    public function test_severity_substring_inside_a_word_is_not_a_signal(): void
    {
        // "workflow" contains "low"; the old bare-substring fallback would have
        // read it as a LOW severity token. Word boundaries govern the classifier.
        $this->ingest('New workflow automation coming tomorrow');

        $ticket = $this->latestTicket();
        $this->assertSame(TicketType::ServiceRequest, $ticket->type);
        $this->assertSame(TicketPriority::P4, $ticket->priority);
    }

    // ── incidents: any single signal keeps the fail-open default ───────────

    public function test_full_incident_title_keeps_incident_type_and_mapped_priority(): void
    {
        $this->ingest(
            'HIGH - Incident on WS-FRONTDESK (Acme Corp)',
            'Report: https://dashboard.huntress.io/org/42/infection_reports/1001'
        );

        $ticket = $this->latestTicket();
        $this->assertSame(TicketType::Incident, $ticket->type);
        $this->assertSame(TicketPriority::P2, $ticket->priority);
        $this->assertSame(1, Alert::where('source', AlertSource::Huntress->value)->count());
    }

    public function test_bare_severity_token_alone_keeps_fail_open_incident_default(): void
    {
        $this->ingest('Huntress EDR HIGH Escalation | Endpoints Missing Key EDR Functionality');

        $ticket = $this->latestTicket();
        $this->assertSame(TicketType::Incident, $ticket->type);
        $this->assertSame(TicketPriority::P2, $ticket->priority);
    }

    public function test_incident_url_in_body_alone_keeps_incident_default(): void
    {
        $this->ingest(
            'Something new from the dashboard',
            'Details: https://dashboard.huntress.io/org/42/infection_reports/2002'
        );

        $ticket = $this->latestTicket();
        $this->assertSame(TicketType::Incident, $ticket->type);
        $this->assertSame(TicketPriority::P2, $ticket->priority);
        $this->assertSame(1, Alert::where('source', AlertSource::Huntress->value)->count());
    }

    public function test_escalation_url_in_body_alone_keeps_incident_default(): void
    {
        $this->ingest(
            'Action requested for your account',
            'Escalation: https://dashboard.huntress.io/org/42/escalations/3003'
        );

        $ticket = $this->latestTicket();
        $this->assertSame(TicketType::Incident, $ticket->type);
        $this->assertSame(TicketPriority::P2, $ticket->priority);
    }
}
