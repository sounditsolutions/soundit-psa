<?php

namespace Tests\Feature\Mcp;

use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
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
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * #3879 / #3880: which refusals on the Tactical client custom-field path may
 * say "no upstream call was made", and which must name the write instead.
 *
 * Each test enters through a public entry point: execute() for the immediate
 * tool, and approveStagedRun() for a proposal staged by execute() (#3886).
 * Every post-read refusal is asserted by its full message (#3881) AND by the
 * exact request log: only GET /clients/, nothing else (#3884). The mock queue
 * always holds a second response, so a write that went out would be recorded;
 * an empty queue is not what stops it. Each pre-read negative control asserts
 * its own full message and an empty request log (#3883 #3887).
 *
 * The resolver's catch arm follows a read ATTEMPT, not necessarily a read
 * that left (#3885): a 500 is recorded, a pre-dispatch refusal records
 * nothing. Its one message has to be true on both paths, so it names only the
 * write that did not go out.
 *
 * TacticalClient builds its own Guzzle client, so Http::fake() cannot see its
 * traffic. Requests are recorded with Guzzle's history middleware on the
 * injected client.
 */
class TacticalUpstreamDenialWordingTest extends TestCase
{
    use RefreshDatabase;

    private const FALSE_CLAIM = 'no upstream call was made';

    private const READ = 'GET https://tactical.invalid/clients/';

    private const WRITE_ARGS = ['field_key' => 'controld_org_id', 'value' => 'org-1', 'reason' => 'wording control'];

    /** @var array<int,mixed> */
    private array $history = [];

    private ?MockHandler $mock = null;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('tactical_api_url', 'https://tactical.invalid');
        Setting::setEncrypted('tactical_api_key', 'test-key');
        Setting::setValue('controld_enabled', '1');
        Setting::setValue('controld_tactical_client_field_id', '7');
        $actor = User::factory()->create();
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $this->approver = User::factory()->create();
    }

    /** @param  callable|null  $resolver  when set, TacticalClient's own SSRF pin runs with it */
    private function bindTactical(array $queue, ?callable $resolver = null): void
    {
        $this->history = [];
        $this->mock = new MockHandler($queue);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        if ($resolver !== null) {
            // Pushed last, so it runs first: a refusal here never reaches the recorder.
            $stack->push(TacticalClient::ssrfPinMiddleware($resolver), 'tactical_ssrf_pin');
        }
        $guzzle = new GuzzleClient(['handler' => $stack, 'base_uri' => 'https://tactical.invalid/']);

        $this->app->bind(TacticalClient::class, fn () => new TacticalClient($guzzle));
    }

    /** A client list followed by a write response, so an attempted write is recorded. */
    private function bindReadThenWritable(array $rows): void
    {
        $this->bindTactical([
            new Response(200, [], json_encode($rows)),
            new Response(200, [], json_encode(['ok' => true])),
        ]);
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

    private function executor(): StaffTacticalAdminToolExecutor
    {
        return app(StaffTacticalAdminToolExecutor::class);
    }

    private function mappedClient(?string $siteKey = 'Acme|Main'): Client
    {
        return Client::factory()->create(['tactical_site_id' => $siteKey]);
    }

    /** The immediate tool, through the executor's public entry. */
    private function writeNow(Client $client): array
    {
        return $this->executor()->execute('tactical_set_client_custom_field', self::WRITE_ARGS, (int) $client->id, 'wording-test');
    }

    /** A real proposal, staged through the public staged verb against a resolving upstream. */
    private function stage(Client $client): TechnicianRun
    {
        $ticket = Ticket::factory()->for($client)->create();
        $this->bindTactical([new Response(200, [], json_encode([['id' => 11, 'name' => 'Acme']]))]);
        $staged = $this->executor()->execute(
            'tactical_stage_set_client_custom_field',
            self::WRITE_ARGS + ['ticket_id' => $ticket->id],
            (int) $client->id,
            'wording-test',
        );
        $this->assertTrue($staged['success'] ?? false, 'staging must succeed: '.json_encode($staged));

        return TechnicianRun::findOrFail($staged['run_id']);
    }

    /** The refusal text approval audited for this run: approveStagedRun returns gate_declined with no message here. */
    private function approvalRefusal(TechnicianRun $run): string
    {
        $row = TechnicianActionLog::query()
            ->where('run_id', $run->id)
            ->where('action_type', 'tactical_set_client_custom_field')
            ->where('result_status', 'rejected')
            ->latest('id')
            ->first();
        $this->assertNotNull($row, 'approval must audit the refusal it declined on');

        return (string) $row->summary;
    }

    private function approve(TechnicianRun $run): string
    {
        $result = $this->executor()->approveStagedRun($run->fresh(), (int) $this->approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(TechnicianRunState::AwaitingApproval, $run->fresh()->state, 'a refusal must release the claim');

        return $this->approvalRefusal($run);
    }

    private function assertOnlyTheRead(string $context): void
    {
        $this->assertSame([self::READ], $this->requests(), "{$context}: exactly the resolver read, and no write");
        $this->assertCount(1, $this->mock, "{$context}: the write response must still be queued, unconsumed");
    }

    private function assertNothingSent(string $context): void
    {
        $this->assertSame([], $this->requests(), "{$context}: this arm precedes the read and must send nothing");
    }

    // ---- positive controls: the recorder and the entry points reach a write ----

    public function test_the_recording_seam_sees_the_read_and_the_write_through_execute(): void
    {
        // Without this, every "only the read" assertion below could pass on a
        // recorder that never sees a write.
        $client = $this->mappedClient();
        $this->bindReadThenWritable([['id' => 11, 'name' => 'Acme']]);

        $out = $this->writeNow($client);

        $this->assertTrue($out['success'] ?? false, json_encode($out));
        $this->assertSame([self::READ, 'PUT https://tactical.invalid/clients/11/'], $this->requests());
    }

    public function test_a_staged_proposal_carries_its_pin_and_approval_reaches_the_write(): void
    {
        // The staged fixture is real: staging writes the resolved id into the
        // encrypted payload, and approveStagedRun, given a matching live
        // resolution, sends the read and then the write.
        $client = $this->mappedClient();
        $run = $this->stage($client);
        $payload = json_decode(Crypt::decryptString($run->proposed_meta['encrypted_payload']), true);
        $this->assertSame(11, $payload['arguments']['staged_upstream_client_id'] ?? null);

        $this->bindReadThenWritable([['id' => 11, 'name' => 'Acme']]);
        $result = $this->executor()->approveStagedRun($run->fresh(), (int) $this->approver->id);

        $this->assertSame('executed', $result->status);
        $this->assertSame([self::READ, 'PUT https://tactical.invalid/clients/11/'], $this->requests());
    }

    // ---- post-read resolver arms, through execute() ----

    public function test_a_failed_client_read_names_the_write_not_the_connection(): void
    {
        $client = $this->mappedClient();
        $this->bindTactical([
            new Response(500, [], json_encode(['detail' => 'upstream blew up'])),
            new Response(200, [], json_encode(['ok' => true])),
        ]);

        $out = $this->writeNow($client);

        $this->assertSame(
            'Could not read Tactical clients to resolve the custom-field target; no custom-field write was sent.',
            $out['error'] ?? null,
        );
        $this->assertOnlyTheRead('catch arm, HTTP 500');
    }

    public function test_the_catch_arm_also_answers_a_read_refused_before_dispatch(): void
    {
        // #3885. TacticalClient's own SSRF pin throws TacticalClientException
        // before the request is handed on when the host does not resolve. The
        // catch arm answers, and nothing was recorded. Its message is the same
        // one as after a 500, and it is true here too: it claims only that no
        // write was sent.
        $client = $this->mappedClient();
        $this->bindTactical(
            [new Response(200, [], json_encode([['id' => 11, 'name' => 'Acme']])), new Response(200, [], '{}')],
            fn (string $host): bool => false,
        );

        $out = $this->writeNow($client);

        $this->assertSame(
            'Could not read Tactical clients to resolve the custom-field target; no custom-field write was sent.',
            $out['error'] ?? null,
        );
        $this->assertSame([], $this->requests(), 'the pin refuses before the request is handed on');
        $this->assertCount(2, $this->mock, 'neither queued response was consumed');
    }

    public function test_a_not_found_client_names_the_write_not_the_connection(): void
    {
        $client = $this->mappedClient();
        $this->bindReadThenWritable([['id' => 7, 'name' => 'Someone Else']]);

        $out = $this->writeNow($client);

        $this->assertSame("Tactical client 'Acme' was not found; no custom-field write was sent.", $out['error'] ?? null);
        $this->assertOnlyTheRead('not-found arm');
    }

    public function test_a_client_with_no_numeric_id_names_the_write_not_the_connection(): void
    {
        $client = $this->mappedClient();
        $this->bindReadThenWritable([['id' => 0, 'name' => 'Acme']]);

        $out = $this->writeNow($client);

        $this->assertSame("A Tactical client named 'Acme' has no numeric id; no custom-field write was sent.", $out['error'] ?? null);
        $this->assertOnlyTheRead('no-numeric-id arm');
    }

    public function test_an_ambiguous_name_names_the_write_not_the_connection(): void
    {
        // Candidate matching is case-insensitive and the exact-case filter takes
        // only a byte-identical hit, so 'acme' against 'Acme' and 'ACME' falls
        // through to this arm.
        $client = $this->mappedClient('acme|Main');
        $this->bindReadThenWritable([['id' => 11, 'name' => 'Acme'], ['id' => 12, 'name' => 'ACME']]);

        $out = $this->writeNow($client);

        $this->assertSame(
            "Tactical client name 'acme' is ambiguous: it matches 2 upstream clients — 'Acme' (#11), 'ACME' (#12). "
            .'Upstream client names are unique only case-sensitively, so this cannot be resolved from the stored name pair; no custom-field write was sent.',
            $out['error'] ?? null,
        );
        $this->assertOnlyTheRead('ambiguous arm');
    }

    // ---- post-read staged-pin arms, through approveStagedRun() (#3886) ----

    public function test_a_proposal_with_no_pinned_client_names_the_write_not_the_connection(): void
    {
        // Staging writes the pin, so this models a proposal staged before the pin
        // existed (22199313): the key is removed from a real staged payload and
        // nothing else is changed.
        $client = $this->mappedClient();
        $run = $this->stage($client);
        $meta = $run->proposed_meta;
        $payload = json_decode(Crypt::decryptString($meta['encrypted_payload']), true);
        unset($payload['arguments']['staged_upstream_client_id']);
        $meta['encrypted_payload'] = Crypt::encryptString(json_encode($payload));
        $run->update(['proposed_meta' => $meta]);

        $this->bindReadThenWritable([['id' => 11, 'name' => 'Acme']]);
        $refusal = $this->approve($run);

        $this->assertSame(
            'This proposal recorded no upstream Tactical client id, so the approved target cannot be confirmed; no custom-field write was sent. '
            .'Deny it in the cockpit, then stage the write again: a proposal awaiting approval is never rewritten in place, so the replacement is read and approved on its own proposal.',
            $refusal,
        );
        $this->assertOnlyTheRead('staged-pin absent arm');
    }

    public function test_a_changed_target_names_the_write_not_the_connection(): void
    {
        $client = $this->mappedClient();
        $run = $this->stage($client);

        $this->bindReadThenWritable([['id' => 99, 'name' => 'Acme']]);
        $refusal = $this->approve($run);

        $this->assertSame(
            "The approved proposal targeted Tactical client #11, but 'Acme' (#99) is what this PSA client's mapping resolves to now; "
            ."the approved target changed and no custom-field write was sent. Deny this proposal in the cockpit, then stage the write again so 'Acme' (#99) is read and approved on its own proposal.",
            $refusal,
        );
        $this->assertOnlyTheRead('staged-pin changed arm');
    }

    // ---- negative controls: pre-read arms keep their claim, each by its own text ----

    public function test_the_unmapped_client_arm_keeps_its_claim(): void
    {
        $client = $this->mappedClient(null);
        $this->bindReadThenWritable([['id' => 11, 'name' => 'Acme']]);

        $out = $this->writeNow($client);

        $this->assertSame('Client has no Tactical site mapping; '.self::FALSE_CLAIM.'.', $out['error'] ?? null);
        $this->assertNothingSent('unmapped arm');
    }

    public function test_the_disabled_integration_arm_keeps_its_claim(): void
    {
        // #3887: the full text names the disabled integration, so another
        // pre-read arm answering in its place fails here.
        Setting::setValue('controld_enabled', '0');
        $client = $this->mappedClient();
        $this->bindReadThenWritable([['id' => 11, 'name' => 'Acme']]);

        $out = $this->writeNow($client);

        $this->assertSame(
            "The Control D integration is disabled in PSA (setting 'controld_enabled'), and 'controld_org_id' is its deploy trigger; ".self::FALSE_CLAIM.'.',
            $out['error'] ?? null,
        );
        $this->assertNothingSent('disabled-integration arm');
    }

    public function test_the_disabled_integration_arm_keeps_its_claim_at_approval(): void
    {
        $client = $this->mappedClient();
        $run = $this->stage($client);
        Setting::setValue('controld_enabled', '0');
        $this->bindReadThenWritable([['id' => 11, 'name' => 'Acme']]);

        $this->assertSame(
            "The Control D integration is disabled in PSA (setting 'controld_enabled'), and 'controld_org_id' is its deploy trigger; ".self::FALSE_CLAIM.'.',
            $this->approve($run),
        );
        $this->assertNothingSent('disabled-integration arm at approval');
    }

    /** @return array<string, array{0: string|null}> */
    public static function unconfiguredFieldIds(): array
    {
        return [
            'the setting is absent' => [null],
            'the setting is empty' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unconfiguredFieldIds')]
    public function test_the_unconfigured_field_id_arm_keeps_its_claim(?string $setting): void
    {
        // #3883. setUp sets the field id, so only this test and the approval
        // variant below reach this arm.
        $setting === null
            ? Setting::where('key', 'controld_tactical_client_field_id')->delete()
            : Setting::setValue('controld_tactical_client_field_id', $setting);
        $client = $this->mappedClient();
        $this->bindReadThenWritable([['id' => 11, 'name' => 'Acme']]);

        $out = $this->writeNow($client);

        $this->assertSame(
            "The Tactical CLIENT custom field id for 'controld_org_id' is not configured (setting 'controld_tactical_client_field_id'); ".self::FALSE_CLAIM.'.',
            $out['error'] ?? null,
        );
        $this->assertNothingSent('unconfigured-field-id arm');
    }

    public function test_the_unconfigured_field_id_arm_keeps_its_claim_at_approval(): void
    {
        $client = $this->mappedClient();
        $run = $this->stage($client);
        Setting::setValue('controld_tactical_client_field_id', '');
        $this->bindReadThenWritable([['id' => 11, 'name' => 'Acme']]);

        $this->assertSame(
            "The Tactical CLIENT custom field id for 'controld_org_id' is not configured (setting 'controld_tactical_client_field_id'); ".self::FALSE_CLAIM.'.',
            $this->approve($run),
        );
        $this->assertNothingSent('unconfigured-field-id arm at approval');
    }

    // ---- the cooldown arm: unreachable at base, measured rather than assumed ----

    public function test_an_active_cooldown_row_does_not_reach_the_cooldown_refusal(): void
    {
        // #3882. NOT a wording control. The client-field cooldown refusal cannot be
        // reached while COOLDOWNS['tactical_set_client_custom_field'] is 0, because
        // cooldownActive() returns false for a zero window before it reads any
        // row. This seeds a row the predicate would count and shows the write
        // still goes out. If a nonzero window comes back, this fails. That is
        // when the cooldown message can get a real negative control.
        $client = $this->mappedClient();
        foreach (['executed', 'awaiting_approval'] as $status) {
            TechnicianActionLog::create([
                'action_type' => 'tactical_set_client_custom_field',
                'client_id' => $client->id,
                'actor_label' => 'seed',
                'result_status' => $status,
                'summary' => 'seeded cooldown row',
                'tier' => 'approve',
                'content_hash' => 'seed-'.$status,
                'correlation_id' => 'seed-'.$status,
            ]);
        }
        $this->bindReadThenWritable([['id' => 11, 'name' => 'Acme']]);

        $out = $this->writeNow($client);

        $this->assertTrue($out['success'] ?? false, json_encode($out));
        $this->assertSame([self::READ, 'PUT https://tactical.invalid/clients/11/'], $this->requests());
    }
}
