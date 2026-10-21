<?php

namespace Tests\Feature\Carbon;

use App\Models\Setting;
use App\Models\User;
use App\Services\Carbon\CarbonMcpAuthException;
use App\Services\Carbon\CarbonMcpConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            'client_secret' => self::CLIENT_SECRET,
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
        $this->assertSame(self::CLIENT_SECRET, Setting::getEncrypted(CarbonMcpConnector::CLIENT_SECRET_SETTING));
        $this->assertStringNotContainsString(self::CLIENT_SECRET, (string) DB::table('settings')->where('key', CarbonMcpConnector::CLIENT_SECRET_SETTING)->value('value'));
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
        ], 200)]);
        $admin = User::factory()->admin()->create(['name' => 'Alex Example']);
        $verifier = str_repeat('a', 64);

        $this->actingAs($admin)
            ->withSession(['carbon_mcp_oauth_state' => 'st-1', 'carbon_mcp_oauth_verifier' => $verifier])
            ->get(route('auth.carbon-mcp.callback', ['state' => 'st-1', 'code' => self::CODE]))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('success');

        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.carbonsys.com/token'
            && $r['grant_type'] === 'authorization_code'
            && $r['code'] === self::CODE
            && $r['code_verifier'] === $verifier
            && $r['client_id'] === 'carbon-client-1'
            && $r['client_secret'] === self::CLIENT_SECRET
            && $r['redirect_uri'] === route('auth.carbon-mcp.callback'));

        $status = app(CarbonMcpConnector::class)->status();
        $this->assertSame('connected', $status['state']);
        $this->assertSame('Alex Example', $status['connected_by']);
        $this->assertCount(5, $status['scopes']);
        $this->assertSame(self::REFRESH, Setting::getEncrypted(CarbonMcpConnector::REFRESH_TOKEN_SETTING));
        $this->assertStringNotContainsString(self::REFRESH, (string) DB::table('settings')->where('key', CarbonMcpConnector::REFRESH_TOKEN_SETTING)->value('value'));
        $this->assertNoSecretInLogs();
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
            && $r['client_secret'] === self::CLIENT_SECRET
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
            && $r['client_secret'] === self::CLIENT_SECRET);
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

    private function assertNoSecretInLogs(): void
    {
        $dump = json_encode($this->logs);
        foreach ([self::REFRESH, self::ROTATED, self::ACCESS, self::CLIENT_SECRET, self::CODE, self::CUSTOMER_CANARY] as $canary) {
            $this->assertStringNotContainsString($canary, (string) $dump);
        }
    }
}
