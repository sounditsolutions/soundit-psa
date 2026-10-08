<?php

namespace Tests\Feature\Integrations;

use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\Setting;
use App\Models\User;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDClientException;
use App\Services\ControlD\ControlDOnboardingStaged;
use App\Services\ControlD\ControlDProvisioning;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ControlDOnboardingStagedTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    public function beginDatabaseTransaction(): void
    {
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::setValue('controld_enabled', '1');
        // Synthetic Global Profile PK (G-13): the org step enforces it as parent_profile.
        Setting::setValue('controld_default_profile_id', '111111synthAA');
    }

    /** One parent-inventory row (GET sub_organizations producer schema) with the global profile set. */
    private function listed(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/sub-organization.json')), true)['body']['sub_organizations'][0];
    }

    private function row(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/organization.json')), true)['body']['organization'];
    }

    private function response(array $body): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'body' => $body]));
    }

    private function writer(?array $responses = null): ControlDOnboardingStaged
    {
        // Direct Guzzle isolation, not a claim that Laravel's facade fakes Guzzle.
        $handler = new MockHandler([...($responses ?? [
            $this->response(['organization' => $this->row()]),
            $this->response(['sub_organizations' => [$this->listed()]]),
        ]), ...array_fill(0, 8, new Response(503))]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));
        $transport = new ControlDClient(['api_key' => 'synthetic-key', 'handler' => $stack]);

        return new ControlDOnboardingStaged($transport, new ControlDProvisioning($transport));
    }

    private function stage(ControlDOnboardingStaged $writer, User $actor, Client $client): string
    {
        return $writer->stageOrganization($actor, $client->id, 'Synthetic Organization', 'synthetic@example.invalid', 1, 'synthetic-region');
    }

    private function refusal(callable $call): void
    {
        try {
            $call();
        } catch (ControlDClientException $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('synthetic-key', $e->getMessage());
            $this->assertStringNotContainsString('synthetic@example.invalid', $e->getMessage());

            return;
        }
        $this->fail('Expected a definite local refusal.');
    }

    public function test_durable_lock_and_intent_exist_before_post_then_exact_readback_binds_atomically(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $observed = [];
        $writer = $this->writer([
            function () use (&$observed) {
                $intent = ControlDOnboardingIntent::first();
                $observed = [DB::transactionLevel(), $intent?->state, $intent?->active_client_id, $intent?->client_id];

                return $this->response(['organization' => $this->row()]);
            },
            $this->response(['sub_organizations' => [array_merge($this->listed(), ['PK' => 'sibling01', 'name' => 'Sibling']), $this->listed()]]),
        ]);
        $id = $this->stage($writer, $actor, $client);
        $this->assertSame('staged', ControlDOnboardingIntent::findOrFail($id)->state);
        $this->assertCount(0, $this->history);
        $this->assertSame($id, $writer->execute($actor, $id));
        $this->assertSame([0, 'posted', $client->id, $client->id], $observed);
        $row = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame('bound', $row->state);
        $this->assertNull($row->active_client_id);
        $this->assertSame('syntheticOrg01', $row->org_pk);
        $this->assertSame($row->org_pk, $client->fresh()->controld_org_id);
        $this->assertCount(2, $this->history);
        $this->assertSame('POST', $this->history[0]['request']->getMethod());
        $this->assertSame('/organizations/suborg', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('GET', $this->history[1]['request']->getMethod());
        $this->assertSame('/organizations/sub_organizations', $this->history[1]['request']->getUri()->getPath());
        $this->refusal(fn () => $writer->execute($actor, $id));
        $this->assertCount(2, $this->history);
    }

    public function test_readonly_rejection_is_terminal_with_fixed_reason_and_no_reconcile_or_retry(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $writer = $this->writer([new Response(403, [], file_get_contents(base_path('tests/Fixtures/ControlD/read-only-rejection.json')))]);
        $id = $this->stage($writer, $actor, $client);
        $writer->execute($actor, $id);
        $row = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame('rejected', $row->state);
        $this->assertSame('post', $row->phase);
        $this->assertSame(40301, $row->reason_code);
        $this->assertSame('vendor key is read-only', $row->reason);
        $this->assertNull($row->vendor_pk);
        $this->assertNull($row->org_pk);
        $this->assertNull($row->active_client_id);
        $this->assertNull($client->fresh()->controld_org_id);
        $this->refusal(fn () => $writer->execute($actor, $id));
        $this->assertCount(1, $this->history);
        $this->assertSame('POST', $this->history[0]['request']->getMethod());
        $this->assertStringNotContainsString('This read-only token', json_encode($row));
    }

    public function test_independent_process_cannot_stage_same_client_during_post_but_can_stage_other_client(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $file = tempnam(sys_get_temp_dir(), 'controld-test-');
        unlink($file);
        DB::statement('VACUUM INTO '.DB::getPdo()->quote($file));
        $old = config('database.connections.sqlite.database');
        config(['database.connections.sqlite.database' => $file]);
        DB::purge('sqlite');
        try {
            $childResult = null;
            $writer = $this->writer([
                function () use ($actor, $client, $other, $file, &$childResult) {
                    $child = new Process([PHP_BINARY, base_path('tests/Fixtures/ControlD/stage-contender.php'),
                        $file, (string) $actor->id, (string) $client->id, (string) $other->id], base_path());
                    $child->setTimeout(10);
                    $child->run();
                    $childResult = [$child->getExitCode(), trim($child->getOutput()), $child->getErrorOutput()];

                    return $this->response(['organization' => $this->row()]);
                },
                $this->response(['sub_organizations' => [$this->listed()]]),
            ]);
            $id = $this->stage($writer, $actor, $client);
            $writer->execute($actor, $id);
            $this->assertSame([0, 'same=refused other=staged', ''], $childResult);
            $this->assertSame(1, ControlDOnboardingIntent::where('client_id', $client->id)->count());
            $this->assertSame(1, ControlDOnboardingIntent::where('client_id', $other->id)->where('state', 'staged')->count());
            $this->assertSame('bound', ControlDOnboardingIntent::findOrFail($id)->state);
            $this->assertCount(2, $this->history);
        } finally {
            DB::purge('sqlite');
            config(['database.connections.sqlite.database' => $old]);
            @unlink($file);
        }
    }

    public function test_posted_crash_evidence_and_uncertain_cannot_be_executed_even_by_fresh_service(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $writer = $this->writer();
        $id = $this->stage($writer, $actor, $client);
        // Simulates durable admission surviving abrupt death, before any completion.
        ControlDOnboardingIntent::whereKey($id)->update(['state' => 'posted', 'phase' => 'post']);
        $this->refusal(fn () => $this->writer()->execute($actor, $id));
        $this->refusal(fn () => $this->stage($this->writer(), $actor, $client));
        $this->assertSame('posted', ControlDOnboardingIntent::findOrFail($id)->state);
        $this->assertCount(0, $this->history);
    }

    public static function readbackFailures(): array
    {
        return ['case-only' => ['synthetic organization', false], 'duplicate' => ['Synthetic Organization', true]];
    }

    #[DataProvider('readbackFailures')]
    public function test_exact_name_and_unique_own_pk_are_required(string $name, bool $duplicate): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $rows = [array_merge($this->listed(), ['name' => $name])];
        if ($duplicate) {
            $rows[] = $this->listed();
        }
        $writer = $this->writer([$this->response(['organization' => $this->row()]), $this->response(['sub_organizations' => $rows])]);
        $id = $this->stage($writer, $actor, $client);
        $writer->execute($actor, $id);
        $row = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame('uncertain', $row->state);
        $this->assertSame('readback', $row->phase);
        $this->assertSame('syntheticOrg01', $row->vendor_pk);
        $this->assertNull($client->fresh()->controld_org_id);
        $this->refusal(fn () => $writer->execute($actor, $id));
        $this->assertCount(2, $this->history);
    }

    public function test_actor_demotion_during_readback_preserves_orphan_and_does_not_bind(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $writer = $this->writer([
            $this->response(['organization' => $this->row()]),
            function () use ($actor) {
                User::whereKey($actor->id)->update(['is_active' => false]);

                return $this->response(['sub_organizations' => [$this->listed()]]);
            },
        ]);
        $id = $this->stage($writer, $actor, $client);
        $writer->execute($actor, $id);
        $row = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame('uncertain', $row->state);
        $this->assertSame('local-persistence', $row->phase);
        $this->assertSame('syntheticOrg01', $row->vendor_pk);
        $this->assertNull($client->fresh()->controld_org_id);
    }

    public function test_ambient_transaction_and_stale_inactive_actor_refuse_before_staging(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $writer = $this->writer();
        DB::beginTransaction();
        try {
            $this->refusal(fn () => $this->stage($writer, $actor, $client));
        } finally {
            DB::rollBack();
        }
        User::whereKey($actor->id)->update(['is_active' => false]);
        $this->refusal(fn () => $this->stage($writer, $actor, $client));
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertCount(0, $this->history);
    }

    public function test_intent_payload_is_encrypted_hidden_and_down_refuses_populated_evidence(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $id = $this->stage($this->writer(), $actor, $client);
        $row = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame('synthetic@example.invalid', $row->payload['contact_email']);
        $this->assertStringNotContainsString('synthetic@example.invalid', $row->getRawOriginal('payload'));
        $this->assertArrayNotHasKey('payload', $row->toArray());
        $migration = require database_path('migrations/2026_09_17_000000_create_controld_onboarding_intents_table.php');
        try {
            $migration->down();
            $this->fail('Populated intent evidence was deleted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Retain populated', $e->getMessage());
        }
        $this->assertNotNull(ControlDOnboardingIntent::find($id));
    }

    private function codeSetup(): array
    {
        $this->travelTo(now()->startOfSecond());
        Setting::setEncrypted('controld_api_key', 'synthetic-key');
        foreach (['tactical_client_field_id' => '18', 'default_profile_id' => 'testprofile01', 'code_expiry_days' => '7',
            'code_device_limit_headroom' => '2', 'code_analytics_level' => '0', 'code_intercept_mode' => 'standard'] as $key => $value) {
            Setting::setValue('controld_'.$key, $value);
        }
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/provision.json')), true);
        $row['max'] = 2;
        $row['ts_exp'] = now()->getTimestamp() + 7 * 86400;

        return $row;
    }

    private function codePreflight(): array
    {
        return [$this->response(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]),
            $this->response(['profiles' => [['PK' => 'testprofile01']]])];
    }

    public function test_code_intent_stores_encrypted_secrets_without_holding_transaction_over_vendor(): void
    {
        $row = $this->codeSetup();
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['controld_org_id' => $row['org']]);
        $observed = [];
        $readbackObserved = [];
        $writer = $this->writer([...$this->codePreflight(), function () use (&$observed) {
            $intent = ControlDOnboardingIntent::first();
            $observed = [DB::transactionLevel(), $intent?->active_client_id, $intent?->state];

            return $this->response(['provision' => $this->codeSetup()]);
        }, function () use ($row, &$readbackObserved) {
            $intent = ControlDOnboardingIntent::first();
            $readbackObserved = [DB::transactionLevel(), $intent?->vendor_pk, $intent?->phase];

            return $this->response(['provisions' => [$row]]);
        }]);
        $id = $writer->stageCode($actor, $client->id, 'desktop-windows');
        $writer->execute($actor, $id);
        $this->assertSame([0, $client->id, 'posted'], $observed);
        $this->assertSame([0, $row['PK'], 'read-back'], $readbackObserved);
        $this->assertSame('bound', ControlDOnboardingIntent::findOrFail($id)->state);
        $this->assertSame($row['code'], $client->fresh()->controld_provisioning_code);
        $this->assertStringNotContainsString($row['code'], $client->fresh()->getRawOriginal('controld_provisioning_code'));
        $this->assertArrayNotHasKey('controld_provisioning_code', $client->fresh()->toArray());
        $this->assertCount(4, $this->history);
        $this->assertSame('POST', $this->history[2]['request']->getMethod());
        $this->assertSame('GET', $this->history[3]['request']->getMethod());
    }

    public function test_code_readonly_rejection_and_readback_uncertainty_remain_distinct(): void
    {
        $row = $this->codeSetup();
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['controld_org_id' => $row['org']]);
        $writer = $this->writer([...$this->codePreflight(), new Response(403, [], file_get_contents(base_path('tests/Fixtures/ControlD/read-only-rejection.json')))]);
        $id = $writer->stageCode($actor, $client->id, 'desktop-windows');
        $writer->execute($actor, $id);
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame('rejected', $intent->state);
        $this->assertSame(40301, $intent->reason_code);
        $this->assertSame('vendor key is read-only', $intent->reason);
        $this->assertNull($intent->vendor_pk);
        $this->refusal(fn () => $writer->execute($actor, $id));
        $this->assertCount(3, $this->history);
        // A new explicit operator intent is not cached key capability nor an automatic
        // retry of the rejected intent. The token type can change without key rotation.
        $writer = $this->writer([...$this->codePreflight(), $this->response(['provision' => $row]), new Response(403, [], file_get_contents(base_path('tests/Fixtures/ControlD/read-only-rejection.json')))]);
        $second = $writer->stageCode($actor, $client->id, 'desktop-windows');
        $writer->execute($actor, $second);
        $intent = ControlDOnboardingIntent::findOrFail($second);
        $this->assertSame('uncertain', $intent->state);
        $this->assertSame('read-back', $intent->phase);
        $this->assertSame($row['PK'], $intent->vendor_pk);
        $this->assertSame($row['org'], $intent->org_pk);
        $this->assertNull($intent->reason_code);
        $this->assertNull($client->fresh()->controld_provisioning_code);
        $this->refusal(fn () => $writer->execute($actor, $second));
        $this->refusal(fn () => $writer->stageCode($actor, $client->id, 'desktop-windows'));
        $this->assertCount(7, $this->history);
    }

    public static function failureResponses(): array
    {
        return [
            'empty429' => [429, '', 'uncertain', null],
            'html403' => [403, '<html>synthetic-key</html>', 'uncertain', null],
            'stringCode' => [403, '{"success":false,"error":{"code":"40301"}}', 'uncertain', null],
            'wrongSuccess' => [403, '{"success":true,"error":{"code":40301}}', 'uncertain', null],
            'serverEnvelope' => [503, '{"success":false,"error":{"code":40301}}', 'uncertain', null],
            'otherRejection' => [400, '{"success":false,"error":{"code":40001}}', 'rejected', 40001],
            'wrongStatus' => [401, '{"success":false,"error":{"code":40301}}', 'rejected', 40301],
        ];
    }

    #[DataProvider('failureResponses')]
    public function test_m2_envelope_classification_and_no_retry(int $status, string $body, string $state, ?int $code): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $writer = $this->writer([new Response($status, [], $body)]);
        $id = $this->stage($writer, $actor, $client);
        $writer->execute($actor, $id);
        $row = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame($state, $row->state);
        $this->assertSame($code, $row->reason_code);
        $this->assertNotSame('vendor key is read-only', $row->reason);
        $this->assertSame('post', $row->phase);
        $this->refusal(fn () => $writer->execute($actor, $id));
        if ($state === 'uncertain') {
            $this->refusal(fn () => $this->stage($writer, $actor, $client));
            $this->assertSame($client->id, $row->active_client_id);
        }
        $this->assertCount(1, $this->history);
        $this->assertNull($client->fresh()->controld_org_id);
    }

    public function test_local_settings_refusal_leaves_the_intent_staged_and_still_executable(): void
    {
        $row = $this->codeSetup();
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['controld_org_id' => $row['org']]);
        $id = $this->writer([])->stageCode($actor, $client->id, 'desktop-windows');
        Setting::setValue('controld_default_profile_id', '');
        // A zero-I/O local refusal is not evidence that a POST may have happened.
        $this->refusal(fn () => $this->writer([])->execute($actor, $id));
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame('staged', $intent->state);
        $this->assertSame('preflight', $intent->phase);
        $this->assertNull($intent->reason);
        $this->assertNull($intent->reason_code);
        $this->assertNull($intent->vendor_pk);
        $this->assertSame($client->id, $intent->active_client_id);
        $this->assertCount(0, $this->history);
        Setting::setValue('controld_default_profile_id', 'testprofile01');
        $writer = $this->writer([...$this->codePreflight(), $this->response(['provision' => $row]), $this->response(['provisions' => [$row]])]);
        $writer->execute($actor, $id);
        $this->assertSame('bound', ControlDOnboardingIntent::findOrFail($id)->state);
        $this->assertSame($row['code'], $client->fresh()->controld_provisioning_code);
        $this->assertCount(4, $this->history);
    }

    public function test_client_state_change_between_stage_and_execute_refuses_without_posting(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $writer = $this->writer();
        $id = $this->stage($writer, $actor, $client);
        Client::whereKey($client->id)->update(['controld_org_id' => 'racedOrg01']);
        $this->refusal(fn () => $writer->execute($actor, $id));
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame('staged', $intent->state);
        $this->assertSame('preflight', $intent->phase);
        $this->assertNull($intent->org_pk);
        $this->assertNull($intent->reason);
        $this->assertSame($client->id, $intent->active_client_id);
        $this->assertCount(0, $this->history);
    }
}
