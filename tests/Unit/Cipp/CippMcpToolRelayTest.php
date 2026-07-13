<?php

namespace Tests\Unit\Cipp;

use App\Models\Client;
use App\Services\Chet\ChetDataSurfaceTextSanitizer;
use App\Services\Cipp\CippMcpClient;
use App\Services\Cipp\CippMcpToolRelay;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class CippMcpToolRelayTest extends TestCase
{
    private function relay(array $upstreamRows): CippMcpToolRelay
    {
        $mcp = Mockery::mock(CippMcpClient::class);
        $mcp->shouldReceive('callTool')->once()->andReturn($upstreamRows);

        return new CippMcpToolRelay($mcp, app(ChetDataSurfaceTextSanitizer::class));
    }

    private function execute(CippMcpToolRelay $relay): array
    {
        $client = new Client(['cipp_tenant_domain' => 'acme.example']);

        return $relay->execute('cipp_list_mailbox_permissions', [
            'user_id' => '11111111-1111-1111-1111-111111111111',
        ], $client, null);
    }

    public function test_warns_when_every_upstream_row_projects_empty(): void
    {
        Log::spy();

        // Rows whose keys match nothing in DEFAULT_FIELDS — the shape-drift
        // failure that produced silent false-empty results (psa-3twu).
        $result = $this->execute($this->relay([
            ['Unexpected' => 'shape', 'AnotherKey' => 'value'],
            ['Unexpected' => 'other'],
        ]));

        $this->assertSame([[], []], $result);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'projected empty')
                && ($context['tool'] ?? null) === 'cipp_list_mailbox_permissions'
                && ($context['row_count'] ?? null) === 2
                && ($context['first_row_keys'] ?? null) === ['Unexpected', 'AnotherKey']);
    }

    public function test_does_not_warn_when_rows_project_fields(): void
    {
        Log::spy();

        $result = $this->execute($this->relay([
            ['User' => 'delegate@acme.example', 'Permissions' => 'FullAccess'],
        ]));

        $this->assertCount(1, $result);
        $this->assertStringContainsString('delegate@acme.example', $result[0]['user']);
        $this->assertSame('FullAccess', $result[0]['permissions']);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_mailboxes_projection_includes_litigation_hold(): void
    {
        // psa-zgfs: litigation-hold status is a compliance signal Chet needs
        // when triaging offboarding / eDiscovery / mailbox requests. CIPP's
        // ListMailboxes surfaces it camelCased, like the sibling forwarding
        // attributes already in the projection.
        $result = $this->relay([[
            'userPrincipalName' => 'user@acme.example',
            'primarySmtpAddress' => 'user@acme.example',
            'litigationHoldEnabled' => true,
        ]])->execute('cipp_list_mailboxes', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['litigationHoldEnabled']);
    }

    public function test_mailboxes_projection_resolves_pascalcase_litigation_hold(): void
    {
        // Exchange/Graph may surface the property PascalCased
        // (LitigationHoldEnabled); the field alias must resolve either casing
        // so the tool never silently drops the hold status (psa-3twu class of
        // shape-drift false-empties).
        $result = $this->relay([[
            'userPrincipalName' => 'user@acme.example',
            'LitigationHoldEnabled' => true,
        ]])->execute('cipp_list_mailboxes', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['litigationHoldEnabled']);
    }

    public function test_mailboxes_projection_keeps_litigation_hold_when_false(): void
    {
        // "Hold explicitly off" is a meaningful compliance signal, so a false
        // value must project — the projection guard is strict `=== null`, not
        // `empty()`, so false is kept rather than silently dropped.
        $result = $this->relay([[
            'userPrincipalName' => 'user@acme.example',
            'LitigationHoldEnabled' => false,
        ]])->execute('cipp_list_mailboxes', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('litigationHoldEnabled', $result[0]);
        $this->assertFalse($result[0]['litigationHoldEnabled']);
    }

    public function test_licenses_projection_resolves_cipp_license_overview_shape(): void
    {
        Log::spy();

        // Fixture mirrors what CIPP actually emits (Get-CIPPLicenseOverview,
        // psa-zw1j): hand-built rows with STRING counts, a License pretty name,
        // and a skuPartNumber that duplicates the pretty name — NOT raw Graph
        // subscribedSkus. The old field list asked for consumedLicenses /
        // assignedLicenses / prepaidUnits / capabilityStatus, none of which are
        // ever emitted, so seat usage silently vanished from every row.
        $result = $this->relay([[
            'Tenant' => 'acme.example',
            'License' => 'Microsoft 365 Business Premium',
            'CountUsed' => '18',
            'CountAvailable' => '2',
            'TotalLicenses' => '20',
            'skuId' => 'cbdc14ab-d96c-4c30-b9f4-6ada7cdc1d46',
            'skuPartNumber' => 'Microsoft 365 Business Premium',
            'availableUnits' => '2',
            'TermInfo' => 'Annual term, renews 2027-03-01',
            'AssignedUsers' => 'user1@acme.example, user2@acme.example',
            'AssignedGroups' => '',
            'ServicePlans' => [['servicePlanId' => '9aaf7827-d63c-4b61-89c3-182f06f82e5c', 'servicePlanName' => 'EXCHANGE_S_STANDARD']],
        ]])->execute('cipp_list_licenses', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        $row = $result[0];
        $this->assertSame('18', $row['countUsed']);
        $this->assertSame('2', $row['countAvailable']);
        $this->assertSame('20', $row['totalLicenses']);
        $this->assertSame('Microsoft 365 Business Premium', $row['license']);
        $this->assertSame('cbdc14ab-d96c-4c30-b9f4-6ada7cdc1d46', $row['skuId']);
        $this->assertSame('Annual term, renews 2027-03-01', $row['termInfo']);

        // Never-emitted legacy fields must not reappear, the misleading upstream
        // skuPartNumber (a display name) is deliberately unprojected, and the
        // bulk AssignedUsers / ServicePlans payloads stay out of the projection.
        foreach (['consumedLicenses', 'assignedLicenses', 'prepaidUnits', 'capabilityStatus', 'skuPartNumber', 'AssignedUsers', 'ServicePlans'] as $unprojected) {
            $this->assertArrayNotHasKey($unprojected, $row);
        }

        Log::shouldNotHaveReceived('warning');
    }

    public function test_user_groups_projection_resolves_renamed_cipp_shape(): void
    {
        // Fixture mirrors Invoke-ListUserGroups (psa-zw1j), which renames every
        // Graph field via Select-Object: Mail / MailEnabled / SecurityGroup /
        // GroupTypes (a comma-joined string, not an array) plus camelCase
        // groupType / calculatedGroupType. The old projection expected raw Graph
        // camelCase names, so every row collapsed to {id, displayName} and the
        // agent could not tell a security group from a distribution list.
        $result = $this->relay([
            [
                'id' => '22222222-2222-2222-2222-222222222222',
                'DisplayName' => 'All Staff',
                'MailEnabled' => true,
                'Mail' => 'allstaff@acme.example',
                'SecurityGroup' => false,
                'GroupTypes' => 'Unified',
                'OnPremisesSync' => false,
                'IsAssignableToRole' => false,
                'calculatedGroupType' => 'Microsoft 365',
                'groupType' => 'Microsoft 365',
            ],
            [
                'id' => '33333333-3333-3333-3333-333333333333',
                'DisplayName' => 'Helpdesk Admins',
                'MailEnabled' => false,
                'Mail' => null,
                'SecurityGroup' => true,
                'GroupTypes' => '',
                'OnPremisesSync' => true,
                'IsAssignableToRole' => true,
                'calculatedGroupType' => 'Security',
                'groupType' => 'Security',
            ],
        ])->execute('cipp_list_user_groups', [
            'user_id' => '11111111-1111-1111-1111-111111111111',
        ], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(2, $result);
        [$m365Group, $securityGroup] = $result;

        $this->assertStringContainsString('All Staff', $m365Group['displayName']);
        $this->assertSame('allstaff@acme.example', $m365Group['mail']);
        $this->assertTrue($m365Group['mailEnabled']);
        // SecurityGroup=false must project as securityEnabled=false — a false
        // discriminator is signal, not absence.
        $this->assertFalse($m365Group['securityEnabled']);
        $this->assertSame('Unified', $m365Group['groupTypes']);
        $this->assertSame('Microsoft 365', $m365Group['calculatedGroupType']);
        $this->assertFalse($m365Group['onPremisesSync']);

        $this->assertTrue($securityGroup['securityEnabled']);
        $this->assertTrue($securityGroup['isAssignableToRole']);
        $this->assertTrue($securityGroup['onPremisesSync']);
        $this->assertSame('Security', $securityGroup['calculatedGroupType']);
        // Invoke-ListUserGroups never emits description under any casing, and a
        // null Mail drops rather than projecting as an empty value.
        $this->assertArrayNotHasKey('description', $securityGroup);
        $this->assertArrayNotHasKey('mail', $securityGroup);
    }

    public function test_message_trace_projection_resolves_pascalcase_to_ip(): void
    {
        // Get-MessageTrace responses carry PascalCase ToIP; the old default list
        // used the request-parameter casing (toIP), so the destination IP was
        // silently dropped from every row (psa-zw1j). FromIP was already right.
        $result = $this->relay([[
            'MessageTraceId' => 'aaaabbbb-cccc-dddd-eeee-ffff00001111',
            'Received' => '2026-07-12T09:30:00',
            'SenderAddress' => 'billing@remote.example',
            'RecipientAddress' => 'user@acme.example',
            'Subject' => 'Your invoice',
            'Status' => 'Delivered',
            'FromIP' => '203.0.113.10',
            'ToIP' => '198.51.100.20',
        ]])->execute('cipp_list_message_trace', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertSame(1, $result['count']);
        $message = $result['messages'][0];
        $this->assertSame('198.51.100.20', $message['ToIP']);
        $this->assertSame('203.0.113.10', $message['FromIP']);
        $this->assertSame('Delivered', $message['Status']);
    }

    public function test_mail_quarantine_projection_carries_quarantine_reason_and_expiry(): void
    {
        // Get-QuarantineMessage rows carry the quarantine reason in Type and the
        // expiry in Expires. The old default list asked for QuarantineTypes (a
        // request parameter that never appears as a row key) and lowercase
        // expires, so the relay had NO field saying why a message was held
        // (psa-zw1j). PolicyName / MessageId / Size were present but unprojected.
        $result = $this->relay([[
            'Identity' => '4c60d138-8a2f-4b0e-9f01-aa11bb22cc33\\9c8fd2ee-1234-4a5b-8c6d-ee77ff88aa99',
            'ReceivedTime' => '2026-07-11T16:42:00',
            'SenderAddress' => 'suspicious@remote.example',
            'RecipientAddress' => 'user@acme.example',
            'Subject' => 'Reset your password now',
            'Type' => 'HighConfPhish',
            'PolicyName' => 'Default Anti-Phishing Policy',
            'MessageId' => '<20260711164200.ABC123@remote.example>',
            'Size' => 44211,
            'ReleaseStatus' => 'NOTRELEASED',
            'Expires' => '2026-07-26T16:42:00',
            'Direction' => 'Inbound',
        ]])->execute('cipp_list_mail_quarantine', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertSame(1, $result['count']);
        $entry = $result['entries'][0];
        $this->assertSame('HighConfPhish', $entry['Type']);
        $this->assertSame('2026-07-26T16:42:00', $entry['Expires']);
        $this->assertSame(44211, $entry['Size']);
        $this->assertSame('NOTRELEASED', $entry['ReleaseStatus']);
        // Admin-chosen policy names and sender-controlled Message-ID headers
        // arrive prompt-fenced but with the original value intact.
        $this->assertStringContainsString('Default Anti-Phishing Policy', $entry['PolicyName']);
        $this->assertStringContainsString('20260711164200.ABC123@remote.example', $entry['MessageId']);
        $this->assertArrayNotHasKey('QuarantineTypes', $entry);
    }

    public function test_users_projection_carries_lic_joined_license_names(): void
    {
        // Graph assignedLicenses entries are {skuId, disabledPlans} — there is
        // no skuPartNumber, so the summarizer can never produce friendly names
        // from them. CIPP has already resolved the names into top-level
        // LicJoined; projecting it gives the agent products, not GUIDs (psa-zw1j).
        $result = $this->relay([[
            'id' => '44444444-4444-4444-4444-444444444444',
            'displayName' => 'Pat Example',
            'userPrincipalName' => 'pat@acme.example',
            'accountEnabled' => true,
            'assignedLicenses' => [['skuId' => 'cbdc14ab-d96c-4c30-b9f4-6ada7cdc1d46', 'disabledPlans' => []]],
            'LicJoined' => 'Microsoft 365 Business Premium, Microsoft Teams Phone Standard',
        ]])->execute('cipp_list_users', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        $user = $result[0];
        $this->assertSame('Microsoft 365 Business Premium, Microsoft Teams Phone Standard', $user['licJoined']);
        $this->assertSame(1, $user['assignedLicenses']['count']);
        $this->assertSame(['cbdc14ab-d96c-4c30-b9f4-6ada7cdc1d46'], $user['assignedLicenses']['skuIds']);
    }

    public function test_devices_projection_drops_fields_absent_from_managed_device(): void
    {
        // Graph managedDevice has deviceName / complianceState but no
        // displayName or isCompliant — those two were dead weight in the
        // default list (benign: fully covered by their resolving siblings) and
        // are dropped from the projection rather than kept as permanently
        // absent keys (psa-zw1j).
        $result = $this->relay([[
            'id' => '55555555-5555-5555-5555-555555555555',
            'deviceName' => 'ACME-LT-042',
            'userPrincipalName' => 'pat@acme.example',
            'operatingSystem' => 'Windows',
            'osVersion' => '10.0.26100.1000',
            'complianceState' => 'compliant',
            'managementAgent' => 'mdm',
            'enrolledDateTime' => '2025-11-02T10:00:00Z',
            'lastSyncDateTime' => '2026-07-12T22:15:00Z',
            'serialNumber' => 'SN0042',
        ]])->execute('cipp_list_devices', [], new Client(['cipp_tenant_domain' => 'acme.example']), null);

        $this->assertCount(1, $result);
        $device = $result[0];
        $this->assertSame('compliant', $device['complianceState']);
        $this->assertStringContainsString('ACME-LT-042', $device['deviceName']);
        $this->assertSame('SN0042', $device['serialNumber']);
        $this->assertArrayNotHasKey('displayName', $device);
        $this->assertArrayNotHasKey('isCompliant', $device);
    }
}
