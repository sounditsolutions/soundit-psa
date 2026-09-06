<?php

namespace Tests\Feature\Huntress;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Enums\ClientStage;
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
use App\Services\Huntress\HuntressClientException;
use App\Services\Huntress\HuntressEscalationReconcileService;
use App\Services\Huntress\HuntressService;
use App\Services\TicketService;
use Carbon\CarbonInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HuntressEscalationReconcileService — poll/reconcile resolve path (bd psa-oe19).
 *
 * Escalations that resolve upstream (status → resolved / resolved_at set) don't fire the
 * CW-Manage status webhook, so the bridged PSA ticket strands open — the escalation analogue
 * of the incident gap (psa-kq1u). This service resolves it, under the SAME safety discipline.
 *
 * SAFETY (from real prod data + psa-shej escalation-shape probes):
 *  - SCOPE: only ESCALATION-backed source=Huntress tickets are eligible (subject carries an
 *    "Escalation" marker and NOT "Incident on <host>"). Incidents / product-notices sharing
 *    the source must never be touched here.
 *  - CORRESPONDENCE: resolve ONLY on positive ticket↔escalation correspondence — an exact
 *    escalation id (ingest-captured), OR a unique org+subject match within the creation
 *    window. Account-level "Failed to Deliver" escalations (no org, no id) are skipped, never
 *    guessed. Bare time-window matching is a mis-close vector and is NOT a resolve trigger.
 *
 * Only the Huntress HTTP boundary is faked; reconcile/scope/correspondence/guards are real.
 */
class HuntressEscalationReconcileTest extends TestCase
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
     * A real ESCALATION ticket: subject carries "… Escalation | <core>". `metaEscalationId`
     * simulates the ingest-fix clean path; `sourceAlertId` the URL/hash on the linked alert.
     */
    private function escalationTicket(
        Client $client,
        string $subject = 'Huntress EDR High Escalation | Endpoints Missing Key EDR Functionality',
        TicketStatus $status = TicketStatus::InProgress,
        ?int $metaEscalationId = null,
        ?string $sourceAlertId = null,
    ): Ticket {
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Huntress->value,
            'status' => $status->value,
            'client_id' => $client->id,
            'subject' => $subject,
            'description' => 'Huntress escalation notice. Please review.',
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

        Alert::create([
            'source' => AlertSource::Huntress->value,
            'source_alert_id' => $sourceAlertId ?? md5('synth-'.$ticket->id),
            'severity' => 'critical',
            'status' => AlertStatus::Ticketed->value,
            'title' => $subject,
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'fired_at' => $ticket->created_at,
            'metadata' => ['escalation_id' => $metaEscalationId],
        ]);

        return $ticket;
    }

    /** An escalation row as returned by getEscalations / getEscalation. */
    private function escalationRow(
        int $id,
        ?int $orgId,
        string $status,
        CarbonInterface $createdAt,
        string $subject,
        ?string $resolvedAt = null,
    ): array {
        return [
            'id' => $id,
            'status' => $status,
            'resolved_at' => $resolvedAt,
            'severity' => 'high',
            'subject' => $subject,
            'type' => 'Escalation',
            'subtype' => 'SecurityControls',
            'created_at' => $createdAt->toIso8601String(),
            'updated_at' => $createdAt->toIso8601String(),
            'organizations' => $orgId !== null ? [['id' => $orgId, 'name' => 'Blue Org']] : [],
        ];
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $escalationsByOrg  org id → rows
     * @param  array<int, array<string, mixed>>  $escalationsById  id → single escalation
     */
    private function service(array $escalationsByOrg = [], array $escalationsById = []): HuntressEscalationReconcileService
    {
        $client = Mockery::mock(HuntressClient::class);
        $client->shouldReceive('getEscalations')
            ->andReturnUsing(fn (array $p) => $escalationsByOrg[$p['organization_id'] ?? 0] ?? []);
        $client->shouldReceive('getEscalation')
            ->andReturnUsing(fn (int $id) => $escalationsById[$id] ?? ['id' => $id, 'status' => 'sent']);

        return new HuntressEscalationReconcileService(
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
        $this->assertStringContainsStringIgnoringCase('escalation', $note->body);
    }

    private function assertStaysOpen(Ticket $ticket): void
    {
        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    // ── correspondence: org + subject + window (the legacy no-id path) ──

    public function test_resolves_on_unique_org_subject_window_correspondence(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        $escalations = [42 => [
            $this->escalationRow(701, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
        ]];

        $result = $this->service($escalations)->reconcile();

        $this->assertResolved($ticket);
        $this->assertSame(1, $result->updated);
        $this->assertSame(AlertStatus::Resolved, Alert::where('ticket_id', $ticket->id)->first()->status);
    }

    public function test_resolves_when_only_resolved_at_is_set_status_absent(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        $escalations = [42 => [
            $this->escalationRow(702, 42, 'sent', $ticket->created_at, 'Endpoints Missing Key EDR Functionality', resolvedAt: now()->toIso8601String()),
        ]];

        $this->service($escalations)->reconcile();

        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    /** THE motivating safety case: account-level "Failed to Deliver" has no org + no id → skip. */
    public function test_account_level_failed_to_deliver_is_never_resolved(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client, 'Huntress High Escalation | Failed to Deliver');

        // A RESOLVED account-level escalation with the exact subject sits in the window — but it
        // carries no organizations[], so there is no positive org correspondence. Must skip.
        $escalations = [42 => [
            $this->escalationRow(710, null, 'resolved', $ticket->created_at, 'Failed to Deliver'),
        ]];

        $result = $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    /** A coincidental sibling close must not resolve a ticket whose own escalation is still open. */
    public function test_sibling_close_with_own_escalation_still_open_is_ambiguous_skip(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        // Two escalations, same org/subject/window: one resolved sibling + the ticket's own,
        // still open. Uniqueness-across-statuses makes this ambiguous → skip.
        $escalations = [42 => [
            $this->escalationRow(720, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
            $this->escalationRow(721, 42, 'sent', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
        ]];

        $result = $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    public function test_two_resolved_escalations_same_subject_in_window_are_ambiguous_skip(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        $escalations = [42 => [
            $this->escalationRow(730, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
            $this->escalationRow(731, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
        ]];

        $result = $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    public function test_subject_mismatch_is_not_resolved(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        $escalations = [42 => [
            $this->escalationRow(740, 42, 'resolved', $ticket->created_at, 'Completely Unrelated Escalation Reason'),
        ]];

        $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
    }

    public function test_match_outside_the_window_is_not_resolved(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        // Correct org + subject, but created 3h from the ticket → a later escalation, not this one.
        $escalations = [42 => [
            $this->escalationRow(750, 42, 'resolved', $ticket->created_at->copy()->addHours(3), 'Endpoints Missing Key EDR Functionality'),
        ]];

        $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
    }

    public function test_escalation_for_a_different_org_is_excluded(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        // Even if returned in the org-42 response, an escalation whose organizations[] is org 99
        // carries no correspondence to this org-42 ticket.
        $escalations = [42 => [
            $this->escalationRow(760, 99, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
        ]];

        $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
    }

    public function test_unique_but_still_open_escalation_leaves_ticket_open(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);

        $escalations = [42 => [
            $this->escalationRow(770, 42, 'sent', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
        ]];

        $result = $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    // ── correspondence: exact id fast path (ingest-captured escalation id) ──

    /**
     * FIXTURE CORRECTION, flagged rather than buried: this stub previously carried no
     * `organizations` key. HuntressClient::getEscalation's docblock records that the by-id
     * view returns "an organizations[] array", so a stub without one was never a shape the API
     * produces — and under the ownership guard it now fails closed, as any unreadable payload
     * does. Org 42 is the org this ticket's client is already mapped to, so what the test
     * asserts is unchanged; it is now asserted against a realistic response.
     */
    public function test_id_bearing_ticket_resolves_via_exact_get_escalation(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client, metaEscalationId: 555);

        $result = $this->service([], [555 => [
            'id' => 555,
            'status' => 'resolved',
            'organizations' => [['id' => 42, 'name' => 'Blue Org']],
        ]])->reconcile();

        $this->assertResolved($ticket);
        $this->assertSame(1, $result->updated);
    }

    /**
     * Same fixture correction, and here it matters MORE: without `organizations[]` this test
     * still passed after the guard landed, but for the wrong reason — the ticket stayed open
     * because the payload was unreadable, not because the escalation was unresolved. That is a
     * test rotted into a tautology. With the org present it once again exercises what its name
     * claims.
     */
    public function test_id_bearing_ticket_with_still_open_escalation_stays_open(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client, metaEscalationId: 556);

        $this->service([], [556 => [
            'id' => 556,
            'status' => 'sent',
            'organizations' => [['id' => 42, 'name' => 'Blue Org']],
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
    }

    public function test_404_skips_the_ticket_through_real_client_exception_wrapping(): void
    {
        Log::spy();
        $ticket = $this->escalationTicket($this->mappedClient(), metaEscalationId: 555);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(404, [], '{}'),
            new Response(404, [], '{}'),
        ]));
        $stack->push(Middleware::history($history));
        $client = new HuntressClient([], new GuzzleClient([
            'base_uri' => 'https://api.huntress.io/v1/',
            'handler' => $stack,
        ]));
        $service = new HuntressEscalationReconcileService($client, app(TicketService::class), app(AlertService::class));

        $result = $service->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        $this->assertSame(AlertStatus::Ticketed, Alert::where('ticket_id', $ticket->id)->first()->status);
        $this->assertCount(1, $history, 'a stale id must not reach the org listing');
        $this->assertSame('/v1/escalations/555', $history[0]['request']->getUri()->getPath());
        // Warning, not info: 404 is the one status this code cannot attribute, and its
        // systemic causes hit every ticket at once. See the catch block's comment.
        Log::shouldHaveReceived('warning')->with(
            '[HuntressEscalationReconcile] getEscalation(555) returned 404; skipping — a stale id cannot fall back to org+subject+window matching',
            ['ticket_id' => $ticket->id],
        )->once();
        // No tombstone: the next run retries the id (a purge may be reversed upstream).
        $this->assertSame(0, $service->reconcile()->updated);
        $this->assertCount(2, $history);
        $this->assertSame('/v1/escalations/555', $history[1]['request']->getUri()->getPath());
    }

    /**
     * THE mis-close vector the 404 path must not open. Once the by-id read fails we can no
     * longer confirm WHICH org row is this ticket's, so a unique org+subject+window match
     * stops implying ownership and a lone survivor may be a sibling. Note the claim we do
     * NOT make, and which the class docblock refutes: a 404 is not evidence this ticket's
     * escalation is absent from the org listing.
     *
     * No sibling is fixtured, deliberately. `shouldNotReceive('getEscalations')` asserts the
     * listing is never consulted AT ALL for a stale-id ticket, which is strictly stronger
     * than asserting some particular sibling goes unmatched — it holds whatever the org
     * contains. That assertion is the whole test; the resolution assertions below would pass
     * against pre-change code too. This is a guard against reintroducing the fallback, not a
     * demonstration that today's code was broken.
     */
    public function test_404_does_not_close_the_ticket_off_a_sibling_escalation(): void
    {
        $ticket = $this->escalationTicket($this->mappedClient(), metaEscalationId: 555);
        $client = Mockery::mock(HuntressClient::class);
        $client->shouldReceive('getEscalation')->once()->with(555)->andThrow(new HuntressClientException('Not found', 404));
        $client->shouldNotReceive('getEscalations');

        $result = (new HuntressEscalationReconcileService($client, app(TicketService::class), app(AlertService::class)))->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        $this->assertSame(AlertStatus::Ticketed, Alert::where('ticket_id', $ticket->id)->first()->status);
    }

    #[DataProvider('non404Failures')]
    public function test_other_throwables_still_warn_and_skip_without_fallback(\Throwable $failure): void
    {
        Log::spy();
        $ticket = $this->escalationTicket($this->mappedClient(), metaEscalationId: 555);
        $client = Mockery::mock(HuntressClient::class);
        $client->shouldReceive('getEscalation')->twice()->with(555)->andThrow($failure);
        $client->shouldNotReceive('getEscalations');
        $service = new HuntressEscalationReconcileService($client, app(TicketService::class), app(AlertService::class));

        $this->assertSame(0, $service->reconcile()->updated);
        $this->assertSame(0, $service->reconcile()->updated);

        $this->assertStaysOpen($ticket);
        $this->assertSame(AlertStatus::Ticketed, Alert::where('ticket_id', $ticket->id)->first()->status);
        Log::shouldHaveReceived('warning')->with(
            "[HuntressEscalationReconcile] getEscalation(555) failed: {$failure->getMessage()}",
            ['ticket_id' => $ticket->id],
        )->twice();
        // Scoped to the one line that must not appear, not to a whole log level: these rows
        // take the generic-failure branch, so the 404 skip line is what proves they did not
        // take the 404 branch. A bare shouldNotHaveReceived('warning') would now be false,
        // and a bare shouldNotHaveReceived('info') would break on unrelated info logging.
        Log::shouldNotHaveReceived('warning', [
            '[HuntressEscalationReconcile] getEscalation(555) returned 404; skipping — a stale id cannot fall back to org+subject+window matching',
            ['ticket_id' => $ticket->id],
        ]);
    }

    public static function non404Failures(): array
    {
        return [
            'server error' => [new HuntressClientException('Unavailable', 503)],
            'unauthorized' => [new HuntressClientException('Unauthorized', 401)],
            'rate limited' => [new HuntressClientException('Rate limited', 429)],
            'transport' => [new HuntressClientException('Timeout')],
            'unrelated 404 code' => [new \RuntimeException('Not an HTTP error', 404)],
            'error' => [new \Error('Unexpected failure')],
        ];
    }

    /**
     * The escalation now has to carry the org it belongs to. That is a fixture correction, not
     * a change of what this test asserts: a real org-scoped escalation always returns its
     * `organizations[]`, and org 42 here is the same org the URL and the client already agreed
     * on. What it now additionally pins is that a text-recovered id still resolves normally on
     * the happy path — the ownership guard below is not a blanket ban on recovered ids.
     */
    public function test_id_recovered_from_escalations_url_in_source_alert_id_resolves(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket(
            $client,
            sourceAlertId: 'https://dashboard.huntress.io/org/42/escalations/777',
        );

        $result = $this->service([], [777 => [
            'id' => 777,
            'status' => 'sent',
            'resolved_at' => now()->toIso8601String(),
            'organizations' => [['id' => 42, 'name' => 'Blue Org']],
        ]])->reconcile();

        $this->assertResolved($ticket);
        $this->assertSame(1, $result->updated);
    }

    // ── ownership: a text-recovered id is not proof the escalation is ours ──

    /**
     * THE cross-client mis-close vector (#1390). `extractEscalationId()` will take an
     * `escalations/{id}` id out of ANY free text on the ticket or its alert, and the by-id
     * fast path then treats a successful fetch as positive correspondence. It is not: the
     * fetch only proves the escalation exists. Here client A's ticket carries client B's
     * escalation URL — a dashboard link quoted into the source id — and B's escalation is
     * resolved. Without the ownership guard A's ticket is auto-closed off B's escalation
     * while A's own incident is still live.
     */
    public function test_a_third_party_escalation_url_does_not_close_the_ticket(): void
    {
        Log::spy();
        $clientA = $this->mappedClient(42);
        $ticket = $this->escalationTicket(
            $clientA,
            sourceAlertId: 'https://dashboard.huntress.io/org/77/escalations/9001',
        );

        $result = $this->service([], [9001 => [
            'id' => 9001,
            'status' => 'resolved',
            'organizations' => [['id' => 77, 'name' => 'Someone Else']],
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        $this->assertSame(AlertStatus::Ticketed, Alert::where('ticket_id', $ticket->id)->first()->status);
        Log::shouldHaveReceived('warning')->with(
            "[HuntressEscalationReconcile] getEscalation(9001) belongs to a different organization than the ticket's client; skipping — resolving here would close one client's ticket off another client's escalation",
            // BOTH sides pinned: without them the warning says a mismatch happened but not
            // between what and what, and "is the escalation wrong or is the client mapped
            // wrong" is the first question anyone triaging this asks.
            ['ticket_id' => $ticket->id, 'escalation_org_ids' => [77], 'ticket_org_id' => 42],
        )->once();
    }

    /** Same vector, reached through the ticket DESCRIPTION — e.g. a quoted email or a reply. */
    public function test_a_third_party_escalation_url_in_the_description_does_not_close_the_ticket(): void
    {
        $ticket = $this->escalationTicket($this->mappedClient(42));
        // Written past the model so no observer manufactures a human-touch note.
        Ticket::query()->whereKey($ticket->id)->update([
            'description' => 'Customer forwarded: see https://dashboard.huntress.io/org/77/escalations/9002 for the write-up.',
        ]);

        $result = $this->service([], [9002 => [
            'id' => 9002,
            'status' => 'resolved',
            'organizations' => [['id' => 77, 'name' => 'Someone Else']],
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    /**
     * MISSING organizations[] is not the account-level shape. Both refuse now, but they refuse
     * with DIFFERENT messages and different remedies: an empty list says "this escalation
     * belongs to no org" and is a well-formed answer, while no `organizations` key at all says
     * nothing and is a malformed or truncated response. Reported as 'absent'.
     */
    public function test_a_recovered_id_fails_closed_when_organizations_is_absent(): void
    {
        Log::spy();
        $ticket = $this->escalationTicket(
            $this->mappedClient(42),
            sourceAlertId: 'https://dashboard.huntress.io/escalations/9003',
        );

        $result = $this->service([], [9003 => [
            'id' => 9003,
            'status' => 'resolved',
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        Log::shouldHaveReceived('warning')->with(
            '[HuntressEscalationReconcile] getEscalation(9003) returned no usable organizations[] and is not the documented account-level shape; skipping — correspondence cannot be established, so this fails closed',
            ['ticket_id' => $ticket->id, 'organizations_type' => 'absent'],
        )->once();
    }

    /**
     * PRESENT AND NULL, which is a THIRD upstream problem: the vendor answered and explicitly
     * said null where a list belongs. It refuses like the absent case but must not REPORT like
     * it — `?? null` collapsed the two into one 'null' reading, which is the reader's first
     * question ("is the key missing or is it there with the wrong shape?") answered wrongly.
     */
    public function test_a_recovered_id_fails_closed_when_organizations_is_present_and_null(): void
    {
        Log::spy();
        $ticket = $this->escalationTicket(
            $this->mappedClient(42),
            sourceAlertId: 'https://dashboard.huntress.io/escalations/9017',
        );

        $result = $this->service([], [9017 => [
            'id' => 9017,
            'status' => 'resolved',
            'organizations' => null,
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        Log::shouldHaveReceived('warning')->with(
            '[HuntressEscalationReconcile] getEscalation(9017) returned no usable organizations[] and is not the documented account-level shape; skipping — correspondence cannot be established, so this fails closed',
            ['ticket_id' => $ticket->id, 'organizations_type' => 'null'],
        )->once();
    }

    /**
     * A NON-EMPTY organizations[] whose entries yield no usable id is malformed, not
     * account-level. Without the present-and-empty requirement this would read as "no org
     * association" and sail through the exception.
     */
    public function test_a_recovered_id_fails_closed_when_organizations_entries_are_malformed(): void
    {
        Log::spy();
        $ticket = $this->escalationTicket(
            $this->mappedClient(42),
            sourceAlertId: 'https://dashboard.huntress.io/escalations/9013',
        );

        $result = $this->service([], [9013 => [
            'id' => 9013,
            'status' => 'resolved',
            'organizations' => [['name' => 'No id here'], ['id' => 'not-a-number']],
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        // organizations_type distinguishes THIS refusal (present, an array, but unreadable) from
        // the absent-key one above, which reports 'null'. A boolean present-flag reported both as
        // the same failure and sent the reader after the wrong upstream problem.
        Log::shouldHaveReceived('warning')->with(
            '[HuntressEscalationReconcile] getEscalation(9013) returned no usable organizations[] and is not the documented account-level shape; skipping — correspondence cannot be established, so this fails closed',
            ['ticket_id' => $ticket->id, 'organizations_type' => 'array'],
        )->once();
    }

    /**
     * TEXT-RECOVERED + MAPPED CLIENT. An earlier revision let account-level escalations through
     * on the reasoning that an org comparison against them is meaningless. This is the harm that
     * bought: ticket text quoting an account-level escalation URL — the "Failed to Deliver"
     * integration-health class, which fires account-wide and resolves routinely — closed the
     * ticket holding the quote, for a mapped client the escalation says nothing about. Having
     * nothing to compare is the absence of correspondence, not correspondence, so it now fails
     * closed with its own message.
     */
    public function test_a_recovered_id_fails_closed_for_an_account_level_escalation(): void
    {
        Log::spy();
        $ticket = $this->escalationTicket(
            $this->mappedClient(42),
            sourceAlertId: 'https://dashboard.huntress.io/escalations/9014',
        );

        $result = $this->service([], [9014 => [
            'id' => 9014,
            'status' => 'resolved',
            'organizations' => [],
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        // A DISTINCT message from the unreadable-payload refusal above: account-level is a
        // well-formed answer we decline to act on, not a payload we failed to read, and an
        // operator seeing this one should not go looking for a broken vendor response.
        Log::shouldHaveReceived('warning')->with(
            '[HuntressEscalationReconcile] getEscalation(9014) is account-level (organizations[] present and empty), so it corresponds to no client and cannot correspond to this ticket; skipping — an account-wide escalation resolving is not evidence about this ticket, so this fails closed',
            ['ticket_id' => $ticket->id],
        )->once();
    }

    /** The ticket side of the same unknown: no org mapping, so nothing to correspond against. */
    public function test_text_recovered_id_is_skipped_when_the_client_has_no_org_mapping(): void
    {
        Log::spy();
        $unmapped = Client::factory()->create(['huntress_organization_id' => null]);
        $ticket = $this->escalationTicket(
            $unmapped,
            sourceAlertId: 'https://dashboard.huntress.io/org/42/escalations/9004',
        );

        $result = $this->service([], [9004 => [
            'id' => 9004,
            'status' => 'resolved',
            'organizations' => [['id' => 42, 'name' => 'Blue Org']],
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        Log::shouldHaveReceived('warning')->with(
            "[HuntressEscalationReconcile] getEscalation(9004) is org-associated but the ticket's client has no huntress_organization_id; skipping — correspondence cannot be established, so this fails closed",
            ['ticket_id' => $ticket->id, 'escalation_org_ids' => [42]],
        )->once();
    }

    /**
     * The OTHER way the ticket's org id comes back null, and it is a different fault with a
     * different remedy: a Huntress ticket with no client at all. The candidate query filters on
     * source and status only, never on client_id, so such a ticket does reach this branch. One
     * message covering both cases would send an operator to the Huntress org-mapping screen for
     * what is actually a ticket missing its client.
     */
    public function test_a_ticket_with_no_client_fails_closed_with_its_own_diagnosis(): void
    {
        Log::spy();
        $ticket = $this->escalationTicket(
            $this->mappedClient(42),
            sourceAlertId: 'https://dashboard.huntress.io/org/42/escalations/9014',
        );
        $ticket->forceFill(['client_id' => null])->save();

        $result = $this->service([], [9014 => [
            'id' => 9014,
            'status' => 'resolved',
            'organizations' => [['id' => 42, 'name' => 'Blue Org']],
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        Log::shouldHaveReceived('warning')->with(
            '[HuntressEscalationReconcile] getEscalation(9014) is org-associated but the ticket has no client; skipping — correspondence cannot be established, so this fails closed',
            ['ticket_id' => $ticket->id, 'escalation_org_ids' => [42]],
        )->once();
    }

    /**
     * METADATA + MAPPED CLIENT — the same refusal reached by the other route. Metadata provenance
     * is not a guarantee of anything: HuntressService scrapes the id out of the vendor payload
     * body with an unanchored `escalations/(\d+)`, so a payload quoting an account-level
     * escalation lands one here just as description text does. Both routes must refuse, or the
     * "trusted metadata" distinction the previous revision was corrected for creeps back in.
     */
    public function test_metadata_captured_id_fails_closed_for_an_account_level_escalation(): void
    {
        Log::spy();
        $ticket = $this->escalationTicket($this->mappedClient(42), metaEscalationId: 9005);

        $result = $this->service([], [9005 => [
            'id' => 9005,
            'status' => 'resolved',
            'organizations' => [],
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        // The alert stays Ticketed: a refusal must not half-close the record either.
        $this->assertSame(AlertStatus::Ticketed, Alert::where('ticket_id', $ticket->id)->first()->status);
        Log::shouldHaveReceived('warning')->with(
            '[HuntressEscalationReconcile] getEscalation(9005) is account-level (organizations[] present and empty), so it corresponds to no client and cannot correspond to this ticket; skipping — an account-wide escalation resolving is not evidence about this ticket, so this fails closed',
            ['ticket_id' => $ticket->id],
        )->once();
    }

    /**
     * METADATA + UNMAPPED CLIENT. The refusal is decided by the ESCALATION's shape, so it must
     * not depend on the ticket side being well-formed. An unmapped client is the case where the
     * org-mismatch and no-mapping guards below can never fire — nothing on the ticket to compare
     * — which is precisely why the account-level exception was so dangerous here: there was no
     * second line of defence behind it. Note the account-level message, NOT the
     * no-huntress_organization_id one: this returns before the ticket is ever consulted.
     */
    public function test_an_account_level_escalation_fails_closed_for_an_unmapped_client(): void
    {
        Log::spy();
        $unmapped = Client::factory()->create(['huntress_organization_id' => null]);
        $ticket = $this->escalationTicket($unmapped, metaEscalationId: 9015);

        $result = $this->service([], [9015 => [
            'id' => 9015,
            'status' => 'resolved',
            'organizations' => [],
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        Log::shouldHaveReceived('warning')->with(
            '[HuntressEscalationReconcile] getEscalation(9015) is account-level (organizations[] present and empty), so it corresponds to no client and cannot correspond to this ticket; skipping — an account-wide escalation resolving is not evidence about this ticket, so this fails closed',
            ['ticket_id' => $ticket->id],
        )->once();
    }

    /**
     * CLIENTLESS. The candidate query filters on source and status only, never on client_id, so
     * a Huntress ticket with no client does reach this branch — and an account-level escalation
     * is the one shape that used to close it. The escalation-shape refusal fires first and says
     * account-level rather than "the ticket has no client": the ticket's defect is real but it is
     * not why we are declining, and a log line that named it would send an operator to fix the
     * wrong thing.
     */
    public function test_an_account_level_escalation_fails_closed_for_a_ticket_with_no_client(): void
    {
        Log::spy();
        $ticket = $this->escalationTicket(
            $this->mappedClient(42),
            sourceAlertId: 'https://dashboard.huntress.io/escalations/9016',
        );
        $ticket->forceFill(['client_id' => null])->save();

        $result = $this->service([], [9016 => [
            'id' => 9016,
            'status' => 'resolved',
            'organizations' => [],
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        Log::shouldHaveReceived('warning')->with(
            '[HuntressEscalationReconcile] getEscalation(9016) is account-level (organizations[] present and empty), so it corresponds to no client and cannot correspond to this ticket; skipping — an account-wide escalation resolving is not evidence about this ticket, so this fails closed',
            ['ticket_id' => $ticket->id],
        )->once();
    }

    /**
     * THE REGRESSION THE PREVIOUS REVISION MISSED, as a direct control on stored state: the id
     * sits in alert metadata — the path that revision exempted as "trusted" — and points at
     * another client's escalation. It must be refused exactly as a description-recovered id is.
     * Metadata is not a provenance guarantee: HuntressService scrapes that value out of the
     * vendor payload body with an unanchored `escalations/(\d+)`.
     */
    public function test_a_metadata_captured_id_for_another_clients_escalation_does_not_close_the_ticket(): void
    {
        Log::spy();
        $ticket = $this->escalationTicket($this->mappedClient(42), metaEscalationId: 9006);

        $result = $this->service([], [9006 => [
            'id' => 9006,
            'status' => 'resolved',
            'organizations' => [['id' => 77, 'name' => 'Someone Else']],
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
        $this->assertSame(AlertStatus::Ticketed, Alert::where('ticket_id', $ticket->id)->first()->status);
        Log::shouldHaveReceived('warning')->with(
            "[HuntressEscalationReconcile] getEscalation(9006) belongs to a different organization than the ticket's client; skipping — resolving here would close one client's ticket off another client's escalation",
            ['ticket_id' => $ticket->id, 'escalation_org_ids' => [77], 'ticket_org_id' => 42],
        )->once();
    }

    /**
     * The same regression END TO END, through real ingest rather than a hand-built alert. A
     * Huntress payload for company A whose body quotes an org-B escalation URL is ingested by
     * HuntressService::createTicketFromCw — which captures 9007 into alert metadata — and the
     * reconcile pass must still refuse to close A's ticket off B's resolved escalation. This
     * is the assertion that fails on the previous revision despite its own guard tests passing.
     */
    public function test_ingested_ticket_is_not_closed_by_an_escalation_from_another_org(): void
    {
        $clientA = Client::factory()->create([
            'stage' => ClientStage::Active,
            'is_active' => true,
            'huntress_organization_id' => 42,
        ]);

        // The org-77 escalations URL is the hostile part: client A's payload quoting another
        // tenant's escalation. The org-42 infection_reports URL is the same test artifact the
        // sibling ingest test uses — it steers dedup down the URL branch instead of the
        // subject-hash branch, whose whereRaw('MD5(subject)…') has no SQLite equivalent.
        app(HuntressService::class)->createTicketFromCw([
            'summary' => 'Huntress EDR High Escalation | Endpoints Missing Key EDR Functionality',
            'initialDescription' => 'Escalation https://dashboard.huntress.io/org/77/escalations/9007 '
                .'re: https://dashboard.huntress.io/org/42/infection_reports/9183',
            'company' => ['id' => $clientA->id],
        ]);

        $alert = Alert::where('source', AlertSource::Huntress->value)
            ->where('client_id', $clientA->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($alert, 'ingest did not produce an alert');
        $this->assertSame(9007, $alert->metadata['escalation_id'], 'ingest no longer captures the id — this test would pass vacuously');

        $ticket = Ticket::findOrFail($alert->ticket_id);

        $result = $this->service([], [9007 => [
            'id' => 9007,
            'status' => 'resolved',
            'organizations' => [['id' => 77, 'name' => 'Someone Else']],
        ]])->reconcile();

        // Not assertStaysOpen(): that helper pins InProgress, and a freshly ingested ticket is
        // New. What matters here is only that reconcile did not resolve or close it.
        $this->assertNotSame(TicketStatus::Resolved, $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->closed_at);
        $this->assertSame(0, $result->updated);
    }

    /** MATCHING-ORG control: the guard must not be a blanket ban — the happy path still closes. */
    public function test_a_metadata_captured_id_resolves_when_the_escalation_carries_the_ticket_org(): void
    {
        $ticket = $this->escalationTicket($this->mappedClient(42), metaEscalationId: 9008);

        $result = $this->service([], [9008 => [
            'id' => 9008,
            'status' => 'resolved',
            'organizations' => [['id' => 42, 'name' => 'Blue Org']],
        ]])->reconcile();

        $this->assertResolved($ticket);
        $this->assertSame(1, $result->updated);
    }

    /**
     * BARE-ID control. escalationOrgIds() accepts both `[['id' => 42]]` and a bare `[42]`;
     * nothing pinned the bare form, so a future "simplification" to `$org['id']` would break
     * ownership matching silently — and it would break it OPEN-to-closed, refusing valid work.
     */
    public function test_ownership_matching_accepts_a_bare_organization_id_list(): void
    {
        $ticket = $this->escalationTicket($this->mappedClient(42), metaEscalationId: 9009);

        $result = $this->service([], [9009 => [
            'id' => 9009,
            'status' => 'resolved',
            'organizations' => [42],
        ]])->reconcile();

        $this->assertResolved($ticket);
        $this->assertSame(1, $result->updated);
    }

    /**
     * STILL-OPEN control. Ownership is a precondition, not the verdict: an escalation that
     * passes the org check but is not resolved upstream must leave the ticket open. Without
     * this, a guard that accidentally returned true for "belongs to us" would read as green.
     */
    public function test_an_owned_but_unresolved_escalation_leaves_the_ticket_open(): void
    {
        $ticket = $this->escalationTicket($this->mappedClient(42), metaEscalationId: 9010);

        $result = $this->service([], [9010 => [
            'id' => 9010,
            'status' => 'sent',
            'resolved_at' => null,
            'organizations' => [['id' => 42, 'name' => 'Blue Org']],
        ]])->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    // ── scope ──────────────────────────────────────────────────────────────

    public function test_incident_ticket_is_never_touched(): void
    {
        $client = $this->mappedClient(42);
        // Incident tickets are the incident reconciler's job — even though a resolved escalation
        // for the org sits in the window, an "Incident on <host>" ticket is out of scope here.
        $incident = $this->escalationTicket($client, 'Huntress EDR Critical Incident Report | Incident on DESKTOP-ARL0EQ1 (Blue Org)');

        $escalations = [42 => [
            $this->escalationRow(780, 42, 'resolved', $incident->created_at, 'Incident on DESKTOP-ARL0EQ1'),
        ]];

        $result = $this->service($escalations)->reconcile();

        $this->assertStaysOpen($incident);
        $this->assertSame(0, $result->updated);
    }

    public function test_product_notice_without_escalation_marker_is_never_touched(): void
    {
        $client = $this->mappedClient(42);
        $notice = $this->escalationTicket($client, 'Huntress Product Notice | Scheduled Maintenance');

        $escalations = [42 => [
            $this->escalationRow(790, 42, 'resolved', $notice->created_at, 'Scheduled Maintenance'),
        ]];

        $this->service($escalations)->reconcile();

        $this->assertStaysOpen($notice);
    }

    public function test_ignores_non_huntress_tickets(): void
    {
        $client = $this->mappedClient(42);
        $ticket = Ticket::factory()->create([
            'source' => TicketSource::Manual->value,
            'status' => TicketStatus::InProgress->value,
            'client_id' => $client->id,
            'subject' => 'Huntress EDR High Escalation | Endpoints Missing Key EDR Functionality',
            'closed_at' => null,
        ]);

        $escalations = [42 => [
            $this->escalationRow(800, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality'),
        ]];

        $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
    }

    // ── guards ─────────────────────────────────────────────────────────────

    public function test_is_idempotent_across_runs(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);
        $escalations = [42 => [$this->escalationRow(810, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality')]];

        $this->service($escalations)->reconcile();
        $this->service($escalations)->reconcile();

        $resolveNotes = TicketNote::where('ticket_id', $ticket->id)
            ->where('status_to', TicketStatus::Resolved->value)
            ->count();
        $this->assertSame(1, $resolveNotes);
        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    public function test_skips_a_ticket_a_human_has_taken_over(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);
        TicketNote::create([
            'ticket_id' => $ticket->id,
            'author_id' => $this->systemUser->id,
            'body' => 'Handling this manually.',
            'note_type' => NoteType::Note->value,
            'is_private' => true,
            'noted_at' => now(),
        ]);

        $escalations = [42 => [$this->escalationRow(820, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality')]];
        $result = $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
        $this->assertSame(0, $result->updated);
    }

    public function test_skips_a_ticket_with_an_end_user_reply(): void
    {
        $client = $this->mappedClient(42);
        $ticket = $this->escalationTicket($client);
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

        $escalations = [42 => [$this->escalationRow(830, 42, 'resolved', $ticket->created_at, 'Endpoints Missing Key EDR Functionality')]];
        $this->service($escalations)->reconcile();

        $this->assertStaysOpen($ticket);
    }

    public function test_gracefully_skips_when_client_has_no_org_mapping_and_no_id(): void
    {
        $unmapped = Client::factory()->create(['huntress_organization_id' => null]);
        $stuck = $this->escalationTicket($unmapped);

        $client = $this->mappedClient(42);
        $resolvable = $this->escalationTicket($client);
        $escalations = [42 => [$this->escalationRow(840, 42, 'resolved', $resolvable->created_at, 'Endpoints Missing Key EDR Functionality')]];

        $result = $this->service($escalations)->reconcile();

        $this->assertStaysOpen($stuck);
        $this->assertSame(TicketStatus::Resolved, $resolvable->fresh()->status);
        $this->assertSame(1, $result->updated);
    }

    // ── ingest fix: capture the escalation id when the payload carries an escalations URL ──

    public function test_ingest_captures_escalation_id_from_payload_url(): void
    {
        $client = Client::factory()->create([
            'stage' => ClientStage::Active,
            'is_active' => true,
            'huntress_organization_id' => 42,
        ]);

        // The escalations URL is what we capture. The infection_reports URL is a test artifact:
        // it steers createTicketFromCw's dedup down the URL branch instead of the subject-hash
        // branch, whose whereRaw('MD5(subject)…') has no SQLite equivalent (prod is MariaDB).
        app(HuntressService::class)->createTicketFromCw([
            'summary' => 'Huntress EDR High Escalation | Endpoints Missing Key EDR Functionality',
            'initialDescription' => 'Escalation https://dashboard.huntress.io/org/42/escalations/9182 '
                .'re: https://dashboard.huntress.io/org/42/infection_reports/9183',
            'company' => ['id' => $client->id],
        ]);

        $alert = Alert::where('source', AlertSource::Huntress->value)
            ->where('client_id', $client->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame(9182, $alert->metadata['escalation_id']);
    }

    // ── command ────────────────────────────────────────────────────────────

    public function test_command_fails_cleanly_when_huntress_is_not_configured(): void
    {
        $this->artisan('huntress:reconcile-escalations')->assertExitCode(1);
    }
}
