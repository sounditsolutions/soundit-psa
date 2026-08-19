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
 * 2026-08-18). Vendor comms is an AFFIRMATIVE classification: ZERO incident
 * signals (no word-bounded severity token, no "Incident on agent (org)" title
 * shape, no org-scoped per-tenant RECORD URL in the body — an org-scoped link
 * to a landing page or a preferences path is a bulletin CTA, not a record) AND
 * vendor announcement
 * language in the title → service_request/p4, no Alert. Any incident signal —
 * or a title with no announcement language at all — keeps the fail-open
 * incident default, so an unrecognised real event is never buried at P4.
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

    public function test_bulletin_linking_the_tenant_dashboard_is_still_vendor_comms(): void
    {
        // The observed ITDR-dashboard bulletin's call to action IS an org-scoped link. Only a
        // per-tenant RECORD path (numeric record id) is an incident signal; a dashboard landing
        // page is marketing whatever org it is scoped to.
        $this->ingest(
            'Now available: Early Access to the new Huntress ITDR dashboard',
            'See it in your account: https://dashboard.huntress.io/org/42/identity/dashboard'
        );

        $ticket = $this->latestTicket();
        $this->assertSame(TicketType::ServiceRequest, $ticket->type);
        $this->assertSame(TicketPriority::P4, $ticket->priority);
        $this->assertSame(0, Alert::where('source', AlertSource::Huntress->value)->count());
    }

    public function test_bulletin_with_per_org_preferences_link_is_still_vendor_comms(): void
    {
        // Per-org preferences/unsubscribe footers are routine in vendor bulk mail.
        $this->ingest(
            'Per-Identity ITDR Notifications Begin Tomorrow',
            'Manage your email preferences: https://links.huntress.io/org/42/preferences/12345'
        );

        $ticket = $this->latestTicket();
        $this->assertSame(TicketType::ServiceRequest, $ticket->type);
        $this->assertSame(TicketPriority::P4, $ticket->priority);
        $this->assertSame(0, Alert::where('source', AlertSource::Huntress->value)->count());
    }

    public function test_lookalike_host_with_org_record_path_is_not_an_incident_signal(): void
    {
        // huntress.io must be the link's own domain, not a substring of some other host.
        $this->ingest(
            'Introducing our new partner portal',
            'Details: https://evil-huntress.io.example.com/org/1/identity_incidents/7788'
        );

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

    public function test_current_form_incident_report_url_in_body_keeps_incident_default(): void
    {
        // `incident_reports/{id}` is the CURRENT link form; `infection_reports/{id}` the legacy
        // one. Both must be a signal, and both must key the dedup/alert source id.
        $this->ingest(
            'New report posted to the dashboard',
            'Details: https://dashboard.huntress.io/org/42/incident_reports/2002'
        );

        $ticket = $this->latestTicket();
        $this->assertSame(TicketType::Incident, $ticket->type);
        $this->assertSame(TicketPriority::P2, $ticket->priority);

        $alert = Alert::where('source', AlertSource::Huntress->value)->sole();
        $this->assertSame('https://dashboard.huntress.io/org/42/incident_reports/2002', $alert->source_alert_id);
    }

    public function test_unrecognized_itdr_incident_url_keeps_incident_default(): void
    {
        // Per-identity ITDR escalations carry none of the legacy EDR signals: no severity token,
        // no "Incident on agent (org)" shape, and a record path the incident-report/escalation
        // parsers do not know. The org-scoped dashboard link is still a per-tenant record.
        $this->ingest(
            'Unexpected Login Activity for j.doe@acme.com',
            'Details: https://dashboard.huntress.io/org/42/identity_incidents/7788'
        );

        $ticket = $this->latestTicket();
        $this->assertSame(TicketType::Incident, $ticket->type);
        $this->assertSame(TicketPriority::P2, $ticket->priority);
        $this->assertSame(1, Alert::where('source', AlertSource::Huntress->value)->count());
    }

    public function test_signal_less_payload_without_announcement_language_is_not_vendor_comms(): void
    {
        // Absence of signal is not evidence of marketing: with no announcement language the
        // payload keeps the fail-open incident default and still gets a monitoring Alert.
        $this->ingest('Unexpected Login Activity for j.doe@acme.com');

        $ticket = $this->latestTicket();
        $this->assertSame(TicketType::Incident, $ticket->type);
        $this->assertSame(TicketPriority::P2, $ticket->priority);
        $this->assertSame(1, Alert::where('source', AlertSource::Huntress->value)->count());
        $this->assertStringNotContainsString('vendor communication', $ticket->notes()->first()->body);
    }
}
