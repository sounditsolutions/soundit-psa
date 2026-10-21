<?php

namespace Tests\Feature\Carbon;

use App\Models\Setting;
use App\Services\Carbon\CarbonMcpConnector;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * Recorded Carbon metadata (tests/Fixtures/carbon-mcp, real) plus synthetic
 * tools/list pages, served through Http::fake with preventStrayRequests, so no
 * test can reach Carbon. Canary strings make a leak searchable byte-for-byte.
 */
trait FakesCarbonMcp
{
    /** Http::fake keeps its first stub per URL, so later steps switch the answer here. */
    protected string $carbonFirstPage = 'tools-list-page-1.json';

    /** When set, the MCP endpoint answers with this HTTP status and a body holding a canary. */
    protected ?int $carbonMcpDown = null;

    /** The CallToolResult tools/call answers with; null is a plain text success. */
    protected ?array $carbonCallResult = null;

    protected const REFRESH = 'RT-CANARY-carbon-4e1f-refresh';

    protected const ROTATED = 'RT-CANARY-carbon-rotated-9a0c';

    protected const ACCESS = 'AT-CANARY-carbon-7b22-access';

    protected const CLIENT_SECRET_CANARY = 'CS-CANARY-carbon-55d1-client';

    protected const CODE = 'AUTHCODE-CANARY-carbon-0c3d';

    protected const CUSTOMER_CANARY = 'Example Dental Co. CANARY-CUSTOMER-8812';

    protected static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/carbon-mcp/'.$name));
    }

    /** @return array<string, mixed> */
    protected static function fixtureJson(string $name): array
    {
        return json_decode(self::fixture($name), true, flags: JSON_THROW_ON_ERROR);
    }

    /** The two metadata documents, exactly as Carbon served them. */
    protected function metadataFakes(): array
    {
        return [
            'https://api.carbonsys.com/.well-known/oauth-protected-resource/mcp' => Http::response(self::fixture('protected-resource.json'), 200, ['Content-Type' => 'application/json']),
            'https://api.carbonsys.com/.well-known/oauth-authorization-server' => Http::response(self::fixture('authorization-server.json'), 200, ['Content-Type' => 'application/json']),
        ];
    }

    /** A stored, connected Carbon sign-in, as exchangeCode() leaves it. */
    protected function seedConnected(bool $enabled = true): void
    {
        $as = self::fixtureJson('authorization-server.json');
        Setting::setValue(CarbonMcpConnector::METADATA_SETTING, json_encode([
            'issuer' => $as['issuer'],
            'resource' => 'https://api.carbonsys.com/mcp',
            'authorization_endpoint' => $as['authorization_endpoint'],
            'token_endpoint' => $as['token_endpoint'],
            'registration_endpoint' => $as['registration_endpoint'],
            'revocation_endpoint' => $as['revocation_endpoint'],
        ]));
        Setting::setValue(CarbonMcpConnector::CLIENT_ID_SETTING, 'carbon-client-1');
        Setting::setEncrypted(CarbonMcpConnector::CLIENT_SECRET_SETTING, self::CLIENT_SECRET_CANARY);
        Setting::setEncrypted(CarbonMcpConnector::REFRESH_TOKEN_SETTING, self::REFRESH);
        Setting::setValue(CarbonMcpConnector::CONNECTED_AT, '2026-10-21T17:00:00+00:00');
        Setting::setValue(CarbonMcpConnector::GRANTED_SCOPES, CarbonMcpConnector::scope());
        Setting::setValue('carbon_mcp_enabled', $enabled ? '1' : '0');
    }

    /** Token endpoint answering a refresh with ACCESS. */
    protected function tokenFake(): array
    {
        return [
            'https://api.carbonsys.com/token' => Http::response(['access_token' => self::ACCESS, 'token_type' => 'Bearer', 'expires_in' => 3600], 200),
        ];
    }

    /**
     * The MCP endpoint, dispatched on the JSON-RPC method: initialize (with a
     * session id), the notification, tools/list pages by cursor, tools/call.
     *
     * @param  array<string, mixed>|null  $callResult  the CallToolResult for tools/call
     */
    protected function mcpFake(string $firstPage = 'tools-list-page-1.json', ?array $callResult = null): array
    {
        $this->carbonFirstPage = $firstPage;
        $this->carbonCallResult = $callResult;

        return [
            'https://api.carbonsys.com/mcp' => function (HttpRequest $request) {
                if ($this->carbonMcpDown !== null) {
                    return Http::response('{"detail":"'.self::CUSTOMER_CANARY.'"}', $this->carbonMcpDown);
                }
                $firstPage = $this->carbonFirstPage;
                $method = $request->data()['method'] ?? null;

                return match ($method) {
                    'initialize' => Http::response(['jsonrpc' => '2.0', 'id' => $request->data()['id'], 'result' => [
                        'protocolVersion' => '2025-06-18', 'capabilities' => ['tools' => new \stdClass], 'serverInfo' => ['name' => 'carbon-example', 'version' => '0'],
                    ]], 200, ['Mcp-Session-Id' => 'sess-example-1']),
                    'notifications/initialized' => Http::response('', 202),
                    'tools/list' => Http::response(
                        (((array) ($request->data()['params'] ?? []))['cursor'] ?? null) === 'page-2' ? self::fixture('tools-list-page-2.json') : self::fixture($firstPage),
                        200,
                        ['Content-Type' => 'application/json'],
                    ),
                    'tools/call' => Http::response(['jsonrpc' => '2.0', 'id' => $request->data()['id'], 'result' => $this->carbonCallResult ?? [
                        'content' => [['type' => 'text', 'text' => 'Quote Q-EXAMPLE-1 for '.self::CUSTOMER_CANARY]],
                        'isError' => false,
                    ]], 200),
                    default => Http::response(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32601, 'message' => 'no']], 200),
                };
            },
        ];
    }
}
