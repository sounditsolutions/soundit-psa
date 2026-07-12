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
        $this->assertContains('convertMailbox', $methods);
        $this->assertContains('setMailboxForwardingInternal', $methods);
        $this->assertContains('setMailboxForwardingExternal', $methods);
        $this->assertContains('disableMailboxForwarding', $methods);
        $this->assertContains('setMailboxGalVisibility', $methods);
        $this->assertContains('setMailboxOutOfOffice', $methods);
        $this->assertContains('setMailboxDelegate', $methods);
        $this->assertContains('resetUserPassword', $methods);
        $this->assertNotContains('post', $methods);
        $this->assertNotContains('request', $methods);
    }

    public function test_set_mailbox_delegate_posts_exec_edit_mailbox_permissions_shape(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'WRITE-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/*' => Http::response(['Results' => [['ok' => true, 'raw' => 'not returned']]]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $result = $client->setMailboxDelegate('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', 'full_access', 'grant', true);
        $client->setMailboxDelegate('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', 'full_access', 'grant', false);
        $client->setMailboxDelegate('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', 'full_access', 'remove', true);
        $client->setMailboxDelegate('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', 'send_as', 'grant', true);
        $client->setMailboxDelegate('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', 'send_as', 'remove', true);
        $client->setMailboxDelegate('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', 'send_on_behalf', 'grant', true);
        $client->setMailboxDelegate('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', 'send_on_behalf', 'remove', true);

        $this->assertSame(['success' => true, 'status' => 200], $result);
        $this->assertStringNotContainsString('not returned', json_encode($result));

        $entry = [['value' => 'target@acme.example', 'label' => 'target@acme.example']];
        $base = [
            'TenantFilter' => 'acme.onmicrosoft.com',
            'UserID' => 'alex@acme.example',
            'AddFullAccess' => [],
            'AddFullAccessNoAutoMap' => [],
            'RemoveFullAccess' => [],
            'AddSendAs' => [],
            'RemoveSendAs' => [],
            'AddSendOnBehalf' => [],
            'RemoveSendOnBehalf' => [],
        ];

        // Full access grant with automap populates AddFullAccess only.
        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecEditMailboxPermissions'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
            && $request->data() === array_merge($base, ['AddFullAccess' => $entry]));

        // Full access grant without automap populates AddFullAccessNoAutoMap only.
        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecEditMailboxPermissions'
            && $request->data() === array_merge($base, ['AddFullAccessNoAutoMap' => $entry]));

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecEditMailboxPermissions'
            && $request->data() === array_merge($base, ['RemoveFullAccess' => $entry]));

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecEditMailboxPermissions'
            && $request->data() === array_merge($base, ['AddSendAs' => $entry]));

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecEditMailboxPermissions'
            && $request->data() === array_merge($base, ['RemoveSendAs' => $entry]));

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecEditMailboxPermissions'
            && $request->data() === array_merge($base, ['AddSendOnBehalf' => $entry]));

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecEditMailboxPermissions'
            && $request->data() === array_merge($base, ['RemoveSendOnBehalf' => $entry]));
    }

    public function test_set_mailbox_delegate_rejects_empty_trustee_before_any_request(): void
    {
        Http::fake();

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('trustee UPN is required');

        try {
            $client->setMailboxDelegate('acme.onmicrosoft.com', 'alex@acme.example', '  ', 'full_access', 'grant', true);
        } finally {
            Http::assertNothingSent();
        }
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

    public function test_posts_curated_mailbox_shapes_with_source_pinned_fields(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'WRITE-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/*' => Http::response(['Results' => [['ok' => true, 'raw' => 'not returned']]]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $result = $client->convertMailbox('acme.onmicrosoft.com', 'alex@acme.example', 'Shared');
        $client->setMailboxForwardingInternal('acme.onmicrosoft.com', 'alex@acme.example', 'target@acme.example', true);
        $client->setMailboxForwardingExternal('acme.onmicrosoft.com', 'alex@acme.example', 'forward@example.net', false);
        $client->disableMailboxForwarding('acme.onmicrosoft.com', 'alex@acme.example');
        $client->setMailboxGalVisibility('acme.onmicrosoft.com', 'alex@acme.example', true);
        $client->setMailboxOutOfOffice(
            'acme.onmicrosoft.com',
            'alex@acme.example',
            'Scheduled',
            'Internal response',
            'External response',
            '2026-07-04T09:00:00Z',
            '2026-07-05T17:00:00Z',
            'UTC',
        );

        $this->assertSame(['success' => true, 'status' => 200], $result);
        $this->assertStringNotContainsString('not returned', json_encode($result));

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecConvertMailbox'
            && $request->method() === 'POST'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'ID' => 'alex@acme.example',
                'MailboxType' => 'Shared',
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecEmailForward'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'userID' => 'alex@acme.example',
                'ForwardInternal' => 'target@acme.example',
                'ForwardExternal' => null,
                'forwardOption' => 'internalAddress',
                'KeepCopy' => 'true',
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecEmailForward'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'userID' => 'alex@acme.example',
                'ForwardInternal' => null,
                'ForwardExternal' => 'forward@example.net',
                'forwardOption' => 'ExternalAddress',
                'KeepCopy' => 'false',
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecEmailForward'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'userID' => 'alex@acme.example',
                'ForwardInternal' => null,
                'ForwardExternal' => null,
                'forwardOption' => 'disabled',
                'KeepCopy' => 'false',
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecHideFromGAL'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'ID' => 'alex@acme.example',
                'HideFromGAL' => true,
            ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecSetOoO'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'userId' => 'alex@acme.example',
                'AutoReplyState' => 'Scheduled',
                'InternalMessage' => 'Internal response',
                'ExternalMessage' => 'External response',
                'StartTime' => '2026-07-04T09:00:00Z',
                'EndTime' => '2026-07-05T17:00:00Z',
                'timezone' => 'UTC',
            ]);

        $oooRequest = collect(Http::recorded())
            ->map(fn (array $record) => $record[0])
            ->first(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecSetOoO');

        $this->assertArrayNotHasKey('CreateOOFEvent', $oooRequest->data());
        $this->assertArrayNotHasKey('AutoDeclineFutureRequestsWhenOOF', $oooRequest->data());
        $this->assertArrayNotHasKey('DeclineEventsForScheduledOOF', $oooRequest->data());
        $this->assertArrayNotHasKey('DeclineMeetingMessage', $oooRequest->data());
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

    public function test_reset_password_posts_exec_reset_pass_shape_and_captures_the_password_body(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'WRITE-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/ExecResetPass' => Http::response([
                'Results' => [
                    'resultText' => 'Successfully reset the password for Alex, alex@acme.example. The new password is Temp-P@ss-9x!',
                    'copyField' => 'Temp-P@ss-9x!',
                    'state' => 'success',
                ],
            ]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
            'application_id' => 'write-app',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $result = $client->resetUserPassword('acme.onmicrosoft.com', 'alex@acme.example', true);

        // captureBody path returns the decoded body so the temp password is available to the executor.
        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('Temp-P@ss-9x!', $result['body']['Results']['copyField']);
        $this->assertSame('success', $result['body']['Results']['state']);

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecResetPass'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'ID' => 'alex@acme.example',
                'MustChange' => true,
            ]);
    }

    public function test_reset_password_forwards_must_change_false_when_requested(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ExecResetPass' => Http::response(['Results' => ['copyField' => 'pw', 'state' => 'success']]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $client->resetUserPassword('acme.onmicrosoft.com', 'alex@acme.example', false);

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecResetPass'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'ID' => 'alex@acme.example',
                'MustChange' => false,
            ]);
    }
}
