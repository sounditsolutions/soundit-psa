<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\StaffTacticalAdminToolExecutor;
use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #3879: six refusal arms on the Tactical client custom-field path said "no
 * upstream call was made" while each was reached only AFTER the resolver had
 * already sent GET /clients/ to pin the target. A read is an upstream call.
 *
 * Each control asserts BOTH halves, because either alone is satisfiable by the
 * wrong code: that the read WAS recorded (so the arm really is post-read, and a
 * future edit moving the refusal above the read would make this test wrong in a
 * way worth catching), and that the message does NOT claim nothing reached
 * Tactical. The sibling convention this adopts is patchResetScope's "no reset
 * was sent" and tacticalClientSiteTarget's "no assignment was sent": name the
 * action that did not go out, and say nothing about the read.
 *
 * TacticalClient builds its own Guzzle client, so Http::fake() is structurally
 * blind to its traffic and Http::recorded() returns empty for every arm whether
 * it read or not. Recording is therefore on Guzzle's own history middleware,
 * through the injected-client seam the class documents.
 */
class TacticalUpstreamDenialWordingTest extends TestCase
{
    use RefreshDatabase;

    private const FALSE_CLAIM = 'no upstream call was made';

    /** @var array<int,mixed> */
    private array $history = [];

    private function bindTactical(array $queue): void
    {
        $this->history = [];
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $guzzle = new GuzzleClient(['handler' => $stack, 'base_uri' => 'https://tactical.invalid/']);

        $this->app->bind(TacticalClient::class, fn () => new TacticalClient($guzzle));
    }

    /** @return list<string> */
    private function requests(): array
    {
        $out = [];
        foreach ($this->history as $t) {
            $out[] = $t['request']->getMethod().' '.(string) $t['request']->getUri();
        }

        return $out;
    }

    private function enableIntegration(): void
    {
        Setting::setValue('controld_enabled', '1');
        Setting::setValue('controld_tactical_client_field_id', '7');
    }

    private function clientList(array $rows): Response
    {
        return new Response(200, [], json_encode($rows));
    }

    private function executor(): StaffTacticalAdminToolExecutor
    {
        return app(StaffTacticalAdminToolExecutor::class);
    }

    private function resolve(Client $client): array
    {
        $exec = $this->executor();
        $m = new \ReflectionMethod($exec, 'resolveUpstreamTacticalClient');
        $m->setAccessible(true);

        return $m->invoke($exec, $client);
    }

    /**
     * The approval path needs a real run and a real approver: the audit row
     * carries approver_user_id as a foreign key, so an invented id aborts the
     * call with a constraint violation before any arm is reached.
     */
    private function approve(array $arguments, Client $client): array
    {
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $run = TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'tactical_stage_set_client_custom_field',
            'content_hash' => 'wording-'.$client->id,
            'state' => TechnicianRunState::AwaitingApproval,
        ]);
        $approver = User::factory()->create();

        $exec = $this->executor();
        $m = new \ReflectionMethod($exec, 'executeClientCustomField');
        $m->setAccessible(true);

        return $m->invoke($exec, $arguments, $client, 'approver-label', $run, (int) $approver->id);
    }

    private function assertPostReadDenial(array $out, string $context): void
    {
        $this->assertNotEmpty(
            $this->requests(),
            "{$context}: this arm is supposed to sit AFTER the resolver read, but nothing was recorded. "
            .'Either the refusal moved above getClients() (in which case it may legitimately claim no '
            .'upstream call) or the recording seam is not bound.'
        );
        $this->assertStringContainsString('GET https://tactical.invalid/clients/', $this->requests()[0], $context);
        $this->assertArrayHasKey('error', $out, $context);
        $this->assertStringNotContainsString(
            self::FALSE_CLAIM,
            $out['error'],
            "{$context}: a GET /clients/ has already left, so this refusal must not claim nothing reached Tactical."
        );
    }

    public function test_the_recording_seam_can_see_a_request_at_all(): void
    {
        // POSITIVE CONTROL. Without this, every "the read was recorded" assertion
        // below could be satisfied by an instrument that records nothing and a
        // suite that never notices.
        $this->enableIntegration();
        $this->bindTactical([
            $this->clientList([['id' => 11, 'name' => 'Acme']]),
            new Response(200, [], json_encode(['ok' => true])),
        ]);
        $client = Client::factory()->create(['tactical_site_id' => 'Acme|Main']);

        $out = $this->approve(
            ['field_key' => 'controld_org_id', 'value' => 'org-1', 'staged_upstream_client_id' => 11],
            $client
        );

        $this->assertTrue($out['success'] ?? false, 'the matching-pin path should reach the write');
        $this->assertCount(2, $this->requests(), 'the resolver read and the write should both be recorded');
    }

    public function test_a_failed_client_read_refusal_does_not_claim_nothing_was_sent(): void
    {
        $this->enableIntegration();
        $this->bindTactical([new Response(500, [], json_encode(['detail' => 'upstream blew up']))]);
        $client = Client::factory()->create(['tactical_site_id' => 'Acme|Main']);

        $out = $this->resolve($client);

        $this->assertPostReadDenial($out, 'resolver catch arm');
        $this->assertStringContainsString('no custom-field write was sent', $out['error']);
    }

    public function test_a_not_found_client_refusal_does_not_claim_nothing_was_sent(): void
    {
        $this->enableIntegration();
        $this->bindTactical([$this->clientList([['id' => 7, 'name' => 'Someone Else']])]);
        $client = Client::factory()->create(['tactical_site_id' => 'Acme|Main']);

        $out = $this->resolve($client);

        $this->assertPostReadDenial($out, 'resolver not-found arm');
        $this->assertStringContainsString('was not found', $out['error']);
    }

    public function test_a_non_numeric_id_refusal_does_not_claim_nothing_was_sent(): void
    {
        $this->enableIntegration();
        $this->bindTactical([$this->clientList([['id' => 0, 'name' => 'Acme']])]);
        $client = Client::factory()->create(['tactical_site_id' => 'Acme|Main']);

        $out = $this->resolve($client);

        $this->assertPostReadDenial($out, 'resolver no-numeric-id arm');
        $this->assertStringContainsString('has no numeric id', $out['error']);
    }

    public function test_an_ambiguous_name_refusal_does_not_claim_nothing_was_sent(): void
    {
        // Reachable, not theoretical: candidate matching is case-INSENSITIVE while
        // the exact-case filter takes only a single byte-identical hit, so two
        // upstream rows differing only in case fall through to the ambiguity arm.
        $this->enableIntegration();
        $this->bindTactical([$this->clientList([
            ['id' => 11, 'name' => 'Acme'],
            ['id' => 12, 'name' => 'ACME'],
        ])]);
        $client = Client::factory()->create(['tactical_site_id' => 'acme|Main']);

        $out = $this->resolve($client);

        $this->assertPostReadDenial($out, 'resolver ambiguous arm');
        $this->assertStringContainsString('is ambiguous', $out['error']);
    }

    public function test_an_unpinned_proposal_refusal_does_not_claim_nothing_was_sent(): void
    {
        $this->enableIntegration();
        $this->bindTactical([$this->clientList([['id' => 11, 'name' => 'Acme']])]);
        $client = Client::factory()->create(['tactical_site_id' => 'Acme|Main']);

        $out = $this->approve(['field_key' => 'controld_org_id', 'value' => 'org-1'], $client);

        $this->assertPostReadDenial($out, 'staged-pin absent arm');
        $this->assertStringContainsString('recorded no upstream Tactical client id', $out['error']);
        $this->assertStringContainsString('no custom-field write was sent', $out['error']);
    }

    public function test_a_changed_target_refusal_does_not_claim_nothing_was_sent(): void
    {
        $this->enableIntegration();
        $this->bindTactical([$this->clientList([['id' => 99, 'name' => 'Acme']])]);
        $client = Client::factory()->create(['tactical_site_id' => 'Acme|Main']);

        $out = $this->approve(
            ['field_key' => 'controld_org_id', 'value' => 'org-1', 'staged_upstream_client_id' => 11],
            $client
        );

        $this->assertPostReadDenial($out, 'staged-pin changed arm');
        $this->assertStringContainsString('the approved target changed', $out['error']);
        $this->assertStringContainsString('no custom-field write was sent', $out['error']);
    }

    public function test_the_unmapped_client_arm_keeps_its_upstream_claim(): void
    {
        // NEGATIVE CONTROL, and the boundary of this change. This arm returns
        // BEFORE getClients(), so its "no upstream call was made" is true and must
        // survive. A fix that rewrote every arm in the file would fail here.
        $this->enableIntegration();
        $this->bindTactical([$this->clientList([['id' => 11, 'name' => 'Acme']])]);
        $client = Client::factory()->create(['tactical_site_id' => null]);

        $out = $this->resolve($client);

        $this->assertSame([], $this->requests(), 'the pre-read arm must send nothing');
        $this->assertStringContainsString(self::FALSE_CLAIM, $out['error']);
    }

    public function test_the_disabled_integration_arm_keeps_its_upstream_claim(): void
    {
        // SECOND NEGATIVE CONTROL: the master-switch refusal also precedes the read.
        Setting::setValue('controld_enabled', '0');
        Setting::setValue('controld_tactical_client_field_id', '7');
        $this->bindTactical([$this->clientList([['id' => 11, 'name' => 'Acme']])]);
        $client = Client::factory()->create(['tactical_site_id' => 'Acme|Main']);

        $out = $this->approve(
            ['field_key' => 'controld_org_id', 'value' => 'org-1', 'staged_upstream_client_id' => 11],
            $client
        );

        $this->assertSame([], $this->requests(), 'the pre-read arm must send nothing');
        $this->assertStringContainsString(self::FALSE_CLAIM, $out['error']);
    }

    public function test_the_cooldown_arm_keeps_its_upstream_claim(): void
    {
        // THIRD NEGATIVE CONTROL. 38 sites in this file are cooldown refusals
        // answered from a local predicate before any client call, and they are the
        // largest group carrying the phrase legitimately. Asserting the predicate
        // itself touches nothing upstream keeps this change off them.
        $this->enableIntegration();
        $this->bindTactical([$this->clientList([['id' => 11, 'name' => 'Acme']])]);
        $client = Client::factory()->create(['tactical_site_id' => 'Acme|Main']);

        $exec = $this->executor();
        $m = new \ReflectionMethod($exec, 'cooldownActive');
        $m->setAccessible(true);
        $active = $m->invoke($exec, 'tactical_set_client_custom_field', (int) $client->id, 600);

        $this->assertFalse($active);
        $this->assertSame([], $this->requests(), 'the cooldown predicate must not touch upstream');
    }
}
