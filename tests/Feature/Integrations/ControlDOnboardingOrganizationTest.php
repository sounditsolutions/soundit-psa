<?php

namespace Tests\Feature\Integrations;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDClientException;
use App\Services\ControlD\ControlDOnboardingOrganization;
use App\Services\ControlD\ControlDOrganizationUncertainException;
use App\Services\ControlD\ControlDWriteRejectedException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ControlDOnboardingOrganizationTest extends TestCase
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

    private function response(mixed $body): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'body' => $body]));
    }

    private function transport(array $responses): ControlDClient
    {
        $stack = HandlerStack::create(new MockHandler([...$responses, ...array_fill(0, 8, new Response(503))]));
        $stack->push(Middleware::history($this->history));

        return new ControlDClient(['api_key' => 'synthetic-key', 'handler' => $stack]);
    }

    private function writer(?array $responses = null): ControlDOnboardingOrganization
    {
        return new ControlDOnboardingOrganization($this->transport($responses ?? [
            $this->response(['organization' => $this->row()]),
            $this->response(['sub_organizations' => [$this->listed()]]),
        ]));
    }

    private function runCreate(ControlDOnboardingOrganization $writer, User $actor, Client $client): void
    {
        $writer->create($actor, $client->id, 'Synthetic Organization', 'synthetic@example.invalid', 1, 'synthetic-region');
    }

    private function refusal(callable $call, string $class): ControlDClientException
    {
        try {
            $call();
        } catch (ControlDClientException $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('synthetic-key', $e->getMessage());
            $this->assertStringNotContainsString('synthetic@example.invalid', $e->getMessage());

            return $e;
        }
        $this->fail('Expected refusal, but operation succeeded.');
    }

    public function test_creates_outside_transaction_then_maps_and_audits_only_ids(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        Log::spy();
        $writer = $this->writer([
            function () {
                $this->assertSame(0, DB::transactionLevel());

                return $this->response(['organization' => $this->row()]);
            },
            function () {
                $this->assertSame(0, DB::transactionLevel());

                return $this->response(['sub_organizations' => [$this->listed()]]);
            },
        ]);
        $this->runCreate($writer, $actor, $client);
        $this->assertSame('syntheticOrg01', $client->fresh()->controld_org_id);
        $this->assertCount(2, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/organizations/suborg', $request->getUri()->getPath());
        $this->assertFalse($request->hasHeader('X-Force-Org-Id'));
        parse_str((string) $request->getBody(), $body);
        $this->assertSame(['name' => 'Synthetic Organization', 'contact_email' => 'synthetic@example.invalid', 'twofa_req' => '1', 'stats_endpoint' => 'synthetic-region', 'parent_profile' => '111111synthAA'], $body);
        $this->assertFalse($this->history[0]['options']['http_errors']);
        $this->assertFalse($this->history[0]['options']['allow_redirects']);
        Log::shouldHaveReceived('info')->once()->with('[ControlDOnboardingOrganization] Organization mapped', ['actor_id' => $actor->id, 'client_id' => $client->id, 'org_pk' => 'syntheticOrg01']);
    }

    public static function unauthorized(): array
    {
        return [['tech', true], ['admin', false]];
    }

    #[DataProvider('unauthorized')]
    public function test_non_admin_and_inactive_refuse_before_vendor(string $role, bool $active): void
    {
        $actor = User::factory()->create(['role' => $role, 'is_active' => $active]);
        $client = Client::factory()->create();
        $this->refusal(fn () => $this->runCreate($this->writer(), $actor, $client), ControlDClientException::class);
        $this->assertCount(0, $this->history);
        $this->assertNull($client->fresh()->controld_org_id);
    }

    public function test_stale_admin_object_is_not_authority(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        User::whereKey($actor->id)->update(['is_active' => false]);
        $this->refusal(fn () => $this->runCreate($this->writer(), $actor, Client::factory()->create()), ControlDClientException::class);
        $this->assertCount(0, $this->history);
    }

    public function test_ambient_transaction_refuses_before_vendor(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        DB::beginTransaction();
        try {
            $this->refusal(fn () => $this->runCreate($this->writer(), $actor, $client), ControlDClientException::class);
            $this->assertCount(0, $this->history);
            $this->assertNull($client->fresh()->controld_org_id);
        } finally {
            DB::rollBack();
        }
    }

    public static function badReadbacks(): array
    {
        return [
            'absent' => [[]],
            'foreign' => [[['PK' => 'foreign', 'name' => 'Synthetic Organization']]],
            'wrong-name' => [[['PK' => 'syntheticOrg01', 'name' => 'Wrong']]],
            'duplicate' => [[['PK' => 'syntheticOrg01', 'name' => 'Synthetic Organization'], ['PK' => 'syntheticOrg01', 'name' => 'Synthetic Organization']]],
            'malformed' => [[['PK' => 'syntheticOrg01']]],
            'object-not-list' => [(object) []],
        ];
    }

    #[DataProvider('badReadbacks')]
    public function test_readback_refusal_preserves_post_pk_and_never_maps(mixed $rows): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $writer = $this->writer([$this->response(['organization' => $this->row()]), $this->response(['sub_organizations' => $rows])]);
        $e = $this->refusal(fn () => $this->runCreate($writer, $actor, $client), ControlDOrganizationUncertainException::class);
        $this->assertSame('syntheticOrg01', $e->orgPk);
        $this->assertSame('readback', $e->phase);
        $this->assertNull($client->fresh()->controld_org_id);
        $this->assertCount(2, $this->history);
    }

    public function test_trashed_mapping_collision_refuses_before_save(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $other = Client::factory()->create(['controld_org_id' => 'syntheticOrg01']);
        $other->delete();
        $saving = 0;
        Client::saving(function () use (&$saving): void {
            $saving++;
        });
        try {
            $e = $this->refusal(fn () => $this->runCreate($this->writer(), $actor, $client), ControlDOrganizationUncertainException::class);
            $this->assertSame(0, $saving, 'Conflict must refuse before the unique index or model save.');
            $this->assertSame('syntheticOrg01', $e->orgPk);
            $this->assertSame('local-persistence', $e->phase);
            $this->assertNull($client->fresh()->controld_org_id);
        } finally {
            Client::flushEventListeners();
        }
    }

    public static function races(): array
    {
        return [['demote'], ['deactivate'], ['delete-actor'], ['map-client'], ['delete-client']];
    }

    #[DataProvider('races')]
    public function test_locked_recheck_refuses_changed_state_with_orphan_pk(string $race): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $writer = $this->writer([
            $this->response(['organization' => $this->row()]),
            function () use ($actor, $client, $race) {
                match ($race) {
                    'demote' => User::whereKey($actor->id)->update(['role' => 'tech']),
                    'deactivate' => User::whereKey($actor->id)->update(['is_active' => false]),
                    'delete-actor' => User::whereKey($actor->id)->delete(),
                    'map-client' => Client::whereKey($client->id)->update(['controld_org_id' => 'otherOrg']),
                    'delete-client' => $client->delete(),
                };

                return $this->response(['sub_organizations' => [$this->listed()]]);
            },
        ]);
        $e = $this->refusal(fn () => $this->runCreate($writer, $actor, $client), ControlDOrganizationUncertainException::class);
        $this->assertSame('syntheticOrg01', $e->orgPk);
        $this->assertSame('local-persistence', $e->phase);
        $this->assertNotSame('syntheticOrg01', Client::withTrashed()->find($client->id)->controld_org_id);
        $this->assertCount(2, $this->history);
    }

    public static function postFailures(): array
    {
        return [
            [400, '{"success":false,"error":{"code":40000,"message":"synthetic-key"}}', true],
            [429, '', false], [403, '<html>synthetic-key</html>', false],
            [400, '{"success":false,"error":{"code":"40000"}}', false],
            [500, '{"success":false,"error":{"code":50000}}', false],
            [302, '', false], [200, '{', false], [200, '{"success":true,"body":[]}', false],
            [200, '{"success":true,"body":{"organization":{"PK":42}}}', false],
        ];
    }

    #[DataProvider('postFailures')]
    public function test_post_envelope_only_rejection_other_outcomes_uncertain(int $status, string $body, bool $rejected): void
    {
        $client = Client::factory()->create();
        $actor = User::factory()->create(['is_active' => true]);
        $e = $this->refusal(fn () => $this->runCreate($this->writer([new Response($status, [], $body)]), $actor, $client), $rejected ? ControlDWriteRejectedException::class : ControlDOrganizationUncertainException::class);
        if (! $rejected) {
            $this->assertNull($e->orgPk);
            $this->assertSame('post', $e->phase);
        }
        $this->assertNull($client->fresh()->controld_org_id);
        $this->assertCount(1, $this->history);
    }

    public function test_get_vendor_rejection_is_uncertain_and_keeps_pk(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $writer = $this->writer([$this->response(['organization' => $this->row()]), new Response(400, [], '{"success":false,"error":{"code":40000}}')]);
        $e = $this->refusal(fn () => $this->runCreate($writer, $actor, $client), ControlDOrganizationUncertainException::class);
        $this->assertSame('syntheticOrg01', $e->orgPk);
        $this->assertSame('readback', $e->phase);
    }

    public function test_save_veto_is_orphan_not_success(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        Client::saving(fn () => false);
        try {
            $e = $this->refusal(fn () => $this->runCreate($this->writer(), $actor, $client), ControlDOrganizationUncertainException::class);
            $this->assertSame('syntheticOrg01', $e->orgPk);
            $this->assertNull($client->fresh()->controld_org_id);
        } finally {
            Client::flushEventListeners();
        }
    }

    public function test_method_path_pairs_and_unconfigured_key_refuse_without_transport(): void
    {
        $transport = $this->transport([]);
        foreach ([['GET', 'organizations/suborg', null], ['POST', 'organizations/sub_organizations', []], ['DELETE', 'organizations/suborg', null], ['GET', 'https://example.invalid', null], ['GET', 'organizations/sub_organizations', []]] as [$method, $path, $body]) {
            $this->refusal(fn () => $transport->requestParent($method, $path, $body), ControlDClientException::class);
        }
        $this->assertCount(0, $this->history);
    }

    public function test_connection_failure_is_sanitized_uncertain_without_retry(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $error = new \GuzzleHttp\Exception\ConnectException('synthetic-key synthetic@example.invalid', new \GuzzleHttp\Psr7\Request('POST', 'https://example.invalid'));
        $e = $this->refusal(fn () => $this->runCreate($this->writer([$error]), $actor, $client), ControlDOrganizationUncertainException::class);
        $this->assertNull($e->orgPk);
        $this->assertCount(1, $this->history);
    }

    public function test_disabled_and_invalid_explicit_choices_refuse_before_vendor(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $writer = $this->writer();
        foreach ([['', 'x@example.invalid', 1, 'region'], ['Name', 'bad-email', 1, 'region'], ['Name', 'x@example.invalid', 2, 'region'], ['Name', 'x@example.invalid', 1, '']] as [$name, $email, $mfa, $region]) {
            $this->refusal(fn () => $writer->create($actor, $client->id, $name, $email, $mfa, $region), ControlDClientException::class);
        }
        Setting::setValue('controld_enabled', '0');
        $this->refusal(fn () => $this->runCreate($writer, $actor, $client), ControlDClientException::class);
        $this->assertCount(0, $this->history);
    }

    public function test_unconfigured_key_refuses_definitely_before_any_request(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 4, new Response(503))));
        $stack->push(Middleware::history($this->history));
        $writer = new ControlDOnboardingOrganization(new ControlDClient(['api_key' => ' ', 'handler' => $stack]));
        $e = $this->refusal(fn () => $this->runCreate($writer, $actor, $client), ControlDClientException::class);
        $this->assertNotInstanceOf(ControlDOrganizationUncertainException::class, $e);
        $this->assertCount(0, $this->history);
        $this->assertNull($client->fresh()->controld_org_id);
    }

    public function test_unconfigured_transport_refuses_and_unsaved_actor_cannot_authorize(): void
    {
        $this->refusal(fn () => (new ControlDClient([]))->requestParent('GET', 'organizations/sub_organizations'), ControlDClientException::class);
        $actor = new User(['role' => UserRole::Admin, 'is_active' => true]);
        $this->refusal(fn () => $this->runCreate($this->writer(), $actor, Client::factory()->create()), ControlDClientException::class);
        $this->assertCount(0, $this->history);
    }

    public function test_existing_mapping_and_deleted_client_refuse_before_vendor(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        foreach ([false, true] as $deleted) {
            $client = Client::factory()->create(['controld_org_id' => $deleted ? null : 'existing']);
            if ($deleted) {
                $client->delete();
            }
            $this->refusal(fn () => $this->runCreate($this->writer(), $actor, $client), ControlDClientException::class);
        }
        $this->assertCount(0, $this->history);
    }
}
