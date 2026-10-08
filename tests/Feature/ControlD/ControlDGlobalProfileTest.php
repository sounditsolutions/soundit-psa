<?php

namespace Tests\Feature\ControlD;

use App\Models\Client;
use App\Models\ControlDOnboardingIntent;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\User;
use App\Services\ControlD\ControlDClient;
use App\Services\ControlD\ControlDClientException;
use App\Services\ControlD\ControlDOnboardingOrganization;
use App\Services\ControlD\ControlDOnboardingStaged;
use App\Services\ControlD\ControlDOrganizationUncertainException;
use App\Services\ControlD\ControlDProvisioning;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * XULQ2iix ruling (c): the configured Global Profile (controld_default_profile_id) is
 * enforced on Control D sub-organizations through `parent_profile`; the code preflight
 * accepts it; a never-admitted intent can be released by an Admin. Every vendor exchange
 * is a Guzzle MockHandler; Http::preventStrayRequests() guards the rest. All PKs are
 * synthetic (G-13). The GET sub_organizations rows come from the producer-schema fixture
 * tests/Fixtures/ControlD/sub-organization.json.
 */
class ControlDGlobalProfileTest extends TestCase
{
    use RefreshDatabase;

    private const GLOBAL = '111111synthAA';

    private const ORG = '222222synthBB';

    private array $history = [];

    /** B3 refuses an ambient transaction; same construction as ControlDOnboardingStagedTest. */
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
        Setting::setEncrypted('controld_api_key', 'synthetic-key');
        Setting::setValue('controld_default_profile_id', self::GLOBAL);
    }

    private function ok(mixed $body): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'body' => $body]));
    }

    /** A producer-schema GET sub_organizations row; $global null = parent_profile ABSENT (the documented unset shape). */
    private function listed(string $pk = self::ORG, ?string $global = self::GLOBAL, string $name = 'Synthetic Organization'): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/sub-organization.json')), true)['body']['sub_organizations'][0];
        $row['PK'] = $pk;
        $row['name'] = $name;
        if ($global === null) {
            unset($row['parent_profile']);
        } else {
            $row['parent_profile']['PK'] = $global;
        }

        return $row;
    }

    private function inventory(array ...$rows): Response
    {
        return $this->ok(['sub_organizations' => $rows]);
    }

    private function created(string $pk = self::ORG): Response
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/organization.json')), true)['body']['organization'];
        $row['PK'] = $pk;

        return $this->ok(['organization' => $row]);
    }

    private function transport(array $responses): ControlDClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([...$responses, ...array_fill(0, 8, new Response(503))]));
        $stack->push(Middleware::history($this->history));

        return new ControlDClient(['api_key' => 'synthetic-key', 'handler' => $stack]);
    }

    private function writer(array $responses): ControlDOnboardingStaged
    {
        $transport = $this->transport($responses);

        return new ControlDOnboardingStaged($transport, new ControlDProvisioning($transport));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['is_active' => true]);
    }

    private function refused(callable $call, string $class = ControlDClientException::class): ControlDClientException
    {
        try {
            $call();
        } catch (ControlDClientException $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('synthetic-key', $e->getMessage());

            return $e;
        }
        $this->fail('Expected a refusal.');
    }

    /** @return array<int, string> method + path of every recorded request */
    private function calls(): array
    {
        return array_map(fn ($r) => $r['request']->getMethod().' '.$r['request']->getUri()->getPath(), $this->history);
    }

    // ── item 1: org create sends parent_profile and reads it back ───────────────

    public function test_b3_organization_post_sends_the_global_profile_and_binds_only_when_read_back(): void
    {
        $actor = $this->admin();
        $client = Client::factory()->create();
        $writer = $this->writer([$this->created(), $this->inventory($this->listed('sibling01', null, 'Sibling'), $this->listed())]);
        $id = $writer->stageOrganization($actor, $client->id, 'Synthetic Organization', 'synthetic@example.invalid', 1, 'synthetic-region');
        $writer->execute($actor, $id);
        $this->assertSame(['POST /organizations/suborg', 'GET /organizations/sub_organizations'], $this->calls());
        parse_str((string) $this->history[0]['request']->getBody(), $sent);
        $this->assertSame(self::GLOBAL, $sent['parent_profile'] ?? null);
        $this->assertSame('bound', ControlDOnboardingIntent::findOrFail($id)->state);
        $this->assertSame(self::ORG, $client->fresh()->controld_org_id);
    }

    public function test_b2_organization_post_sends_the_global_profile_and_maps_only_when_read_back(): void
    {
        $actor = $this->admin();
        $client = Client::factory()->create();
        $writer = new ControlDOnboardingOrganization($this->transport([$this->created(), $this->inventory($this->listed())]));
        $writer->create($actor, $client->id, 'Synthetic Organization', 'synthetic@example.invalid', 1, 'synthetic-region');
        parse_str((string) $this->history[0]['request']->getBody(), $sent);
        $this->assertSame(self::GLOBAL, $sent['parent_profile'] ?? null);
        $this->assertSame(self::ORG, $client->fresh()->controld_org_id);
    }

    /** Shapes the producer schema does not document as 'equal to the setting'. Absent = unset; the rest are malformed. */
    public static function badParentProfiles(): array
    {
        return [
            'absent' => ['absent'],
            'other-pk' => [['PK' => '333333synthCC', 'updated' => 1, 'name' => 'Other']],
            'null' => [null],
            'scalar' => [self::GLOBAL],
            'object-without-pk' => [['updated' => 1, 'name' => 'x']],
            'integer-pk' => [['PK' => 111111, 'updated' => 1, 'name' => 'x']],
        ];
    }

    private function withParentProfile(mixed $shape): array
    {
        $row = $this->listed();
        if ($shape === 'absent') {
            unset($row['parent_profile']);
        } else {
            $row['parent_profile'] = $shape;
        }

        return $row;
    }

    #[DataProvider('badParentProfiles')]
    public function test_b3_readback_without_the_global_profile_is_uncertain_and_never_retried(mixed $shape): void
    {
        $actor = $this->admin();
        $client = Client::factory()->create();
        $writer = $this->writer([$this->created(), $this->inventory($this->withParentProfile($shape))]);
        $id = $writer->stageOrganization($actor, $client->id, 'Synthetic Organization', 'synthetic@example.invalid', 1, 'synthetic-region');
        $writer->execute($actor, $id);
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame(['uncertain', 'readback', self::ORG, $client->id], [$intent->state, $intent->phase, $intent->vendor_pk, (int) $intent->active_client_id]);
        $this->assertNull($client->fresh()->controld_org_id);
        $this->refused(fn () => $writer->execute($actor, $id));
        $this->assertCount(2, $this->history, 'one POST, one read-back, no retry');
    }

    #[DataProvider('badParentProfiles')]
    public function test_b2_readback_without_the_global_profile_is_uncertain(mixed $shape): void
    {
        $actor = $this->admin();
        $client = Client::factory()->create();
        $writer = new ControlDOnboardingOrganization($this->transport([$this->created(), $this->inventory($this->withParentProfile($shape))]));
        $e = $this->refused(fn () => $writer->create($actor, $client->id, 'Synthetic Organization', 'synthetic@example.invalid', 1, 'synthetic-region'), ControlDOrganizationUncertainException::class);
        $this->assertSame([self::ORG, 'readback'], [$e->orgPk, $e->phase]);
        $this->assertNull($client->fresh()->controld_org_id);
        $this->assertCount(2, $this->history);
    }

    public function test_missing_global_profile_setting_refuses_before_any_intent_or_post(): void
    {
        $actor = $this->admin();
        $client = Client::factory()->create();
        Setting::setValue('controld_default_profile_id', '');
        $writer = $this->writer([$this->created(), $this->inventory($this->listed())]);
        $this->refused(fn () => $writer->stageOrganization($actor, $client->id, 'Synthetic Organization', 'synthetic@example.invalid', 1, 'synthetic-region'));
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $b2 = new ControlDOnboardingOrganization($this->transport([$this->created()]));
        $this->refused(fn () => $b2->create($actor, $client->id, 'Synthetic Organization', 'synthetic@example.invalid', 1, 'synthetic-region'));
        $this->assertCount(0, $this->history);

        // Cleared between stage and execute: refused before admit, intent stays staged.
        Setting::setValue('controld_default_profile_id', self::GLOBAL);
        $writer = $this->writer([$this->created(), $this->inventory($this->listed())]);
        $id = $writer->stageOrganization($actor, $client->id, 'Synthetic Organization', 'synthetic@example.invalid', 1, 'synthetic-region');
        Setting::setValue('controld_default_profile_id', '');
        $this->refused(fn () => $writer->execute($actor, $id));
        $this->assertSame(['staged', 'preflight'], [ControlDOnboardingIntent::findOrFail($id)->state, ControlDOnboardingIntent::findOrFail($id)->phase]);
        $this->assertCount(0, $this->history);
    }

    // ── item 3: code preflight arms ───────────────────────────────────────────

    private function provisionRow(string $profile): array
    {
        $row = json_decode(file_get_contents(base_path('tests/Fixtures/ControlD/provision.json')), true);
        $row['org'] = self::ORG;
        $row['profile_id'] = $profile;

        return $row;
    }

    private function fields(string $profile): array
    {
        return array_intersect_key($this->provisionRow($profile), array_flip(['icon', 'profile_id', 'max', 'ts_exp', 'stats', 'intercept_mode']));
    }

    private function types(): Response
    {
        return $this->ok(['types' => ['os' => ['icons' => ['desktop-windows' => ['name' => 'Windows']]]]]);
    }

    /** Arm (i): the global profile is NOT in the sub-org's own profiles (measured vendor behaviour) but is its parent_profile. */
    public function test_preflight_accepts_the_sub_organizations_global_profile_read_live(): void
    {
        $row = $this->provisionRow(self::GLOBAL);
        $service = new ControlDProvisioning($this->transport([
            $this->types(), $this->ok(['profiles' => [['PK' => '444444synthDD']]]),
            $this->inventory($this->listed('sibling01', '333333synthCC', 'Sibling'), $this->listed()),
            $this->ok(['provision' => $row]), $this->ok(['provisions' => [$row]]),
        ]));
        $this->assertSame('fixture001', $service->create(self::ORG, $this->fields(self::GLOBAL))['PK']);
        $this->assertSame(['GET /devices/types', 'GET /profiles', 'GET /organizations/sub_organizations', 'POST /provision', 'GET /provision'], $this->calls());
        $this->assertFalse($this->history[2]['request']->hasHeader('X-Force-Org-Id'), 'the global profile is read from the PARENT inventory');
    }

    /** Arm (ii) unchanged: exactly one own profile needs no parent read. */
    public function test_preflight_accepts_exactly_one_own_profile_without_the_parent_read(): void
    {
        $row = $this->provisionRow('444444synthDD');
        $service = new ControlDProvisioning($this->transport([
            $this->types(), $this->ok(['profiles' => [['PK' => '444444synthDD']]]),
            $this->ok(['provision' => $row]), $this->ok(['provisions' => [$row]]),
        ]));
        $this->assertSame('fixture001', $service->create(self::ORG, $this->fields('444444synthDD'))['PK']);
        $this->assertSame(['GET /devices/types', 'GET /profiles', 'POST /provision', 'GET /provision'], $this->calls());
    }

    public static function preflightRefusals(): array
    {
        return [
            'global-is-another-profile' => [['333333synthCC']],
            'global-absent' => [[null]],
            'org-not-listed' => [['unlisted']],
            'org-listed-twice' => [['twice']],
            'parent-profile-null' => [['null']],
            'inventory-malformed' => [['malformed']],
        ];
    }

    #[DataProvider('preflightRefusals')]
    public function test_preflight_refuses_without_writing_when_neither_arm_holds(array $case): void
    {
        $inventory = match ($case[0]) {
            'unlisted' => $this->inventory($this->listed('sibling01')),
            'twice' => $this->inventory($this->listed(), $this->listed()),
            'null' => $this->inventory(array_merge($this->listed(), ['parent_profile' => null])),
            'malformed' => $this->ok(['sub_organizations' => (object) []]),
            default => $this->inventory($this->listed(self::ORG, $case[0])),
        };
        $service = new ControlDProvisioning($this->transport([$this->types(), $this->ok(['profiles' => [['PK' => '444444synthDD']]]), $inventory]));
        $e = $this->refused(fn () => $service->create(self::ORG, $this->fields(self::GLOBAL)));
        $this->assertMatchesRegularExpression('/neither this organization\'s global profile|could not be confirmed/', $e->getMessage());
        $this->assertSame(['GET /devices/types', 'GET /profiles', 'GET /organizations/sub_organizations'], $this->calls(), 'no write');
    }

    // ── item 2: enforce-global-profile step ─────────────────────────────────────

    private function mapped(): Client
    {
        return Client::factory()->create(['controld_org_id' => self::ORG]);
    }

    private function putResponse(): Response
    {
        return $this->ok(['organization' => ['PK' => self::ORG]]);
    }

    public function test_enforce_step_reads_then_puts_under_the_sub_org_then_reads_back_and_releases_the_lock(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $writer = $this->writer([$this->inventory($this->listed(self::ORG, null)), $this->putResponse(), $this->inventory($this->listed())]);
        $id = $writer->stageGlobalProfile($actor, $client->id);
        $writer->execute($actor, $id);
        $this->assertSame(['GET /organizations/sub_organizations', 'PUT /organizations', 'GET /organizations/sub_organizations'], $this->calls());
        $put = $this->history[1]['request'];
        $this->assertSame(self::ORG, $put->getHeaderLine('X-Force-Org-Id'));
        parse_str((string) $put->getBody(), $sent);
        $this->assertSame(['parent_profile' => self::GLOBAL], $sent);
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame(['bound', null, self::ORG], [$intent->state, $intent->active_client_id, $intent->org_pk]);
        $this->assertSame(self::ORG, $client->fresh()->controld_org_id);
    }

    public function test_enforce_step_is_a_definite_no_op_refusal_when_already_enforced(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $writer = $this->writer([$this->inventory($this->listed())]);
        $id = $writer->stageGlobalProfile($actor, $client->id);
        $e = $this->refused(fn () => $writer->execute($actor, $id));
        $this->assertStringContainsString('already enforced', $e->getMessage());
        $this->assertSame(['GET /organizations/sub_organizations'], $this->calls());
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame([ControlDOnboardingStaged::RELEASED, null, null], [$intent->state, $intent->active_client_id, $intent->vendor_pk]);
    }

    public function test_enforce_step_pre_admit_read_failure_is_a_definite_refusal_and_stays_staged(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $writer = $this->writer([$this->inventory($this->listed('sibling01', null))]);
        $id = $writer->stageGlobalProfile($actor, $client->id);
        $this->refused(fn () => $writer->execute($actor, $id));
        $this->assertSame(['GET /organizations/sub_organizations'], $this->calls(), 'no PUT');
        $this->assertSame('staged', ControlDOnboardingIntent::findOrFail($id)->state);
    }

    public function test_enforce_step_vendor_envelope_rejection_is_rejected_and_releases_the_lock(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $writer = $this->writer([$this->inventory($this->listed(self::ORG, null)), new Response(400, [], '{"success":false,"error":{"code":40000,"message":"vendor text"}}')]);
        $id = $writer->stageGlobalProfile($actor, $client->id);
        $writer->execute($actor, $id);
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame(['rejected', 40000, null], [$intent->state, $intent->reason_code, $intent->active_client_id]);
        $this->assertStringNotContainsString('vendor text', json_encode($intent->toArray()));
        $this->assertCount(2, $this->history);
    }

    public static function uncertainAfterAdmit(): array
    {
        return [
            'put-503' => ['put-503', 'post'],
            'put-unconfirmed' => ['put-unconfirmed', 'post'],
            'readback-absent' => ['readback-absent', 'readback'],
            'readback-other' => ['readback-other', 'readback'],
            'readback-null' => ['readback-null', 'readback'],
            'readback-503' => ['readback-503', 'readback'],
        ];
    }

    #[DataProvider('uncertainAfterAdmit')]
    public function test_enforce_step_failure_after_admit_is_uncertain_terminal_and_never_retried(string $case, string $phase): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $responses = [$this->inventory($this->listed(self::ORG, null))];
        $responses[] = match ($case) {
            'put-503' => new Response(503),
            'put-unconfirmed' => new Response(200, [], json_encode(['success' => false])),
            default => $this->putResponse(),
        };
        $responses[] = match ($case) {
            'readback-absent' => $this->inventory($this->listed(self::ORG, null)),
            'readback-other' => $this->inventory($this->listed(self::ORG, '333333synthCC')),
            'readback-null' => $this->inventory(array_merge($this->listed(), ['parent_profile' => null])),
            'readback-503' => new Response(503),
            default => $this->inventory($this->listed()),
        };
        $writer = $this->writer($responses);
        $id = $writer->stageGlobalProfile($actor, $client->id);
        $writer->execute($actor, $id);
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame(['uncertain', $phase, $client->id], [$intent->state, $intent->phase, (int) $intent->active_client_id]);
        $puts = count(array_filter($this->calls(), fn ($c) => str_starts_with($c, 'PUT')));
        $this->assertSame(1, $puts, 'exactly one PUT');
        $before = count($this->history);
        $this->refused(fn () => $writer->execute($actor, $id));
        $this->assertCount($before, $this->history, 'an uncertain intent is never re-executed');
    }

    public function test_enforce_step_needs_a_mapped_client_and_the_setting(): void
    {
        $actor = $this->admin();
        $writer = $this->writer([]);
        $this->refused(fn () => $writer->stageGlobalProfile($actor, Client::factory()->create()->id));
        Setting::setValue('controld_default_profile_id', '');
        $this->refused(fn () => $writer->stageGlobalProfile($actor, $this->mapped()->id));
        $this->assertSame(0, ControlDOnboardingIntent::count());
        $this->assertCount(0, $this->history);
    }

    public function test_transport_accepts_put_organizations_only_with_a_body_and_classifies_its_envelope_rejection(): void
    {
        $transport = $this->transport([new Response(400, [], '{"success":false,"error":{"code":40000}}')]);
        $this->refused(fn () => $transport->requestForOrg('PUT', 'organizations', self::ORG));
        $this->refused(fn () => $transport->requestForOrg('GET', 'organizations', self::ORG));
        $this->refused(fn () => $transport->requestForOrg('POST', 'organizations', self::ORG, ['parent_profile' => self::GLOBAL]));
        $this->assertCount(0, $this->history);
        $this->refused(fn () => $transport->requestForOrg('PUT', 'organizations', self::ORG, ['parent_profile' => self::GLOBAL]), \App\Services\ControlD\ControlDWriteRejectedException::class);
        $this->assertSame('application/x-www-form-urlencoded', $this->history[0]['request']->getHeaderLine('Content-Type'));
    }

    // ── item 4: release of a never-admitted intent ──────────────────────────────

    private function stagedIntent(Client $client, User $actor): string
    {
        return $this->writer([])->stageGlobalProfile($actor, $client->id);
    }

    public function test_admin_releases_a_never_admitted_intent_with_an_audit_row_and_the_client_can_stage_again(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $id = $this->stagedIntent($client, $actor);
        $this->refused(fn () => $this->stagedIntent($client, $actor), ControlDClientException::class);
        $releaser = $this->admin();
        $this->writer([])->release($releaser, $id, 'never admitted; clearing the lock');
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame([ControlDOnboardingStaged::RELEASED, null], [$intent->state, $intent->active_client_id]);
        $this->assertStringContainsString('#'.$releaser->id, (string) $intent->reason);
        $log = TechnicianActionLog::where('action_type', 'controld_release_intent')->sole();
        $this->assertSame([$releaser->id, $client->id, 'executed'], [(int) $log->approver_user_id, (int) $log->client_id, $log->result_status]);
        $this->assertStringContainsString($id, $log->summary);
        $this->assertStringContainsString('never admitted; clearing the lock', $log->summary);
        $this->assertNotSame($id, $this->stagedIntent($client, $actor), 'the lock is free');
        $this->assertCount(0, $this->history, 'release makes no vendor call');
    }

    public static function unreleasable(): array
    {
        return [
            'posted' => [['state' => 'posted', 'phase' => 'post']],
            'uncertain' => [['state' => 'uncertain', 'phase' => 'readback']],
            'bound' => [['state' => 'bound', 'phase' => 'local-persistence', 'active_client_id' => null]],
            'rejected' => [['state' => 'rejected', 'phase' => 'post', 'active_client_id' => null]],
            'released' => [['state' => 'released', 'active_client_id' => null]],
            // State alone must refuse: phase preflight and no vendor PK, so only the state predicate can.
            'posted-at-preflight' => [['state' => 'posted', 'phase' => 'preflight']],
            'uncertain-at-preflight' => [['state' => 'uncertain', 'phase' => 'preflight']],
            'bound-at-preflight' => [['state' => 'bound', 'phase' => 'preflight']],
            'rejected-at-preflight' => [['state' => 'rejected', 'phase' => 'preflight']],
            'staged-past-preflight' => [['state' => 'staged', 'phase' => 'post']],
            'staged-with-vendor-pk' => [['state' => 'staged', 'vendor_pk' => self::ORG]],
        ];
    }

    #[DataProvider('unreleasable')]
    public function test_release_refuses_every_admitted_or_terminal_intent_and_changes_nothing(array $attributes): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $id = $this->stagedIntent($client, $actor);
        ControlDOnboardingIntent::whereKey($id)->update($attributes);
        $before = ControlDOnboardingIntent::findOrFail($id)->getAttributes();
        $e = $this->refused(fn () => $this->writer([])->release($this->admin(), $id, 'try'));
        $this->assertStringContainsString('never-admitted', $e->getMessage());
        $this->assertSame($before, ControlDOnboardingIntent::findOrFail($id)->getAttributes());
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'controld_release_intent')->count());
    }

    public function test_release_requires_an_active_admin_and_a_reason(): void
    {
        $actor = $this->admin();
        $id = $this->stagedIntent($this->mapped(), $actor);
        $this->refused(fn () => $this->writer([])->release(User::factory()->tech()->create(['is_active' => true]), $id, 'x'));
        $this->refused(fn () => $this->writer([])->release(User::factory()->admin()->create(['is_active' => false]), $id, 'x'));
        $this->refused(fn () => $this->writer([])->release($actor, $id, '   '));
        $this->assertSame('staged', ControlDOnboardingIntent::findOrFail($id)->state);
    }

    /** RACE: admit() commits first (vendor call in flight); a release attempted then must refuse and leave the posted row. */
    public function test_admit_then_release_never_releases_the_posted_intent(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $observed = null;
        $writer = $this->writer([
            $this->inventory($this->listed(self::ORG, null)),
            function () use (&$observed, &$id) {
                try {
                    $this->writer([])->release($this->admin(), $id, 'racing');
                    $observed = 'released';
                } catch (ControlDClientException) {
                    $observed = 'refused';
                }

                return new Response(503);
            },
        ]);
        $id = $writer->stageGlobalProfile($actor, $client->id);
        $writer->execute($actor, $id);
        $this->assertSame('refused', $observed);
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame(['uncertain', $client->id], [$intent->state, (int) $intent->active_client_id]);
    }

    /** RACE inside release: admit() commits between release's read and its write. Only the conditional UPDATE can see it. */
    public function test_release_whose_read_is_overtaken_by_admit_refuses_through_the_conditional_update(): void
    {
        $actor = $this->admin();
        $id = $this->stagedIntent($this->mapped(), $actor);
        ControlDOnboardingIntent::retrieved(function (ControlDOnboardingIntent $row) use ($id): void {
            if ($row->id === $id && $row->state === 'staged') {
                ControlDOnboardingIntent::whereKey($id)->update(['state' => 'posted', 'phase' => 'post']);
            }
        });
        try {
            $this->refused(fn () => $this->writer([])->release($this->admin(), $id, 'racing'));
        } finally {
            ControlDOnboardingIntent::flushEventListeners();
        }
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame(['posted', 'post'], [$intent->state, $intent->phase]);
        $this->assertNotNull($intent->active_client_id);
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'controld_release_intent')->count());
    }

    public function test_release_route_is_admin_only_scoped_to_the_client_and_audited(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $id = $this->stagedIntent($client, $actor);
        $other = Client::factory()->create();
        $tech = User::factory()->tech()->create(['is_active' => true]);
        $this->actingAs($tech)->post(route('clients.controld.intent.release', [$client, $id]), ['reason' => 'x'])->assertStatus(403);
        $this->actingAs($actor)->post(route('clients.controld.intent.release', [$other, $id]), ['reason' => 'x'])->assertNotFound();
        $this->actingAs($actor)->post(route('clients.controld.intent.release', [$client, $id]), [])->assertSessionHasErrors('reason');
        $this->assertSame('staged', ControlDOnboardingIntent::findOrFail($id)->state);
        $this->actingAs($actor)->post(route('clients.controld.intent.release', [$client, $id]), ['reason' => 'lock clear'])
            ->assertRedirect(route('clients.show', $client))->assertSessionHas('success');
        $this->assertSame(ControlDOnboardingStaged::RELEASED, ControlDOnboardingIntent::findOrFail($id)->state);
        $this->assertSame(1, TechnicianActionLog::where('action_type', 'controld_release_intent')->count());
        $this->actingAs($actor)->post(route('clients.controld.intent.release', [$client, $id]), ['reason' => 'again'])
            ->assertRedirect(route('clients.show', $client))->assertSessionHasErrors('controld_onboarding');
    }
}
