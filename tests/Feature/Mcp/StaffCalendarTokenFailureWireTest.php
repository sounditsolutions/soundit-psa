<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Graph\GraphClient;
use App\Services\Mcp\StaffCalendarToolExecutor;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * #6022 / #6018 / #6014 / #6016: the calendar write executor over a REAL GraphClient, so the
 * token-failure arms are graded at the wire rather than through a mocked cancelEvent().
 *
 * - #6022: a cold-cache token failure on each write tool (create, update with a body, cancel,
 *   respond) sends no request to graph.microsoft.com, immediate and staged.
 * - #6018: each of those four tools takes the 'not sent' arm on both paths; update with a body
 *   fails on its getEvent() pre-read, which is also unsent.
 * - #6014: a write that WAS sent, answered 401, and whose refresh then failed
 *   (GraphTokenRefreshFailedException) stays on the indeterminate arm: a non-idempotent cancel
 *   is held Executing, not reopened, and no 'not sent' row is written.
 * - #6016: when releaseClaim()'s CAS loses (the run left Executing while the write was being
 *   tried), the decline does not say the run was reopened.
 *
 * G-5: every request goes to a scripted MockHandler on both GraphClient clients (the token leg
 * included), and Http::preventStrayRequests() is on. Synthetic values only (G-13).
 */
class StaffCalendarTokenFailureWireTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'owner@example.test';

    private const EVENT_ID = 'EVT-SYNTHETIC-6022';

    private const ISSUED_ACCESS_FIXTURE = 'wire6022-synthetic-issued-access';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    private MockHandler $mock;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $actor = User::factory()->create(['name' => 'Chet']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $this->approver = User::factory()->create(['name' => 'Gus']);
        Setting::setValue('calendar_enabled', '1');
        Setting::setValue('calendar_allowed_owner_upns', json_encode([self::OWNER]));
    }

    /** Bind a real GraphClient over $responses, with an empty (cold) token cache. */
    private function graph(Response|\Closure ...$responses): void
    {
        $this->history = [];
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        $this->app->instance(GraphClient::class, new GraphClient([
            'tenant_id' => 'tenant-wire6022-synthetic',
            'client_id' => 'client-wire6022',
            'client_secret' => 'wire6022-synthetic-secret-not-real',
            'request_timeout' => 5,
            'token_timeout' => 5,
            'handler' => $stack,
        ], new Repository(new ArrayStore)));
    }

    private static function tokenRefused(): Response
    {
        return new Response(400, ['Content-Type' => 'application/json'], (string) json_encode(['error' => 'invalid_client']));
    }

    /**
     * Queued after the token failure. A request that went out would take it (an empty queue
     * throws before the history middleware records the request), so it still being queued
     * shows that nothing was sent.
     */
    private static function spare(): Response
    {
        return new Response(202, [], '');
    }

    private static function tokenIssued(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['access_token' => self::ISSUED_ACCESS_FIXTURE, 'expires_in' => 3600]));
    }

    /** @return list<RequestInterface> requests to the given host */
    private function requestsTo(string $host): array
    {
        return array_values(array_map(
            fn (array $h) => $h['request'],
            array_filter($this->history, fn (array $h) => $h['request']->getUri()->getHost() === $host),
        ));
    }

    /**
     * [immediate tool, staged tool, arguments without ticket_id]. Update carries a body, so the
     * executor's getEvent() pre-read (updateBodyPreservingTeamsJoin) is the first Graph call.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function writes(): array
    {
        return [
            'create' => ['calendar_create_event', 'calendar_stage_create_event', [
                'user_upn' => self::OWNER, 'subject' => 'Onsite', 'start' => '2026-07-29T15:00:00', 'end' => '2026-07-29T16:00:00', 'reason' => 'Asked.',
            ]],
            'update with a body' => ['calendar_update_event', 'calendar_stage_update_event', [
                'user_upn' => self::OWNER, 'event_id' => self::EVENT_ID, 'body' => 'New agenda', 'reason' => 'Asked.',
            ]],
            'cancel' => ['calendar_cancel_event', 'calendar_stage_cancel_event', [
                'user_upn' => self::OWNER, 'event_id' => self::EVENT_ID, 'comment' => 'x', 'reason' => 'Resolved.',
            ]],
            'respond' => ['calendar_respond_event', 'calendar_stage_respond_event', [
                'user_upn' => self::OWNER, 'event_id' => self::EVENT_ID, 'response' => 'accept', 'reason' => 'Asked.',
            ]],
        ];
    }

    /** #6022 / #6018: immediate path, cold cache, token endpoint refuses. */
    #[\PHPUnit\Framework\Attributes\DataProvider('writes')]
    public function test_a_cold_token_immediate_write_sends_nothing_to_graph(string $tool, string $stagedTool, array $arguments): void
    {
        $ticket = Ticket::factory()->create();
        $this->graph(self::tokenRefused(), self::spare());

        $result = app(StaffCalendarToolExecutor::class)->execute($tool, $arguments + ['ticket_id' => $ticket->id], 0, 'mcp-staff:chet');

        $this->assertSame(['error' => 'The calendar write was not sent: the PSA could not obtain a Microsoft Graph access token. Nothing was written to the calendar, so a retry cannot duplicate it.'], $result);
        $this->assertCount(1, $this->requestsTo('login.microsoftonline.com'), 'positive control: the token request ran');
        $this->assertSame([], $this->requestsTo('graph.microsoft.com'), 'nothing reached Graph');
        $this->assertCount(1, $this->mock, 'the spare response is still queued');
        $this->assertCount(1, $this->history, 'and nothing else was requested');
        $this->assertSame(
            ['Graph calendar write not sent (no Graph access token was obtained): Failed to obtain Graph API token (HTTP 400)'],
            TechnicianActionLog::where('ticket_id', $ticket->id)->where('result_status', 'error')->pluck('summary')->all(),
        );
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', '%indeterminate%')->count());
    }

    /** #6022 / #6018: staged path. The run is reopened and a re-approve is offered. */
    #[\PHPUnit\Framework\Attributes\DataProvider('writes')]
    public function test_a_cold_token_staged_write_sends_nothing_and_reopens_the_run(string $tool, string $stagedTool, array $arguments): void
    {
        $run = $this->staged($stagedTool, $arguments);
        $this->graph(self::tokenRefused(), self::spare());

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);

        $this->assertSame('gate_declined', $r->status);
        $this->assertSame('The calendar write was not sent: the PSA could not obtain a Microsoft Graph access token. Nothing was written to the calendar; the run is reopened, so re-approve to retry.', $r->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertCount(1, $this->requestsTo('login.microsoftonline.com'), 'positive control: the token request ran');
        $this->assertSame([], $this->requestsTo('graph.microsoft.com'), 'nothing reached Graph');
        $this->assertCount(1, $this->mock, 'the spare response is still queued');
        $this->assertCount(1, $this->history, 'and nothing else was requested (#6122)');
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', '%indeterminate%')->count());
    }

    /**
     * #6010: the audit row keeps a 140-character slice of the real GraphTokenException message.
     * On each getToken() arm the message is fixed text plus a status, the Guzzle exception
     * class or a field name, so the row carries no tenant, token endpoint path, identity
     * provider text or token. Each failure below plants all of those where Guzzle's own message
     * or the response body would carry them.
     *
     * @return array<string, array{0: \Closure(): (Response|\Closure), 1: string}>
     */
    public static function tokenFailureMessages(): array
    {
        $idp = 'AADSTS7000215 B4N-SYNTHETIC-IDP-MARKER';

        return [
            'token endpoint answered 400' => [fn () => new Response(400, ['Content-Type' => 'application/json'], (string) json_encode(['error' => 'invalid_client', 'error_description' => $idp])), 'Failed to obtain Graph API token (HTTP 400)'],
            'no response' => [fn () => fn (RequestInterface $request) => new \GuzzleHttp\Exception\ConnectException('cURL error 7: '.$idp.' '.$request->getUri(), $request), 'Failed to obtain Graph API token (GuzzleHttp\Exception\ConnectException)'],
            'no access_token' => [fn () => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['error_description' => $idp])), 'Graph API token response did not contain access_token'],
            'malformed expires_in' => [fn () => new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['access_token' => self::ISSUED_ACCESS_FIXTURE, 'expires_in' => $idp])), 'Graph API token response carried a malformed expires_in'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tokenFailureMessages')]
    public function test_the_audit_slice_of_a_real_token_failure_carries_no_vendor_text(\Closure $failure, string $message): void
    {
        $ticket = Ticket::factory()->create();
        $this->graph($failure(), self::spare());

        app(StaffCalendarToolExecutor::class)->execute('calendar_cancel_event', self::writes()['cancel'][2] + ['ticket_id' => $ticket->id], 0, 'mcp-staff:chet');

        $this->assertCount(1, $this->requestsTo('login.microsoftonline.com'), 'positive control: the token request ran');
        $summaries = TechnicianActionLog::where('ticket_id', $ticket->id)->where('result_status', 'error')->pluck('summary')->all();
        $this->assertSame(['Graph calendar write not sent (no Graph access token was obtained): '.$message], $summaries);
        foreach (['tenant-wire6022-synthetic', 'login.microsoftonline.com', 'oauth2', 'AADSTS', 'B4N-SYNTHETIC-IDP-MARKER', 'cURL', self::ISSUED_ACCESS_FIXTURE, 'wire6022-synthetic-secret-not-real'] as $needle) {
            $this->assertStringNotContainsString($needle, $summaries[0]);
        }
    }

    /**
     * #6129: an update with no body makes no getEvent() pre-read, so a cold-token failure is
     * raised by updateEvent()'s own send, on both paths, and takes the 'not sent' arm.
     */
    public function test_a_cold_token_update_without_a_body_fails_on_update_events_own_send(): void
    {
        $arguments = ['user_upn' => self::OWNER, 'event_id' => self::EVENT_ID, 'subject' => 'Moved', 'reason' => 'Asked.'];
        $ticket = Ticket::factory()->create();
        $this->graph(self::tokenRefused(), self::spare());
        $this->assertSame(
            ['error' => 'The calendar write was not sent: the PSA could not obtain a Microsoft Graph access token. Nothing was written to the calendar, so a retry cannot duplicate it.'],
            app(StaffCalendarToolExecutor::class)->execute('calendar_update_event', $arguments + ['ticket_id' => $ticket->id], 0, 'mcp-staff:chet'),
        );
        $this->assertSame([], $this->requestsTo('graph.microsoft.com'), 'nothing reached Graph');
        $this->assertCount(1, $this->history);

        $run = $this->staged('calendar_stage_update_event', $arguments);
        $this->graph(self::tokenRefused(), self::spare());
        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);
        $this->assertSame('The calendar write was not sent: the PSA could not obtain a Microsoft Graph access token. Nothing was written to the calendar; the run is reopened, so re-approve to retry.', $r->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
        $this->assertCount(1, $this->history);

        // Positive control: with a token, the same update sends exactly one PATCH and no GET.
        $this->graph(self::tokenIssued(), new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['id' => self::EVENT_ID, 'subject' => 'Moved'])));
        $this->assertSame('executed', app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id)->status);
        $this->assertSame(['PATCH'], array_map(fn (RequestInterface $r) => $r->getMethod(), $this->requestsTo('graph.microsoft.com')));
    }

    /**
     * #6121: on an update with a body, the getEvent() pre-read is sent and answered 401, and the
     * refresh fails. Only that GET was sent, so the outcome is determinate: the staged run is
     * reopened (CAS won) and nothing says indeterminate; the immediate path says not sent.
     * A 500 on the pre-read is the same.
     *
     * @return array<string, array{0: \Closure(): list<Response>}>
     */
    public static function preReadFailures(): array
    {
        return [
            '401, refresh fails' => [fn () => [self::tokenIssued(), new Response(401, [], '{}'), self::tokenRefused(), self::spare()]],
            '500' => [fn () => [self::tokenIssued(), new Response(500, [], '{}'), self::spare()]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('preReadFailures')]
    public function test_a_failed_update_pre_read_is_not_sent_and_reopens_the_run(\Closure $responses): void
    {
        $run = $this->staged('calendar_stage_update_event', self::writes()['update with a body'][2]);
        $this->graph(...$responses());

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);

        $this->assertSame(['GET'], array_map(fn (RequestInterface $q) => $q->getMethod(), $this->requestsTo('graph.microsoft.com')), 'positive control: only the pre-read was sent');
        $this->assertCount(1, $this->mock, 'the spare response is still queued: no PATCH');
        $this->assertSame('gate_declined', $r->status);
        $this->assertStringStartsWith('The calendar update was not sent: reading the event before the update failed upstream (Microsoft Graph). Nothing was written to the calendar; the run is reopened, so re-approve to retry.', (string) $r->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'not held Executing');
        $summaries = TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'error')->pluck('summary')->all();
        $this->assertCount(1, $summaries);
        $this->assertStringStartsWith('Graph calendar write not sent (the event read before the update failed): Graph API error: GET ', $summaries[0]);
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', '%indeterminate%')->count());
    }

    /** #6121 / #6016: on the pre-read arm too, a lost CAS is not reported as a reopen. */
    public function test_a_failed_update_pre_read_whose_release_loses_does_not_say_reopened(): void
    {
        $run = $this->staged('calendar_stage_update_event', self::writes()['update with a body'][2]);
        $this->graph(self::tokenIssued(), function () use ($run) {
            TechnicianRun::whereKey($run->id)->update(['state' => TechnicianRunState::Flagged->value]);

            return new Response(500, [], '{}');
        });

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);

        $this->assertSame('The calendar update was not sent: reading the event before the update failed upstream (Microsoft Graph). Nothing was written to the calendar; the run was not reopened; check its current state before acting on it again.', $r->message);
        $this->assertSame(TechnicianRunState::Flagged, $run->fresh()->state, 'positive control: the CAS lost');
        $this->assertSame(['GET'], array_map(fn (RequestInterface $q) => $q->getMethod(), $this->requestsTo('graph.microsoft.com')));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('preReadFailures')]
    public function test_a_failed_immediate_update_pre_read_is_not_sent(\Closure $responses): void
    {
        $ticket = Ticket::factory()->create();
        $this->graph(...$responses());

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_update_event', self::writes()['update with a body'][2] + ['ticket_id' => $ticket->id], 0, 'mcp-staff:chet');

        $this->assertSame(['GET'], array_map(fn (RequestInterface $q) => $q->getMethod(), $this->requestsTo('graph.microsoft.com')));
        $this->assertSame(['error' => 'The calendar update was not sent: reading the event before the update failed upstream (Microsoft Graph). Nothing was written to the calendar, so a retry cannot duplicate it.'], $result);
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', '%indeterminate%')->count());
    }

    /**
     * #6014: the cancel WAS sent and Graph answered 401; the refresh then failed. That is a
     * GraphTokenRefreshFailedException, not a GraphTokenException, so the staged run is held
     * Executing as indeterminate (cancel is not retry-safe), never reopened as 'not sent'.
     */
    public function test_a_sent_cancel_whose_401_refresh_fails_is_held_not_reopened(): void
    {
        $run = $this->staged('calendar_stage_cancel_event', self::writes()['cancel'][2]);
        $this->graph(self::tokenIssued(), new Response(401, [], '{}'), self::tokenRefused());

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);

        $this->assertCount(1, $this->requestsTo('graph.microsoft.com'), 'positive control: the cancel was sent');
        $this->assertCount(2, $this->requestsTo('login.microsoftonline.com'), 'positive control: the first token and the failed refresh');
        $this->assertSame('gate_declined', $r->status);
        $this->assertStringContainsString('outcome unknown', (string) $r->message);
        $this->assertStringNotContainsString('not sent', (string) $r->message);
        $this->assertSame(TechnicianRunState::Executing, $run->fresh()->state, 'held for manual verification, not reopened');
        $summaries = TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'error')->pluck('summary')->all();
        $this->assertCount(1, $summaries);
        $this->assertStringStartsWith('Graph calendar write failed/timed out at approval (indeterminate outcome): ', $summaries[0]);
        $this->assertSame('already_handled', app(StaffCalendarToolExecutor::class)->approveStagedRun($run->fresh(), $this->approver->id)->status, 'a re-approve cannot resend it');
    }

    /** #6014: the same on the immediate path: the error is the indeterminate one. */
    public function test_a_sent_immediate_cancel_whose_401_refresh_fails_is_indeterminate(): void
    {
        $ticket = Ticket::factory()->create();
        $this->graph(self::tokenIssued(), new Response(401, [], '{}'), self::tokenRefused());

        $result = app(StaffCalendarToolExecutor::class)->execute('calendar_cancel_event', self::writes()['cancel'][2] + ['ticket_id' => $ticket->id], 0, 'mcp-staff:chet');

        $this->assertCount(1, $this->requestsTo('graph.microsoft.com'), 'positive control: the cancel was sent');
        $this->assertStringContainsString('INDETERMINATE', (string) ($result['error'] ?? ''));
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', 'Graph calendar write not sent%')->count(), 'no not-sent row');
    }

    /**
     * #6016: the run leaves Executing while the write is tried (here the token request moves it,
     * as another path could), so releaseClaim()'s CAS loses. The decline must not say the run
     * was reopened or offer a re-approve, and the run keeps the state the other path gave it.
     */
    public function test_a_token_failure_whose_release_loses_does_not_say_reopened(): void
    {
        $run = $this->staged('calendar_stage_cancel_event', self::writes()['cancel'][2]);
        $this->graph(function () use ($run) {
            TechnicianRun::whereKey($run->id)->update(['state' => TechnicianRunState::Flagged->value]);

            return self::tokenRefused();
        }, self::spare());

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);

        $this->assertSame('gate_declined', $r->status);
        $this->assertSame('The calendar write was not sent: the PSA could not obtain a Microsoft Graph access token. Nothing was written to the calendar; the run was not reopened; check its current state before acting on it again.', $r->message);
        $this->assertSame(TechnicianRunState::Flagged, $run->fresh()->state, 'positive control: the CAS lost');
        // #6122: the same controls as the other wire rows.
        $this->assertCount(1, $this->requestsTo('login.microsoftonline.com'), 'positive control: the token request ran');
        $this->assertSame([], $this->requestsTo('graph.microsoft.com'), 'nothing reached Graph');
        $this->assertCount(1, $this->mock, 'the spare response is still queued');
        $this->assertCount(1, $this->history, 'and nothing else was requested');
        $this->assertSame(
            ['Graph calendar write not sent (no Graph access token was obtained): Failed to obtain Graph API token (HTTP 400)'],
            TechnicianActionLog::where('run_id', $run->id)->where('result_status', 'error')->pluck('summary')->all(),
        );
        $this->assertSame(0, TechnicianActionLog::where('summary', 'like', '%indeterminate%')->count());
    }

    /** #6016: the same on the retry-safe create arm of an upstream failure. */
    public function test_a_retry_safe_create_failure_whose_release_loses_does_not_offer_a_re_approve(): void
    {
        $run = $this->staged('calendar_stage_create_event', self::writes()['create'][2]);
        $this->graph(self::tokenIssued(), function () use ($run) {
            TechnicianRun::whereKey($run->id)->update(['state' => TechnicianRunState::Flagged->value]);

            return new Response(500, [], '{}');
        });

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);

        $this->assertSame('gate_declined', $r->status);
        $this->assertSame('The calendar write failed upstream (Microsoft Graph); it is safe to retry (Graph de-duplicates the create by transaction id). The run was not reopened; check its current state before acting on it again.', $r->message);
        $this->assertSame(TechnicianRunState::Flagged, $run->fresh()->state, 'positive control: the CAS lost');
    }

    /** #6016 control: with the CAS won, the retry-safe create arm still offers the re-approve. */
    public function test_a_retry_safe_create_failure_whose_release_wins_offers_a_re_approve(): void
    {
        $run = $this->staged('calendar_stage_create_event', self::writes()['create'][2]);
        $this->graph(self::tokenIssued(), new Response(500, [], '{}'));

        $r = app(StaffCalendarToolExecutor::class)->approveStagedRun($run, $this->approver->id);

        $this->assertSame('The calendar write failed upstream (Microsoft Graph); it is safe to retry (Graph de-duplicates the create by transaction id). Re-approve to retry.', $r->message);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state);
    }

    /** Stage $tool on a fresh ticket with no Graph call, and return the run. */
    private function staged(string $stagedTool, array $arguments): TechnicianRun
    {
        $this->graph();
        $ticket = Ticket::factory()->create();
        $staged = app(StaffCalendarToolExecutor::class)->execute($stagedTool, $arguments + ['ticket_id' => $ticket->id], 0, 'mcp-staff:chet', 'chet');
        $this->assertSame([], $this->history, 'staging calls nothing');

        return TechnicianRun::findOrFail($staged['run_id']);
    }
}
