<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippClientException;
use App\Services\Cipp\CippRestWriteClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

class CippRestWriteClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_exposes_curated_methods_only_no_arbitrary_endpoint_post(): void
    {
        $methods = collect((new ReflectionClass(CippRestWriteClient::class))->getMethods())
            ->filter(fn ($method) => $method->isPublic() && $method->class === CippRestWriteClient::class)
            ->map(fn ($method) => $method->name)
            ->all();

        $this->assertContains('setUserSignInState', $methods);
        $this->assertContains('revokeUserSessions', $methods);
        $this->assertContains('removeUserMfaMethods', $methods);
        $this->assertContains('setLegacyPerUserMfa', $methods);
        $this->assertContains('assignUserLicense', $methods);
        $this->assertContains('removeUserLicense', $methods);
        $this->assertNotContains('post', $methods);
        $this->assertNotContains('request', $methods);
    }

    public function test_posts_curated_user_lifecycle_shapes_with_redirect_refusal_and_dns_pinning(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'WRITE-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/*' => Http::response(['Results' => [['ok' => true, 'secretBody' => 'do-not-return']]]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
            'application_id' => 'write-app',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $result = $client->setUserSignInState('acme.onmicrosoft.com', 'user-123', false);
        $client->revokeUserSessions('acme.onmicrosoft.com', 'user-123', 'alex@acme.example');
        $client->removeUserMfaMethods('acme.onmicrosoft.com', 'alex@acme.example');
        $client->setLegacyPerUserMfa('acme.onmicrosoft.com', 'alex@acme.example', 'user-123', 'enabled');

        $this->assertSame(['success' => true, 'status' => 200], $result);
        $this->assertStringNotContainsString('do-not-return', json_encode($result));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'login.microsoftonline.com/tenant-1/oauth2/v2.0/token')
            && $request['client_id'] === 'write-client'
            && $request['client_secret'] === 'write-secret'
            && $request['scope'] === 'api://write-app/.default');

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecDisableUser'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
            && $request->method() === 'POST'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'ID' => 'user-123',
                'Enable' => false,
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecRevokeSessions'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'id' => 'user-123',
                'Username' => 'alex@acme.example',
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecResetMFA'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'ID' => 'alex@acme.example',
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecPerUserMFA'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'userPrincipalName' => 'alex@acme.example',
                'userId' => 'user-123',
                'State' => 'enabled',
            ]);
    }

    public function test_exec_bulk_license_source_body_shape_is_pinned_for_single_user_assign_and_remove(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'WRITE-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/*' => Http::response(['Results' => [['ok' => true]]]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $client->assignUserLicense('acme.onmicrosoft.com', 'user-123', 'sku-from-sync');
        $client->removeUserLicense('acme.onmicrosoft.com', 'user-123', 'sku-from-sync');

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecBulkLicense'
            && $request->method() === 'POST'
            && $request->data() === [[
                'tenantFilter' => 'acme.onmicrosoft.com',
                'userIds' => ['user-123'],
                'LicenseOperation' => 'Add',
                'Licenses' => [['value' => 'sku-from-sync']],
                'LicensesToRemove' => [],
                'RemoveAllLicenses' => false,
                'ReplaceAllLicenses' => false,
            ]]);

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecBulkLicense'
            && $request->method() === 'POST'
            && $request->data() === [[
                'tenantFilter' => 'acme.onmicrosoft.com',
                'userIds' => ['user-123'],
                'LicenseOperation' => 'Remove',
                'Licenses' => [],
                'LicensesToRemove' => [['value' => 'sku-from-sync']],
                'RemoveAllLicenses' => false,
                'ReplaceAllLicenses' => false,
            ]]);
    }

    public function test_rejects_unsafe_write_url_before_token_request(): void
    {
        Http::fake();

        $client = new CippRestWriteClient([
            'api_url' => 'https://127.0.0.1',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store());

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('CIPP API URL resolves to a private or reserved address');

        try {
            $client->setUserSignInState('acme.onmicrosoft.com', 'user-123', false);
        } finally {
            Http::assertNothingSent();
        }
    }
}
