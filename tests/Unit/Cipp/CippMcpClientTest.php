<?php

namespace Tests\Unit\Cipp;

use App\Services\Cipp\CippClientException;
use App\Services\Cipp\CippMcpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CippMcpClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_mints_mcp_client_token_posts_scoped_json_rpc_and_parses_sse_result(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'MCP-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/ExecMCP*' => Http::response(
                "event: message\n".
                'data: {"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"{\"Results\":[{\"displayName\":\"Alex Acme\",\"userPrincipalName\":\"alex@acme.example\"}]}"}]}}'."\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $client = new CippMcpClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'mcp-client-1',
            'client_secret' => 'mcp-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $result = $client->callTool('ListUsers', ['tenantFilter' => 'acme.example']);

        $this->assertSame([
            ['displayName' => 'Alex Acme', 'userPrincipalName' => 'alex@acme.example'],
        ], $result);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'login.microsoftonline.com/tenant-1/oauth2/v2.0/token')
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'mcp-client-1'
            && $request['client_secret'] === 'mcp-secret'
            && $request['scope'] === 'api://mcp-client-1/.default');

        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return str_contains($request->url(), 'cipp.example.test/api/ExecMCP')
                && ($query['tools'] ?? null) === 'ListUsers'
                && $request->hasHeader('Authorization', 'Bearer MCP-TOKEN')
                && $request['method'] === 'tools/call'
                && $request['params']['name'] === 'ListUsers'
                && $request['params']['arguments']['tenantFilter'] === 'acme.example';
        });
    }

    public function test_call_tool_throws_on_mcp_tool_error_result(): void
    {
        // CIPP's ExecMCP reports inner-endpoint failures as HTTP 200 with
        // isError:true and the message as text content. Swallowing that used to
        // surface the error message as a pseudo-row that projected to a
        // false-empty result downstream (psa-3twu).
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'MCP-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/ExecMCP*' => Http::response(
                "event: message\n".
                'data: {"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"Tool execution failed: upstream said no"}],"isError":true}}'."\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $client = new CippMcpClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'mcp-client-1',
            'client_secret' => 'mcp-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('Tool execution failed: upstream said no');

        $client->callTool('ListmailboxPermissions', ['tenantFilter' => 'acme.example', 'userId' => '11111111-1111-1111-1111-111111111111']);
    }

    public function test_call_tool_returns_no_rows_for_empty_tool_result_text(): void
    {
        // PowerShell's `@() | ConvertTo-Json` emits nothing, so an empty CIPP
        // result arrives as empty text content. That must decode to zero rows,
        // not to a pseudo-row fabricated from the {content, isError} envelope.
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'MCP-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/ExecMCP*' => Http::response(
                "event: message\n".
                'data: {"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":""}],"isError":false}}'."\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $client = new CippMcpClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'mcp-client-1',
            'client_secret' => 'mcp-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $result = $client->callTool('ListmailboxPermissions', ['tenantFilter' => 'acme.example', 'userId' => '11111111-1111-1111-1111-111111111111']);

        $this->assertSame([], $result);
    }

    public function test_lists_cipp_mcp_tools_with_json_rpc_tools_list(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'MCP-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/ExecMCP*' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'tools' => [
                        [
                            'name' => 'ListDBCache',
                            'description' => '[CIPP] List cache.',
                            'inputSchema' => ['type' => 'object', 'properties' => []],
                            'annotations' => ['readOnlyHint' => true],
                        ],
                    ],
                ],
            ]),
        ]);

        $client = new CippMcpClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'mcp-client-1',
            'client_secret' => 'mcp-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);

        $tools = $client->listTools();

        $this->assertSame('ListDBCache', $tools[0]['name']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'cipp.example.test/api/ExecMCP')
                && $request->hasHeader('Authorization', 'Bearer MCP-TOKEN')
                && $request['method'] === 'tools/list';
        });
    }

    public function test_rejects_unsafe_exec_mcp_url_before_token_request(): void
    {
        Http::fake();

        $client = new CippMcpClient([
            'api_url' => 'https://127.0.0.1',
            'tenant_id' => 'tenant-1',
            'client_id' => 'mcp-client-1',
            'client_secret' => 'mcp-secret',
        ], Cache::store());

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('CIPP API URL resolves to a private or reserved address');

        try {
            $client->callTool('ListUsers', ['tenantFilter' => 'acme.example']);
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * Build a client whose single ExecMCP tool call returns $toolResultJson as
     * its text content. $toolResultJson must be transcribed from a CIPP
     * producer, never authored from the shape this client expects (psa-7lgo).
     */
    private function clientReturningToolText(string $toolResultJson): CippMcpClient
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'MCP-TOKEN',
                'expires_in' => 3600,
            ]),
            'cipp.example.test/api/ExecMCP*' => Http::response(
                "event: message\n".
                'data: '.json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'result' => [
                        'content' => [['type' => 'text', 'text' => $toolResultJson]],
                        'isError' => false,
                    ],
                ])."\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        return new CippMcpClient([
            'api_url' => 'https://cipp.example.test',
            'tenant_id' => 'tenant-1',
            'client_id' => 'mcp-client-1',
            'client_secret' => 'mcp-secret',
        ], Cache::store(), fn (string $host): array => ['93.184.216.34']);
    }

    public function test_call_tool_throws_when_queue_fields_are_hoisted_into_metadata_and_results_blanked(): void
    {
        // CIPP-API Invoke-ListGraphRequest.ps1:176-191 — when Get-GraphRequestList
        // answers with a queue marker, the entrypoint HOISTS Queued/QueueMessage/
        // QueueId into Metadata and sets `$Results = @()`. So "still loading"
        // arrives as an empty Results array: the exact rule-3 false-clear, where a
        // confident [] means "we do not know" rather than "nothing found".
        $client = $this->clientReturningToolText(
            '{"Results":[],"Metadata":{"TenantFilter":"AllTenants","Endpoint":"users","Queued":true,'.
            '"QueueMessage":"Data still processing, please wait","QueueId":"9f2c1b7e-0d3a-4c55-9a10-2b6f8e4d1c33"}}'
        );

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('Data still processing, please wait');

        $client->callTool('ListGraphRequest', ['tenantFilter' => 'AllTenants', 'Endpoint' => 'users']);
    }

    public function test_call_tool_throws_on_queue_metadata_without_a_queued_flag(): void
    {
        // CIPP-API Invoke-ListMailQuarantine.ps1:48-51 + :95-96 — this producer
        // builds Metadata with QueueMessage + QueueId and NO Queued flag. Keying
        // the guard on `Queued` alone would sail straight past this one and hand
        // back a clean empty for "quarantine still loading".
        $client = $this->clientReturningToolText(
            '{"Results":[],"Metadata":{"QueueMessage":"Still loading data for all tenants. Please check back in a few more minutes",'.
            '"QueueId":"3d1f6a08-77bc-4e21-9f0d-5c8ab2e94411"}}'
        );

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('Still loading data for all tenants');

        $client->callTool('ListMailQuarantine', ['tenantFilter' => 'AllTenants']);
    }

    public function test_call_tool_throws_on_the_concrete_tenant_mailbox_rules_queue_payload(): void
    {
        // *** THIS IS THE ONE THAT IS NOT LATENT (psa-4k6m). ***
        // Every other queue path in this integration is reachable only for
        // TenantFilter=AllTenants, which we never send — which is why the guard's
        // docblock long said "latent, not live". Invoke-ListMailboxRules.ps1 breaks
        // that: BOTH of its queue branches fire for a CONCRETE tenant, and it reads a
        // cache with a ONE-HOUR TTL rather than calling Exchange —
        //     if ($RunningQueue -and !$Rows) { ... QueueMessage = "Still loading data for $TenantFilter..." }
        //     elseif ((!$Rows -and !$RunningQueue) -or ($TenantFilter -eq 'AllTenants' -and ...)) { ... }
        // Note the elseif's FIRST clause has no AllTenants test at all. So the first
        // call for any tenant, and any call more than an hour after the last, takes
        // the queue path with Results=[]. That is the COMMON case, not an edge case.
        //
        // Unguarded, cipp_list_tenant_mailbox_rules would answer "this tenant has no
        // malicious inbox rules" while CIPP was still loading. Payload copied from the
        // vendor's own string, tenant substituted.
        $client = $this->clientReturningToolText(
            '{"Results":[],"Metadata":{"QueueMessage":"Loading data for acme.example. Please check back in 1 minute",'.
            '"QueueId":"7a4e9c21-3b5d-4f88-a1c6-0e2d9b7f5a34"}}'
        );

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('Loading data for acme.example');

        $client->callTool('ListMailboxRules', ['tenantFilter' => 'acme.example']);
    }

    public function test_call_tool_does_not_mistake_the_healthy_mailbox_rules_branch_for_a_queue(): void
    {
        // The trap the guard's docblock already warns about, now pinned for THIS
        // endpoint too: Invoke-ListMailboxRules's healthy branch also emits Metadata
        // with a QueueId — `$Metadata = [PSCustomObject]@{ QueueId = $RunningQueue.RowKey ?? $null }`
        // — so a guard keying on QueueId rather than QueueMessage would reject real
        // rules and turn a working BEC sweep into a permanent error.
        $client = $this->clientReturningToolText(
            '{"Results":[{"Identity":"a@acme.example\\\\1698","Name":"zz","Enabled":true,"Tenant":"acme.example"}],'.
            '"Metadata":{"QueueId":null}}'
        );

        $rows = $client->callTool('ListMailboxRules', ['tenantFilter' => 'acme.example']);

        $this->assertCount(1, $rows);
        $this->assertSame('zz', $rows[0]['Name']);
    }

    public function test_call_tool_throws_on_flat_top_level_queue_payload(): void
    {
        // CIPP-API Get-GraphRequestList.ps1:259-263 (also :267-271, :317-320,
        // :344-347) — the generic producer emits the queue marker FLAT, with no
        // Results wrapper at all. Entrypoints that pass it through unhoisted
        // deliver this shape verbatim, where it would otherwise decode into a
        // single junk pseudo-row.
        $client = $this->clientReturningToolText(
            '{"QueueMessage":"Data still processing, please wait","QueueId":"7c5e2a91-4b6d-4a3f-8e12-0d9f7b3c6a55","Queued":true}'
        );

        $this->expectException(CippClientException::class);
        $this->expectExceptionMessage('Data still processing, please wait');

        $client->callTool('ListGraphRequest', ['tenantFilter' => 'AllTenants', 'Endpoint' => 'users']);
    }

    public function test_call_tool_returns_rows_when_metadata_carries_only_a_null_queue_id(): void
    {
        // CIPP-API Invoke-ListMailQuarantine.ps1:73-77 + :95-96 — the HEALTHY
        // rows-present branch still emits Metadata{QueueId}, and because
        // $RunningQueue is falsy there it serialises as null. QueueId is
        // therefore NOT a queue marker: a guard keyed on it would reject good
        // data. This is the precision test for that trap.
        $client = $this->clientReturningToolText(
            '{"Results":[{"Identity":"acme\\\\quarantine\\\\1","Subject":"Invoice overdue","Tenant":"acme.example"}],"Metadata":{"QueueId":null}}'
        );

        $rows = $client->callTool('ListMailQuarantine', ['tenantFilter' => 'acme.example']);

        $this->assertSame([
            ['Identity' => 'acme\\quarantine\\1', 'Subject' => 'Invoice overdue', 'Tenant' => 'acme.example'],
        ], $rows);
    }

    public function test_call_tool_returns_rows_when_metadata_carries_pagination_next_link(): void
    {
        // CIPP-API Invoke-ListMailQuarantine.ps1:20-25 + :95-96 — Metadata is
        // also the carrier for pagination (nextLink = the next page number), which
        // has nothing to do with queueing. A guard that fired on "Metadata exists"
        // would break every paginated read.
        $client = $this->clientReturningToolText(
            '{"Results":[{"Identity":"acme\\\\quarantine\\\\2","Subject":"Password reset","Tenant":"acme.example"}],"Metadata":{"nextLink":"2"}}'
        );

        $rows = $client->callTool('ListMailQuarantine', ['tenantFilter' => 'acme.example']);

        $this->assertSame([
            ['Identity' => 'acme\\quarantine\\2', 'Subject' => 'Password reset', 'Tenant' => 'acme.example'],
        ], $rows);
    }
}
