<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippClientException;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Mcp\StaffCippWriteToolExecutor;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Tenant-scoped licence target: cipp_assign_tenant_user_license (and its staged
 * twin), target_upn + sku_id, for a tenant user with NO PSA person record. Its
 * own verb pair with its own grant — the established person-keyed
 * cipp_assign_user_license keeps its original contract, restored by the split.
 *
 * The properties these tests exist to pin, in the order they matter:
 *
 *  1. AN EMPTY TENANT USER LISTING IS A REFUSAL, not "no such user". Those two
 *     are the same shape and opposite conclusions, and only one is safe to act
 *     on — a queue-backed or degraded read must never be able to answer "that
 *     address does not exist" and let a caller move on.
 *  2. The typed address never becomes an identity. The object id written
 *     upstream is the one the SERVER read out of the resolved tenant.
 *  3. sku_id is allowed on THIS family only. Every other tool still refuses it
 *     through UPSTREAM_IDENTIFIER_KEYS, and so does this one for every other
 *     blocklisted key.
 *  4. The client-entitlement gate is unchanged: a SKU with no active local
 *     licence row for this client is refused.
 */
class CippWriteLicenseTargetTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'acme.onmicrosoft.com';

    private const TARGET_UPN = 'contractor@acme.example';

    private const TARGET_OBJECT_ID = '7d1f0a2c-55aa-4bb1-9c33-77e1c0aa1234';

    private const SKU = 'sku-business-premium';

    private function configureCipp(): void
    {
        Setting::setValue('cipp_enabled', '1');
        Setting::setValue('cipp_api_url', 'https://cipp.example.test');
        Setting::setValue('cipp_tenant_id', 'tenant-1');
        Setting::setValue('cipp_client_id', 'client-1');
        Setting::setEncrypted('cipp_client_secret', 'secret');
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
    }

    private function token(array $tools): string
    {
        return McpConfig::rotateStaffToken(allowedTools: $tools, label: 'opsbot');
    }

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function decodedResult(TestResponse $response): array
    {
        return json_decode((string) $response->json('result.content.0.text'), true) ?? [];
    }

    /** @return array{client: Client, ticket: Ticket, licenseType: LicenseType} */
    private function fixture(): array
    {
        $client = Client::factory()->create([
            'name' => 'Acme',
            'cipp_tenant_domain' => self::TENANT,
        ]);

        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Alex',
            'last_name' => 'Acme',
            'email' => 'alex@acme.example',
            'cipp_user_id' => 'user-123',
            'cipp_upn' => 'alex@acme.example',
            'is_active' => true,
        ]);

        $ticket = Ticket::factory()->for($client)->create([
            'contact_id' => $person->id,
            'subject' => 'Licence for new contractor',
        ]);

        $licenseType = LicenseType::create([
            'name' => 'Business Premium',
            'vendor' => 'cipp_m365',
            'vendor_sku_id' => self::SKU,
            'is_active' => true,
        ]);

        License::create([
            'license_type_id' => $licenseType->id,
            'client_id' => $client->id,
            'quantity' => 10,
            'assigned_quantity' => 2,
            'vendor_ref' => 'sku-from-tenant-sync',
            'status' => 'active',
            'synced_at' => now(),
        ]);

        return compact('client', 'ticket', 'licenseType');
    }

    /**
     * One row of the tenant user listing as CIPP's ListUsers projects it.
     *
     * @return array<string, mixed>
     */
    private function userRow(array $overrides = []): array
    {
        return array_merge([
            'id' => self::TARGET_OBJECT_ID,
            'userPrincipalName' => self::TARGET_UPN,
            'displayName' => 'Sam Contractor',
            'accountEnabled' => true,
            'mail' => self::TARGET_UPN,
        ], $overrides);
    }

    public function test_tenant_target_assigns_the_license_to_the_server_derived_object_id(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)
            ->andReturn([$this->userRow(['userPrincipalName' => 'someone.else@acme.example', 'id' => 'other-id']), $this->userRow()]);
        // BOTH halves of the upstream call are server-derived, and this is the
        // assertion that proves it: the object id is the one listUsers()
        // returned, never the caller's typed address; and the SKU sent upstream
        // is the synced licence row's vendor_ref ('sku-from-tenant-sync'), NOT
        // the 'sku-business-premium' string the caller typed — that value only
        // ever selected a local licence type.
        $client->shouldReceive('assignUserLicense')->once()
            ->with(self::TENANT, self::TARGET_OBJECT_ID, 'sku-from-tenant-sync');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => strtoupper(self::TARGET_UPN),
            'sku_id' => self::SKU,
            'reason' => 'New contractor needs a seat.',
        ]);

        $result = $this->decodedResult($response);
        $this->assertTrue($result['success'] ?? false, (string) $response->json('result.content.0.text'));
        $this->assertSame(self::TARGET_UPN, $result['target_upn'] ?? null);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_assign_tenant_user_license',
            'client_id' => $f['client']->id,
            'result_status' => 'executed',
        ]);
    }

    public function test_an_empty_tenant_user_listing_refuses_instead_of_reporting_no_such_user(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        // THE FINDING THIS FAMILY EXISTS TO AVOID: an unread listing and a
        // tenant with no such user are the same shape. The refusal must name
        // the ambiguity, and nothing may reach upstream.
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([]);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'New contractor needs a seat.',
        ]);

        $body = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('came back empty', $body);
        $this->assertStringNotContainsString('No user with that target_upn', $body);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_an_address_absent_from_the_listing_is_refused_and_says_so_distinctly(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)
            ->andReturn([$this->userRow(['userPrincipalName' => 'someone.else@acme.example'])]);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'New contractor needs a seat.',
        ]);

        $body = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('No user with that target_upn', $body);
        // Distinct from the empty-listing refusal: different problem, different fix.
        $this->assertStringNotContainsString('came back empty', $body);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_a_sku_the_client_has_no_active_license_row_for_is_refused_before_any_read(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        // Entitlement gate unchanged by this family: the licence row is the
        // PSA's assertion that the client is billed for the seat.
        LicenseType::create([
            'name' => 'E5',
            'vendor' => 'cipp_m365',
            'vendor_sku_id' => 'sku-e5',
            'is_active' => true,
        ]);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listUsers');
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => 'sku-e5',
            'reason' => 'New contractor needs a seat.',
        ]);

        $this->assertArrayNotHasKey('success', $this->decodedResult($response));
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_an_unrecognised_sku_is_refused_and_nothing_upstream_is_called(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listUsers');
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => 'sku-that-does-not-exist',
            'reason' => 'New contractor needs a seat.',
        ]);

        $this->assertStringContainsString('sku_id', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_sku_id_is_allowed_only_alongside_target_upn_and_other_upstream_keys_still_refuse(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listUsers');
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        // The carve-out is for sku_id ALONE. Every other blocklisted key is
        // still refused on this family, before any upstream read.
        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'userPrincipalName' => 'attacker@evil.example',
            'reason' => 'New contractor needs a seat.',
        ]);

        $body = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('upstream CIPP identifiers are not accepted', $body);
        $this->assertStringContainsString('userPrincipalName', $body);
        // NEGATIVE CONTROL for the carve-out itself: the refusal must not name
        // sku_id, or the family could never run at all.
        $this->assertStringNotContainsString('sku_id.', $body);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_a_malformed_target_upn_is_refused_before_any_upstream_read(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listUsers');
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => 'not-an-address',
            'sku_id' => self::SKU,
            'reason' => 'New contractor needs a seat.',
        ]);

        $this->assertStringContainsString('target_upn must be', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_a_real_person_shape_value_alongside_target_upn_is_refused(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listUsers');
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        // The rail: two shapes name two different users, and routing on
        // target_upn alone would silently drop the person path's confirm_upn.
        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'person_id' => 12345,
            'reason' => 'New contractor needs a seat.',
        ]);

        $body = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('For a PSA-mapped person use cipp_assign_user_license', $body);
        $this->assertStringContainsString('person_id', $body);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_explicitly_null_person_shape_keys_do_not_refuse_a_tenant_shape_call(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        // The published schema is the MERGED property set of both shapes, so a
        // client that fills every declared property sends nulls for the shape it
        // is not using. That is a filled-in template, not a second target — and
        // keying the mixed-shape guard on array_key_exists() alone refused every
        // one of these. Found independently by all three verify seats.
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $client->shouldReceive('assignUserLicense')->once()
            ->with(self::TENANT, self::TARGET_OBJECT_ID, 'sku-from-tenant-sync');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'person_id' => null,
            'license_type_id' => null,
            'confirm_upn' => '',
            'reason' => 'New contractor needs a seat.',
        ]);

        $result = $this->decodedResult($response);
        $this->assertTrue($result['success'] ?? false, (string) $response->json('result.content.0.text'));
    }

    public function test_two_targets_on_one_ticket_stage_two_distinct_runs_and_neither_borrows_the_others_run_id(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_stage_assign_tenant_user_license']);

        // THE COLLISION THIS PINS: contentHash() strips every UPSTREAM_IDENTIFIER
        // key, and BOTH of this family's params are on that list — so without a
        // hashable projection of the target, two different users on one
        // client+ticket collapse onto one hash, collide on
        // firstOrCreate(['ticket_id','action_type','content_hash']), and the
        // SECOND caller is handed the FIRST user's run under idempotent: true.
        $second = $this->userRow(['userPrincipalName' => 'second@acme.example', 'id' => 'aaaa1111-2222-3333-4444-555566667777']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->twice()->with(self::TENANT)
            ->andReturn([$this->userRow(), $second]);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $one = $this->decodedResult($this->callTool($token, 'cipp_stage_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'ticket_id' => $f['ticket']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor one needs a seat.',
        ]));
        $two = $this->decodedResult($this->callTool($token, 'cipp_stage_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'ticket_id' => $f['ticket']->id,
            'target_upn' => 'second@acme.example',
            'sku_id' => self::SKU,
            'reason' => 'Contractor two needs a seat.',
        ]));

        $this->assertTrue($one['success'] ?? false);
        $this->assertTrue($two['success'] ?? false);
        $this->assertArrayNotHasKey('idempotent', $two, 'The second target was suppressed as a duplicate of the first.');
        $this->assertNotSame($one['run_id'] ?? null, $two['run_id'] ?? null, 'The second target borrowed the first user\'s run_id.');

        $runs = TechnicianRun::where('ticket_id', $f['ticket']->id)
            ->where('action_type', 'cipp_stage_assign_tenant_user_license')->get();
        $this->assertCount(2, $runs);
        $this->assertCount(2, $runs->pluck('content_hash')->unique(), 'Two distinct targets produced one content hash.');
    }

    /**
     * A RE-SYNCED SKU IS A DIFFERENT ENTITLEMENT, and the dedup key must see it.
     *
     * The caller's sku_id only ever SELECTS a local licence type; what gets
     * billed is the licence row's vendor_ref. A CIPP re-sync rewrites that
     * without the claim changing a byte, so a claim-keyed dedup hash suppressed
     * the second — genuinely different — assignment within DIRECT_DEDUP_HOURS
     * and answered success/idempotent while nothing reached upstream. A false
     * success on a billing write, recorded in the log as a duplicate rather
     * than as the missed write it was.
     */
    public function test_a_resynced_sku_is_not_suppressed_as_a_duplicate_of_the_caller_claim(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->twice()->with(self::TENANT)->andReturn([$this->userRow()]);
        $client->shouldReceive('assignUserLicense')->once()
            ->with(self::TENANT, self::TARGET_OBJECT_ID, 'sku-from-tenant-sync');
        $client->shouldReceive('assignUserLicense')->once()
            ->with(self::TENANT, self::TARGET_OBJECT_ID, 'sku-e5-guid');
        $this->app->instance(CippRestWriteClient::class, $client);

        $arguments = [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ];

        $first = $this->decodedResult($this->callTool($token, 'cipp_assign_tenant_user_license', $arguments));
        $this->assertTrue($first['success'] ?? false, (string) json_encode($first));

        // The re-sync: same claim, different seat.
        License::query()->where('client_id', $f['client']->id)->update(['vendor_ref' => 'sku-e5-guid']);

        $second = $this->decodedResult($this->callTool($token, 'cipp_assign_tenant_user_license', $arguments));
        $this->assertTrue($second['success'] ?? false, (string) json_encode($second));
        $this->assertArrayNotHasKey(
            'idempotent',
            $second,
            'The re-synced SKU was suppressed as a duplicate of the caller\'s claim and never assigned.',
        );

        $this->assertSame(2, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_two_targets_immediate_both_reach_the_upstream_call(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        // The direct twin of the collision: the exact-content rail must not
        // answer "already executed" for a DIFFERENT human.
        $second = $this->userRow(['userPrincipalName' => 'second@acme.example', 'id' => 'aaaa1111-2222-3333-4444-555566667777']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->twice()->with(self::TENANT)->andReturn([$this->userRow(), $second]);
        $client->shouldReceive('assignUserLicense')->once()->with(self::TENANT, self::TARGET_OBJECT_ID, 'sku-from-tenant-sync');
        $client->shouldReceive('assignUserLicense')->once()->with(self::TENANT, 'aaaa1111-2222-3333-4444-555566667777', 'sku-from-tenant-sync');
        $this->app->instance(CippRestWriteClient::class, $client);

        foreach ([self::TARGET_UPN, 'second@acme.example'] as $upn) {
            $result = $this->decodedResult($this->callTool($token, 'cipp_assign_tenant_user_license', [
                'client_id' => $f['client']->id,
                'target_upn' => $upn,
                'sku_id' => self::SKU,
                'reason' => 'Contractor needs a seat.',
            ]));
            $this->assertTrue($result['success'] ?? false);
            $this->assertArrayNotHasKey('idempotent', $result, "{$upn} was suppressed as a duplicate of the other target.");
        }

        $this->assertSame(2, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_approval_declines_when_the_address_now_resolves_to_a_different_object_id(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_stage_assign_tenant_user_license']);
        $approver = User::factory()->create(['name' => 'Approver']);

        $client = Mockery::mock(CippRestWriteClient::class);
        // Staged against one object id...
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $this->app->instance(CippRestWriteClient::class, $client);

        $staged = $this->decodedResult($this->callTool($token, 'cipp_stage_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'ticket_id' => $f['ticket']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]));
        $this->assertTrue($staged['success'] ?? false);

        // ...and the address now points at a DIFFERENT user. The operator
        // approved one person; a UPN can be reassigned.
        $drifted = Mockery::mock(CippRestWriteClient::class);
        $drifted->shouldReceive('listUsers')->once()->with(self::TENANT)
            ->andReturn([$this->userRow(['id' => 'bbbb2222-3333-4444-5555-666677778888'])]);
        $drifted->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $drifted);

        $run = TechnicianRun::findOrFail($staged['run_id']);
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
        // A drift decline is the most interesting thing that can happen on this
        // path — the target moved between the operator reading the card and
        // approving it — so it must not be the one refusal leaving no trace.
        $this->assertStringContainsString(
            'user drift at approval',
            (string) TechnicianActionLog::where('result_status', 'rejected')->latest('id')->value('summary'),
        );
    }

    /**
     * The approver signed off on ONE SKU, and only a licence rail can see it.
     *
     * Approval re-resolves the licence fresh from an unordered first() over the
     * client's active rows, so a vendor_ref re-sync (or a second active row for
     * the same licence type) between staging and approval sends a different —
     * here costlier — SKU upstream than the card named. The user object is
     * unchanged, so the user drift rail passes and notices nothing.
     */
    public function test_approval_declines_when_the_licence_now_maps_to_a_different_sku(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_stage_assign_tenant_user_license']);
        $approver = User::factory()->create(['name' => 'Approver']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $this->app->instance(CippRestWriteClient::class, $client);

        $staged = $this->decodedResult($this->callTool($token, 'cipp_stage_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'ticket_id' => $f['ticket']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]));
        $this->assertTrue($staged['success'] ?? false);

        // The card named 'sku-from-tenant-sync'; the row now resolves to E5.
        License::query()->where('client_id', $f['client']->id)->update(['vendor_ref' => 'sku-e5-guid']);

        $drifted = Mockery::mock(CippRestWriteClient::class);
        $drifted->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $drifted->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $drifted);

        $run = TechnicianRun::findOrFail($staged['run_id']);
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $approver->id);

        $this->assertSame('gate_declined', $result->status, 'A costlier SKU than the approved one was executed.');
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
        // This one carries money: a silent decline means nobody can later tell
        // that a SKU re-sync changed what would have been billed.
        $this->assertStringContainsString(
            'licence drift at approval',
            (string) TechnicianActionLog::where('result_status', 'rejected')->latest('id')->value('summary'),
        );
    }

    /**
     * A DEDUP MUST NOT CLOSE AN APPROVAL A DRIFT RAIL WOULD HAVE DECLINED.
     *
     * The already-executed key is built from the FRESHLY RESOLVED SKU, so a
     * vendor_ref re-sync between staging and approval points it at an
     * entitlement the operator never approved. With the dedup ahead of the
     * licence rail, an executed row for that (user, NEW SKU) pair closed the run
     * as 'already_handled' — Done, so not even re-approvable, and audited as an
     * identical user/SKU already executed — while the approved SKU was never
     * assigned and the drift decline the card promises never fired.
     */
    public function test_an_executed_row_for_a_drifted_sku_does_not_close_the_approval_as_already_handled(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_stage_assign_tenant_user_license', 'cipp_assign_tenant_user_license']);
        $approver = User::factory()->create(['name' => 'Approver']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->twice()->with(self::TENANT)->andReturn([$this->userRow()]);
        // The earlier write, against the SKU the licence row points at AFTER the
        // re-sync — the seat the staged run was never approved for.
        $client->shouldReceive('assignUserLicense')->once()
            ->with(self::TENANT, self::TARGET_OBJECT_ID, 'sku-e5-guid');
        $this->app->instance(CippRestWriteClient::class, $client);

        $staged = $this->decodedResult($this->callTool($token, 'cipp_stage_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'ticket_id' => $f['ticket']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]));
        $this->assertTrue($staged['success'] ?? false);

        // The re-sync, then an executed assignment for the SAME user on the NEW SKU.
        License::query()->where('client_id', $f['client']->id)->update(['vendor_ref' => 'sku-e5-guid']);

        $direct = $this->decodedResult($this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs the new seat now.',
        ]));
        $this->assertTrue($direct['success'] ?? false, (string) json_encode($direct));

        $drifted = Mockery::mock(CippRestWriteClient::class);
        $drifted->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $drifted->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $drifted);

        $run = TechnicianRun::findOrFail($staged['run_id']);
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $approver->id);

        $this->assertSame('gate_declined', $result->status, 'The approval was closed as a duplicate of a SKU the operator never approved.');
        // The DECLINE, not the termination: the operator is told the SKU moved
        // and can re-stage, and the log does not claim their SKU already executed.
        $this->assertStringContainsString(
            'licence drift at approval',
            (string) TechnicianActionLog::where('result_status', 'rejected')->latest('id')->value('summary'),
        );
        $this->assertSame(1, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_an_account_disabled_between_staging_and_approval_declines_rather_than_spending_a_seat(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_stage_assign_tenant_user_license']);
        $approver = User::factory()->create(['name' => 'Approver']);

        // psa-pgnj shape: enabled at staging, disabled by approval time. A
        // licence on a disabled account is a paid seat spent on someone who left.
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $this->app->instance(CippRestWriteClient::class, $client);

        $staged = $this->decodedResult($this->callTool($token, 'cipp_stage_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'ticket_id' => $f['ticket']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]));
        $this->assertTrue($staged['success'] ?? false);

        $disabled = Mockery::mock(CippRestWriteClient::class);
        $disabled->shouldReceive('listUsers')->once()->with(self::TENANT)
            ->andReturn([$this->userRow(['accountEnabled' => false])]);
        $disabled->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $disabled);

        $run = TechnicianRun::findOrFail($staged['run_id']);
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
        // An account disabled BETWEEN staging and approval is the exact event the
        // re-gate exists to surface. A silent decline is indistinguishable in
        // TechnicianActionLog from the approval never having been attempted.
        $this->assertStringContainsString(
            'tenant user re-verification refused the approval',
            (string) TechnicianActionLog::where('result_status', 'rejected')->latest('id')->value('summary'),
        );
    }

    public function test_a_disabled_account_is_refused_on_the_immediate_path_too(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)
            ->andReturn([$this->userRow(['accountEnabled' => false])]);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]);

        $this->assertStringContainsString('disabled', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    /**
     * Unable-to-assess is a refusal, and the refusal names its own cause.
     *
     * If CIPP stops projecting accountEnabled, the alternative is assigning a
     * paid seat while unable to tell whether the account is live — a quiet
     * wrong outcome that reads exactly like a correct one. This fails loudly
     * instead, and says which field went missing so the outage diagnoses
     * itself rather than looking like the tool broke.
     */
    public function test_an_absent_account_enabled_field_refuses_and_names_the_missing_field(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        $row = $this->userRow();
        unset($row['accountEnabled']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$row]);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]);

        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('accountEnabled', $text);
        $this->assertStringNotContainsString('"success":true', $text);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_the_person_tool_refuses_target_upn_by_presence_the_restored_strict_contract(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_user_license']);

        // THE RESTORED CONTRACT, pinned. In the merged-schema era the person
        // path needed a value-test carve-out because its own published template
        // carried target_upn/sku_id slots. The split takes those keys out of
        // cipp_assign_user_license's schema entirely, so the tool goes back to
        // the strict PRESENCE test every other tool runs: a blocklisted key
        // appearing at all — even as an explicit null — is the signal, exactly
        // as it was before the tenant family existed. A caller aiming a
        // tenant-shape call at the person tool hits this wall by name.
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listUsers');
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_user_license', [
            'client_id' => $f['client']->id,
            'person_id' => 999999,
            'license_type_id' => $f['licenseType']->id,
            'confirm_upn' => 'alex@acme.example',
            'target_upn' => null,
            'sku_id' => null,
            'reason' => 'Mapped person needs a seat.',
        ]);

        $body = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('upstream CIPP identifiers are not accepted', $body);
        // Refused by the strict blocklist, never by the tenant family's guards:
        // the split means this call no longer has a tenant family to wander into.
        $this->assertStringNotContainsString('Person not found', $body);
        $this->assertStringNotContainsString('tenant user with no PSA person record', $body);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    /**
     * THE MIRROR OF THE MIXED-SHAPE RAIL, on the person tool's side of the
     * split: a real SKU on the person shape must refuse, never be silently
     * dropped while license_type_id decides the seat — the operator believes
     * they named E5 and the log says Business Basic, on a billing write. With
     * the strict presence test restored the refusal comes from the global
     * blocklist, and it must fire BEFORE the person gate.
     */
    public function test_a_real_sku_id_on_the_person_tool_is_refused_before_the_person_gate(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listUsers');
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_user_license', [
            'client_id' => $f['client']->id,
            'person_id' => 999999,
            'license_type_id' => $f['licenseType']->id,
            'confirm_upn' => 'alex@acme.example',
            'sku_id' => 'ENTERPRISEPREMIUM',
            'reason' => 'Mapped person needs a seat.',
        ]);

        $body = (string) $response->json('result.content.0.text');
        // Refused FOR THE SKU, and refused before the person gate: a value the
        // person path cannot honour must never reach a path that discards it.
        $this->assertStringContainsString('upstream CIPP identifiers are not accepted', $body);
        $this->assertStringNotContainsString('Person not found', $body);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
        $this->assertTrue(TechnicianActionLog::where('result_status', 'rejected')->exists(), 'The refusal left no audit row.');
    }

    /**
     * A PascalCase tenant row resolves and is gated on its real value.
     *
     * This reads the RAW CIPP listing, not a CippToolContract projection, so
     * the contract's alias table never runs on it — and CIPP demonstrably sends
     * PascalCase for this object, which is why CippContactSyncService::syncUser()
     * has always hedged both casings. Reading one casing would turn a casing
     * flip into "no such user": a safe refusal wearing a wrong cause, and one
     * that would send someone hunting upstream for a user that is right there.
     */
    public function test_a_pascal_case_tenant_row_is_resolved_and_gated_on_its_real_value(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([[
            'Id' => self::TARGET_OBJECT_ID,
            'UserPrincipalName' => self::TARGET_UPN,
            'DisplayName' => 'Sam Contractor',
            'AccountEnabled' => false,
        ]]);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $text = (string) $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ])->json('result.content.0.text');

        // Gated as DISABLED — the row was found and read. Falling through to
        // "no user with that target_upn exists" would be the casing bug.
        $this->assertStringContainsString('disabled', $text);
        $this->assertStringNotContainsString('No user with that target_upn', $text);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    /**
     * The SUCCESS half of the PascalCase row — the half nothing exercised.
     *
     * The disabled-row test above exits at the enabled gate, so the return
     * statement was never reached on a PascalCase row. Reading one casing THERE
     * is not a safe refusal: the row has already been matched, resolved and
     * gated, so it is an undefined-key read on a row the module has admitted is
     * valid — and if it survives, the result, the audit line and the billing
     * approval card all name nobody while the seat is spent.
     */
    public function test_an_enabled_pascal_case_row_is_named_on_the_result_and_the_audit(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([[
            'Id' => self::TARGET_OBJECT_ID,
            'UserPrincipalName' => self::TARGET_UPN,
            'DisplayName' => 'Sam Contractor',
            'AccountEnabled' => true,
        ]]);
        $client->shouldReceive('assignUserLicense')->once()
            ->with(self::TENANT, self::TARGET_OBJECT_ID, 'sku-from-tenant-sync');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]);

        $result = $this->decodedResult($response);
        $this->assertTrue($result['success'] ?? false, (string) $response->json('result.content.0.text'));
        $this->assertSame(self::TARGET_UPN, $result['target_upn'] ?? null, 'The verified UPN was read from one casing only.');

        $log = TechnicianActionLog::where('result_status', 'executed')->firstOrFail();
        $this->assertStringContainsString(self::TARGET_UPN, (string) $log->summary, 'The audit line for a billing write named nobody.');
    }

    public function test_a_held_payload_whose_family_marker_is_wrong_declines_and_leaves_a_row(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_stage_assign_tenant_user_license']);
        $approver = User::factory()->create(['name' => 'Approver']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $this->app->instance(CippRestWriteClient::class, $client);

        $staged = $this->decodedResult($this->callTool($token, 'cipp_stage_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'ticket_id' => $f['ticket']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]));
        $this->assertTrue($staged['success'] ?? false);

        // The family marker is what stops a licence-target run being approved
        // through the person-keyed tail. A payload that fails it is exactly the
        // approval you would want to find in the log afterwards — and it was one
        // of four early exits on this method still leaving no row. Found by
        // enumerating every refusal in the family rather than waiting for the
        // next panel to name the next one.
        $run = TechnicianRun::findOrFail($staged['run_id']);
        $meta = $run->proposed_meta;
        $meta['encrypted_payload'] = Crypt::encryptString(json_encode([
            'direct_tool' => 'cipp_assign_tenant_user_license',
            'family' => 'not_license_target',
            'client_id' => $f['client']->id,
            'person_id' => null,
            'ticket_id' => $f['ticket']->id,
            'params' => [],
        ], JSON_THROW_ON_ERROR));
        $run->update(['proposed_meta' => $meta]);

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('listUsers');
        $blocked->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run->fresh(), $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
        $this->assertStringContainsString(
            'does not match this action type or family',
            (string) TechnicianActionLog::where('result_status', 'rejected')->latest('id')->value('summary'),
        );
    }

    public function test_a_corrupt_held_payload_declines_with_a_row_instead_of_escaping(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_stage_assign_tenant_user_license']);
        $approver = User::factory()->create(['name' => 'Approver']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $this->app->instance(CippRestWriteClient::class, $client);

        $staged = $this->decodedResult($this->callTool($token, 'cipp_stage_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'ticket_id' => $f['ticket']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]));
        $this->assertTrue($staged['success'] ?? false);

        // Crypt::decryptString() throws DecryptException on a tampered or
        // key-rotated payload. That is not a CippWriteScopeException, so it used
        // to fall past every audited refusal to the outer \Throwable arm and
        // rethrow: no row, and a framework error instead of an operator-readable
        // decline. The one refusal that most wants a row was the one that could
        // not leave one.
        $run = TechnicianRun::findOrFail($staged['run_id']);
        $meta = $run->proposed_meta;
        $meta['encrypted_payload'] = 'not-a-valid-ciphertext';
        $run->update(['proposed_meta' => $meta]);

        $blocked = Mockery::mock(CippRestWriteClient::class);
        $blocked->shouldNotReceive('listUsers');
        $blocked->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $blocked);

        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run->fresh(), $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
        $this->assertStringContainsString(
            'could not be decrypted',
            (string) TechnicianActionLog::where('result_status', 'rejected')->latest('id')->value('summary'),
        );
    }

    public function test_other_write_tools_still_refuse_a_blocklisted_key_that_is_merely_present(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_set_group_membership']);

        // SCOPE, not spelling. The licence family value-tests because its
        // published schema is the merged set of two shapes; every OTHER tool has
        // a narrow schema and no reason to carry a blocklisted key at all, so
        // presence alone is the tripwire. An earlier rework widened the GLOBAL
        // helper to fix the licence family's local problem — this pins that the
        // global one is strict again.
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listGroups');
        $client->shouldNotReceive('setGroupMembership');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_set_group_membership', [
            'client_id' => $f['client']->id,
            'person_id' => 1,
            'group_id' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            'operation' => 'add',
            'confirm_group_name' => 'Sales',
            'confirm_upn' => 'alex@acme.example',
            'userId' => null,
            'reason' => 'Adding to sales group.',
        ]);

        $this->assertStringContainsString(
            'upstream CIPP identifiers are not accepted',
            (string) $response->json('result.content.0.text'),
        );
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_a_whitespace_only_mapping_column_does_not_deadlock_both_shapes(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        // A person whose cipp_user_id holds ONLY A TAB is half-mapped as far as
        // resolveCippPerson() is concerned — PHP trim() reads it blank, so the
        // person shape refuses for want of a mapping. If the completeness gate
        // here asks the same question in SQL (TRIM strips the space character
        // only, on sqlite/MySQL/Postgres alike) it reads COMPLETE, refuses the
        // tenant shape too, and the seat becomes unassignable by every route.
        // One question asked in two dialects.
        Person::create([
            'client_id' => $f['client']->id,
            'person_type' => PersonType::User,
            'first_name' => 'Half',
            'last_name' => 'Mapped',
            'email' => self::TARGET_UPN,
            'cipp_user_id' => "\t",
            'cipp_upn' => self::TARGET_UPN,
            'is_active' => true,
        ]);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $client->shouldReceive('assignUserLicense')->once()
            ->with(self::TENANT, self::TARGET_OBJECT_ID, 'sku-from-tenant-sync');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]);

        $this->assertTrue(
            $this->decodedResult($response)['success'] ?? false,
            (string) $response->json('result.content.0.text'),
        );
    }

    public function test_a_fully_mapped_person_still_refuses_the_tenant_shape(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        // NEGATIVE CONTROL for the above: the completeness qualifier must not
        // become a way to bypass the person-keyed rails. A person mapped on BOTH
        // columns still sends the caller to person_id + confirm_upn.
        Person::create([
            'client_id' => $f['client']->id,
            'person_type' => PersonType::User,
            'first_name' => 'Fully',
            'last_name' => 'Mapped',
            'email' => self::TARGET_UPN,
            'cipp_user_id' => self::TARGET_OBJECT_ID,
            'cipp_upn' => self::TARGET_UPN,
            'is_active' => true,
        ]);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->zeroOrMoreTimes()->with(self::TENANT)->andReturn([$this->userRow()]);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]);

        $this->assertStringContainsString('mapped to PSA person', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    /**
     * A PADDED mapping column STILL refuses — the gate cannot be stricter than
     * the resolver it defers to, and it must not be looser either.
     *
     * The sync stores cipp_upn/cipp_user_id raw and resolveCippPerson() reads
     * both through PHP trim(), so "contractor@acme.example\n" is a COMPLETE,
     * USABLE mapping: that person is assignable on the person-keyed shape. A
     * gate that matched the column untrimmed in SQL finds nothing here and lets
     * that fully mapped person onto the tenant shape with confirm_upn, every
     * person-scoped gate, and the audit's person linkage all skipped — the same
     * bypass the mapped-person refusal exists to close, reopened by asking the
     * MATCH in a different dialect from the completeness test beside it.
     */
    public function test_a_padded_upn_alone_still_refuses_the_tenant_shape(): void
    {
        // ONE ARM PER TEST. The first cut padded BOTH columns at once, so it
        // could not fail if only one arm's trim() regressed — a vacuous pass
        // wearing a green tick. Each arm now carries its own case.
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        // BOTH arms padded: the address arm by a trailing newline, and the
        // object-id arm — the value the SERVER read out of the tenant — by a
        // leading space. Either one alone would have to refuse.
        Person::create([
            'client_id' => $f['client']->id,
            'person_type' => PersonType::User,
            'first_name' => 'Padded',
            'last_name' => 'Mapped',
            'email' => self::TARGET_UPN,
            'cipp_user_id' => self::TARGET_OBJECT_ID,
            'cipp_upn' => self::TARGET_UPN."\n",
            'is_active' => true,
        ]);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->zeroOrMoreTimes()->with(self::TENANT)->andReturn([$this->userRow()]);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]);

        $this->assertStringContainsString('mapped to PSA person', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_a_padded_user_id_alone_still_refuses_the_tenant_shape(): void
    {
        // ONE ARM PER TEST. The first cut padded BOTH columns at once, so it
        // could not fail if only one arm's trim() regressed — a vacuous pass
        // wearing a green tick. Each arm now carries its own case.
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        // BOTH arms padded: the address arm by a trailing newline, and the
        // object-id arm — the value the SERVER read out of the tenant — by a
        // leading space. Either one alone would have to refuse.
        Person::create([
            'client_id' => $f['client']->id,
            'person_type' => PersonType::User,
            'first_name' => 'Padded',
            'last_name' => 'Mapped',
            'email' => self::TARGET_UPN,
            'cipp_user_id' => ' '.self::TARGET_OBJECT_ID,
            'cipp_upn' => self::TARGET_UPN,
            'is_active' => true,
        ]);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->zeroOrMoreTimes()->with(self::TENANT)->andReturn([$this->userRow()]);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]);

        $this->assertStringContainsString('mapped to PSA person', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_a_sku_stored_with_trailing_whitespace_still_resolves(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        // The licence sync stores vendor_sku_id RAW, exactly as it stores the
        // person mapping columns raw. Matched with SQL equality against a
        // trimmed claim, a stored value carrying a trailing newline never
        // matches and the operator is told no licence type exists for a SKU
        // that plainly does. Same root as the person gate, opposite failure
        // direction: a false refusal rather than a bypass.
        $f['licenseType']->update(['vendor_sku_id' => self::SKU."\n"]);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $client->shouldReceive('assignUserLicense')->once()
            ->with(self::TENANT, self::TARGET_OBJECT_ID, 'sku-from-tenant-sync');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]);

        $this->assertTrue(
            $this->decodedResult($response)['success'] ?? false,
            (string) $response->json('result.content.0.text'),
        );
    }

    public function test_a_mixed_case_sku_claim_still_resolves(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        // NEGATIVE CONTROL on the normalisation itself: moving the decision from
        // SQL LOWER() into PHP would silently make the match case-sensitive if
        // the claim were not lowered at the single point it is normalised.
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $client->shouldReceive('assignUserLicense')->once()
            ->with(self::TENANT, self::TARGET_OBJECT_ID, 'sku-from-tenant-sync');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => strtoupper(self::SKU),
            'reason' => 'Contractor needs a seat.',
        ]);

        $this->assertTrue(
            $this->decodedResult($response)['success'] ?? false,
            (string) $response->json('result.content.0.text'),
        );
    }

    public function test_the_person_keyed_shape_is_untouched_by_this_family(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        // NEGATIVE CONTROL for the dispatch: with no target_upn the call must
        // still land on the person-keyed path and be judged by ITS gates —
        // here, the missing person_id — not by anything this family added.
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listUsers');
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'license_type_id' => $f['licenseType']->id,
            'reason' => 'New contractor needs a seat.',
        ]);

        $body = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('person_id', $body);
        $this->assertStringNotContainsString('target_upn must be', $body);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    /**
     * THE PREMISE OF THE SHAPE, ENFORCED.
     *
     * The tool text and the approval card both assert the target is NOT mapped
     * to a PSA person, and nothing checked it — so naming a mapped person's
     * ADDRESS instead of their person_id skipped confirm_upn, skipped every
     * person-scoped gate, and wrote person_id null into the audit, on an
     * immediate billing write. The tenant read alone cannot catch this: a
     * mapped person is a perfectly real tenant user.
     */
    public function test_a_target_upn_mapped_to_a_psa_person_is_refused(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        // Alex IS mapped (fixture: cipp_upn alex@acme.example) and is present
        // and enabled in the tenant listing — every other gate on this path
        // passes.
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)
            ->andReturn([$this->userRow(['userPrincipalName' => 'alex@acme.example'])]);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => 'alex@acme.example',
            'sku_id' => self::SKU,
            'reason' => 'Mapped person needs a seat.',
        ]);

        $body = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('mapped to PSA person', $body);
        // The refusal must point at the shape that CAN express this target.
        $this->assertStringContainsString('person_id', $body);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
        $this->assertStringContainsString(
            'mapped to PSA person',
            (string) TechnicianActionLog::where('result_status', 'rejected')->latest('id')->value('summary'),
        );
    }

    /**
     * The object-id arm of the same gate: a UPN can be renamed upstream while
     * the PSA still holds the mapping on cipp_user_id, so checking only the
     * address would leave the bypass open to anyone who typed the new one.
     */
    public function test_a_target_whose_object_id_is_mapped_to_a_psa_person_is_refused(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        Person::create([
            'client_id' => $f['client']->id,
            'person_type' => PersonType::User,
            'first_name' => 'Sam',
            'last_name' => 'Renamed',
            'email' => 'renamed@acme.example',
            'cipp_user_id' => self::TARGET_OBJECT_ID,
            'cipp_upn' => 'renamed@acme.example',
            'is_active' => true,
        ]);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]);

        $this->assertStringContainsString('mapped to PSA person', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    /**
     * A HALF-MAPPED person must not make the target unassignable by BOTH shapes.
     *
     * resolveCippPerson() requires cipp_user_id AND cipp_upn, and the sync
     * really produces rows carrying only one: CippContactSyncService::syncUser()
     * sets cipp_user_id unconditionally while an array_filter drops cipp_upn
     * when the tenant row has no userPrincipalName. Refusing the tenant shape
     * for such a person closes the only path that can express the target — the
     * refusal would redirect to person_id + license_type_id + confirm_upn,
     * which answers 'Person has no CIPP user mapping'. The gate refuses a
     * mapping the person path can USE, not the mere presence of a column.
     */
    public function test_a_half_mapped_person_does_not_close_the_tenant_shape_as_well(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        Person::create([
            'client_id' => $f['client']->id,
            'person_type' => PersonType::User,
            'first_name' => 'Sam',
            'last_name' => 'Halfmapped',
            'email' => 'half@acme.example',
            'cipp_user_id' => self::TARGET_OBJECT_ID,
            'cipp_upn' => null,
            'is_active' => true,
        ]);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $client->shouldReceive('assignUserLicense')->once()
            ->with(self::TENANT, self::TARGET_OBJECT_ID, 'sku-from-tenant-sync');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]);

        $result = $this->decodedResult($response);
        $this->assertTrue($result['success'] ?? false, (string) $response->json('result.content.0.text'));
        $this->assertStringNotContainsString('mapped to PSA person', (string) $response->json('result.content.0.text'));
    }

    /**
     * AMBIGUITY REFUSES on the direct path too.
     *
     * The CIPP sync upserts on (license_type_id, client_id, vendor_ref), so a
     * vendor_ref re-sync leaves a second ACTIVE row for the same client and
     * licence type. An unordered first() over those rows means two identical
     * calls for two different contractors can bill different SKUs — Business
     * Premium for one, E5 for the other — both reported as success, with no
     * approver in the loop and a dedup key that hashes the caller's claim
     * rather than the resolved SKU.
     */
    public function test_two_active_licence_rows_for_one_sku_refuse_instead_of_billing_an_arbitrary_one(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        License::create([
            'license_type_id' => $f['licenseType']->id,
            'client_id' => $f['client']->id,
            'quantity' => 10,
            'assigned_quantity' => 2,
            'vendor_ref' => 'sku-e5-guid',
            'status' => 'active',
            'synced_at' => now(),
        ]);

        // Refused during resolution, before the tenant is even read.
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listUsers');
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]);

        $body = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('more than one active local license row', $body);
        $this->assertArrayNotHasKey('success', $this->decodedResult($response));
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    /**
     * THE APPROVER'S SCREEN IS NOT THE AUDIT LOG — r8 diff:5, and it landed with
     * nothing in this file able to fail on it.
     *
     * The approval catch used to return $e->getMessage() straight to the
     * approver while auditing the SAME failure through safeFailureSummary().
     * declined() redacts what it is given, but the redactor's business is
     * credentials — auth-scheme tokens, AWS key ids, 32+ character runs — and a
     * CIPP relay error body carries the request URL, so the TENANT FILTER rides
     * through every one of those patterns untouched. Be exact about what the fix
     * buys: not redaction, which was already applied, but SURFACE. The detail
     * belongs in the immutable row, which is access-controlled; the approver
     * gets the sentence they can act on.
     *
     * Written by me AFTER the pipeline's rework seat fixed the defect, because a
     * sanitization no test can fail on is not a fixed defect — it is the same
     * defect with a comment attached.
     */
    public function test_an_upstream_failure_at_approval_does_not_put_relay_text_on_the_approvers_screen(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_stage_assign_tenant_user_license']);
        $approver = User::factory()->create(['name' => 'Approver']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $this->app->instance(CippRestWriteClient::class, $client);

        $staged = $this->decodedResult($this->callTool($token, 'cipp_stage_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'ticket_id' => $f['ticket']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]));
        $this->assertTrue($staged['success'] ?? false);

        // A realistic relay error body: the request line survives the credential
        // redactor intact, which is the whole reason this arm matters.
        $relayError = 'POST https://cipp.internal/api/ExecAddLicense?tenantfilter='.self::TENANT
            .' returned 500 — Graph said: subscription for sku-from-tenant-sync is exhausted';

        $failing = Mockery::mock(CippRestWriteClient::class);
        $failing->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $failing->shouldReceive('assignUserLicense')->once()->andThrow(new CippClientException($relayError));
        $this->app->instance(CippRestWriteClient::class, $failing);

        $run = TechnicianRun::findOrFail($staged['run_id']);
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertStringNotContainsString(self::TENANT, (string) $result->message);
        $this->assertStringNotContainsString('cipp.internal', (string) $result->message);
        $this->assertStringContainsString('treat the licence assignment as not applied', (string) $result->message);

        // And the cause is NOT lost — it moved, it did not vanish. A sanitizer
        // that also blinds the audit trail trades one defect for a worse one.
        $summary = (string) TechnicianActionLog::where('result_status', 'error')->latest('id')->value('summary');
        $this->assertStringContainsString('failed before completion', $summary);
        $this->assertStringContainsString('ExecAddLicense', $summary);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    /**
     * The OTHER half of the same question, pinned separately — because "what
     * does an upstream failure tell the caller" having two answers in one file
     * is the root defect of this whole branch, in its sixth costume.
     *
     * The direct path was already sanitized; nothing asserted it. Without this
     * arm the two paths can drift apart again and only the approval half would
     * fail.
     */
    public function test_an_upstream_failure_on_the_immediate_path_returns_the_same_sanitized_sentence(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        $relayError = 'POST https://cipp.internal/api/ExecAddLicense?tenantfilter='.self::TENANT.' returned 500';

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $client->shouldReceive('assignUserLicense')->once()->andThrow(new CippClientException($relayError));
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]);

        $body = (string) $response->json('result.content.0.text');
        $this->assertStringNotContainsString(self::TENANT, $body);
        $this->assertStringNotContainsString('cipp.internal', $body);
        $this->assertStringContainsString('treat the licence assignment as not applied', $body);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
        $this->assertStringContainsString(
            'ExecAddLicense',
            (string) TechnicianActionLog::where('result_status', 'error')->latest('id')->value('summary'),
        );
    }

    /**
     * HAND PASS, 2026-08-14. The schema no longer enforces this; the executor
     * must, and now something can fail if it stops.
     *
     * assignLicenseTool()'s `required` went from
     * ['person_id','license_type_id','confirm_upn','reason'] to ['reason'] —
     * the price of merging two target shapes into one tool, since a required
     * list can only express the INTERSECTION of the shapes it serves. r9's
     * contract:2 called that a possible bypass of the typed-confirmation rail
     * and three seats discarded it by READING confirmUpnError(). They were
     * right — I measured it rather than trusting the read, and a person-shape
     * call with confirm_upn entirely absent is refused with "The typed
     * confirm_upn does not match the resolved CIPP user. CIPP write cancelled."
     * and zero executed rows.
     *
     * The residue they named is real though: nothing PINNED it. A rail enforced
     * only in code, with its schema declaration removed and no test, is one
     * refactor away from silently becoming optional — and the whole point of a
     * typed confirmation is that it cannot be skipped by an agent that simply
     * omits the field.
     */
    public function test_a_person_shape_call_with_no_confirm_upn_at_all_is_still_refused(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_user_license']);

        $person = Person::create([
            'client_id' => $f['client']->id,
            'person_type' => PersonType::User,
            'first_name' => 'Fully',
            'last_name' => 'Mapped',
            'email' => self::TARGET_UPN,
            'cipp_user_id' => self::TARGET_OBJECT_ID,
            'cipp_upn' => self::TARGET_UPN,
            'is_active' => true,
        ]);

        // Nothing upstream may be reached: the rail is BEFORE the write, and a
        // permissive mock proves the refusal is the executor's and not a
        // missing expectation.
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldIgnoreMissing();
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_user_license', [
            'client_id' => $f['client']->id,
            'person_id' => $person->id,
            'license_type_id' => $f['licenseType']->id,
            'reason' => 'No confirm_upn sent at all.',
        ]);

        $body = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('confirm_upn', $body);
        $this->assertStringContainsString('CIPP write cancelled', $body);
        $this->assertArrayNotHasKey('success', $this->decodedResult($response));
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    /**
     * THE OTHER HALF OF THE IDENTITY KEY — r10 context:1, and the cycle-1
     * rework that fixed it shipped no test at all (the panel said so; c1:v1:2).
     *
     * r9 moved the SKU half of this key off the caller's claim and onto the
     * resolved value, and argued at length in the docblock about why hashing a
     * claim is a false-success bug. The USER half was left on the claim anyway,
     * in the same function, under that argument. A UPN is reassignable: delete
     * the contractor, create a new starter at the same address, and the typed
     * string is byte-identical while the object behind it is a different human.
     * Keyed on the claim, the second assignment collides with the first inside
     * DIRECT_DEDUP_HOURS, returns success/idempotent, and the new person never
     * gets the seat. On the staged path the already-executed check closed the
     * run before verifiedTenantUser() and the user-drift rail could see it —
     * the same before-the-rail-that-exists-for-it ordering the SKU half had.
     *
     * The key is now the SERVER-VERIFIED object id, which is why the rails had
     * to move behind the verification read. That reordering is the expensive
     * part of the fix and the reason this test exists: nothing else can fail if
     * the key drifts back onto the address.
     */
    public function test_a_reassigned_upn_is_not_suppressed_as_a_duplicate_of_the_previous_object(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_tenant_user_license']);

        // Object A holds the address and is licensed.
        $first = Mockery::mock(CippRestWriteClient::class);
        $first->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([$this->userRow()]);
        $first->shouldReceive('assignUserLicense')->once()->with(self::TENANT, self::TARGET_OBJECT_ID, Mockery::any());
        $this->app->instance(CippRestWriteClient::class, $first);

        $a = $this->decodedResult($this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'Contractor needs a seat.',
        ]));
        $this->assertTrue($a['success'] ?? false, (string) json_encode($a));

        // Same address, DIFFERENT object: the leaver is gone and a new starter
        // was created at the same UPN. Byte-identical claim, different human.
        $newObjectId = 'cccc3333-4444-5555-6666-777788889999';
        $second = Mockery::mock(CippRestWriteClient::class);
        $second->shouldReceive('listUsers')->once()->with(self::TENANT)
            ->andReturn([$this->userRow(['id' => $newObjectId])]);
        $second->shouldReceive('assignUserLicense')->once()->with(self::TENANT, $newObjectId, Mockery::any());
        $this->app->instance(CippRestWriteClient::class, $second);

        $b = $this->decodedResult($this->callTool($token, 'cipp_assign_tenant_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => self::TARGET_UPN,
            'sku_id' => self::SKU,
            'reason' => 'New starter at the same address needs a seat.',
        ]));

        $this->assertTrue($b['success'] ?? false, (string) json_encode($b));
        $this->assertArrayNotHasKey('idempotent', $b, 'The new starter was suppressed as a duplicate of the leaver.');
        // Two DIFFERENT people were licensed, so there are two executed rows.
        $this->assertSame(2, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    /**
     * THE SPLIT ITSELF, pinned from the advertised surface. The tenant target
     * is its own verb with its own required list, so it arrives in nobody's
     * allowed_tools until it is granted — and the person tool goes back to its
     * original contract: person_id + license_type_id + confirm_upn + reason
     * all required, and no tenant-shape keys in its schema at all.
     */
    public function test_the_tenant_verb_is_its_own_grant_and_the_person_contract_is_restored(): void
    {
        $this->configureCipp();

        // A legacy full-surface token (no allowlist) must NOT gain the new verb.
        $legacyNames = array_column($this->listTools(McpConfig::rotateStaffToken()), 'name');
        $this->assertNotContains('cipp_assign_tenant_user_license', $legacyNames);
        $this->assertNotContains('cipp_stage_assign_tenant_user_license', $legacyNames);

        $tools = collect($this->listTools($this->token([
            'cipp_assign_user_license',
            'cipp_assign_tenant_user_license',
        ])))->keyBy('name');

        $person = $tools['cipp_assign_user_license'];
        foreach (['person_id', 'license_type_id', 'confirm_upn', 'reason'] as $key) {
            $this->assertContains($key, $person['inputSchema']['required'], "person tool must require {$key} again");
        }
        $this->assertArrayNotHasKey('target_upn', $person['inputSchema']['properties']);
        $this->assertArrayNotHasKey('sku_id', $person['inputSchema']['properties']);

        $tenant = $tools['cipp_assign_tenant_user_license'];
        foreach (['target_upn', 'sku_id', 'reason'] as $key) {
            $this->assertContains($key, $tenant['inputSchema']['required'], "tenant verb must require {$key}");
        }
        $this->assertArrayNotHasKey('person_id', $tenant['inputSchema']['properties']);
        $this->assertArrayNotHasKey('license_type_id', $tenant['inputSchema']['properties']);
        $this->assertArrayNotHasKey('confirm_upn', $tenant['inputSchema']['properties']);
        // Unified surface: the staged twin rides the canonical verb as
        // staged=true rather than being advertised as its own alias.
        $this->assertFalse($tools->has('cipp_stage_assign_tenant_user_license'));
        $this->assertArrayHasKey('staged', $tenant['inputSchema']['properties']);
    }

    /**
     * #405, direct path: a freed M365 address stays on the departed person's
     * DEACTIVATED row (the stale sweep never clears cipp_upn/cipp_user_id),
     * and confirm_upn compares against that same stored column — so the
     * friction rail passes while the write lands on the old occupant's object
     * id. The licence grant now requires an ACTIVE person.
     */
    public function test_an_inactive_person_refuses_a_licence_assignment_on_the_direct_path(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_user_license']);

        $leaver = Person::create([
            'client_id' => $f['client']->id,
            'person_type' => PersonType::User,
            'first_name' => 'Lee',
            'last_name' => 'Aver',
            'email' => 'lee@acme.example',
            'cipp_user_id' => 'leaver-object-id',
            'cipp_upn' => 'lee@acme.example',
            'is_active' => false,
        ]);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        // confirm_upn matches the STORED column exactly — the friction rail
        // passes, which is precisely why it cannot be the gate here.
        $response = $this->callTool($token, 'cipp_assign_user_license', [
            'client_id' => $f['client']->id,
            'person_id' => $leaver->id,
            'license_type_id' => $f['licenseType']->id,
            'confirm_upn' => 'lee@acme.example',
            'reason' => 'New starter at this address needs a seat.',
        ]);

        $body = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('inactive in the PSA', $body);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    /**
     * #405, staged arm: the person may be offboarded — and their address
     * reassigned — between staging and approval. Approval re-resolves fresh
     * and must decline, mirroring the group-membership 'add' re-gate.
     */
    public function test_a_person_deactivated_between_staging_and_approval_declines_the_licence_grant(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_stage_assign_user_license']);
        $approver = User::factory()->create(['name' => 'Approver']);

        $person = Person::where('email', 'alex@acme.example')->firstOrFail();

        $staged = $this->decodedResult($this->callTool($token, 'cipp_stage_assign_user_license', [
            'client_id' => $f['client']->id,
            'ticket_id' => $f['ticket']->id,
            'person_id' => $person->id,
            'license_type_id' => $f['licenseType']->id,
            'confirm_upn' => 'alex@acme.example',
            'reason' => 'Alex needs a seat.',
        ]));
        $this->assertTrue($staged['success'] ?? false, (string) json_encode($staged));

        $person->update(['is_active' => false]);

        $upstream = Mockery::mock(CippRestWriteClient::class);
        $upstream->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $upstream);

        $run = TechnicianRun::findOrFail($staged['run_id']);
        $result = app(StaffCippWriteToolExecutor::class)->approveStagedRun($run, $approver->id);

        $this->assertSame('gate_declined', $result->status);
        $this->assertStringContainsString('inactive in the PSA', (string) $result->message);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    /** @return array<int, array<string, mixed>> */
    private function listTools(string $token): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->json('result.tools') ?? [];
    }
}
