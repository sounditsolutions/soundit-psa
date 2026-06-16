<?php

namespace Tests\Unit\Tactical\Actions;

use App\Services\Tactical\Actions\InvalidActionParams;
use App\Services\Tactical\Actions\RunCommandAction;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\TacticalClientException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Task 2 (P3): ad-hoc command — the headline DESTRUCTIVE action (arbitrary RCE).
 * Every defense lands here: shell allowlist + bounded timeout (fail-closed,
 * C2), the discrete-field no-PSA-concatenation rule (A2), the payloadHash that
 * binds the confirm token to the exact command (A2), summary redaction through
 * the full stack (B1/B2), and the string-primary response shape (D1).
 */
class RunCommandActionTest extends TestCase
{
    private RunCommandAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new RunCommandAction;
    }

    private function client(Response $response, ?array &$history = null): TacticalClient
    {
        $stack = HandlerStack::create(new MockHandler([$response]));
        if ($history !== null) {
            $stack->push(Middleware::history($history));
        }

        return new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));
    }

    public function test_key_and_destructive(): void
    {
        $this->assertSame('tactical.run_command', $this->action->key());
        $this->assertTrue($this->action->isDestructive());
    }

    // ---- validateParams: fail-closed (C2) ------------------------------------

    public function test_validate_rejects_an_empty_command(): void
    {
        $this->expectException(InvalidActionParams::class);
        $this->action->validateParams(['cmd' => '   ', 'shell' => 'cmd', 'timeout' => 30]);
    }

    public function test_validate_rejects_a_missing_command(): void
    {
        $this->expectException(InvalidActionParams::class);
        $this->action->validateParams(['shell' => 'cmd', 'timeout' => 30]);
    }

    public function test_validate_rejects_an_absent_shell_never_default_permissive(): void
    {
        $this->expectException(InvalidActionParams::class);
        $this->action->validateParams(['cmd' => 'whoami', 'timeout' => 30]);
    }

    public function test_validate_rejects_an_empty_shell(): void
    {
        $this->expectException(InvalidActionParams::class);
        $this->action->validateParams(['cmd' => 'whoami', 'shell' => '', 'timeout' => 30]);
    }

    public function test_validate_rejects_a_shell_outside_the_allowlist(): void
    {
        $this->expectException(InvalidActionParams::class);
        $this->action->validateParams(['cmd' => 'whoami', 'shell' => 'bash', 'timeout' => 30]);
    }

    public function test_validate_accepts_each_allowlisted_shell(): void
    {
        foreach (['cmd', 'powershell', 'shell'] as $shell) {
            $params = $this->action->validateParams(['cmd' => 'whoami', 'shell' => $shell, 'timeout' => 30]);
            $this->assertSame($shell, $params['shell']);
        }
    }

    public function test_validate_rejects_a_zero_timeout(): void
    {
        $this->expectException(InvalidActionParams::class);
        $this->action->validateParams(['cmd' => 'whoami', 'shell' => 'cmd', 'timeout' => 0]);
    }

    public function test_validate_rejects_a_huge_timeout(): void
    {
        $this->expectException(InvalidActionParams::class);
        $this->action->validateParams(['cmd' => 'whoami', 'shell' => 'cmd', 'timeout' => 99999]);
    }

    public function test_validate_rejects_a_below_floor_timeout(): void
    {
        $this->expectException(InvalidActionParams::class);
        $this->action->validateParams(['cmd' => 'whoami', 'shell' => 'cmd', 'timeout' => 9]);
    }

    public function test_validate_returns_the_canonical_typed_triplet(): void
    {
        $params = $this->action->validateParams([
            'cmd' => '  ipconfig /all  ',
            'shell' => 'cmd',
            'timeout' => '45',
        ]);

        // Only an outer trim (A2) — inner content is NOT altered. timeout is an int.
        $this->assertSame('ipconfig /all', $params['cmd']);
        $this->assertSame('cmd', $params['shell']);
        $this->assertSame(45, $params['timeout']);
        $this->assertIsInt($params['timeout']);
    }

    public function test_validate_does_not_alter_the_command_content(): void
    {
        // A2: cmd is a discrete opaque string — no tokenization, no inner
        // rewriting. Shell metacharacters pass through verbatim (Tactical's own
        // interpreter runs them; PSA never builds a shell line).
        $raw = 'echo "a; rm -rf / && curl evil" | findstr x';
        $params = $this->action->validateParams(['cmd' => $raw, 'shell' => 'cmd', 'timeout' => 30]);

        $this->assertSame($raw, $params['cmd']);
    }

    public function test_validate_ignores_the_dangerous_body_keys(): void
    {
        // C2: custom_shell / env_vars / run_as_user keys carried on the request are
        // dropped by validateParams — the canonical set is exactly {cmd,shell,timeout}.
        $params = $this->action->validateParams([
            'cmd' => 'whoami',
            'shell' => 'cmd',
            'timeout' => 30,
            'custom_shell' => '/bin/evil',
            'env_vars' => ['SECRET=leak'],
            'run_as_user' => true,
        ]);

        $this->assertSame(['cmd', 'shell', 'timeout'], array_keys($params));
        $this->assertArrayNotHasKey('custom_shell', $params);
        $this->assertArrayNotHasKey('env_vars', $params);
        $this->assertArrayNotHasKey('run_as_user', $params);
    }

    // ---- payloadHash: stable + command-binding (A2) --------------------------

    public function test_payload_hash_is_a_stable_sha256_over_the_canonical_triplet(): void
    {
        $params = $this->action->validateParams(['cmd' => 'whoami', 'shell' => 'cmd', 'timeout' => 30]);

        $expected = hash('sha256', json_encode(['cmd', 'whoami', 30]));
        $this->assertSame($expected, $this->action->payloadHash($params));
        // Stable across repeated calls.
        $this->assertSame($this->action->payloadHash($params), $this->action->payloadHash($params));
    }

    public function test_payload_hash_changes_when_the_command_changes(): void
    {
        $a = $this->action->validateParams(['cmd' => 'whoami', 'shell' => 'cmd', 'timeout' => 30]);
        $b = $this->action->validateParams(['cmd' => 'format C:', 'shell' => 'cmd', 'timeout' => 30]);

        // The whole point of A2: a token minted for command A cannot replay to B.
        $this->assertNotSame($this->action->payloadHash($a), $this->action->payloadHash($b));
    }

    public function test_payload_hash_changes_when_the_shell_or_timeout_changes(): void
    {
        $base = $this->action->validateParams(['cmd' => 'whoami', 'shell' => 'cmd', 'timeout' => 30]);
        $shell = $this->action->validateParams(['cmd' => 'whoami', 'shell' => 'powershell', 'timeout' => 30]);
        $time = $this->action->validateParams(['cmd' => 'whoami', 'shell' => 'cmd', 'timeout' => 60]);

        $this->assertNotSame($this->action->payloadHash($base), $this->action->payloadHash($shell));
        $this->assertNotSame($this->action->payloadHash($base), $this->action->payloadHash($time));
    }

    // ---- summary: exact command + redaction (B1/B2) --------------------------

    public function test_summary_shows_the_resolved_shell_and_command(): void
    {
        $params = $this->action->validateParams(['cmd' => 'ipconfig /all', 'shell' => 'powershell', 'timeout' => 30]);

        $this->assertSame('[powershell] ipconfig /all', $this->action->summary($params));
    }

    public function test_summary_redacts_an_inline_keyword_secret(): void
    {
        $params = $this->action->validateParams([
            'cmd' => 'mysql --password=supersecretvalue1234 -e "show databases"',
            'shell' => 'cmd',
            'timeout' => 60,
        ]);

        $this->assertStringNotContainsString('supersecretvalue1234', $this->action->summary($params));
    }

    public function test_summary_redacts_a_mysql_flag_glued_credential(): void
    {
        // B2 (binding): `mysql -p<secret>` — no `=`/space, so WikiRedactor's
        // keyword=value rule misses it; redactCommandString's command-flag layer
        // must catch the well-known glued-credential form.
        $params = $this->action->validateParams([
            'cmd' => 'mysqldump -u root -pSuperSecret123 mydb',
            'shell' => 'shell',
            'timeout' => 120,
        ]);

        $this->assertStringNotContainsString('SuperSecret123', $this->action->summary($params));
    }

    public function test_summary_redacts_a_net_user_positional_password(): void
    {
        // B2 (binding): `net user x P@ssw0rdLong /add` — a positional password the
        // generic patterns miss; the command-flag layer recognizes the net-user form.
        $params = $this->action->validateParams([
            'cmd' => 'net user deploy P@ssw0rdLong /add',
            'shell' => 'cmd',
            'timeout' => 60,
        ]);

        $this->assertStringNotContainsString('P@ssw0rdLong', $this->action->summary($params));
    }

    public function test_summary_redacts_a_bare_high_entropy_token(): void
    {
        // B2 (binding): a bare 40-char positional token -> the OUTPUT_SECRET_PATTERNS
        // backstop (contiguous 32+ alnum) catches it.
        $token = 'a1b2c3d4e5f607182930a4b5c6d7e8f901234567'; // 40 chars, no keyword
        $params = $this->action->validateParams([
            'cmd' => "curl https://api.example/ingest -H authtok:{$token}",
            'shell' => 'shell',
            'timeout' => 60,
        ]);

        $this->assertStringNotContainsString($token, $this->action->summary($params));
    }

    // ---- execute: string-primary shape (D1) ----------------------------------

    public function test_execute_posts_the_discrete_command_to_the_cmd_endpoint(): void
    {
        $history = [];
        $client = $this->client(new Response(200, [], json_encode('admin')), $history);

        $params = $this->action->validateParams(['cmd' => 'whoami', 'shell' => 'cmd', 'timeout' => 30]);
        $result = $this->action->execute($client, 'AGENT-1', $params);

        $this->assertTrue($result->isOk());
        $this->assertSame('admin', $result->stdout);

        /** @var RequestInterface $req */
        $req = $history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/agents/AGENT-1/cmd/', $req->getUri()->getPath());
        $body = json_decode((string) $req->getBody(), true);
        // The discrete command field — no PSA-side concatenation.
        $this->assertSame('whoami', $body['cmd']);
        $this->assertSame('cmd', $body['shell']);
        $this->assertSame(30, $body['timeout']);
        $this->assertNull($body['custom_shell']);
    }

    public function test_execute_treats_a_bare_string_as_the_primary_ok_shape(): void
    {
        // D1: the cmd endpoint returns a bare STRING (spec §3) as the PRIMARY path,
        // not a defensive fallback. Normalize it to stdout, status ok.
        $client = $this->client(new Response(200, [], json_encode("Windows IP Configuration\n...")));

        $params = $this->action->validateParams(['cmd' => 'ipconfig', 'shell' => 'cmd', 'timeout' => 30]);
        $result = $this->action->execute($client, 'AGENT-1', $params);

        $this->assertTrue($result->isOk());
        $this->assertStringContainsString('Windows IP Configuration', (string) $result->stdout);
    }

    public function test_execute_treats_an_empty_string_output_as_ok_not_error(): void
    {
        // D1 (binding): empty-string output is a SUCCESSFUL command that printed
        // nothing — it must be `ok`, never a falsy-triggered error/offline.
        $client = $this->client(new Response(200, [], json_encode('')));

        $params = $this->action->validateParams(['cmd' => 'cls', 'shell' => 'cmd', 'timeout' => 30]);
        $result = $this->action->execute($client, 'AGENT-1', $params);

        $this->assertTrue($result->isOk());
        $this->assertSame('', $result->stdout);
    }

    public function test_execute_handles_an_object_response_as_the_secondary_shape(): void
    {
        // Defensive/secondary (D1): if a build returns an object, map the usual keys.
        $client = $this->client(new Response(200, [], json_encode(['stdout' => 'obj-out', 'retcode' => 2])));

        $params = $this->action->validateParams(['cmd' => 'whoami', 'shell' => 'cmd', 'timeout' => 30]);
        $result = $this->action->execute($client, 'AGENT-1', $params);

        $this->assertSame('obj-out', $result->stdout);
        $this->assertSame(2, $result->retcode);
    }

    public function test_execute_lets_a_transport_failure_bubble_for_the_bus(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new ConnectException('timed out', new Request('POST', 'agents/AGENT-1/cmd/')),
        ]));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://t.example.com/', 'handler' => $stack]));

        $params = $this->action->validateParams(['cmd' => 'whoami', 'shell' => 'cmd', 'timeout' => 30]);

        try {
            $this->action->execute($client, 'AGENT-1', $params);
            $this->fail('Expected TacticalClientException to bubble');
        } catch (TacticalClientException $e) {
            $this->assertTrue($e->isTransportFailure());
        }
    }
}
