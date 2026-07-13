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

    private function executeConditionalAccess(array $upstreamRows): array
    {
        return $this->relay($upstreamRows)->execute(
            'cipp_list_conditional_access_policies',
            [],
            new Client(['cipp_tenant_domain' => 'acme.example']),
            null,
        );
    }

    public function test_conditional_access_projects_real_flattened_cipp_shape(): void
    {
        Log::spy();

        // REAL shape verified against CIPP-API Invoke-ListConditionalAccessPolicies.ps1
        // (psa-mybo): flattened rows with GUIDs resolved to display names. The
        // include*/exclude* user/group/role fields are Out-String output — newline-
        // joined with a trailing newline; the rest are comma-joined single lines.
        $result = $this->executeConditionalAccess([[
            'id' => 'policy-1',
            'displayName' => 'Block legacy auth',
            'customer' => null,
            'Tenant' => 'acme.example',
            'createdDateTime' => '2025-11-02T10:15:00',
            'modifiedDateTime' => '2026-03-18T08:00:00',
            'state' => 'enabled',
            'clientAppTypes' => 'exchangeActiveSync,other',
            'includePlatforms' => '',
            'excludePlatforms' => '',
            'includeLocations' => 'All',
            'excludeLocations' => 'Trusted HQ Network',
            'includeApplications' => 'All',
            'excludeApplications' => '',
            'includeUserActions' => '',
            'includeAuthenticationContextClassReferences' => '',
            'includeUsers' => "All\r\n",
            'excludeUsers' => "BreakGlass Admin\r\nsvc-scanner\r\n",
            'includeGroups' => "\r\n",
            'excludeGroups' => '',
            'includeRoles' => '',
            'excludeRoles' => "Global Administrator\r\n",
            'grantControlsOperator' => 'OR',
            'builtInControls' => 'block',
            'customAuthenticationFactors' => '',
            'termsOfUse' => '',
            'rawjson' => '{"sessionControls":{"signInFrequency":{"value":1}}}',
        ]]);

        $this->assertSame(1, $result['count']);
        $this->assertSame(1, $result['total_returned_by_cipp']);
        $policy = $result['policies'][0];

        $this->assertSame('policy-1', $policy['id']);
        $this->assertSame('enabled', $policy['state']);
        $this->assertSame('2025-11-02T10:15:00', $policy['createdDateTime']);
        $this->assertStringContainsString('Block legacy auth', $policy['displayName']);

        // Enum/ID fields pass through as plain trimmed strings.
        $this->assertSame('exchangeActiveSync,other', $policy['clientAppTypes']);
        $this->assertSame('OR', $policy['grantControlsOperator']);
        $this->assertSame('block', $policy['builtInControls']);
        $this->assertSame('', $policy['includePlatforms']);

        // Resolved display names are untrusted free text — fenced when non-empty,
        // an explicit '' when empty.
        $this->assertStringContainsString('Trusted HQ Network', $policy['excludeLocations']);
        $this->assertStringContainsString('UNTRUSTED CIPP LIST CONDITIONAL ACCESS POLICIES EXCLUDELOCATIONS', $policy['excludeLocations']);
        $this->assertSame('', $policy['excludeApplications']);

        // Out-String fields split into one entry per name; whitespace-only
        // values become an explicit empty list, not a phantom entry.
        $this->assertCount(1, $policy['includeUsers']);
        $this->assertStringContainsString('All', $policy['includeUsers'][0]);
        $this->assertCount(2, $policy['excludeUsers']);
        $this->assertStringContainsString('BreakGlass Admin', $policy['excludeUsers'][0]);
        $this->assertStringContainsString('svc-scanner', $policy['excludeUsers'][1]);
        $this->assertSame([], $policy['includeGroups']);
        $this->assertSame([], $policy['excludeGroups']);
        $this->assertStringContainsString('Global Administrator', $policy['excludeRoles'][0]);

        // rawjson (full raw policy blob) and the raw Graph nested keys must
        // never appear.
        $this->assertArrayNotHasKey('rawjson', $policy);
        $this->assertArrayNotHasKey('Tenant', $policy);
        $this->assertArrayNotHasKey('customer', $policy);
        $this->assertArrayNotHasKey('conditions', $policy);
        $this->assertArrayNotHasKey('grantControls', $policy);
        $this->assertArrayNotHasKey('sessionControls', $policy);

        $this->assertStringContainsString('Session controls', $result['note']);
        $this->assertArrayNotHasKey('warning', $result);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_conditional_access_empty_result_carries_unverified_warning(): void
    {
        // CIPP's error path is overwritten before return (its catch sets
        // Forbidden, then an unconditional `if (!$Body)` resets the status to
        // OK and filters the error string out), so a failed Graph query also
        // comes back HTTP 200 with empty Results. An empty list must carry an
        // explicit "unverified" warning, never read as authoritative.
        $result = $this->executeConditionalAccess([]);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['policies']);
        $this->assertStringContainsString('Graph query fails', $result['warning']);
    }

    public function test_conditional_access_truncates_long_name_lists_explicitly(): void
    {
        $names = array_map(fn (int $i): string => "User {$i}", range(1, 27));

        $result = $this->executeConditionalAccess([[
            'id' => 'policy-1',
            'displayName' => 'Scoped policy',
            'state' => 'enabled',
            'excludeUsers' => implode("\r\n", $names)."\r\n",
        ]]);

        // Silently dropping excludeUsers entries would recreate the exact
        // blind spot this projection fixes — truncation must be explicit.
        $excluded = $result['policies'][0]['excludeUsers'];
        $this->assertCount(21, $excluded);
        $this->assertStringContainsString('User 20', $excluded[19]);
        $this->assertSame('(+7 more not shown)', $excluded[20]);
    }

    public function test_conditional_access_warns_when_targeting_fields_vanish(): void
    {
        Log::spy();

        // The insidious drift mode (psa-mybo): scalar fields still resolve so
        // the projection looks healthy, but every flattened targeting/control
        // key is gone — CA posture would be silently invisible again.
        $result = $this->executeConditionalAccess([[
            'id' => 'policy-1',
            'displayName' => 'Require MFA',
            'state' => 'enabled',
        ]]);

        $this->assertSame(1, $result['count']);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'shape drift')
                && ($context['tool'] ?? null) === 'cipp_list_conditional_access_policies'
                && ($context['row_count'] ?? null) === 1
                && ($context['first_row_keys'] ?? null) === ['id', 'displayName', 'state']);
    }
}
