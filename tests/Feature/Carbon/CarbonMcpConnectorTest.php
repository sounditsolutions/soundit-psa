<?php

namespace Tests\Feature\Carbon;

use App\Models\CarbonMcpTool;
use App\Models\Setting;
use App\Models\User;
use App\Services\Carbon\CarbonMcpAuthException;
use App\Services\Carbon\CarbonMcpCatalogSyncService;
use App\Services\Carbon\CarbonMcpConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Carbon Connect MCP connection (card PEYvh4NL): discovery from Carbon's
 * recorded metadata, dynamic registration, PKCE S256, the state check, the
 * code exchange, refresh and sign-out. No request leaves the process.
 */
class CarbonMcpConnectorTest extends TestCase
{
    use FakesCarbonMcp;
    use RefreshDatabase;

    /** @var array<int, array{level: string, message: string, context: array}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->logs = [];
        Log::listen(function ($event): void {
            $this->logs[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
    }

    private function registrationFake(): array
    {
        return ['https://api.carbonsys.com/register' => Http::response([
            'client_id' => 'carbon-client-1',
            'client_secret' => self::CLIENT_SECRET_CANARY,
            'redirect_uris' => [route('auth.carbon-mcp.callback')],
        ], 201)];
    }

    public function test_connect_discovers_registers_and_redirects_with_pkce_s256_state_and_all_five_scopes(): void
    {
        Http::fake($this->metadataFakes() + $this->registrationFake());
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('auth.carbon-mcp'));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://api.carbonsys.com/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('carbon-client-1', $query['client_id']);
        $this->assertSame(route('auth.carbon-mcp.callback'), $query['redirect_uri']);
        $this->assertSame('https://api.carbonsys.com/mcp', $query['resource']);
        $this->assertSame(
            ['mcp:read', 'mcp:products:write', 'mcp:orders:draft', 'mcp:companies:write', 'mcp:customers:write'],
            explode(' ', $query['scope']),
        );

        // PKCE: the challenge is S256 of the verifier held in the session, never the verifier itself.
        $verifier = session('carbon_mcp_oauth_verifier');
        $this->assertIsString($verifier);
        $this->assertGreaterThanOrEqual(43, strlen($verifier));
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $query['code_challenge']);
        $this->assertStringNotContainsString($verifier, $location);
        $this->assertSame(session('carbon_mcp_oauth_state'), $query['state']);
        $this->assertSame(40, strlen($query['state']));

        // Registration: confidential (client_secret_post, which /revoke requires), our callback, all five scopes.
        Http::assertSent(function (HttpRequest $r): bool {
            return $r->url() === 'https://api.carbonsys.com/register'
                && $r->method() === 'POST'
                && $r['redirect_uris'] === [route('auth.carbon-mcp.callback')]
                && $r['token_endpoint_auth_method'] === 'client_secret_post'
                && $r['grant_types'] === ['authorization_code', 'refresh_token']
                && $r['scope'] === 'mcp:read mcp:products:write mcp:orders:draft mcp:companies:write mcp:customers:write';
        });
        $this->assertSame('carbon-client-1', Setting::getValue(CarbonMcpConnector::CLIENT_ID_SETTING));
        $this->assertSame(self::CLIENT_SECRET_CANARY, Setting::getEncrypted(CarbonMcpConnector::CLIENT_SECRET_SETTING));
        $this->assertStringNotContainsString(self::CLIENT_SECRET_CANARY, (string) DB::table('settings')->where('key', CarbonMcpConnector::CLIENT_SECRET_SETTING)->value('value'));
    }

    public function test_a_second_connect_reuses_the_registration(): void
    {
        Http::fake($this->metadataFakes() + $this->registrationFake());
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('auth.carbon-mcp'))->assertRedirect();
        $this->actingAs($admin)->get(route('auth.carbon-mcp'))->assertRedirect();

        Http::assertSentCount(5); // 2 x (prm + as) + ONE /register
    }

    public function test_connect_refuses_an_authorization_server_without_s256(): void
    {
        $as = self::fixtureJson('authorization-server.json');
        $as['code_challenge_methods_supported'] = ['plain'];
        Http::fake([
            'https://api.carbonsys.com/.well-known/oauth-protected-resource/mcp' => Http::response(self::fixture('protected-resource.json'), 200),
            'https://api.carbonsys.com/.well-known/oauth-authorization-server' => Http::response($as, 200),
        ]);

        $this->actingAs(User::factory()->admin()->create())->get(route('auth.carbon-mcp'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'PKCE S256'));
        Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/register'));
    }

    public function test_connect_is_admin_only(): void
    {
        Http::fake();
        $tech = User::factory()->tech()->create();
        $this->actingAs($tech)->get(route('auth.carbon-mcp'))->assertForbidden();
        $this->actingAs($tech)->get(route('auth.carbon-mcp.callback', ['state' => 'x', 'code' => 'y']))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_callback_refuses_a_state_mismatch_and_exchanges_nothing(): void
    {
        Http::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->withSession(['carbon_mcp_oauth_state' => 'expected-state', 'carbon_mcp_oauth_verifier' => str_repeat('v', 64)])
            ->get(route('auth.carbon-mcp.callback', ['state' => 'forged-state', 'code' => self::CODE]))
            ->assertStatus(400);

        Http::assertNothingSent();
        $this->assertNull(Setting::getValue(CarbonMcpConnector::REFRESH_TOKEN_SETTING));
    }

    public function test_callback_refuses_a_missing_session_state(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('auth.carbon-mcp.callback', ['state' => 'anything', 'code' => self::CODE]))
            ->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_callback_exchanges_the_code_with_the_verifier_and_stores_the_connection_encrypted(): void
    {
        $this->seedConnected();
        Setting::where('key', CarbonMcpConnector::REFRESH_TOKEN_SETTING)->delete();
        Http::fake(['https://api.carbonsys.com/token' => Http::response([
            'access_token' => self::ACCESS,
            'refresh_token' => self::REFRESH,
            'expires_in' => 3600,
            'scope' => 'mcp:read mcp:products:write mcp:orders:draft mcp:companies:write mcp:customers:write',
        ], 200)] + $this->mcpFake());
        $admin = User::factory()->admin()->create(['name' => 'Alex Example']);
        $verifier = str_repeat('a', 64);

        $this->actingAs($admin)
            ->withSession(['carbon_mcp_oauth_state' => 'st-1', 'carbon_mcp_oauth_verifier' => $verifier])
            ->get(route('auth.carbon-mcp.callback', ['state' => 'st-1', 'code' => self::CODE]))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', 'Carbon connected. 6 tools loaded.');

        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.carbonsys.com/token'
            && $r['grant_type'] === 'authorization_code'
            && $r['code'] === self::CODE
            && $r['code_verifier'] === $verifier
            && $r['client_id'] === 'carbon-client-1'
            && $r['client_secret'] === self::CLIENT_SECRET_CANARY
            && $r['redirect_uri'] === route('auth.carbon-mcp.callback'));

        $status = app(CarbonMcpConnector::class)->status();
        $this->assertSame('connected', $status['state']);
        $this->assertSame('Alex Example', $status['connected_by']);
        $this->assertCount(5, $status['scopes']);
        $this->assertSame(self::REFRESH, Setting::getEncrypted(CarbonMcpConnector::REFRESH_TOKEN_SETTING));
        $this->assertStringNotContainsString(self::REFRESH, (string) DB::table('settings')->where('key', CarbonMcpConnector::REFRESH_TOKEN_SETTING)->value('value'));
        $this->assertNoSecretInLogs();
    }

    public function test_connect_loads_the_tool_catalogue_in_the_same_request(): void
    {
        $this->seedConnected();
        Setting::where('key', CarbonMcpConnector::REFRESH_TOKEN_SETTING)->delete();
        Http::fake($this->exchangeFake() + $this->mcpFake());
        $this->assertSame(0, CarbonMcpTool::count());

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['carbon_mcp_oauth_state' => 'st-1', 'carbon_mcp_oauth_verifier' => str_repeat('a', 64)])
            ->get(route('auth.carbon-mcp.callback', ['state' => 'st-1', 'code' => self::CODE]))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', 'Carbon connected. 6 tools loaded.')
            ->assertSessionMissing('warning');

        $this->assertSame(6, CarbonMcpTool::where('active', true)->count());
        $this->assertNotNull(app(CarbonMcpConnector::class)->status()['last_sync_at']);
        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.carbonsys.com/mcp'
            && ($r->data()['method'] ?? null) === 'tools/list');
        $this->assertNoSecretInLogs();
    }

    public function test_a_failed_catalogue_load_on_connect_keeps_the_connection_and_says_to_sync(): void
    {
        $this->seedConnected();
        Setting::where('key', CarbonMcpConnector::REFRESH_TOKEN_SETTING)->delete();
        $this->carbonMcpDown = 503;
        Http::fake($this->exchangeFake() + $this->mcpFake());

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['carbon_mcp_oauth_state' => 'st-1', 'carbon_mcp_oauth_verifier' => str_repeat('a', 64)])
            ->get(route('auth.carbon-mcp.callback', ['state' => 'st-1', 'code' => self::CODE]))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('warning', 'Carbon connected, but loading the Carbon tools failed (Carbon MCP initialize failed: HTTP 503). Click Sync tools now to try again.')
            ->assertSessionMissing('success')
            ->assertSessionMissing('error');

        $this->assertTrue(app(CarbonMcpConnector::class)->isConnected());
        $this->assertSame(self::REFRESH, Setting::getEncrypted(CarbonMcpConnector::REFRESH_TOKEN_SETTING));
        $this->assertSame(0, CarbonMcpTool::count());
        $this->assertNull(app(CarbonMcpConnector::class)->status()['last_sync_at']);
        $this->assertTrue(collect($this->logs)->contains(fn (array $l): bool => $l['message'] === '[Carbon MCP OAuth] Catalogue sync after connect failed'
            && $l['context'] === ['error' => 'Carbon MCP initialize failed: HTTP 503']));
        $this->assertStringNotContainsString(self::CUSTOMER_CANARY, (string) json_encode($this->logs));
        $this->assertNoSecretInLogs();
    }

    public function test_connect_when_carbon_lists_no_tools_warns_and_keeps_the_connection(): void
    {
        $this->seedConnected();
        Setting::where('key', CarbonMcpConnector::REFRESH_TOKEN_SETTING)->delete();
        Http::fake($this->exchangeFake() + $this->singlePageMcpFake([]));

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['carbon_mcp_oauth_state' => 'st-1', 'carbon_mcp_oauth_verifier' => str_repeat('a', 64)])
            ->get(route('auth.carbon-mcp.callback', ['state' => 'st-1', 'code' => self::CODE]))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('warning', 'Carbon connected, but Carbon listed no tools. Click Sync tools now to try again.')
            ->assertSessionMissing('success')
            ->assertSessionMissing('error');

        $this->assertTrue(app(CarbonMcpConnector::class)->isConnected());
        $this->assertSame(self::REFRESH, Setting::getEncrypted(CarbonMcpConnector::REFRESH_TOKEN_SETTING));
        $this->assertSame(0, CarbonMcpTool::where('active', true)->count());
        $this->assertTrue(collect($this->logs)->contains(fn (array $l): bool => $l['level'] === 'warning'
            && $l['message'] === '[Carbon MCP OAuth] Catalogue sync after connect loaded no tools'));
        $this->assertNoSecretInLogs();
    }

    public function test_a_non_carbon_exception_on_connect_is_reported_by_class_never_by_its_message(): void
    {
        $this->seedConnected();
        Setting::where('key', CarbonMcpConnector::REFRESH_TOKEN_SETTING)->delete();
        Http::fake($this->exchangeFake());
        $canary = 'RUNTIME-MESSAGE-CANARY-'.self::CUSTOMER_CANARY;
        $this->mock(CarbonMcpCatalogSyncService::class, fn ($m) => $m->shouldReceive('sync')->once()->andThrow(new \RuntimeException($canary)));

        $response = $this->actingAs(User::factory()->admin()->create())
            ->withSession(['carbon_mcp_oauth_state' => 'st-1', 'carbon_mcp_oauth_verifier' => str_repeat('a', 64)])
            ->get(route('auth.carbon-mcp.callback', ['state' => 'st-1', 'code' => self::CODE]));

        $response->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('warning', 'Carbon connected, but loading the Carbon tools failed (RuntimeException). Click Sync tools now to try again.');
        $this->assertTrue(collect($this->logs)->contains(fn (array $l): bool => $l['message'] === '[Carbon MCP OAuth] Catalogue sync after connect failed'
            && $l['context'] === ['error' => 'RuntimeException']));
        $this->assertStringNotContainsString('RUNTIME-MESSAGE-CANARY', (string) json_encode($this->logs));
        $this->assertStringNotContainsString('RUNTIME-MESSAGE-CANARY', (string) json_encode(session()->all()));
        $this->assertTrue(app(CarbonMcpConnector::class)->isConnected());
    }

    public function test_a_failed_catalogue_load_on_connect_is_logged_at_warning_level(): void
    {
        $this->seedConnected();
        Setting::where('key', CarbonMcpConnector::REFRESH_TOKEN_SETTING)->delete();
        $this->carbonMcpDown = 503;
        Http::fake($this->exchangeFake() + $this->mcpFake());

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['carbon_mcp_oauth_state' => 'st-1', 'carbon_mcp_oauth_verifier' => str_repeat('a', 64)])
            ->get(route('auth.carbon-mcp.callback', ['state' => 'st-1', 'code' => self::CODE]))
            ->assertSessionHas('warning');

        $entries = collect($this->logs)->where('message', '[Carbon MCP OAuth] Catalogue sync after connect failed');
        $this->assertCount(1, $entries);
        $this->assertSame('warning', $entries->first()['level']);
    }

    public function test_a_reconnect_counts_the_active_tools_not_the_newly_created_ones(): void
    {
        // Two tools already stored from an earlier connection; Carbon now lists one of
        // them, so active = 1 while created = 0 (and one is deactivated).
        $this->seedConnected();
        Setting::where('key', CarbonMcpConnector::REFRESH_TOKEN_SETTING)->delete();
        foreach (['search_products_example', 'getQuoteExample'] as $upstream) {
            CarbonMcpTool::create(['local_name' => CarbonMcpCatalogSyncService::localNameFor($upstream), 'upstream_name' => $upstream, 'read_only' => true, 'sensitive' => false, 'active' => true]);
        }
        $page = self::fixtureJson('tools-list-page-1.json')['result']['tools'];
        Http::fake($this->exchangeFake() + $this->singlePageMcpFake([$page[0]]));

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['carbon_mcp_oauth_state' => 'st-1', 'carbon_mcp_oauth_verifier' => str_repeat('a', 64)])
            ->get(route('auth.carbon-mcp.callback', ['state' => 'st-1', 'code' => self::CODE]))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', 'Carbon connected. 1 tool loaded.');

        $this->assertSame(2, CarbonMcpTool::count());
        $this->assertSame(['search_products_example'], CarbonMcpTool::where('active', true)->pluck('upstream_name')->all());
    }

    /**
     * The MCP endpoint answering tools/list with ONE page holding exactly $tools
     * (synthetic; an empty array is Carbon listing no tools).
     *
     * @param  array<int, array<string, mixed>>  $tools
     */
    private function singlePageMcpFake(array $tools): array
    {
        return ['https://api.carbonsys.com/mcp' => fn (HttpRequest $r) => match ($r->data()['method'] ?? null) {
            'initialize' => Http::response(['jsonrpc' => '2.0', 'id' => $r->data()['id'], 'result' => ['protocolVersion' => '2025-06-18']], 200, ['Mcp-Session-Id' => 'sess-example-1']),
            'notifications/initialized' => Http::response('', 202),
            'tools/list' => Http::response(['jsonrpc' => '2.0', 'id' => $r->data()['id'], 'result' => ['tools' => $tools]], 200),
            default => Http::response(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32601, 'message' => 'no']], 200),
        }];
    }

    /** The token endpoint answering the authorization-code exchange. */
    private function exchangeFake(): array
    {
        return ['https://api.carbonsys.com/token' => Http::response([
            'access_token' => self::ACCESS,
            'refresh_token' => self::REFRESH,
            'expires_in' => 3600,
            'scope' => 'mcp:read mcp:products:write mcp:orders:draft mcp:companies:write mcp:customers:write',
        ], 200)];
    }

    public function test_a_refused_code_exchange_reports_the_error_code_only(): void
    {
        $this->seedConnected();
        Setting::where('key', CarbonMcpConnector::REFRESH_TOKEN_SETTING)->delete();
        Http::fake(['https://api.carbonsys.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'code '.self::CODE.' for '.self::CUSTOMER_CANARY], 400)]);

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['carbon_mcp_oauth_state' => 'st-1', 'carbon_mcp_oauth_verifier' => str_repeat('a', 64)])
            ->get(route('auth.carbon-mcp.callback', ['state' => 'st-1', 'code' => self::CODE]))
            ->assertSessionHas('error', 'Could not connect Carbon: Carbon MCP code exchange was refused: invalid_grant (HTTP 400)');

        $this->assertFalse(app(CarbonMcpConnector::class)->isConnected());
        $this->assertNoSecretInLogs();
    }

    public function test_refresh_mints_an_access_token_and_saves_a_rotated_refresh_token(): void
    {
        $this->seedConnected();
        Http::fake(['https://api.carbonsys.com/token' => Http::response(['access_token' => self::ACCESS, 'refresh_token' => self::ROTATED, 'expires_in' => 1800], 200)]);
        $connector = app(CarbonMcpConnector::class);

        $this->assertSame(self::ACCESS, $connector->accessToken());
        $this->assertSame(self::ACCESS, $connector->accessToken()); // cached: one POST

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r): bool => $r['grant_type'] === 'refresh_token'
            && $r['refresh_token'] === self::REFRESH
            && $r['client_secret'] === self::CLIENT_SECRET_CANARY
            && $r['resource'] === 'https://api.carbonsys.com/mcp');
        $this->assertSame(self::ROTATED, Setting::getEncrypted(CarbonMcpConnector::REFRESH_TOKEN_SETTING));
        $this->assertNotNull(Setting::getValue(CarbonMcpConnector::ACCESS_EXPIRES_AT));
    }

    public function test_a_failed_refresh_marks_the_panel_and_throws_the_code_only(): void
    {
        $this->seedConnected();
        Http::fake(['https://api.carbonsys.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => self::CUSTOMER_CANARY], 400)]);

        try {
            app(CarbonMcpConnector::class)->accessToken();
            $this->fail('expected CarbonMcpAuthException');
        } catch (CarbonMcpAuthException $e) {
            $this->assertSame('Carbon MCP refresh failed: invalid_grant (HTTP 400)', $e->getMessage());
        }

        $status = app(CarbonMcpConnector::class)->status();
        $this->assertSame('refresh_failed', $status['state']);
        $this->assertSame('invalid_grant (HTTP 400)', $status['error_code']);
        $this->assertNoSecretInLogs();
    }

    public function test_sign_out_revokes_at_carbon_and_clears_the_local_connection(): void
    {
        $this->seedConnected();
        Http::fake(['https://api.carbonsys.com/revoke' => Http::response('', 200)]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('auth.carbon-mcp.disconnect'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success');

        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.carbonsys.com/revoke'
            && $r['token'] === self::REFRESH
            && $r['token_type_hint'] === 'refresh_token'
            && $r['client_id'] === 'carbon-client-1'
            && $r['client_secret'] === self::CLIENT_SECRET_CANARY);
        $this->assertFalse(app(CarbonMcpConnector::class)->isConnected());
        $this->assertNull(Setting::getValue(CarbonMcpConnector::GRANTED_SCOPES));
    }

    public function test_sign_out_clears_locally_and_says_so_when_carbon_refuses_the_revocation(): void
    {
        $this->seedConnected();
        Http::fake(['https://api.carbonsys.com/revoke' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('auth.carbon-mcp.disconnect'))
            ->assertSessionHas('warning', fn (string $m): bool => str_contains($m, 'invalid_client (HTTP 401)'));

        $this->assertFalse(app(CarbonMcpConnector::class)->isConnected());
    }

    public function test_sign_out_is_admin_only(): void
    {
        $this->seedConnected();
        Http::fake();
        $this->actingAs(User::factory()->tech()->create())->post(route('auth.carbon-mcp.disconnect'))->assertForbidden();
        $this->assertTrue(app(CarbonMcpConnector::class)->isConnected());
        Http::assertNothingSent();
    }

    public function test_sign_out_with_no_stored_token_says_nothing_was_sent_to_carbon(): void
    {
        $this->seedConnected();
        Setting::where('key', CarbonMcpConnector::REFRESH_TOKEN_SETTING)->delete();
        Http::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('auth.carbon-mcp.disconnect'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success', 'Carbon signed out locally. The local connection was cleared and nothing was sent to Carbon (no_stored_token).');

        Http::assertNothingSent();
        $this->assertNull(Setting::getValue(CarbonMcpConnector::GRANTED_SCOPES));
    }

    public function test_sign_out_says_revoked_at_carbon_only_when_revoke_answered_2xx(): void
    {
        $this->seedConnected();
        Http::fake(['https://api.carbonsys.com/revoke' => Http::response('', 200)]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('auth.carbon-mcp.disconnect'))
            ->assertSessionHas('success', 'Carbon signed out. The token was revoked at Carbon and the local connection cleared.');
        Http::assertSentCount(1);
    }

    public function test_sign_out_with_no_response_from_revoke_is_a_warning_not_revoked(): void
    {
        $this->seedConnected();
        Http::fake(['https://api.carbonsys.com/revoke' => Http::failedConnection()]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('auth.carbon-mcp.disconnect'))
            ->assertSessionHas('warning', 'Carbon signed out locally. Carbon did not confirm the revocation (no response (ConnectionException)); the stored token has been deleted from PSA.')
            ->assertSessionMissing('success');
        $this->assertFalse(app(CarbonMcpConnector::class)->isConnected());
    }

    public function test_callback_error_flash_does_not_claim_nothing_was_stored(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['carbon_mcp_oauth_state' => 'st-1', 'carbon_mcp_oauth_verifier' => str_repeat('a', 64)])
            ->get(route('auth.carbon-mcp.callback', ['state' => 'st-1', 'error' => 'access_denied']))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('error', 'Carbon sign-in failed (error code: access_denied). Click Connect Carbon to try again.');
        Http::assertNothingSent();
    }

    public function test_a_code_exchange_with_no_response_says_it_may_have_been_delivered(): void
    {
        $this->seedConnected();
        Setting::where('key', CarbonMcpConnector::REFRESH_TOKEN_SETTING)->delete();
        Http::fake(['https://api.carbonsys.com/token' => Http::failedConnection()]);

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['carbon_mcp_oauth_state' => 'st-1', 'carbon_mcp_oauth_verifier' => str_repeat('a', 64)])
            ->get(route('auth.carbon-mcp.callback', ['state' => 'st-1', 'code' => self::CODE]))
            ->assertSessionHas('error', 'Could not connect Carbon: Carbon MCP code exchange got no response from Carbon (may have been delivered): ConnectionException');
        $this->assertNoSecretInLogs();
    }

    /** @return array<string, array{0: callable(array, array): array{0: array, 1: array}, 1: string}> */
    public static function discoveryRefusals(): array
    {
        return [
            'resource names another server' => [
                fn (array $prm, array $as): array => [['resource' => 'https://evil.example/mcp'] + $prm, $as],
                'names a different resource than the configured MCP URL',
            ],
            'authorization server is not https' => [
                fn (array $prm, array $as): array => [['authorization_servers' => ['http://api.carbonsys.com/']] + $prm, $as],
                'names no https authorization server',
            ],
            'issuer does not match' => [
                fn (array $prm, array $as): array => [$prm, ['issuer' => 'https://other-issuer.example/'] + $as],
                'issuer does not match the protected resource',
            ],
            'token endpoint is not https' => [
                fn (array $prm, array $as): array => [$prm, ['token_endpoint' => 'http://api.carbonsys.com/token'] + $as],
                'has no https token_endpoint',
            ],
        ];
    }

    #[DataProvider('discoveryRefusals')]
    public function test_discovery_refuses_untrusted_metadata_and_registers_nothing(callable $mutate, string $expected): void
    {
        [$prm, $as] = $mutate(self::fixtureJson('protected-resource.json'), self::fixtureJson('authorization-server.json'));
        Http::fake([
            'https://api.carbonsys.com/.well-known/oauth-protected-resource/mcp' => Http::response($prm, 200),
            'https://api.carbonsys.com/.well-known/oauth-authorization-server' => Http::response($as, 200),
            'http://api.carbonsys.com/*' => Http::response($as, 200),
            'https://other-issuer.example/*' => Http::response($as, 200),
        ]);

        $this->actingAs(User::factory()->admin()->create())->get(route('auth.carbon-mcp'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, $expected));
        Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/register'));
        $this->assertNull(Setting::getValue(CarbonMcpConnector::METADATA_SETTING));
        $this->assertNull(Setting::getValue(CarbonMcpConnector::CLIENT_ID_SETTING));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function oauthErrorCodes(): array
    {
        return [
            'rfc 6749 code kept' => ['invalid_grant', 'invalid_grant'],
            'forty characters kept' => [str_repeat('a', 40), str_repeat('a', 40)],
            'long input' => [str_repeat('a', 41), 'unknown_error'],
            'input with spaces' => ['access denied by customer', 'unknown_error'],
            'input with html' => ['<b>access_denied</b>', 'unknown_error'],
            'uppercase' => ['Invalid_Grant', 'unknown_error'],
            'empty' => ['', 'unknown_error'],
        ];
    }

    #[DataProvider('oauthErrorCodes')]
    public function test_sanitize_code_allows_only_the_rfc_6749_shape(string $input, string $expected): void
    {
        $this->assertSame($expected, CarbonMcpConnector::sanitizeCode($input));
    }

    public function test_a_vendor_error_with_html_never_reaches_the_flash_or_log(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->admin()->create())
            ->withSession(['carbon_mcp_oauth_state' => 'st-1', 'carbon_mcp_oauth_verifier' => str_repeat('a', 64)])
            ->get(route('auth.carbon-mcp.callback', ['state' => 'st-1', 'error' => '<script>x</script> '.self::CUSTOMER_CANARY]))
            ->assertSessionHas('error', 'Carbon sign-in failed (error code: unknown_error). Click Connect Carbon to try again.');
        $this->assertNoSecretInLogs();
    }

    private function assertNoSecretInLogs(): void
    {
        $dump = json_encode($this->logs);
        foreach ([self::REFRESH, self::ROTATED, self::ACCESS, self::CLIENT_SECRET_CANARY, self::CODE, self::CUSTOMER_CANARY] as $canary) {
            $this->assertStringNotContainsString($canary, (string) $dump);
        }
    }
}
