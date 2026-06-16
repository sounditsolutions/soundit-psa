<?php

namespace Tests\Feature\Tactical;

use App\Models\Asset;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\Ticket;
use App\Services\Tactical\TacticalClient;
use App\Services\Triage\ContextBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create as PromiseCreate;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Amendment D (P4): the ContextBuilder foot-gun. The inline getAgentChecks() in
 * buildAssetSection was un-timed (30s), exception-swallowed, run serially inside
 * foreach($ticket->assets) on the AI triage hot path, and injected ~150 chars of
 * check stdout UNREDACTED into the prompt. P4 routes it through the bounded
 * primitive (bounded timeout + classify-degrade, no silent hang) and redacts the
 * stdout — keeping the output shape byte-identical (this is foot-gun removal, not
 * the P5 AI block).
 */
class ContextBuilderTacticalChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Make TacticalConfig::isConfigured() true so buildAssetSection enters the
        // checks branch.
        Setting::setValue('tactical_api_url', 'https://tactical.example.com');
        Setting::setEncrypted('tactical_api_key', 'svc-key-abc123');
    }

    private function bindClient(callable|array $handler): void
    {
        $stack = is_array($handler)
            ? HandlerStack::create(new MockHandler($handler))
            : HandlerStack::create($handler);

        $http = new GuzzleClient([
            'base_uri' => 'https://tactical.example.com/',
            'handler' => $stack,
            'timeout' => 30,
            'allow_redirects' => false,
        ]);
        $this->app->instance(TacticalClient::class, new TacticalClient($http));
    }

    private function ticketWithLinkedAsset(): Ticket
    {
        $asset = Asset::factory()->create(['hostname' => 'BOX-1']);
        $ta = TacticalAsset::create([
            'asset_id' => $asset->id,
            'agent_id' => 'AGENT-1',
            'hostname' => 'BOX-1',
            'status' => 'online',
        ]);
        $asset->update(['tactical_asset_id' => $ta->id]);

        $ticket = Ticket::factory()->create(['client_id' => $asset->client_id]);
        $ticket->assets()->attach($asset->id);

        return $ticket->fresh();
    }

    public function test_failing_check_keeps_the_foot_gun_output_shape(): void
    {
        $ticket = $this->ticketWithLinkedAsset();

        $this->bindClient([
            new Response(200, [], json_encode([
                ['name' => 'Disk Space C', 'check_result' => ['status' => 'failing', 'stdout' => 'C: at 95%']],
                ['name' => 'Ping', 'check_result' => ['status' => 'passing']],
            ])),
        ]);

        $context = ContextBuilder::buildForTicket($ticket);

        // Shape preserved: "| Failing checks: N" then "  - [FAILING] name: stdout".
        $this->assertStringContainsString('| Failing checks: 1', $context);
        $this->assertStringContainsString('[FAILING] Disk Space C: C: at 95%', $context);
    }

    public function test_planted_secret_in_check_stdout_is_redacted_before_the_prompt(): void
    {
        $ticket = $this->ticketWithLinkedAsset();

        // A check whose stdout echoes a credential (e.g. a misconfigured script
        // dumping a connection string). Build the secret by concatenation so no
        // contiguous AWS-key-shaped literal lives in the test (secret-guard).
        $secret = 'db password = '.'S3cr3tP'.'@ssw0rd!';

        $this->bindClient([
            new Response(200, [], json_encode([
                ['name' => 'Backup Job', 'check_result' => ['status' => 'failing', 'stdout' => $secret]],
            ])),
        ]);

        $context = ContextBuilder::buildForTicket($ticket);

        // The failing check is surfaced...
        $this->assertStringContainsString('[FAILING] Backup Job:', $context);
        // ...but the credential value never reaches the prompt string.
        $this->assertStringNotContainsString('S3cr3tP@ssw0rd!', $context);
        $this->assertStringContainsString('[REDACTED:credential]', $context);
    }

    public function test_offline_tactical_returns_within_bound_and_never_throws(): void
    {
        $ticket = $this->ticketWithLinkedAsset();

        // A handler that simulates an offline/erroring agent (HTTP 500). The old
        // code swallowed this silently; the new bounded path classifies+degrades
        // but must STILL not throw and must not emit a checks line.
        $this->bindClient([
            new Response(500, [], 'natsdown'),
        ]);

        $context = ContextBuilder::buildForTicket($ticket);

        // No failing-checks line (couldn't read) — and crucially, no exception.
        $this->assertStringNotContainsString('| Failing checks:', $context);
        // The asset itself is still present in the section.
        $this->assertStringContainsString('BOX-1', $context);
    }

    public function test_bounded_read_uses_a_short_timeout_not_30s(): void
    {
        $ticket = $this->ticketWithLinkedAsset();

        $captured = [];
        $this->bindClient(function (RequestInterface $request, array $options) use (&$captured) {
            $captured[] = $options['timeout'] ?? null;

            return PromiseCreate::promiseFor(new Response(200, [], json_encode([])));
        });

        ContextBuilder::buildForTicket($ticket);

        $this->assertNotEmpty($captured);
        $this->assertNotNull($captured[0], 'the foot-gun read must now be time-bounded');
        $this->assertLessThanOrEqual(3, $captured[0], 'must be the short bound, not the 30s default');
    }
}
