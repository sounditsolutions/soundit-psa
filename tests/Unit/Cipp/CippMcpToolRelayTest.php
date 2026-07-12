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
}
