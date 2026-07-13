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
        $this->assertContains('listDirectoryRoles', $methods);
        $this->assertContains('removeDirectoryRoleMember', $methods);
        $this->assertContains('releaseQuarantineMessage', $methods);
        $this->assertContains('addTenantAllowListEntry', $methods);
        $this->assertContains('listMailQuarantine', $methods);
        $this->assertContains('wipeDevice', $methods);
        $this->assertContains('reassignOneDriveOwnership', $methods);
        $this->assertContains('editUser', $methods);
        $this->assertContains('listGroups', $methods);
        $this->assertContains('setGroupMembership', $methods);
        $this->assertNotContains('post', $methods);
        $this->assertNotContains('get', $methods);
        $this->assertNotContains('request', $methods);
        $this->assertNotContains('get', $methods);
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

    public function test_remove_directory_role_member_posts_exec_remove_admin_role_shape(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'WRITE-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/*' => Http::response(['Results' => ['Successfully removed the user.', 'raw' => 'not returned']]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $result = $client->removeDirectoryRoleMember(
            'acme.onmicrosoft.com',
            'role-object-1',
            'Exchange Administrator',
            'user-123',
            'alex@acme.example',
        );

        // Discard-body path: the caller only learns success/status, never the upstream body.
        $this->assertSame(['success' => true, 'status' => 200], $result);
        $this->assertStringNotContainsString('not returned', json_encode($result));

        // Source-pinned body (CIPP-API Invoke-ExecRemoveAdminRole.ps1): tenantFilter,
        // RoleId (the tenant's ACTIVATED directoryRole object id), RoleName (label used
        // for upstream logging), and ONE Users entry in {value,label} autocomplete shape.
        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecRemoveAdminRole'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'RoleId' => 'role-object-1',
                'RoleName' => 'Exchange Administrator',
                'Users' => [['value' => 'user-123', 'label' => 'alex@acme.example']],
            ]);
    }

    public function test_remove_directory_role_member_rejects_empty_role_or_user_before_any_request(): void
    {
        Http::fake();

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        try {
            $client->removeDirectoryRoleMember('acme.onmicrosoft.com', '  ', 'Exchange Administrator', 'user-123', 'alex@acme.example');
            $this->fail('Expected CippClientException for empty role id');
        } catch (CippClientException $e) {
            $this->assertStringContainsString('role id is required', $e->getMessage());
        }

        try {
            $client->removeDirectoryRoleMember('acme.onmicrosoft.com', 'role-object-1', 'Exchange Administrator', '', 'alex@acme.example');
            $this->fail('Expected CippClientException for empty user id');
        } catch (CippClientException $e) {
            $this->assertStringContainsString('user id is required', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_list_directory_roles_gets_list_roles_with_tenant_filter_and_returns_roles(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'WRITE-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/ListRoles*' => Http::response([
                [
                    'Id' => 'role-object-1',
                    'roleTemplateId' => '29232cdf-9323-42fd-ade2-1d097af3e4de',
                    'DisplayName' => 'Exchange Administrator',
                    'Description' => 'Can manage all aspects of the Exchange product.',
                    'Members' => [['displayName' => 'Alex Acme', 'userPrincipalName' => 'alex@acme.example', 'id' => 'user-123']],
                ],
            ]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $roles = $client->listDirectoryRoles('acme.onmicrosoft.com');

        $this->assertCount(1, $roles);
        $this->assertSame('role-object-1', $roles[0]['Id']);
        $this->assertSame('29232cdf-9323-42fd-ade2-1d097af3e4de', $roles[0]['roleTemplateId']);
        $this->assertSame('Exchange Administrator', $roles[0]['DisplayName']);
        $this->assertSame('user-123', $roles[0]['Members'][0]['id']);

        // Source-pinned read (CIPP-API Invoke-ListRoles.ps1): GET with the tenant in the query string.
        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ListRoles?tenantFilter=acme.onmicrosoft.com'
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN'));
    }

    public function test_list_directory_roles_unwraps_results_envelope(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ListRoles*' => Http::response([
                'Results' => [['Id' => 'role-object-2', 'roleTemplateId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'DisplayName' => 'Helpdesk Administrator', 'Members' => []]],
            ]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $roles = $client->listDirectoryRoles('acme.onmicrosoft.com');

        $this->assertCount(1, $roles);
        $this->assertSame('role-object-2', $roles[0]['Id']);
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

    private function emailSecurityClient(): CippRestWriteClient
    {
        return new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);
    }

    public function test_release_quarantine_message_posts_exec_quarantine_management_release_shape(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ExecQuarantineManagement' => Http::response([
                'Results' => 'Successfully processed aaaaaaaa-1111-2222-3333-444444444444\bbbbbbbb-5555-6666-7777-888888888888',
            ]),
        ]);

        $identity = 'aaaaaaaa-1111-2222-3333-444444444444\bbbbbbbb-5555-6666-7777-888888888888';
        $result = $this->emailSecurityClient()->releaseQuarantineMessage('acme.onmicrosoft.com', $identity);

        $this->assertSame(['success' => true, 'status' => 200], $result);

        // Release only, single identity, and NEVER the AllowSender/policy keys —
        // the tenant allow-list is its own explicit, audited capability.
        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecQuarantineManagement'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'Type' => 'Release',
                'Identity' => $identity,
            ]);
    }

    public function test_release_quarantine_message_throws_on_reported_failure_in_200_body(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ExecQuarantineManagement' => Http::response([
                'Results' => 'Failed. The message has expired from quarantine.',
            ]),
        ]);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('reported failure');

        $this->emailSecurityClient()->releaseQuarantineMessage(
            'acme.onmicrosoft.com',
            'aaaaaaaa-1111-2222-3333-444444444444\bbbbbbbb-5555-6666-7777-888888888888',
        );
    }

    public function test_release_quarantine_message_rejects_empty_identity_before_any_request(): void
    {
        Http::fake();

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('identity is required');

        try {
            $this->emailSecurityClient()->releaseQuarantineMessage('acme.onmicrosoft.com', '   ');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_add_tenant_allow_list_entry_posts_pinned_allow_shape(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/AddTenantAllowBlockList' => Http::response([
                'Results' => ['Successfully added billing@vendor.example as type Sender to the Allow list for acme.onmicrosoft.com'],
            ]),
        ]);

        $result = $this->emailSecurityClient()->addTenantAllowListEntry(
            'acme.onmicrosoft.com',
            'Sender',
            'billing@vendor.example',
            'Added via Sound PSA (ticket T-1001)',
        );

        $this->assertSame(['success' => true, 'status' => 200], $result);

        // listMethod pinned to Allow, expiry pinned to RemoveAfter (45 days
        // after last use) — a NoExpiration allow can never leave this client.
        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/AddTenantAllowBlockList'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
            && $request->data() === [
                'tenantID' => 'acme.onmicrosoft.com',
                'entries' => ['billing@vendor.example'],
                'listType' => 'Sender',
                'notes' => 'Added via Sound PSA (ticket T-1001)',
                'listMethod' => 'Allow',
                'RemoveAfter' => true,
            ]
            && ! array_key_exists('NoExpiration', $request->data()));
    }

    public function test_add_tenant_allow_list_entry_throws_on_reported_failure_in_200_body(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/AddTenantAllowBlockList' => Http::response([
                'Results' => ['Failed to create blocklist. Error: The entry already exists.'],
            ]),
        ]);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('reported failure');

        $this->emailSecurityClient()->addTenantAllowListEntry('acme.onmicrosoft.com', 'Sender', 'billing@vendor.example', 'notes');
    }

    public function test_add_tenant_allow_list_entry_guards_inputs_before_any_request(): void
    {
        Http::fake();
        $client = $this->emailSecurityClient();

        foreach ([
            ['AllTenants', 'Sender', 'billing@vendor.example', 'single resolved tenant'],
            ['acme.onmicrosoft.com', 'Sender', '   ', 'entry value is required'],
            ['acme.onmicrosoft.com', 'FileHash', 'abc123', 'Unsupported tenant allow-list type'],
        ] as [$tenant, $type, $entry, $expected]) {
            try {
                $client->addTenantAllowListEntry($tenant, $type, $entry, 'notes');
                $this->fail('Expected CippClientException for '.$expected);
            } catch (CippClientException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_list_mail_quarantine_gets_the_listing_and_returns_result_rows(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ListMailQuarantine*' => Http::response([
                'Results' => [
                    ['Identity' => 'aaaaaaaa-1111-2222-3333-444444444444\bbbbbbbb-5555-6666-7777-888888888888', 'SenderAddress' => 'billing@vendor.example'],
                    'not-a-row',
                ],
                'Metadata' => null,
            ]),
        ]);

        $rows = $this->emailSecurityClient()->listMailQuarantine('acme.onmicrosoft.com');

        $this->assertCount(1, $rows);
        $this->assertSame('billing@vendor.example', $rows[0]['SenderAddress']);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://cipp.example.test/api/ListMailQuarantine')
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
            && str_contains($request->url(), 'tenantFilter=acme.onmicrosoft.com'));
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

    public function test_wipe_device_posts_exec_device_action_shape(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/*' => Http::response(['Results' => 'Queued wipe on b7e2f9c4-3a1d-4e5b-9c8f-2d6a7b1e0f3c']),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $result = $client->wipeDevice('acme.onmicrosoft.com', 'b7e2f9c4-3a1d-4e5b-9c8f-2d6a7b1e0f3c', 'wipe');
        $client->wipeDevice('acme.onmicrosoft.com', 'b7e2f9c4-3a1d-4e5b-9c8f-2d6a7b1e0f3c', 'retire');

        // The upstream body is discarded: nothing beyond success/status comes back.
        $this->assertSame(['success' => true, 'status' => 200], $result);

        // Source-pinned (CIPP-API Invoke-ExecDeviceAction.ps1 default arm forwards the
        // whole JSON body to Graph POST /deviceManagement/managedDevices('{GUID}')/wipe):
        // a full wipe pins the data-destroying options explicitly so Graph defaults can
        // never soften the action.
        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecDeviceAction'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'GUID' => 'b7e2f9c4-3a1d-4e5b-9c8f-2d6a7b1e0f3c',
                'Action' => 'wipe',
                'keepUserData' => false,
                'keepEnrollmentData' => false,
            ]);

        // retire (unenroll + remove company data) carries no wipe options — the body
        // matches what the CIPP frontend itself sends for the Retire device action.
        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecDeviceAction'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'GUID' => 'b7e2f9c4-3a1d-4e5b-9c8f-2d6a7b1e0f3c',
                'Action' => 'retire',
            ]);
    }

    public function test_wipe_device_rejects_unsupported_action_or_blank_device_id_before_any_request(): void
    {
        Http::fake();

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        try {
            $client->wipeDevice('acme.onmicrosoft.com', '  ', 'wipe');
            $this->fail('Expected CippClientException for blank device id');
        } catch (CippClientException $e) {
            $this->assertStringContainsString('device id is required', $e->getMessage());
        }

        // The action arms are a closed allowlist: anything else (delete, autopilot
        // variants, arbitrary Graph actions) must throw, never fall through to a POST.
        try {
            $client->wipeDevice('acme.onmicrosoft.com', 'b7e2f9c4-3a1d-4e5b-9c8f-2d6a7b1e0f3c', 'delete');
            $this->fail('Expected CippClientException for unsupported action');
        } catch (CippClientException $e) {
            $this->assertStringContainsString('Unsupported device wipe action', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_reassign_onedrive_ownership_posts_exec_sharepoint_perms_shape(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/*' => Http::response([
                'Results' => 'Successfully added sam@acme.example as an owner of https://acme-my.sharepoint.example/personal/alex_acme_example',
            ]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $result = $client->reassignOneDriveOwnership('acme.onmicrosoft.com', 'alex@acme.example', 'sam@acme.example');

        // The upstream Results line (successor UPN + OneDrive URL) is verified, then
        // discarded — callers only ever see success/status.
        $this->assertSame(['success' => true, 'status' => 200], $result);
        $this->assertStringNotContainsString('sharepoint.example', json_encode($result));

        // Source-pinned (CIPP-API Invoke-ExecSharePointPerms.ps1 + the frontend
        // teams-share/onedrive action): UPN is the OneDrive owner, the successor rides
        // in onedriveAccessUser {value,label}, RemovePermission=false adds the owner.
        // URL is deliberately omitted — CIPP resolves the OneDrive URL from Graph
        // server-side, so no caller-supplied URL ever exists in this flow.
        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecSharePointPerms'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'UPN' => 'alex@acme.example',
                'RemovePermission' => false,
                'onedriveAccessUser' => ['value' => 'sam@acme.example', 'label' => 'sam@acme.example'],
            ]);
    }

    public function test_reassign_onedrive_ownership_fails_closed_on_unconfirmed_or_failed_results(): void
    {
        // Set-CIPPSharePointPerms collects per-user CSOM failures into Results and
        // still returns HTTP 200 — a status check alone would report success on a
        // failed reassignment, so the wrapper must parse the Results text.
        $bodies = [
            ['Results' => 'Failed to change access for sam@acme.example: Access denied.'],
            ['Results' => ['Some unrelated message']],
            ['Results' => null],
        ];

        foreach ($bodies as $body) {
            Http::fake([
                'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
                'cipp.example.test/api/*' => Http::response($body),
            ]);

            $client = new CippRestWriteClient([
                'api_url' => 'https://cipp.example.test',
                'tenant_id' => 'tenant-1',
                'client_id' => 'write-client',
                'client_secret' => 'write-secret',
            ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

            try {
                $client->reassignOneDriveOwnership('acme.onmicrosoft.com', 'alex@acme.example', 'sam@acme.example');
                $this->fail('Expected CippClientException for unconfirmed Results: '.json_encode($body));
            } catch (CippClientException $e) {
                $this->assertStringContainsString('did not confirm the OneDrive permission change', $e->getMessage());
            }
        }
    }

    public function test_set_group_membership_posts_edit_group_shape(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/*' => Http::response([
                'Results' => ['Success - Added member alex@acme.example to Sales Team group'],
            ]),
        ]);

        $client = $this->emailSecurityClient();

        $result = $client->setGroupMembership('acme.onmicrosoft.com', '3f2504e0-4f89-11d3-9a0c-0305e82c3301', 'Sales Team', 'Microsoft 365', 'user-123', 'alex@acme.example', 'add');
        $client->setGroupMembership('acme.onmicrosoft.com', '9b2a4c31-77aa-42dd-8be2-11d2aa8bc102', 'VPN Users', 'Security', 'user-123', 'alex@acme.example', 'remove');
        $client->setGroupMembership('acme.onmicrosoft.com', '3f2504e0-4f89-11d3-9a0c-0305e82c3301', 'All Staff DL', 'Distribution List', 'user-123', 'alex@acme.example', 'add');

        // The upstream body is verified, then discarded — success/status only.
        $this->assertSame(['success' => true, 'status' => 200], $result);

        // Source-pinned body (CIPP-API Invoke-EditGroup.ps1): groupId is the plain
        // GUID ($UserObj.groupId.value ?? $UserObj.groupId), groupType routes the
        // Exchange-vs-Graph arm, groupName is the label used for upstream logging
        // (displayName is NEVER sent — it would trigger the property-edit branch),
        // and each member entry carries value (Graph object id, used by the Graph
        // PATCH/DELETE arms) plus addedFields.userPrincipalName (used by the
        // Exchange Add/Remove-DistributionGroupMember arm and log lines).
        $member = [['value' => 'user-123', 'label' => 'alex@acme.example', 'addedFields' => ['userPrincipalName' => 'alex@acme.example']]];

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/EditGroup'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'groupId' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
                'groupType' => 'Microsoft 365',
                'groupName' => 'Sales Team',
                'AddMember' => $member,
            ]
            && ! array_key_exists('displayName', $request->data())
            && ! array_key_exists('RemoveMember', $request->data()));

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/EditGroup'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'groupId' => '9b2a4c31-77aa-42dd-8be2-11d2aa8bc102',
                'groupType' => 'Security',
                'groupName' => 'VPN Users',
                'RemoveMember' => $member,
            ]
            && ! array_key_exists('AddMember', $request->data()));

        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/EditGroup'
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'groupId' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
                'groupType' => 'Distribution List',
                'groupName' => 'All Staff DL',
                'AddMember' => $member,
            ]);
    }

    public function test_set_group_membership_fails_closed_when_cipp_reports_failure_or_silence(): void
    {
        // Invoke-EditGroup returns HTTP 200 unconditionally; per-member failures
        // surface only as "Error - …" Results strings, and a member the endpoint
        // silently dropped (its AddMembers try/catch) produces NO line at all.
        // Both must fail closed — a confident success on a failed membership
        // change is exactly the psa-7lgo bug class.
        $bodies = [
            ['Results' => ['Error - One or more added object references already exist for the following modified properties: \'members\'.']],
            ['Results' => ['Success - Added member alex@acme.example to Sales Team group', 'Error - Failed to remove member. Insufficient privileges.']],
            ['Results' => []],
            ['Results' => ['Something unrecognized happened']],
            [],
        ];

        foreach ($bodies as $body) {
            Http::fake([
                'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
                'cipp.example.test/api/*' => Http::response($body),
            ]);

            try {
                $this->emailSecurityClient()->setGroupMembership('acme.onmicrosoft.com', '3f2504e0-4f89-11d3-9a0c-0305e82c3301', 'Sales Team', 'Microsoft 365', 'user-123', 'alex@acme.example', 'add');
                $this->fail('Expected CippClientException for body: '.json_encode($body));
            } catch (CippClientException $e) {
                $this->assertStringContainsString('did not confirm the group membership change', $e->getMessage());
            }
        }
    }

    public function test_set_group_membership_guards_inputs_before_any_request(): void
    {
        Http::fake();
        $client = $this->emailSecurityClient();

        foreach ([
            ['acme.onmicrosoft.com', '  ', 'Sales Team', 'Microsoft 365', 'user-123', 'alex@acme.example', 'add', 'group id is required'],
            ['acme.onmicrosoft.com', '3f2504e0-4f89-11d3-9a0c-0305e82c3301', 'Sales Team', 'Microsoft 365', '', 'alex@acme.example', 'add', 'user id is required'],
            ['acme.onmicrosoft.com', '3f2504e0-4f89-11d3-9a0c-0305e82c3301', 'Sales Team', 'Microsoft 365', 'user-123', ' ', 'add', 'UPN is required'],
            ['acme.onmicrosoft.com', '3f2504e0-4f89-11d3-9a0c-0305e82c3301', 'Sales Team', 'Microsoft 365', 'user-123', 'alex@acme.example', 'replace', 'Unsupported group membership operation'],
            ['acme.onmicrosoft.com', '3f2504e0-4f89-11d3-9a0c-0305e82c3301', 'Sales Team', 'Dynamic', 'user-123', 'alex@acme.example', 'add', 'Unsupported group type'],
        ] as [$tenant, $groupId, $groupName, $groupType, $userId, $upn, $operation, $expected]) {
            try {
                $client->setGroupMembership($tenant, $groupId, $groupName, $groupType, $userId, $upn, $operation);
                $this->fail('Expected CippClientException for '.$expected);
            } catch (CippClientException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_list_groups_gets_the_tenant_listing_and_returns_rows(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ListGroups*' => Http::response([
                // Shape from CIPP-API Invoke-ListGroups.ps1 (list view): Graph
                // fields plus the projection's computed keys.
                [
                    'id' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
                    'displayName' => 'Sales Team',
                    'mail' => 'sales@acme.example',
                    'mailEnabled' => true,
                    'securityEnabled' => false,
                    'groupTypes' => ['Unified'],
                    'onPremisesSyncEnabled' => null,
                    'membershipRule' => null,
                    'groupType' => 'Microsoft 365',
                    'calculatedGroupType' => 'm365',
                    'dynamicGroupBool' => false,
                ],
                'not-a-row',
            ]),
        ]);

        $rows = $this->emailSecurityClient()->listGroups('acme.onmicrosoft.com');

        $this->assertCount(1, $rows);
        $this->assertSame('Sales Team', $rows[0]['displayName']);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://cipp.example.test/api/ListGroups')
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
            && str_contains($request->url(), 'tenantFilter=acme.onmicrosoft.com'));
    }

    public function test_list_groups_unwraps_results_envelope(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ListGroups*' => Http::response([
                'Results' => [['id' => '9b2a4c31-77aa-42dd-8be2-11d2aa8bc102', 'displayName' => 'VPN Users', 'groupType' => 'Security']],
            ]),
        ]);

        $rows = $this->emailSecurityClient()->listGroups('acme.onmicrosoft.com');

        $this->assertCount(1, $rows);
        $this->assertSame('VPN Users', $rows[0]['displayName']);
    }

    public function test_reassign_onedrive_ownership_rejects_blank_parties_before_any_request(): void
    {
        Http::fake();

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        try {
            $client->reassignOneDriveOwnership('acme.onmicrosoft.com', '  ', 'sam@acme.example');
            $this->fail('Expected CippClientException for blank owner');
        } catch (CippClientException $e) {
            $this->assertStringContainsString('owner UPN is required', $e->getMessage());
        }

        try {
            $client->reassignOneDriveOwnership('acme.onmicrosoft.com', 'alex@acme.example', '');
            $this->fail('Expected CippClientException for blank successor');
        } catch (CippClientException $e) {
            $this->assertStringContainsString('successor UPN is required', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    private function editUserClient(): CippRestWriteClient
    {
        return new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);
    }

    public function test_edit_user_posts_edit_user_shape_with_pinned_upn_halves(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            // Fixture from the vendor source (Set-CIPPUser.ps1): the edit's own
            // positive marker plus Set-CIPPManager's success line.
            'cipp.example.test/api/*' => Http::response(['Results' => [
                'Success. The user has been edited.',
                "Set alex@acme.example's manager to boss@acme.example",
            ]]),
        ]);

        $result = $this->editUserClient()->editUser(
            'acme.onmicrosoft.com',
            'user-123',
            'alex@acme.example',
            ['jobTitle' => 'Operations Manager', 'businessPhones' => ['555 0100']],
            ['department'],
            'boss@acme.example',
        );

        $this->assertSame(['success' => true, 'status' => 200], $result);

        // Source-pinned body (CIPP-API Invoke-EditUser.ps1 → Set-CIPPUser.ps1):
        // id is the Graph user object id; username + Domain are the CURRENT
        // UPN halves (Set-CIPPUser recomposes userPrincipalName from them on
        // every edit, so omitting them would ship the literal UPN "@");
        // clearProperties carries explicit blanks; setManager rides the
        // {value,label} autocomplete shape with the server-resolved UPN.
        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/EditUser'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
            && $request->data() === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'id' => 'user-123',
                'username' => 'alex',
                'Domain' => 'acme.example',
                'jobTitle' => 'Operations Manager',
                'businessPhones' => ['555 0100'],
                'clearProperties' => ['department'],
                'setManager' => ['value' => 'boss@acme.example', 'label' => 'boss@acme.example'],
            ]);
    }

    public function test_edit_user_omits_clear_and_manager_keys_and_never_sends_extra_action_keys(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/*' => Http::response(['Results' => ['Success. The user has been edited.']]),
        ]);

        $result = $this->editUserClient()->editUser(
            'acme.onmicrosoft.com',
            'user-123',
            'alex@acme.example',
            ['displayName' => 'Alex A. Acme'],
            [],
            null,
        );

        $this->assertSame(['success' => true, 'status' => 200], $result);

        // A minimal set-only edit carries NOTHING beyond the identity pins and
        // the provided field: no clearProperties, no setManager, and never a
        // key that would trigger Set-CIPPUser's other action arms (password
        // echoes into the Results text; licenses/groups/aliases have their
        // own tools; MustChangePass is deliberately omitted so the vendor's
        // always-sent forceChangePasswordNextSignIn rides as false, exactly
        // like CIPP's own edit form defaults).
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://cipp.example.test/api/EditUser') {
                return false;
            }

            $data = $request->data();
            foreach (['clearProperties', 'setManager', 'password', 'MustChangePass', 'licenses', 'removeLicenses', 'AddedAliases', 'AddToGroups', 'RemoveFromGroups', 'CopyFrom', 'copyFrom', 'setSponsor', 'defaultAttributes', 'customData', 'Scheduled', 'userPrincipalName', 'mailNickname'] as $forbidden) {
                if (array_key_exists($forbidden, $data)) {
                    return false;
                }
            }

            return $data === [
                'tenantFilter' => 'acme.onmicrosoft.com',
                'id' => 'user-123',
                'username' => 'alex',
                'Domain' => 'acme.example',
                'displayName' => 'Alex A. Acme',
            ];
        });
    }

    public function test_edit_user_throws_on_reported_failure_inside_http_200(): void
    {
        // Set-CIPPUser catches its own Graph errors into Results strings and
        // Invoke-EditUser still returns HTTP 200 — the Results text is the
        // ONLY failure signal.
        $cases = [
            // Full failure: the Graph PATCH itself failed.
            [
                'body' => ['Results' => ['Failed to edit user. Insufficient privileges to complete the operation.']],
                'expect' => 'Failed to edit user',
            ],
            // Partial: the edit applied but the manager step failed — the
            // error must say the profile PATCH itself already applied.
            [
                'body' => ['Results' => [
                    'Success. The user has been edited.',
                    "Failed to set alex@acme.example's manager: Resource 'boss@acme.example' does not exist.",
                ]],
                'expect' => 'already reported applied',
            ],
            // No positive marker at all: fail closed rather than assume.
            [
                'body' => ['Results' => []],
                'expect' => 'did not confirm the user edit',
            ],
        ];

        // One fake with a response SEQUENCE: Http::fake() inside a loop stacks
        // stubs (first registered wins), which would silently replay case 1's
        // body for every case.
        $sequence = Http::sequence();
        foreach ($cases as $case) {
            $sequence->push($case['body']);
        }
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/*' => $sequence,
        ]);

        $client = $this->editUserClient();

        foreach ($cases as $case) {
            try {
                $client->editUser(
                    'acme.onmicrosoft.com',
                    'user-123',
                    'alex@acme.example',
                    ['jobTitle' => 'Operations Manager'],
                    [],
                    'boss@acme.example',
                );
                $this->fail('Expected CippClientException for body: '.json_encode($case['body']));
            } catch (CippClientException $e) {
                $this->assertStringContainsString($case['expect'], $e->getMessage(), json_encode($case['body']));
            }
        }
    }

    public function test_edit_user_rejects_malformed_target_identity_or_empty_change_before_any_request(): void
    {
        Http::fake();

        $client = $this->editUserClient();

        try {
            $client->editUser('acme.onmicrosoft.com', '  ', 'alex@acme.example', ['jobTitle' => 'X'], [], null);
            $this->fail('Expected CippClientException for empty user id');
        } catch (CippClientException $e) {
            $this->assertStringContainsString('user object id is required', $e->getMessage());
        }

        foreach (['no-at-sign', '@acme.example', 'alex@'] as $badUpn) {
            try {
                $client->editUser('acme.onmicrosoft.com', 'user-123', $badUpn, ['jobTitle' => 'X'], [], null);
                $this->fail("Expected CippClientException for UPN '{$badUpn}'");
            } catch (CippClientException $e) {
                $this->assertStringContainsString('UPN', $e->getMessage());
            }
        }

        try {
            $client->editUser('acme.onmicrosoft.com', 'user-123', 'alex@acme.example', [], [], null);
            $this->fail('Expected CippClientException for a no-change edit');
        } catch (CippClientException $e) {
            $this->assertStringContainsString('at least one change', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
