<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippClientException;
use App\Services\Cipp\CippRestWriteClient;
use App\Services\Cipp\CippWriteNotSentException;
use App\Services\Cipp\CippWriteUnconfirmedException;
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

    public function test_reset_http_failure_carries_status_without_response_body(): void
    {
        Http::preventStrayRequests();
        foreach ([400, 401, 429, 500, 502, 504] as $status) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake([
                'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
                'cipp.example.test/api/*' => Http::response(['Results' => 'synthetic-sensitive-body'], $status),
            ]);
            $client = new CippRestWriteClient([
                'api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1',
                'client_id' => 'write-client', 'client_secret' => 'write-secret',
            ], Cache::store(), fn (string $host): array => ['93.184.216.34']);
            try {
                $client->resetUserPassword('example.onmicrosoft.com', 'alex@example.test', true);
                $this->fail('HTTP failure must throw');
            } catch (CippClientException $e) {
                $this->assertInstanceOf(\App\Services\Cipp\CippWriteHttpException::class, $e);
                $this->assertSame($status, $e->status);
                $this->assertStringNotContainsString('synthetic-sensitive-body', $e->getMessage());
            }
            Http::assertSent(fn ($request) => str_contains($request->url(), '/api/ExecResetPass'));
        }
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
        $this->assertContains('listUserMailboxRules', $methods);
        $this->assertContains('removeMailboxRule', $methods);
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

    public function test_remove_mailbox_rule_posts_exec_remove_mailbox_rule_shape(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'WRITE-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/*' => Http::response(['Results' => 'Successfully deleted mailbox rule', 'raw' => 'not returned']),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $result = $client->removeMailboxRule(
            'acme.onmicrosoft.com',
            'alex@acme.example',
            'mbx-guid-1\\rule-id-1',
            'Move invoices to RSS Feeds',
        );

        // Discard-body path: the caller only learns success/status, never the upstream body.
        $this->assertSame(['success' => true, 'status' => 200], $result);
        $this->assertStringNotContainsString('not returned', json_encode($result));

        // Source-pinned body (CIPP-API master, verified 2026-08-19,
        // Invoke-ExecRemoveMailboxRule.ps1): TenantFilter, userPrincipalName,
        // ruleId (the rule's upstream Identity, from which upstream derives
        // MailboxObjectId), and ruleName (used for CIPP's own log line). The
        // endpoint calls Remove-CIPPMailboxRule WITHOUT -RemoveAllRules, so
        // exactly one rule is deleted; failure is HTTP 500, which send() throws on.
        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ExecRemoveMailboxRule'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN')
            && $request->data() === [
                'TenantFilter' => 'acme.onmicrosoft.com',
                'userPrincipalName' => 'alex@acme.example',
                'ruleId' => 'mbx-guid-1\\rule-id-1',
                'ruleName' => 'Move invoices to RSS Feeds',
            ]);
    }

    public function test_remove_mailbox_rule_rejects_empty_inputs_before_any_request(): void
    {
        Http::fake();

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        try {
            $client->removeMailboxRule('acme.onmicrosoft.com', '  ', 'mbx-guid-1\\rule-id-1', 'Move invoices to RSS Feeds');
            $this->fail('Expected CippClientException for empty UPN');
        } catch (CippClientException $e) {
            $this->assertStringContainsString('owner UPN is required', $e->getMessage());
        }

        try {
            $client->removeMailboxRule('acme.onmicrosoft.com', 'alex@acme.example', '  ', 'Move invoices to RSS Feeds');
            $this->fail('Expected CippClientException for empty rule id');
        } catch (CippClientException $e) {
            $this->assertStringContainsString('rule id is required', $e->getMessage());
        }

        try {
            $client->removeMailboxRule('acme.onmicrosoft.com', 'alex@acme.example', 'mbx-guid-1\\rule-id-1', '  ');
            $this->fail('Expected CippClientException for empty rule name');
        } catch (CippClientException $e) {
            $this->assertStringContainsString('rule name is required', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_list_user_mailbox_rules_gets_live_listing_with_tenant_filter_and_user_id(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'WRITE-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/ListUserMailboxRules*' => Http::response([
                [
                    'Identity' => 'mbx-guid-1\\rule-id-1',
                    'Name' => 'Move invoices to RSS Feeds',
                    'Enabled' => true,
                ],
            ]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $rules = $client->listUserMailboxRules('acme.onmicrosoft.com', 'alex@acme.example');

        $this->assertCount(1, $rules);
        $this->assertSame('mbx-guid-1\\rule-id-1', $rules[0]['Identity']);
        $this->assertSame('Move invoices to RSS Feeds', $rules[0]['Name']);

        // Source-pinned read: ListUserMailboxRules runs Get-InboxRule -Mailbox $UserID
        // (a LIVE per-mailbox Exchange call — see CippMcpToolRelay's TOOL_MAP notes),
        // and its user parameter is UserID, not the camelCase userId its siblings take.
        Http::assertSent(fn ($request) => $request->url() === 'https://cipp.example.test/api/ListUserMailboxRules?TenantFilter=acme.onmicrosoft.com&UserID=alex%40acme.example'
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer WRITE-TOKEN'));
    }

    public function test_list_user_mailbox_rules_unwraps_results_envelope_and_rejects_empty_upn(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ListUserMailboxRules*' => Http::response([
                'Results' => [['Identity' => 'mbx-guid-1\\rule-id-2', 'Name' => 'Sort newsletters']],
            ]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $rules = $client->listUserMailboxRules('acme.onmicrosoft.com', 'alex@acme.example');

        $this->assertCount(1, $rules);
        $this->assertSame('Sort newsletters', $rules[0]['Name']);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('owner UPN is required');
        $client->listUserMailboxRules('acme.onmicrosoft.com', '  ');
    }

    public function test_list_user_mailbox_rules_throws_on_a_queue_backed_payload_rather_than_reporting_no_rules(): void
    {
        // ListUserMailboxRules is a LIVE Exchange call and should never answer
        // with a queue marker — but the guard runs at the source anyway
        // (listMailQuarantine precedent, psa-lmex): on the removal gate an
        // unguarded "still loading" would decline as "no such rule" — the wrong
        // reason — and the day this read backs an answer it would be a false
        // all-clear.
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ListUserMailboxRules*' => Http::response([
                'Results' => [],
                'Metadata' => ['QueueMessage' => 'Still loading data for acme.onmicrosoft.com. Please check back in 1 minute'],
            ]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('Still loading data');

        $client->listUserMailboxRules('acme.onmicrosoft.com', 'alex@acme.example');
    }

    public function test_list_user_mailbox_rules_throws_on_an_unrecognized_payload_rather_than_reporting_no_rules(): void
    {
        // The removal gate reports "no rules" to the approver as 'No inbox rule
        // named "X" exists on this mailbox', so an HTTP-200 error envelope, a
        // non-array body, or any shape drift must hard-error here rather than
        // collapse to []: a degraded read must never read as a clean mailbox
        // (psa-7lgo rule 3), or a technician closes a live BEC ticket.
        foreach ([
            '{"Results":"Failed to connect to Exchange"}',
            '{"error":{"code":"Forbidden"}}',
            '"unexpected-scalar"',
            'null',
        ] as $payload) {
            Http::fake([
                'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
                'cipp.example.test/api/ListUserMailboxRules*' => Http::response($payload, 200, ['Content-Type' => 'application/json']),
            ]);

            try {
                $this->emailSecurityClient()->listUserMailboxRules('acme.onmicrosoft.com', 'alex@acme.example');
                $this->fail("Expected CippClientException for upstream body {$payload}");
            } catch (CippClientException $e) {
                $this->assertMatchesRegularExpression('/unrecognized payload|unreadable payload/', $e->getMessage(), $payload);
            }
        }
    }

    public function test_list_user_mailbox_rules_throws_on_a_non_object_entry_rather_than_filtering_it_away(): void
    {
        // The shape guard has to reach the ELEMENTS. CIPP answers a failed
        // Exchange call HTTP 200 with the error as a bare string inside a list,
        // and an empty object is the other degraded shape. Filtering non-objects
        // out is the same fault as collapsing the body to []: the removal gate
        // reports whatever survives as authoritative, so a listing that could
        // not be read becomes 'No inbox rule named "X" exists on this mailbox'
        // — a live BEC forwarding rule closed as clean (psa-7lgo rule 3, one
        // level down).
        foreach ([
            '["Failed to connect to Exchange"]',
            '{"Results":["Failed to connect to Exchange"]}',
            '[{"Identity":"mbx-guid-1\\\\rule-id-1","Name":"Move invoices to RSS Feeds"},"Failed to connect to Exchange"]',
            '[null]',
            '[123]',
        ] as $payload) {
            Http::fake([
                'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
                'cipp.example.test/api/ListUserMailboxRules*' => Http::response($payload, 200, ['Content-Type' => 'application/json']),
            ]);

            try {
                $this->emailSecurityClient()->listUserMailboxRules('acme.onmicrosoft.com', 'alex@acme.example');
                $this->fail("Expected CippClientException for upstream body {$payload}");
            } catch (CippClientException $e) {
                $this->assertStringContainsString('non-object entry', $e->getMessage(), $payload);
            }
        }
    }

    public function test_list_user_mailbox_rules_still_reads_a_genuinely_empty_mailbox_as_empty(): void
    {
        // The element guard must not turn "this mailbox has no rules" into a
        // refusal — that would decline every clean-mailbox approval and take the
        // remediation path with it. An empty LIST is a real answer; only a
        // non-object ELEMENT is drift.
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ListUserMailboxRules*' => Http::response('[]', 200, ['Content-Type' => 'application/json']),
        ]);

        $this->assertSame([], $this->emailSecurityClient()->listUserMailboxRules('acme.onmicrosoft.com', 'alex@acme.example'));
    }

    public function test_list_mail_quarantine_throws_on_a_queue_backed_payload_rather_than_reporting_no_rows(): void
    {
        // The write client unwraps {"Results": ...} like the two read clients do, so it owns
        // the same hazard: a CIPP "still loading" reply becomes an empty row list. Today its
        // only caller is a write GATE (StaffCippWriteToolExecutor::verifiedQuarantineRow),
        // which turns "no rows" into a REFUSED release — so it fails safe rather than into a
        // false all-clear. That safety is a property of the CALLER, not of this client, so
        // the guard belongs here before someone adds a read whose result is an answer.
        // Shape from CIPP-API Invoke-ListMailQuarantine.ps1:48-51 + :95-96.
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ListMailQuarantine*' => Http::response([
                'Results' => [],
                'Metadata' => [
                    'QueueMessage' => 'Still loading data for all tenants. Please check back in a few more minutes',
                    'QueueId' => 'b7d1c0e2-3f45-4a68-91bc-2d5e8f0a7c34',
                ],
            ]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('Still loading data for all tenants');

        $client->listMailQuarantine('acme.onmicrosoft.com');
    }

    public function test_list_mail_quarantine_returns_rows_when_metadata_carries_only_a_null_queue_id(): void
    {
        // CIPP-API Invoke-ListMailQuarantine.ps1:73-77 — the healthy rows-present branch
        // still emits Metadata{QueueId}, serialised null. Good data must survive the guard.
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/ListMailQuarantine*' => Http::response([
                'Results' => [['Identity' => 'acme\\quarantine\\1', 'SenderAddress' => 'sender@example.test']],
                'Metadata' => ['QueueId' => null],
            ]),
        ]);

        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'write-client',
            'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $rows = $client->listMailQuarantine('acme.onmicrosoft.com');

        $this->assertCount(1, $rows);
        $this->assertSame('acme\\quarantine\\1', $rows[0]['Identity']);
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
            // The vendor's own success line: the licence methods now require it
            // (CippRestWriteClient::confirmLicenseWrite), so the old
            // [['ok' => true]] fixture would be refused as unconfirmed.
            'cipp.example.test/api/*' => Http::response(['Results' => ['Successfully set licenses for alex@acme.example. It may take 2–5 minutes before the changes become visible.']]),
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

    /**
     * Pins the degraded-payload contract the shared sendGet() helper is
     * responsible for: a non-array upstream body yields no rows rather than an
     * offset error. Empty is the safe answer on THIS path specifically —
     * listMailQuarantine only ever backs verifiedQuarantineRow(), which
     * requires the identity to be present and refuses the release when it is
     * not, so a degraded read fails closed instead of reading as an all-clear.
     */
    public function test_list_mail_quarantine_returns_no_rows_when_upstream_body_is_not_an_array(): void
    {
        foreach (['"unexpected-scalar"', '123', 'null'] as $payload) {
            Http::fake([
                'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
                'cipp.example.test/api/ListMailQuarantine*' => Http::response($payload, 200, ['Content-Type' => 'application/json']),
            ]);

            $this->assertSame(
                [],
                $this->emailSecurityClient()->listMailQuarantine('acme.onmicrosoft.com'),
                "Expected no rows for upstream body {$payload}"
            );
        }
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
                $this->fail('Expected CippWriteUnconfirmedException for unconfirmed Results: '.json_encode($body));
            } catch (CippWriteUnconfirmedException $e) {
                $this->assertSame(CippWriteUnconfirmedException::UNKNOWN, $e->outcome);
                $this->assertSame('api/ExecSharePointPerms', $e->endpoint);
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
                $this->fail('Expected CippWriteUnconfirmedException for body: '.json_encode($body));
            } catch (CippWriteUnconfirmedException $e) {
                $this->assertSame(CippWriteUnconfirmedException::UNKNOWN, $e->outcome);
                $this->assertSame('api/EditGroup', $e->endpoint);
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
                'confirmed' => null,
            ],
            // Partial: the edit applied but the manager step failed — the
            // error must say the profile PATCH itself already applied.
            [
                'body' => ['Results' => [
                    'Success. The user has been edited.',
                    "Failed to set alex@acme.example's manager: Resource 'boss@acme.example' does not exist.",
                ]],
                'expect' => 'manager',
                'confirmed' => 'profile edit',
            ],
            // No positive marker at all: fail closed rather than assume.
            [
                'body' => ['Results' => []],
                'expect' => 'was sent but not confirmed (unknown)',
                'confirmed' => null,
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
                $this->fail('Expected CippWriteUnconfirmedException for body: '.json_encode($case['body']));
            } catch (CippWriteUnconfirmedException $e) {
                $this->assertSame(CippWriteUnconfirmedException::UNKNOWN, $e->outcome);
                $this->assertStringContainsString($case['expect'], $e->getMessage(), json_encode($case['body']));
                $this->assertSame($case['confirmed'], $e->confirmedPart, json_encode($case['body']));
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

    /**
     * The three "did not confirm" throws are raised AFTER send() has POSTed, so
     * their operator text must claim neither applied nor not-applied. These
     * arms assert the MECHANISM (the write request left the process) alongside
     * the wording, so a message that merely reads well cannot pass while the
     * claim it makes is false.
     */
    private function denialClient(int $status, mixed $results): CippRestWriteClient
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/*' => Http::response(['Results' => $results], $status),
        ]);

        return new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1',
            'client_id' => 'write-client', 'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);
    }

    /**
     * Count only POSTs to the ONE endpoint the call under test writes to.
     *
     * The earlier version counted any request whose URL contained '/api/', which
     * a token request or a future read on the same host would also satisfy - so
     * "the write left" could have been proven by traffic that was not the write.
     * Matching the method AND the endpoint makes the counter answer the question
     * it is being asked.
     *
     * @return array{0:?CippClientException,1:int} exception and the number of write POSTs that left
     */
    private function countWritePosts(string $endpoint): int
    {
        $writes = 0;
        Http::recorded(function ($request) use (&$writes, $endpoint) {
            if ($request->method() === 'POST'
                && str_contains($request->url(), 'cipp.example.test/api/'.$endpoint)) {
                $writes++;
            }

            return true;
        });

        return $writes;
    }

    /** @return array{0:?CippClientException,1:int} exception and the number of write POSTs that left */
    private function captureDenial(string $endpoint, callable $call): array
    {
        $thrown = null;
        try {
            $call();
        } catch (CippClientException $e) {
            $thrown = $e;
        }

        return [$thrown, $this->countWritePosts($endpoint)];
    }

    /**
     * A post-send throw carries the "sent" type with outcome UNKNOWN; the
     * operator sentence is built from that by the executor (#3709).
     */
    private function assertClaimsNeitherOutcome(CippClientException $e): void
    {
        $this->assertInstanceOf(CippWriteUnconfirmedException::class, $e);
        $this->assertSame(CippWriteUnconfirmedException::UNKNOWN, $e->outcome);
        $this->assertStringNotContainsString('not applied', $e->getMessage());
    }

    public function test_group_membership_error_line_leaves_the_write_sent_and_claims_neither_outcome(): void
    {
        $client = $this->denialClient(200, ['Error - could not add member']);
        [$thrown, $writes] = $this->captureDenial('EditGroup', fn () => $client->setGroupMembership(
            'example.onmicrosoft.com', 'gid', 'Group', 'Security', 'uid', 'alex@example.test', 'add'
        ));

        $this->assertInstanceOf(CippClientException::class, $thrown);
        $this->assertSame(1, $writes, 'the write POST must have left before this throw');
        $this->assertClaimsNeitherOutcome($thrown);
    }

    public function test_group_membership_empty_results_leaves_the_write_sent_and_claims_neither_outcome(): void
    {
        // The endpoint's own documented case: a member it silently dropped
        // produces no line at all, indistinguishable from a change that landed
        // and logged nothing.
        $client = $this->denialClient(200, []);
        [$thrown, $writes] = $this->captureDenial('EditGroup', fn () => $client->setGroupMembership(
            'example.onmicrosoft.com', 'gid', 'Group', 'Security', 'uid', 'alex@example.test', 'add'
        ));

        $this->assertInstanceOf(CippClientException::class, $thrown);
        $this->assertSame(1, $writes, 'the write POST must have left before this throw');
        $this->assertClaimsNeitherOutcome($thrown);
    }

    public function test_group_membership_http_500_leaves_the_write_sent(): void
    {
        $client = $this->denialClient(500, 'upstream exploded');
        [$thrown, $writes] = $this->captureDenial('EditGroup', fn () => $client->setGroupMembership(
            'example.onmicrosoft.com', 'gid', 'Group', 'Security', 'uid', 'alex@example.test', 'add'
        ));

        $this->assertInstanceOf(\App\Services\Cipp\CippWriteHttpException::class, $thrown);
        $this->assertSame(500, $thrown->status);
        $this->assertSame(1, $writes, 'the write POST must have left before this throw');
    }

    public function test_group_membership_success_marker_returns_without_throwing(): void
    {
        // Positive control: the same wiring succeeds when upstream confirms, so
        // the arms above cannot be an artefact of the harness. It goes through
        // the SAME counter as the throwing arms - a control that measures the
        // request a different way is not a control on the measurement.
        $client = $this->denialClient(200, ['Success - member added']);
        $result = $client->setGroupMembership(
            'example.onmicrosoft.com', 'gid', 'Group', 'Security', 'uid', 'alex@example.test', 'add'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->countWritePosts('EditGroup'), 'the success arm posts exactly the same one write');
    }

    public function test_onedrive_reassignment_failure_leaves_the_write_sent_and_claims_neither_outcome(): void
    {
        $client = $this->denialClient(200, ['Failed to set permissions']);
        [$thrown, $writes] = $this->captureDenial('ExecSharePointPerms', fn () => $client->reassignOneDriveOwnership(
            'example.onmicrosoft.com', 'owner@example.test', 'successor@example.test'
        ));

        $this->assertInstanceOf(CippClientException::class, $thrown);
        $this->assertSame(1, $writes, 'the write POST must have left before this throw');
        $this->assertClaimsNeitherOutcome($thrown);
    }

    public function test_edit_user_unconfirmed_leaves_the_write_sent_and_claims_neither_outcome(): void
    {
        $client = $this->denialClient(200, ['Queued the request']);
        [$thrown, $writes] = $this->captureDenial('EditUser', fn () => $client->editUser(
            'example.onmicrosoft.com', 'uid', 'alex@example.test', ['displayName' => 'Alex Example'], [], null
        ));

        $this->assertInstanceOf(CippClientException::class, $thrown);
        $this->assertSame(1, $writes, 'the write POST must have left before this throw');
        $this->assertClaimsNeitherOutcome($thrown);
    }

    /*
     * ExecBulkLicense answers HTTP 200 whether or not it wrote (CIPP-API
     * 7c756b0d, Invoke-ExecBulkLicense.ps1 + Set-CIPPUserLicense.ps1). Each arm
     * below is one Results shape that script can produce for one user, run
     * through the REAL send() via Http::fake, for both assign and remove.
     */

    /** @return array<string, array{0: string}> */
    public static function licenseOperations(): array
    {
        return ['assign' => ['assign'], 'remove' => ['remove']];
    }

    private function licenseClient(int $status, mixed $body): CippRestWriteClient
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/*' => Http::response($body, $status),
        ]);

        return new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1',
            'client_id' => 'write-client', 'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);
    }

    /** @return array{0: ?array, 1: ?CippClientException, 2: int} result, exception, write POSTs */
    private function runLicense(string $operation, int $status, mixed $body): array
    {
        $client = $this->licenseClient($status, $body);
        $result = null;
        $thrown = null;
        try {
            $result = $operation === 'assign'
                ? $client->assignUserLicense('acme.onmicrosoft.com', 'user-123', 'sku-1')
                : $client->removeUserLicense('acme.onmicrosoft.com', 'user-123', 'sku-1');
        } catch (CippClientException $e) {
            $thrown = $e;
        }

        return [$result, $thrown, $this->countWritePosts('ExecBulkLicense')];
    }

    private function assertUnconfirmed(?CippClientException $e, string $outcome): \App\Services\Cipp\CippWriteUnconfirmedException
    {
        $this->assertInstanceOf(\App\Services\Cipp\CippWriteUnconfirmedException::class, $e);
        $this->assertSame($outcome, $e->outcome);
        $this->assertSame('api/ExecBulkLicense', $e->endpoint);

        return $e;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('licenseOperations')]
    public function test_licence_success_line_returns_success(string $operation): void
    {
        [$result, $thrown, $writes] = $this->runLicense($operation, 200, ['Results' => ['Successfully set licenses for alex@acme.example. It may take 2–5 minutes before the changes become visible.']]);

        $this->assertNull($thrown);
        $this->assertSame(['success' => true, 'status' => 200, 'no_change' => false], $result);
        $this->assertSame(1, $writes);
    }

    public function test_licence_success_after_usage_location_retry_returns_success(): void
    {
        [$result, $thrown] = $this->runLicense('assign', 200, ['Results' => ['Successfully set licenses for alex@acme.example after setting usage location. It may take 2–5 minutes before the changes become visible.']]);

        $this->assertNull($thrown);
        $this->assertTrue($result['success']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('licenseOperations')]
    public function test_licence_user_not_found_is_definitely_not_applied(string $operation): void
    {
        [, $thrown, $writes] = $this->runLicense($operation, 200, ['Results' => ['User user-123 not found in tenant acme.onmicrosoft.com']]);

        $e = $this->assertUnconfirmed($thrown, 'not_applied');
        $this->assertSame('User user-123 not found in tenant acme.onmicrosoft.com', $e->upstreamLine);
        $this->assertSame(1, $writes, 'the request left; CIPP answered that it made no write');
    }

    public function test_licence_no_valid_user_id_is_definitely_not_applied(): void
    {
        [, $thrown] = $this->runLicense('remove', 200, ['Results' => ['No valid user ID found in request for tenant acme.onmicrosoft.com']]);

        $this->assertUnconfirmed($thrown, 'not_applied');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('licenseOperations')]
    public function test_licence_failed_to_process_inner_catch_is_unknown(string $operation): void
    {
        [, $thrown] = $this->runLicense($operation, 200, ['Results' => ['Failed to process bulk license operation for tenant acme.onmicrosoft.com. Error: The operation timed out']]);

        $this->assertUnconfirmed($thrown, 'unknown');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('licenseOperations')]
    public function test_licence_failed_to_assign_graph_line_is_unknown(string $operation): void
    {
        // The line does not carry Graph's status, so a refusal and a
        // server-side timeout read the same.
        [, $thrown] = $this->runLicense($operation, 200, ['Results' => ['Failed to assign licenses for user alex@acme.example: Subscription has no available licenses']]);

        $e = $this->assertUnconfirmed($thrown, 'unknown');
        $this->assertFalse($e->usageLocationMayHaveChanged);
    }

    public function test_licence_failed_after_usage_location_is_unknown_and_flags_the_patch(): void
    {
        [, $thrown] = $this->runLicense('assign', 200, ['Results' => ['Failed to assign licenses for user alex@acme.example after setting usage location: License assignment failed']]);

        $e = $this->assertUnconfirmed($thrown, 'unknown');
        $this->assertTrue($e->usageLocationMayHaveChanged);
    }

    public function test_remove_no_changes_needed_is_a_no_change_success(): void
    {
        [$result, $thrown] = $this->runLicense('remove', 200, ['Results' => ['No license changes needed for user alex@acme.example']]);

        $this->assertNull($thrown);
        $this->assertSame(['success' => true, 'status' => 200, 'no_change' => true], $result);
    }

    public function test_assign_no_changes_needed_is_not_accepted(): void
    {
        // Add does not filter its SKU list, so this line cannot answer an
        // assign; if it ever does, it is not a success.
        [, $thrown] = $this->runLicense('assign', 200, ['Results' => ['No license changes needed for user alex@acme.example']]);

        $this->assertUnconfirmed($thrown, 'unknown');
    }

    /** @return array<string, array{0: string, 1: int, 2: mixed}> */
    public static function unconfirmedShapes(): array
    {
        $ok = 'Successfully set licenses for alex@acme.example.';

        return [
            'assign empty Results' => ['assign', 200, ['Results' => []]],
            'remove empty Results' => ['remove', 200, ['Results' => []]],
            'assign unknown line' => ['assign', 200, ['Results' => ['Queued for processing']]],
            'remove unknown line' => ['remove', 200, ['Results' => ['Queued for processing']]],
            'assign two lines' => ['assign', 200, ['Results' => [$ok, $ok]]],
            'remove two lines' => ['remove', 200, ['Results' => [$ok, 'Failed to remove licenses for user alex@acme.example: x']]],
            'assign non-array body' => ['assign', 200, 'Successfully set licenses for alex@acme.example.'],
            'remove non-array body' => ['remove', 200, 'Successfully set licenses for alex@acme.example.'],
            'assign no Results key' => ['assign', 200, ['ok' => true]],
            'remove non-string line' => ['remove', 200, ['Results' => [['ok' => true]]]],
            'assign 302 with success line' => ['assign', 302, ['Results' => [$ok]]],
            'remove 301' => ['remove', 301, ['Results' => [$ok]]],
            'assign 204' => ['assign', 204, ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unconfirmedShapes')]
    public function test_licence_unrecognised_answer_is_unknown_never_success(string $operation, int $status, mixed $body): void
    {
        [$result, $thrown, $writes] = $this->runLicense($operation, $status, $body);

        $this->assertNull($result);
        $this->assertUnconfirmed($thrown, 'unknown');
        $this->assertSame(1, $writes, 'the write POST left before the answer was read');
    }

    public function test_licence_3xx_is_not_followed(): void
    {
        // A redirect is not failed() and is not followed (allow_redirects=false),
        // so exactly one request reaches CIPP and nothing is sent to the Location.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/*' => Http::response('', 302, ['Location' => 'https://elsewhere.example.test/login']),
        ]);
        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1',
            'client_id' => 'write-client', 'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        try {
            $client->assignUserLicense('acme.onmicrosoft.com', 'user-123', 'sku-1');
            $this->fail('a 3xx must not be reported as a completed licence write');
        } catch (CippClientException $e) {
            $this->assertUnconfirmed($e, 'unknown');
            $this->assertSame('HTTP 302', $e->upstreamLine);
        }
    }

    public function test_licence_http_500_is_still_the_status_exception(): void
    {
        [, $thrown] = $this->runLicense('assign', 500, ['Results' => ['synthetic-sensitive-body']]);

        $this->assertInstanceOf(\App\Services\Cipp\CippWriteHttpException::class, $thrown);
        $this->assertNotInstanceOf(\App\Services\Cipp\CippWriteUnconfirmedException::class, $thrown);
        $this->assertStringNotContainsString('synthetic-sensitive-body', $thrown->getMessage());
    }

    public function test_unconfirmed_exception_keeps_only_a_bounded_flattened_line(): void
    {
        $long = "Failed to process bulk license operation\nfor tenant x. ".str_repeat('A', 500);
        $e = new \App\Services\Cipp\CippWriteUnconfirmedException('api/ExecBulkLicense', 'unknown', $long);

        $this->assertSame(200, mb_strlen((string) $e->upstreamLine));
        $this->assertStringNotContainsString("\n", (string) $e->upstreamLine);
        $this->assertStringNotContainsString(str_repeat('A', 300), $e->getMessage());
        $this->assertInstanceOf(CippClientException::class, $e);
    }

    /*
     * #3709: the not-sent / sent split, per converted throw and per send()
     * refusal, through the real send() under Http::fake.
     */

    /** @return array<string, array{0: string, 1: string, 2: array<int, string>}> */
    public static function postSendThrows(): array
    {
        return [
            'group membership' => ['group', 'EditGroup', ['Error - could not add member']],
            'onedrive' => ['onedrive', 'ExecSharePointPerms', ['Failed to change access for sam@example.test']],
            'edit user failed line' => ['edit', 'EditUser', ['Failed to edit user. Insufficient privileges.']],
            'edit user no marker' => ['edit', 'EditUser', ['Queued the request']],
        ];
    }

    private function callWrite(CippRestWriteClient $client, string $kind): mixed
    {
        return match ($kind) {
            'group' => $client->setGroupMembership('example.onmicrosoft.com', 'gid', 'Group', 'Security', 'uid', 'alex@example.test', 'add'),
            'onedrive' => $client->reassignOneDriveOwnership('example.onmicrosoft.com', 'owner@example.test', 'successor@example.test'),
            'edit' => $client->editUser('example.onmicrosoft.com', 'uid', 'alex@example.test', ['jobTitle' => 'Ops'], [], null),
            'license' => $client->assignUserLicense('example.onmicrosoft.com', 'uid', 'sku-1'),
        };
    }

    /** @param  array<int, string>  $results */
    #[\PHPUnit\Framework\Attributes\DataProvider('postSendThrows')]
    public function test_post_send_throw_is_unconfirmed_unknown_after_one_write(string $kind, string $endpoint, array $results): void
    {
        $client = $this->denialClient(200, $results);
        [$thrown, $writes] = $this->captureDenial($endpoint, fn () => $this->callWrite($client, $kind));

        $this->assertInstanceOf(CippWriteUnconfirmedException::class, $thrown);
        $this->assertSame(CippWriteUnconfirmedException::UNKNOWN, $thrown->outcome);
        $this->assertSame('api/'.$endpoint, $thrown->endpoint);
        $this->assertFalse($thrown->noAnswer);
        $this->assertSame(1, $writes, 'the write POST left before this throw');
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: callable|null}> */
    public static function preSendRefusals(): array
    {
        $write = ['api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1', 'client_id' => 'write-client', 'client_secret' => 'write-secret'];

        return [
            'credentials unconfigured' => [$write, 'public', 'credentials are not configured', ['client_secret' => '']],
            'api url unconfigured' => [$write, 'public', 'API URL is not configured', ['api_url' => '']],
            'host resolves private' => [$write, 'private', 'private or reserved address', []],
            'host does not resolve' => [$write, 'none', 'could not be resolved', []],
        ];
    }

    /** @param  array<string, string>  $config  @param  array<string, string>  $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('preSendRefusals')]
    public function test_pre_send_refusal_is_not_sent_and_makes_no_request(array $config, string $dns, string $expect, array $override): void
    {
        foreach (['group', 'onedrive', 'edit', 'license'] as $kind) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::preventStrayRequests();
            Http::fake();
            $client = new CippRestWriteClient(array_merge($config, $override), Cache::store(), fn (string $host): array|false => match ($dns) {
                'public' => ['93.184.216.34'],
                'private' => ['10.0.0.5'],
                'none' => false,
            });

            try {
                $this->callWrite($client, $kind);
                $this->fail("{$kind}: expected a not-sent refusal");
            } catch (CippClientException $e) {
                $this->assertInstanceOf(CippWriteNotSentException::class, $e, $kind);
                $this->assertStringContainsString($expect, $e->getMessage(), $kind);
            }
            $this->assertSame(0, Http::recorded()->count(), "{$kind}: no request of any kind may leave");
        }
    }

    public function test_oauth_failure_is_not_sent_and_no_write_leaves(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client'], 401),
            'cipp.example.test/api/*' => Http::response(['Results' => ['Success - x']]),
        ]);
        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1',
            'client_id' => 'write-client', 'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        [$thrown, $writes] = $this->captureDenial('EditGroup', fn () => $this->callWrite($client, 'group'));

        $this->assertInstanceOf(CippWriteNotSentException::class, $thrown);
        $this->assertSame(0, $writes);
    }

    public function test_write_method_input_validation_is_not_sent(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake();
        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1',
            'client_id' => 'write-client', 'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $calls = [
            fn () => $client->setGroupMembership('t', '', 'G', 'Security', 'uid', 'a@x.test', 'add'),
            fn () => $client->reassignOneDriveOwnership('t', '', 'b@x.test'),
            fn () => $client->editUser('t', 'uid', 'a@x.test', [], [], null),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail("case {$i}: expected a not-sent refusal");
            } catch (CippClientException $e) {
                $this->assertInstanceOf(CippWriteNotSentException::class, $e, "case {$i}");
            }
        }
        $this->assertSame(0, Http::recorded()->count());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function everyKind(): array
    {
        return [
            'group' => ['group', 'EditGroup'], 'onedrive' => ['onedrive', 'ExecSharePointPerms'],
            'edit' => ['edit', 'EditUser'], 'license' => ['license', 'ExecBulkLicense'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyKind')]
    public function test_connection_exception_after_send_is_unconfirmed_no_answer(string $kind, string $endpoint): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'WRITE-TOKEN', 'expires_in' => 3600]),
            'cipp.example.test/api/*' => Http::failedConnection('cURL error 28: Operation timed out for https://cipp.example.test/api/'.$endpoint),
        ]);
        $client = new CippRestWriteClient([
            'api_url' => 'https://cipp.example.test', 'tenant_id' => 'tenant-1',
            'client_id' => 'write-client', 'client_secret' => 'write-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        [$thrown, $writes] = $this->captureDenial($endpoint, fn () => $this->callWrite($client, $kind));

        $this->assertInstanceOf(CippWriteUnconfirmedException::class, $thrown);
        $this->assertSame(CippWriteUnconfirmedException::UNKNOWN, $thrown->outcome);
        $this->assertTrue($thrown->noAnswer);
        $this->assertInstanceOf(\Illuminate\Http\Client\ConnectionException::class, $thrown->getPrevious());
        $this->assertStringNotContainsString('cipp.example.test', $thrown->getMessage(), 'the transport text carries the URL');
        $this->assertSame(1, $writes, 'the request was handed to the client');
    }
}
