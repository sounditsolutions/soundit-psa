<?php

namespace Tests\Feature\Mcp;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cipp\CippRestWriteClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * Tenant-scoped target for cipp_assign_user_license: target_upn + sku_id, for a
 * tenant user with NO PSA person record. Selected by argument shape rather than
 * by tool name, so the established person-keyed path is untouched.
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
        $token = $this->token(['cipp_assign_user_license']);

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

        $response = $this->callTool($token, 'cipp_assign_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => strtoupper(self::TARGET_UPN),
            'sku_id' => self::SKU,
            'reason' => 'New contractor needs a seat.',
        ]);

        $result = $this->decodedResult($response);
        $this->assertTrue($result['success'] ?? false, (string) $response->json('result.content.0.text'));
        $this->assertSame(self::TARGET_UPN, $result['target_upn'] ?? null);

        $this->assertDatabaseHas('technician_action_logs', [
            'action_type' => 'cipp_assign_user_license',
            'client_id' => $f['client']->id,
            'result_status' => 'executed',
        ]);
    }

    public function test_an_empty_tenant_user_listing_refuses_instead_of_reporting_no_such_user(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_user_license']);

        // THE FINDING THIS FAMILY EXISTS TO AVOID: an unread listing and a
        // tenant with no such user are the same shape. The refusal must name
        // the ambiguity, and nothing may reach upstream.
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)->andReturn([]);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_user_license', [
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
        $token = $this->token(['cipp_assign_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldReceive('listUsers')->once()->with(self::TENANT)
            ->andReturn([$this->userRow(['userPrincipalName' => 'someone.else@acme.example'])]);
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_user_license', [
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
        $token = $this->token(['cipp_assign_user_license']);

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

        $response = $this->callTool($token, 'cipp_assign_user_license', [
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
        $token = $this->token(['cipp_assign_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listUsers');
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_user_license', [
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
        $token = $this->token(['cipp_assign_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listUsers');
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        // The carve-out is for sku_id ALONE. Every other blocklisted key is
        // still refused on this family, before any upstream read.
        $response = $this->callTool($token, 'cipp_assign_user_license', [
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
        $token = $this->token(['cipp_assign_user_license']);

        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listUsers');
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_user_license', [
            'client_id' => $f['client']->id,
            'target_upn' => 'not-an-address',
            'sku_id' => self::SKU,
            'reason' => 'New contractor needs a seat.',
        ]);

        $this->assertStringContainsString('target_upn must be', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }

    public function test_the_person_keyed_shape_is_untouched_by_this_family(): void
    {
        $this->configureCipp();
        $f = $this->fixture();
        $token = $this->token(['cipp_assign_user_license']);

        // NEGATIVE CONTROL for the dispatch: with no target_upn the call must
        // still land on the person-keyed path and be judged by ITS gates —
        // here, the missing person_id — not by anything this family added.
        $client = Mockery::mock(CippRestWriteClient::class);
        $client->shouldNotReceive('listUsers');
        $client->shouldNotReceive('assignUserLicense');
        $this->app->instance(CippRestWriteClient::class, $client);

        $response = $this->callTool($token, 'cipp_assign_user_license', [
            'client_id' => $f['client']->id,
            'license_type_id' => $f['licenseType']->id,
            'reason' => 'New contractor needs a seat.',
        ]);

        $body = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString('person_id', $body);
        $this->assertStringNotContainsString('target_upn must be', $body);
        $this->assertSame(0, TechnicianActionLog::where('result_status', 'executed')->count());
    }
}
