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

    /** Arm (i): the global profile is NOT in the sub-org's own profiles but is its parent_profile (the shape a production read on card XULQ2iix reported; these fixtures are synthetic, built from the producer schema). */
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
        $neither = 'Enforced profile is neither this organization\'s global profile nor exactly one of its own profiles.';
        $unconfirmed = 'Control D global profile of this organization could not be confirmed.';

        return [
            'global-is-another-profile' => [['333333synthCC'], $neither],
            'global-absent' => [[null], $neither],
            'org-not-listed' => [['unlisted'], $unconfirmed],
            'org-listed-twice' => [['twice'], $unconfirmed],
            'parent-profile-null' => [['null'], $unconfirmed],
            'inventory-malformed' => [['malformed'], $unconfirmed],
            'inventory-503' => [['503'], $unconfirmed],
        ];
    }

    #[DataProvider('preflightRefusals')]
    public function test_preflight_refuses_without_writing_when_neither_arm_holds(array $case, string $message): void
    {
        $inventory = match ($case[0]) {
            '503' => new Response(503),
            'unlisted' => $this->inventory($this->listed('sibling01')),
            'twice' => $this->inventory($this->listed(), $this->listed()),
            'null' => $this->inventory(array_merge($this->listed(), ['parent_profile' => null])),
            'malformed' => $this->ok(['sub_organizations' => (object) []]),
            default => $this->inventory($this->listed(self::ORG, $case[0])),
        };
        $service = new ControlDProvisioning($this->transport([$this->types(), $this->ok(['profiles' => [['PK' => '444444synthDD']]]), $inventory]));
        $e = $this->refused(fn () => $service->create(self::ORG, $this->fields(self::GLOBAL)));
        $this->assertSame($message, $e->getMessage(), 'each arm pins its own message');
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
        $id = $writer->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL);
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
        $id = $writer->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL);
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
        $id = $writer->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL);
        $this->refused(fn () => $writer->execute($actor, $id));
        $this->assertSame(['GET /organizations/sub_organizations'], $this->calls(), 'no PUT');
        $this->assertSame('staged', ControlDOnboardingIntent::findOrFail($id)->state);
    }

    public function test_enforce_step_vendor_envelope_rejection_is_rejected_and_releases_the_lock(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $writer = $this->writer([$this->inventory($this->listed(self::ORG, null)), new Response(400, [], '{"success":false,"error":{"code":40000,"message":"vendor text"}}')]);
        $id = $writer->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL);
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
        $id = $writer->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL);
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
        $this->refused(fn () => $writer->stageGlobalProfile($actor, Client::factory()->create()->id, self::ORG, self::GLOBAL));
        Setting::setValue('controld_default_profile_id', '');
        $this->refused(fn () => $writer->stageGlobalProfile($actor, $this->mapped()->id, self::ORG, self::GLOBAL));
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
        return $this->writer([])->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL);
    }

    public function test_admin_releases_a_never_admitted_intent_with_an_audit_row_and_the_client_can_stage_again(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $id = $this->stagedIntent($client, $actor);
        $this->refused(fn () => $this->stagedIntent($client, $actor), ControlDClientException::class);
        $releaser = $this->admin();
        $this->writer([])->release($releaser, $client->id, $id, 'never admitted; clearing the lock');
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
        $e = $this->refused(fn () => $this->writer([])->release($this->admin(), $client->id, $id, 'try'));
        $this->assertSame(ControlDOnboardingStaged::RELEASE_REFUSAL, $e->getMessage());
        $this->assertSame($before, ControlDOnboardingIntent::findOrFail($id)->getAttributes());
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'controld_release_intent')->count());
    }

    public function test_release_requires_an_active_admin_and_a_reason(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $id = $this->stagedIntent($client, $actor);
        $this->refused(fn () => $this->writer([])->release(User::factory()->tech()->create(['is_active' => true]), $client->id, $id, 'x'));
        $this->refused(fn () => $this->writer([])->release(User::factory()->admin()->create(['is_active' => false]), $client->id, $id, 'x'));
        $this->refused(fn () => $this->writer([])->release($actor, $client->id, $id, '   '));
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
            function () use (&$observed, &$id, $client) {
                try {
                    $this->writer([])->release($this->admin(), $client->id, $id, 'racing');
                    $observed = 'released';
                } catch (ControlDClientException $e) {
                    $observed = $e->getMessage();
                }

                return new Response(503);
            },
        ]);
        $id = $writer->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL);
        $writer->execute($actor, $id);
        $this->assertSame(ControlDOnboardingStaged::RELEASE_REFUSAL, $observed, 'refused by the guarded UPDATE');
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame(['uncertain', $client->id], [$intent->state, (int) $intent->active_client_id]);
    }

    /** RACE inside release: admit() commits immediately before release's guarded UPDATE. Only the conditional UPDATE can see it. */
    public function test_release_whose_read_is_overtaken_by_admit_refuses_through_the_conditional_update(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $id = $this->stagedIntent($client, $actor);
        $fired = false;
        \Illuminate\Support\Facades\DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($id, $client, &$fired): void {
            if (! $fired && str_starts_with(strtolower(ltrim($sql)), 'update') && str_contains($sql, 'controld_onboarding_intents') && in_array(ControlDOnboardingStaged::RELEASED, $bindings, true)) {
                $fired = true;
                // Exactly what admit() commits: state staged -> posted, phase -> post, lock kept.
                \Illuminate\Support\Facades\DB::table('controld_onboarding_intents')->where('id', $id)->where('state', 'staged')
                    ->where('active_client_id', $client->id)->update(['state' => 'posted', 'phase' => 'post']);
            }
        });
        $e = $this->refused(fn () => $this->writer([])->release($this->admin(), $client->id, $id, 'racing'));
        $this->assertTrue($fired, 'the admit was injected immediately before the guarded UPDATE');
        $this->assertSame(ControlDOnboardingStaged::RELEASE_REFUSAL, $e->getMessage());
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
        $this->onboardingActive();
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

    /** The release route's availability gate (C-47): master switch, API key, the six defaults and the onboarding toggle (synthetic values). */
    private function onboardingActive(): void
    {
        foreach (['controld_tactical_client_field_id' => '18', 'controld_code_expiry_days' => '7', 'controld_code_device_limit_headroom' => '2',
            'controld_code_analytics_level' => '0', 'controld_code_intercept_mode' => 'standard'] as $key => $value) {
            Setting::setValue($key, $value);
        }
        Setting::setValue(\App\Support\ControlDConfig::ONBOARDING_ENABLED_SETTING, '1');
    }

    public static function releaseGateOff(): array
    {
        return [
            'onboarding-toggle-off' => ['toggle'],
            'integration-disabled' => ['disabled'],
            'api-key-missing' => ['key'],
            'defaults-incomplete' => ['defaults'],
        ];
    }

    /** diff:3 (OFF=OFF): with any one gate off, the release route is a 404 and changes nothing; the route test above is the positive control. */
    #[DataProvider('releaseGateOff')]
    public function test_release_route_is_inert_404_unless_onboarding_is_active(string $case): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $id = $this->stagedIntent($client, $actor);
        $this->onboardingActive();
        match ($case) {
            'toggle' => Setting::setValue(\App\Support\ControlDConfig::ONBOARDING_ENABLED_SETTING, '0'),
            'disabled' => Setting::setValue('controld_enabled', '0'),
            'key' => Setting::where('key', 'controld_api_key')->delete(),
            'defaults' => Setting::setValue('controld_code_expiry_days', ''),
        };
        $this->actingAs($actor)->post(route('clients.controld.intent.release', [$client, $id]), ['reason' => 'lock clear'])->assertNotFound();
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame(['staged', 'preflight', $client->id], [$intent->state, $intent->phase, (int) $intent->active_client_id]);
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'controld_release_intent')->count());
        $this->assertCount(0, $this->history, 'no vendor call');
    }

    // ── r2 (Jeeves 22:4xZ must-carry) ───────────────────────────────────────────

    private const SECRET_FIXTURE = 'r2-synthetic-secret-not-real';

    /** Item 1: a DIFFERENT parent_profile is never replaced: definite pre-admit refusal, no PUT, intent stays staged. */
    public function test_enforce_step_refuses_a_different_global_profile_before_admit_without_a_write(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $writer = $this->writer([$this->inventory($this->listed(self::ORG, '333333synthCC'))]);
        $id = $writer->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL);
        $e = $this->refused(fn () => $writer->execute($actor, $id));
        $this->assertSame(ControlDOnboardingStaged::DIFFERENT_PROFILE_REFUSAL, $e->getMessage());
        $this->assertStringNotContainsString('333333synthCC', $e->getMessage(), 'no PK in the operator text');
        $this->assertSame(['GET /organizations/sub_organizations'], $this->calls(), 'no PUT');
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame(['staged', 'preflight', null], [$intent->state, $intent->phase, $intent->vendor_pk]);
    }

    /** Item 1: the step state reader tells absent, enforced and different apart (nextStep decides on it). */
    public function test_global_profile_state_distinguishes_absent_enforced_and_different(): void
    {
        $this->assertSame(ControlDOnboardingStaged::PROFILE_ABSENT, $this->writer([$this->inventory($this->listed(self::ORG, null))])->globalProfileState(self::ORG));
        $this->assertSame(ControlDOnboardingStaged::PROFILE_ENFORCED, $this->writer([$this->inventory($this->listed())])->globalProfileState(self::ORG));
        $this->assertSame(ControlDOnboardingStaged::PROFILE_DIFFERENT, $this->writer([$this->inventory($this->listed(self::ORG, '333333synthCC'))])->globalProfileState(self::ORG));
    }

    public static function pinDrift(): array
    {
        return [
            'org-remapped-after-staging' => ['org'],
            'setting-changed-after-staging' => ['setting'],
            'payload-org-not-the-client' => ['payload-org'],
            'payload-profile-not-the-setting' => ['payload-profile'],
        ];
    }

    /** Item 2: execute() refuses before any vendor call when the live org or setting no longer equals the pins. */
    #[DataProvider('pinDrift')]
    public function test_enforce_step_refuses_pin_drift_before_any_vendor_call(string $case): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $writer = $this->writer([$this->inventory($this->listed(self::ORG, null)), $this->putResponse(), $this->inventory($this->listed())]);
        if ($case === 'payload-org') {
            $this->refused(fn () => $writer->stageGlobalProfile($actor, $client->id, '555555synthEE', self::GLOBAL));
            $this->assertSame(0, ControlDOnboardingIntent::count());
            $this->assertCount(0, $this->history);

            return;
        }
        if ($case === 'payload-profile') {
            $this->refused(fn () => $writer->stageGlobalProfile($actor, $client->id, self::ORG, '333333synthCC'));
            $this->assertSame(0, ControlDOnboardingIntent::count());
            $this->assertCount(0, $this->history);

            return;
        }
        $id = $writer->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL);
        if ($case === 'org') {
            $client->forceFill(['controld_org_id' => '555555synthEE'])->save();
        } else {
            Setting::setValue('controld_default_profile_id', '333333synthCC');
        }
        $e = $this->refused(fn () => $writer->execute($actor, $id));
        $this->assertSame('The Control D organization or the configured global profile no longer matches what was approved; nothing was written.', $e->getMessage());
        $this->assertCount(0, $this->history, 'no vendor call at all');
        $this->assertSame('staged', ControlDOnboardingIntent::findOrFail($id)->state);
    }

    /** Item 2: the PUT carries the PINNED profile under the PINNED org (both equal the live values by construction). */
    public function test_enforce_step_puts_the_pinned_profile_under_the_pinned_org(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $writer = $this->writer([$this->inventory($this->listed(self::ORG, null)), $this->putResponse(), $this->inventory($this->listed())]);
        $id = $writer->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL);
        $payload = ControlDOnboardingIntent::findOrFail($id)->payload;
        $this->assertSame(['org_pk' => self::ORG, 'profile_pk' => self::GLOBAL], $payload);
        $writer->execute($actor, $id);
        $put = $this->history[1]['request'];
        $this->assertSame(self::ORG, $put->getHeaderLine('X-Force-Org-Id'));
        parse_str((string) $put->getBody(), $sent);
        $this->assertSame(['parent_profile' => self::GLOBAL], $sent);
    }

    public static function bindRecheckFailures(): array
    {
        return [
            'mapping-changed-during-put' => ['remap'],
            'actor-demoted-during-put' => ['demote'],
            'client-deleted-during-put' => ['delete'],
        ];
    }

    /** Item 3: posted->bound goes through bind(): a failed re-check after the PUT is uncertain and terminal, never bound. */
    #[DataProvider('bindRecheckFailures')]
    public function test_enforce_step_bind_recheck_failure_after_the_put_is_uncertain_and_terminal(string $case): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $writer = $this->writer([
            $this->inventory($this->listed(self::ORG, null)),
            function () use ($case, $client, $actor) {
                match ($case) {
                    'remap' => Client::whereKey($client->id)->update(['controld_org_id' => '555555synthEE']),
                    'demote' => User::whereKey($actor->id)->update(['is_active' => false]),
                    'delete' => Client::whereKey($client->id)->delete(),
                };

                return $this->putResponse();
            },
            $this->inventory($this->listed()),
        ]);
        $id = $writer->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL);
        $writer->execute($actor, $id);
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame(['uncertain', 'local-persistence', $client->id], [$intent->state, $intent->phase, (int) $intent->active_client_id]);
        $this->assertSame(['GET /organizations/sub_organizations', 'PUT /organizations', 'GET /organizations/sub_organizations'], $this->calls());
    }

    /** Item 4: the already-enforced no-op uses release()'s predicate, writes a system audit row, and reports truthfully. */
    public function test_already_enforced_no_op_release_is_audited_as_a_system_no_op(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $writer = $this->writer([$this->inventory($this->listed())]);
        $id = $writer->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL);
        $e = $this->refused(fn () => $writer->execute($actor, $id));
        $this->assertSame('The Control D global profile is already enforced on this organization; no vendor write was made and the intent was released.', $e->getMessage());
        $log = TechnicianActionLog::where('action_type', 'controld_release_intent')->sole();
        $this->assertSame(['system:controld-global-profile-noop', $client->id, 'executed'], [$log->actor_label, (int) $log->client_id, $log->result_status]);
        $this->assertStringContainsString('no vendor write was made', $log->summary);
        $this->assertStringNotContainsString(self::GLOBAL, $log->summary, 'no PK or vendor text in the audit row');
    }

    /** Item 4: row count 0 (the intent no longer holds THIS client's lock) => not released, no audit, and it says so. */
    public function test_already_enforced_no_op_with_zero_rows_does_not_claim_a_release(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $writer = $this->writer([
            function () use (&$id) {
                // The lock moved between execute()'s staged check and the no-op release.
                ControlDOnboardingIntent::whereKey($id)->update(['active_client_id' => null]);

                return $this->inventory($this->listed());
            },
        ]);
        $id = $writer->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL);
        $e = $this->refused(fn () => $writer->execute($actor, $id));
        $this->assertStringContainsString('was not released', $e->getMessage());
        $this->assertSame('staged', ControlDOnboardingIntent::findOrFail($id)->state);
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'controld_release_intent')->count());
    }

    /** Item 5: an own inventory listing the PK twice is ambiguous and refused, even when it is also the parent_profile. */
    public function test_preflight_refuses_a_duplicated_own_profile_even_when_it_is_the_global_profile(): void
    {
        $service = new ControlDProvisioning($this->transport([
            $this->types(), $this->ok(['profiles' => [['PK' => self::GLOBAL], ['PK' => self::GLOBAL]]]),
            $this->inventory($this->listed()),
        ]));
        $e = $this->refused(fn () => $service->create(self::ORG, $this->fields(self::GLOBAL)));
        $this->assertSame('Enforced profile is listed more than once in this organization\'s own profiles (ambiguous).', $e->getMessage());
        $this->assertSame(['GET /devices/types', 'GET /profiles'], $this->calls(), 'arm (i) is not consulted; no write');
    }

    /** Item 6: the service-level release is scoped to the client on its own (no controller check in front). */
    public function test_service_release_refuses_another_clients_intent(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $id = $this->stagedIntent($client, $actor);
        $other = Client::factory()->create();
        $e = $this->refused(fn () => $this->writer([])->release($this->admin(), $other->id, $id, 'cross-client'));
        $this->assertSame(ControlDOnboardingStaged::RELEASE_REFUSAL, $e->getMessage());
        $this->assertSame(['staged', $client->id], [ControlDOnboardingIntent::findOrFail($id)->state, (int) ControlDOnboardingIntent::findOrFail($id)->active_client_id]);
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'controld_release_intent')->count());
    }

    /** Item 7: the release reason passes through ActionRedactor before it reaches the audit row. */
    public function test_release_reason_is_redacted_in_the_audit_row(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        $id = $this->stagedIntent($client, $actor);
        $this->writer([])->release($this->admin(), $client->id, $id, 'mailbox imaps://ops:'.self::SECRET_FIXTURE.'@mail.example.test/ password: '.self::SECRET_FIXTURE.' for ops@example.test');
        $log = TechnicianActionLog::where('action_type', 'controld_release_intent')->sole();
        $this->assertStringNotContainsString(self::SECRET_FIXTURE, $log->summary);
        $this->assertStringContainsString('[REDACTED:credential]', $log->summary);
        $this->assertStringContainsString('ops@example.test', $log->summary, 'the non-secret text survives');
        $this->assertStringNotContainsString(self::SECRET_FIXTURE, (string) ControlDOnboardingIntent::findOrFail($id)->reason);
    }

    // ── follow-up (Jeeves 2026-10-08 17:51 PT item 3; #6204 residuals) ──────────

    /** #6231 (diff:6, m6b): client_id = A, active_client_id = B. Releasing as B must refuse on the client_id predicate alone. */
    public function test_service_release_refuses_a_row_whose_lock_names_the_caller_but_whose_client_does_not(): void
    {
        $actor = $this->admin();
        $owner = $this->mapped();
        $caller = Client::factory()->create();
        $id = $this->stagedIntent($owner, $actor);
        // No writer produces this row today (every writer sets active_client_id to the
        // row's own client_id or NULL); the schema does not forbid it, so it is built here.
        ControlDOnboardingIntent::whereKey($id)->update(['active_client_id' => $caller->id]);
        $e = $this->refused(fn () => $this->writer([])->release($this->admin(), $caller->id, $id, 'cross-client lock'));
        $this->assertSame(ControlDOnboardingStaged::RELEASE_REFUSAL, $e->getMessage());
        $intent = ControlDOnboardingIntent::findOrFail($id);
        $this->assertSame(['staged', $caller->id], [$intent->state, (int) $intent->active_client_id], 'nothing changed');
        $this->assertSame(0, TechnicianActionLog::where('action_type', 'controld_release_intent')->count());
        // And as the owner: the lock is not the owner's, so the active_client_id predicate refuses too.
        $this->refused(fn () => $this->writer([])->release($this->admin(), $owner->id, $id, 'not the lock holder'));
        $this->assertSame('staged', ControlDOnboardingIntent::findOrFail($id)->state);
        $this->assertCount(0, $this->history, 'no vendor call');
    }

    /** #6236 (context:2): a non-unique failure of the staging INSERT says no vendor WRITE was made (a read-only GET may precede it at approval). */
    public function test_staging_insert_failure_says_no_vendor_write_was_made(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        ControlDOnboardingIntent::creating(function (): void {
            throw new \RuntimeException('synthetic insert failure');
        });
        try {
            $e = $this->refused(fn () => $this->writer([])->stageGlobalProfile($actor, $client->id, self::ORG, self::GLOBAL));
        } finally {
            ControlDOnboardingIntent::flushEventListeners();
        }
        $this->assertSame('Control D intent could not be durably staged; no vendor write was made.', $e->getMessage());
        $this->assertSame(0, ControlDOnboardingIntent::count());
    }

    /** #6228 (diff:2): the different-profile refusal states what happened, not a 'never replaces' guarantee. */
    public function test_different_profile_refusal_text_is_exact(): void
    {
        $this->assertSame('This Control D sub-organization already enforces a different global profile, so onboarding refused and nothing was written to Control D. Change it in Control D, or change the configured default, before onboarding continues.', ControlDOnboardingStaged::DIFFERENT_PROFILE_REFUSAL);
        $this->assertStringNotContainsString('never replaces', ControlDOnboardingStaged::DIFFERENT_PROFILE_REFUSAL);
    }

    /** #6229 (diff:4): the release flash claims only what release() did: the lock is free, no Control D call. Not 'can be staged again'. */
    public function test_release_success_flash_claims_only_the_freed_lock(): void
    {
        $actor = $this->admin();
        $client = $this->mapped();
        // The onboarded arm: a client with a code can still have a leftover never-admitted intent; staging it again would refuse.
        $client->forceFill(['controld_provisioning_code' => 'synthetic-code'])->save();
        $intent = new ControlDOnboardingIntent;
        $intent->forceFill(['id' => (string) \Illuminate\Support\Str::uuid(), 'client_id' => $client->id, 'actor_id' => $actor->id,
            'active_client_id' => $client->id, 'operation' => 'code', 'state' => 'staged', 'phase' => 'preflight', 'payload' => []])->save();
        $this->onboardingActive();
        $this->actingAs($actor)->post(route('clients.controld.intent.release', [$client, $intent->id]), ['reason' => 'leftover'])
            ->assertRedirect(route('clients.show', $client))
            ->assertSessionHas('success', "Control D onboarding intent {$intent->id} released; the onboarding lock it held is free and no Control D call was made.");
        $this->assertSame(ControlDOnboardingStaged::RELEASED, $intent->fresh()->state);
        $this->assertCount(0, $this->history, 'no vendor call');
    }
}
