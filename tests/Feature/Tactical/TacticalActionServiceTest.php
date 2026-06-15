<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\TacticalActionLog;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tactical\Actions\InvalidActionParams;
use App\Services\Tactical\Actions\TacticalAction;
use App\Services\Tactical\Actions\TacticalActionResult;
use App\Services\Tactical\TacticalActionConfirmToken;
use App\Services\Tactical\TacticalActionService;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 5 (P2): the action bus — resolve → authorize → validate → confirm →
 * execute → classify → audit (spec §5.1, §5.2, amendments M1/M2/B1/m2).
 *
 * The bus is driven with the T1 injection seam (a MockHandler-backed
 * TacticalClient) and small fake TacticalActions. Every path returns a
 * normalized result AND writes exactly one immutable audit row.
 */
class TacticalActionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function asset(bool $linked = true, string $agentId = 'AGENT-1'): Asset
    {
        $asset = Asset::factory()->create(['hostname' => 'WORKSTATION-01']);

        if ($linked) {
            TacticalAsset::create([
                'asset_id' => $asset->id,
                'agent_id' => $agentId,
                'hostname' => 'WORKSTATION-01',
                'status' => 'online',
            ]);
            $asset->refresh();
        }

        return $asset;
    }

    /** A bus whose TacticalClient is backed by the given mock transport queue. */
    private function busReturning(array $queue): TacticalActionService
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $http = new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]);

        return new TacticalActionService(new TacticalClient($http));
    }

    /** A non-destructive fake action that returns whatever the client gives back. */
    private function okAction(string $key = 'tactical.fake'): TacticalAction
    {
        return new class($key) implements TacticalAction
        {
            public function __construct(private string $key) {}

            public function key(): string
            {
                return $this->key;
            }

            public function isDestructive(): bool
            {
                return false;
            }

            public function validateParams(array $params): array
            {
                return $params;
            }

            public function summary(array $params): string
            {
                return 'fake action';
            }

            public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
            {
                $resp = $client->post("agents/{$agentId}/fake/", $params);

                return TacticalActionResult::ok($resp['stdout'] ?? null, $resp['retcode'] ?? 0);
            }
        };
    }

    // ── resolve ──────────────────────────────────────────────────────────

    public function test_unlinked_asset_returns_error_and_audits(): void
    {
        $asset = $this->asset(linked: false);
        $actor = User::factory()->create();

        $result = $this->busReturning([])->dispatch($this->okAction(), $asset, $actor, []);

        $this->assertSame('error', $result->status);
        $this->assertStringContainsStringIgnoringCase('not linked', (string) $result->message);

        $this->assertSame(1, TacticalActionLog::count());
        $this->assertDatabaseHas('tactical_action_logs', [
            'asset_id' => $asset->id,
            'result_status' => 'error',
        ]);
    }

    // ── authorize ────────────────────────────────────────────────────────

    public function test_no_actor_and_no_label_is_denied_and_audited(): void
    {
        $asset = $this->asset();

        $result = $this->busReturning([])->dispatch($this->okAction(), $asset, null, []);

        $this->assertSame('denied', $result->status);
        $this->assertDatabaseHas('tactical_action_logs', ['result_status' => 'denied']);
        $this->assertSame(1, TacticalActionLog::count());
    }

    public function test_ai_label_actor_is_authorized_and_audited_as_ai(): void
    {
        // M1: the AI-triage path has no User; it attributes via actorLabel.
        $asset = $this->asset();

        $result = $this->busReturning([new Response(200, [], json_encode(['stdout' => 'diag', 'retcode' => 0]))])
            ->dispatch($this->okAction(), $asset, null, [], null, 'ai-triage');

        $this->assertSame('ok', $result->status);
        $this->assertDatabaseHas('tactical_action_logs', [
            'actor_id' => null,
            'actor_label' => 'ai-triage',
            'result_status' => 'ok',
        ]);
    }

    // ── validateParams ───────────────────────────────────────────────────

    public function test_invalid_params_are_rejected_and_not_executed(): void
    {
        $asset = $this->asset();
        $actor = User::factory()->create();

        $action = new class implements TacticalAction
        {
            public bool $executed = false;

            public function key(): string
            {
                return 'tactical.fake';
            }

            public function isDestructive(): bool
            {
                return false;
            }

            public function validateParams(array $params): array
            {
                throw new InvalidActionParams('missing script');
            }

            public function summary(array $params): string
            {
                return 'fake';
            }

            public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
            {
                $this->executed = true;

                return TacticalActionResult::ok();
            }
        };

        $result = $this->busReturning([])->dispatch($action, $asset, $actor, []);

        $this->assertSame('rejected', $result->status);
        $this->assertStringContainsStringIgnoringCase('missing script', (string) $result->message);
        $this->assertFalse($action->executed, 'action must NOT execute on invalid params');
        $this->assertDatabaseHas('tactical_action_logs', ['result_status' => 'rejected']);
    }

    // ── confirm (destructive) ─────────────────────────────────────────────

    private function destructiveAction(): TacticalAction
    {
        return new class implements TacticalAction
        {
            public function key(): string
            {
                return 'tactical.reboot';
            }

            public function isDestructive(): bool
            {
                return true;
            }

            public function validateParams(array $params): array
            {
                return $params;
            }

            public function summary(array $params): string
            {
                return 'reboot the box';
            }

            public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
            {
                $client->post("agents/{$agentId}/reboot/", []);

                return TacticalActionResult::ok('rebooting', 0);
            }
        };
    }

    public function test_destructive_without_token_is_blocked_and_audited(): void
    {
        $asset = $this->asset();
        $actor = User::factory()->create();

        $result = $this->busReturning([])->dispatch($this->destructiveAction(), $asset, $actor, []);

        $this->assertSame('blocked', $result->status);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.reboot',
            'result_status' => 'blocked',
        ]);
    }

    public function test_destructive_with_valid_token_proceeds(): void
    {
        $asset = $this->asset(agentId: 'AGENT-1');
        $actor = User::factory()->create();

        $token = TacticalActionConfirmToken::issue('tactical.reboot', 'AGENT-1', $actor->id);

        $result = $this->busReturning([new Response(200, [], json_encode([]))])
            ->dispatch($this->destructiveAction(), $asset, $actor, [], $token);

        $this->assertSame('ok', $result->status);
        $this->assertDatabaseHas('tactical_action_logs', [
            'action_key' => 'tactical.reboot',
            'result_status' => 'ok',
        ]);
    }

    public function test_destructive_with_token_for_a_different_agent_is_blocked(): void
    {
        $asset = $this->asset(agentId: 'AGENT-1');
        $actor = User::factory()->create();

        // Token bound to a DIFFERENT agent — must not authorize this dispatch.
        $token = TacticalActionConfirmToken::issue('tactical.reboot', 'OTHER-AGENT', $actor->id);

        $result = $this->busReturning([])->dispatch($this->destructiveAction(), $asset, $actor, [], $token);

        $this->assertSame('blocked', $result->status);
    }

    // ── execute + classify (M2) ───────────────────────────────────────────

    public function test_happy_path_returns_ok_with_correlation_id(): void
    {
        $asset = $this->asset();
        $actor = User::factory()->create();

        $result = $this->busReturning([new Response(200, [], json_encode(['stdout' => 'hello', 'retcode' => 0]))])
            ->dispatch($this->okAction(), $asset, $actor, []);

        $this->assertSame('ok', $result->status);
        $this->assertSame('hello', $result->stdout);

        $row = TacticalActionLog::sole();
        $this->assertNotEmpty($row->correlation_id);
        $this->assertSame($actor->id, $row->actor_id);
        $this->assertSame($asset->id, $row->asset_id);
        $this->assertSame('hello', $row->output);
    }

    public function test_transport_failure_classifies_as_offline(): void
    {
        $asset = $this->asset();
        $actor = User::factory()->create();

        $result = $this->busReturning([
            new ConnectException('Connection timed out', new Request('POST', 'agents/AGENT-1/fake/')),
        ])->dispatch($this->okAction(), $asset, $actor, []);

        $this->assertSame('offline', $result->status, 'a transport failure must classify as offline');
        $this->assertDatabaseHas('tactical_action_logs', ['result_status' => 'offline']);
    }

    public function test_http_403_classifies_as_error_not_offline(): void
    {
        // M2: a 401/403/404/5xx is an auth/HTTP error and must NEVER be offline.
        $asset = $this->asset();
        $actor = User::factory()->create();

        $result = $this->busReturning([new Response(403, [], 'forbidden by role')])
            ->dispatch($this->okAction(), $asset, $actor, []);

        $this->assertSame('error', $result->status, 'a 403 must classify as error, never offline');
        $this->assertNotSame('offline', $result->status);
        $this->assertDatabaseHas('tactical_action_logs', ['result_status' => 'error']);
    }

    /** A fake action whose execute() throws the given client exception. */
    private function throwingAction(TacticalClientException $e): TacticalAction
    {
        return new class($e) implements TacticalAction
        {
            public function __construct(private TacticalClientException $e) {}

            public function key(): string
            {
                return 'tactical.fake';
            }

            public function isDestructive(): bool
            {
                return false;
            }

            public function validateParams(array $params): array
            {
                return $params;
            }

            public function summary(array $params): string
            {
                return 'fake';
            }

            public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
            {
                throw $this->e;
            }
        };
    }

    public function test_http_400_with_offline_marker_body_classifies_as_offline(): void
    {
        // Regression (live-verified, M2): an offline agent makes Tactical return
        // HTTP 400 with body "Unable to contact the agent". The bus's
        // isAgentOffline() recognises the marker and classifies offline — NOT error.
        $asset = $this->asset();
        $actor = User::factory()->create();

        $e = new TacticalClientException(
            'Tactical API error',
            statusCode: 400,
            responseBody: 'Unable to contact the agent',
            transportFailure: false,
        );

        $result = $this->busReturning([])->dispatch($this->throwingAction($e), $asset, $actor, []);

        $this->assertSame('offline', $result->status, 'a 400 with the offline marker must classify as offline');
        $this->assertDatabaseHas('tactical_action_logs', ['result_status' => 'offline']);
    }

    public function test_http_403_with_non_marker_body_stays_error(): void
    {
        // The marker check must NOT swallow genuine errors: a 403 with an
        // unrelated body is still `error`, never offline.
        $asset = $this->asset();
        $actor = User::factory()->create();

        $e = new TacticalClientException(
            'Tactical API error',
            statusCode: 403,
            responseBody: 'Authentication credentials were not provided.',
            transportFailure: false,
        );

        $result = $this->busReturning([])->dispatch($this->throwingAction($e), $asset, $actor, []);

        $this->assertSame('error', $result->status, 'a 403 with a non-marker body must stay error');
        $this->assertDatabaseHas('tactical_action_logs', ['result_status' => 'error']);
    }

    public function test_transport_failure_exception_classifies_as_offline(): void
    {
        // The original signal still works: a transport failure (no HTTP response)
        // is always offline, regardless of body.
        $asset = $this->asset();
        $actor = User::factory()->create();

        $e = new TacticalClientException('Connection timed out', transportFailure: true);

        $result = $this->busReturning([])->dispatch($this->throwingAction($e), $asset, $actor, []);

        $this->assertSame('offline', $result->status);
        $this->assertDatabaseHas('tactical_action_logs', ['result_status' => 'offline']);
    }

    // ── audit: ticket attribution (M1) ────────────────────────────────────

    public function test_ticket_originated_dispatch_records_ticket_id(): void
    {
        $asset = $this->asset();
        $actor = User::factory()->create();
        $ticket = Ticket::factory()->create();

        $this->busReturning([new Response(200, [], json_encode(['stdout' => 'ok', 'retcode' => 0]))])
            ->dispatch($this->okAction(), $asset, $actor, [], null, null, $ticket->id);

        $this->assertDatabaseHas('tactical_action_logs', [
            'ticket_id' => $ticket->id,
            'result_status' => 'ok',
        ]);
    }

    // ── audit: redaction (B1) ─────────────────────────────────────────────

    public function test_secret_in_argv_params_is_redacted_in_the_audit_row(): void
    {
        $asset = $this->asset();
        $actor = User::factory()->create();

        // The argv secret (B1's target — the `-Flag <secret>` shape WikiRedactor
        // misses) and a separately-shaped secret echoed back in stdout
        // (key=value — the shape output redaction can catch). Both must be gone.
        $argvSecret = 'hunter2-SUPER-SECRET-xyz';
        $outputSecret = 'leakedpassword1234567890';

        $action = new class($outputSecret) implements TacticalAction
        {
            public function __construct(private string $outputSecret) {}

            public function key(): string
            {
                return 'tactical.fake';
            }

            public function isDestructive(): bool
            {
                return false;
            }

            public function validateParams(array $params): array
            {
                return $params;
            }

            public function summary(array $params): string
            {
                return 'fake';
            }

            public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
            {
                return TacticalActionResult::ok("connected; password={$this->outputSecret}", 0);
            }
        };

        $params = ['args' => ['-Password', $argvSecret]];

        $result = $this->busReturning([])->dispatch($action, $asset, $actor, $params);

        $this->assertSame('ok', $result->status);

        // The stored row must NOT contain either secret (params JSON or output).
        $row = TacticalActionLog::sole();
        $raw = json_encode($row->getAttributes());
        $this->assertStringNotContainsString($argvSecret, $raw, 'argv secret leaked into the audit row');
        $this->assertStringNotContainsString($outputSecret, $raw, 'output secret leaked into the audit row');

        // Sanity: the flag itself survives (we only scrub the value).
        $this->assertStringContainsString('-Password', json_encode($row->params));
    }

    public function test_bare_token_secret_in_stdout_is_redacted_in_the_audit_row(): void
    {
        // Code-review (critic BLOCKER): WikiRedactor only catches keyword-ADJACENT
        // secrets (password=…). A curated/recovery script that prints a bare
        // credential on its own line — a 40-char API token, no keyword — would
        // otherwise land VERBATIM in the IMMUTABLE audit row. The output-path
        // backstop must scrub it.
        $asset = $this->asset();
        $actor = User::factory()->create();

        $bareSecret = 'a1b2c3d4e5f607182930a4b5c6d7e8f901234567'; // 40-char, no adjacent keyword

        $action = $this->staticOkAction("recovered key:\n{$bareSecret}\ndone.", null);

        $result = $this->busReturning([])->dispatch($action, $asset, $actor, []);

        $this->assertSame('ok', $result->status);
        $row = TacticalActionLog::sole();
        $this->assertStringNotContainsString($bareSecret, (string) $row->output, 'bare token leaked into the audit output');
    }

    public function test_bare_token_secret_in_stderr_is_redacted_in_the_audit_row(): void
    {
        // The live fix (247d54d) added stderr carry-through; audit() folds stderr
        // into the output column. A bare secret printed to stderr must be scrubbed
        // there too (the redaction guarantee covers stdout AND stderr).
        $asset = $this->asset();
        $actor = User::factory()->create();

        $bareSecret = 'ZZZ9f8e7d6c5b4a3928171605f4e3d2c1b0a9988'; // 40-char, no adjacent keyword

        $action = $this->staticOkAction('clean stdout', "fatal: token {$bareSecret} rejected");

        $result = $this->busReturning([])->dispatch($action, $asset, $actor, []);

        $this->assertSame('ok', $result->status);
        $row = TacticalActionLog::sole();
        $this->assertStringNotContainsString($bareSecret, (string) $row->output, 'bare stderr token leaked into the audit output');
    }

    public function test_http_403_with_offline_marker_body_stays_error(): void
    {
        // M2 hardening: the offline-marker body-sniff must NOT reclassify a genuine
        // auth/HTTP error as offline just because the body coincidentally contains
        // a marker substring. Only Tactical's HTTP 400 natsdown carries the marker;
        // a 403 (even with "agent is offline" in the body) stays `error` so a key
        // compromise / permission failure is never masked as a safe no-op.
        $asset = $this->asset();
        $actor = User::factory()->create();

        $e = new TacticalClientException(
            'Tactical API error',
            statusCode: 403,
            responseBody: 'Forbidden: agent is offline for this role',
            transportFailure: false,
        );

        $result = $this->busReturning([])->dispatch($this->throwingAction($e), $asset, $actor, []);

        $this->assertSame('error', $result->status, 'a 403 must stay error even when its body contains an offline marker');
        $this->assertDatabaseHas('tactical_action_logs', ['result_status' => 'error']);
    }

    /** A fake non-destructive action returning a fixed stdout/stderr (no client call). */
    private function staticOkAction(?string $stdout, ?string $stderr): TacticalAction
    {
        return new class($stdout, $stderr) implements TacticalAction
        {
            public function __construct(private ?string $stdout, private ?string $stderr) {}

            public function key(): string
            {
                return 'tactical.fake';
            }

            public function isDestructive(): bool
            {
                return false;
            }

            public function validateParams(array $params): array
            {
                return $params;
            }

            public function summary(array $params): string
            {
                return 'fake';
            }

            public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
            {
                return TacticalActionResult::ok($this->stdout, 0, $this->stderr);
            }
        };
    }
}
