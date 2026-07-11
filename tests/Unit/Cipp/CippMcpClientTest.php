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
}
