<?php

namespace Tests\Feature\Tactical;

use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\PortalInstallAudit;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\User;
use App\Services\Level\LevelClient;
use App\Services\Portal\InstallerInfo;
use App\Services\Tactical\TacticalClient;
use App\Support\McpConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * #841 — the installer tools used to hand out TRMM's bare agent binary while telling
 * the user it would register itself. TRMM returns {cmd, url} for installMethod
 * manual/mac; "url" is get_agent_url() (the binary, which does nothing on its own)
 * and every install flag lives in "cmd", which was discarded.
 *
 * These tests pin the three halves of the fix:
 *   1. TacticalClient surfaces "cmd" as InstallerInfo::$installScript and stops
 *      promising self-registration — including when there is no usable command.
 *   2. The MCP tools return the command under the same never-audited contract as
 *      the signed URL (it carries a live --auth enrolment token).
 *   3. The portal's ?download=1 auto-redirect no longer bounces the user past the
 *      page that carries the command.
 */
class TacticalInstallerCommandTest extends TestCase
{
    use RefreshDatabase;

    /** The real shape, abridged: silent install, wait, then register with --auth. */
    private const WINDOWS_CMD = 'tacticalagent-v2.4.9-windows-amd64.exe /VERYSILENT /SUPPRESSMSGBOXES '
        .'&& ping 127.0.0.1 -n 7 && "C:\\Program Files\\TacticalAgent\\tacticalrmm.exe" -m install '
        .'--api https://tactical.example.test --client-id 3 --site-id 5 --agent-type workstation '
        .'--auth 3f9c1e7b-enrolment-token';

    private const MAC_CMD = "curl -L -o tacticalagent-v2.4.9-darwin-amd64 'https://downloads.example.test/agent'"
        .' && chmod +x tacticalagent-v2.4.9-darwin-amd64 && sudo ./tacticalagent-v2.4.9-darwin-amd64 -m install'
        .' --api https://tactical.example.test --client-id 3 --site-id 5 --agent-type workstation'
        .' --auth 3f9c1e7b-enrolment-token';

    private const DOWNLOAD_URL = 'https://downloads.example.test/agent.exe?token=signed-secret';

    /** Level's composed account credential — static and permanently valid. */
    private const LEVEL_KEY = 'level-account-token-secret:42';

    private function configureTactical(): void
    {
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'secret');
    }

    /**
     * TacticalClient over a mock transport. getInstallerInfo() makes two calls:
     * getClients(), then POST agents/installer/.
     *
     * @param  array<string, mixed>|null  $installerPayload
     */
    private function clientReturning(?array $installerPayload): TacticalClient
    {
        $clients = [[
            'id' => 3,
            'name' => 'Acme',
            'sites' => [['id' => 5, 'name' => 'Main']],
        ]];

        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode($clients)),
            new Response(200, [], json_encode($installerPayload ?? [])),
        ]));

        return new TacticalClient(new GuzzleClient([
            'base_uri' => 'https://tactical.example.test/',
            'handler' => $stack,
            'allow_redirects' => false,
            'headers' => ['X-API-KEY' => 'secret', 'Accept' => 'application/json'],
        ]));
    }

    // ── 1. TacticalClient ────────────────────────────────────────────────────

    public function test_windows_installer_returns_the_registering_command_as_a_script(): void
    {
        $this->configureTactical();

        $info = $this->clientReturning(['cmd' => self::WINDOWS_CMD, 'url' => self::DOWNLOAD_URL])
            ->getInstallerInfo('Acme|Main', 'windows');

        $this->assertNotNull($info);
        $this->assertTrue($info->hasScript(), 'the cmd TRMM returned must not be discarded');
        $this->assertSame(self::WINDOWS_CMD, $info->installScript);
        $this->assertSame(self::DOWNLOAD_URL, $info->downloadUrl);
    }

    public function test_mac_installer_returns_its_self_contained_command(): void
    {
        $this->configureTactical();

        $info = $this->clientReturning(['cmd' => self::MAC_CMD, 'url' => self::DOWNLOAD_URL])
            ->getInstallerInfo('Acme|Main', 'mac');

        $this->assertNotNull($info);
        $this->assertSame(self::MAC_CMD, $info->installScript);
    }

    public function test_instructions_no_longer_promise_that_the_download_self_registers(): void
    {
        $this->configureTactical();

        $info = $this->clientReturning(['cmd' => self::WINDOWS_CMD, 'url' => self::DOWNLOAD_URL])
            ->getInstallerInfo('Acme|Main', 'windows');

        $this->assertNotNull($info);
        $instructions = (string) $info->instructions;

        // The exact promise that sent a non-installing binary to a customer.
        $this->assertStringNotContainsString('automatically register', $instructions);
        // ...and it must say so, not merely omit it.
        $this->assertStringContainsString('does not register', $instructions);
        $this->assertStringContainsString('command', $instructions);
    }

    public function test_windows_instructions_state_the_command_needs_the_downloaded_file(): void
    {
        $this->configureTactical();

        $info = $this->clientReturning(['cmd' => self::WINDOWS_CMD, 'url' => self::DOWNLOAD_URL])
            ->getInstallerInfo('Acme|Main', 'windows');

        $this->assertNotNull($info);
        // The windows cmd runs the file from the working directory, so the download
        // is genuinely step one of two and the wording has to place it.
        $this->assertStringContainsString('folder', (string) $info->instructions);
    }

    /**
     * @dataProvider unusableCommands
     */
    public function test_an_unusable_command_is_dropped_and_the_wording_degrades_honestly(mixed $cmd): void
    {
        $this->configureTactical();

        $info = $this->clientReturning(['cmd' => $cmd, 'url' => self::DOWNLOAD_URL])
            ->getInstallerInfo('Acme|Main', 'windows');

        $this->assertNotNull($info, 'a bad cmd must not cost us the download URL');
        $this->assertFalse($info->hasScript());
        $this->assertNull($info->installScript);
        $this->assertStringNotContainsString('automatically register', (string) $info->instructions);
        $this->assertStringContainsString('does not register', (string) $info->instructions);
    }

    /** @return array<string, array{0: mixed}> */
    public static function unusableCommands(): array
    {
        return [
            'absent' => [null],
            'blank' => ['   '],
            'not a string' => [['cmd']],
            'integer' => [7],
            // A newline would split the copy-paste block into several commands,
            // the first of which silently half-installs.
            'embedded newline' => ["agent.exe /VERYSILENT\r\nshutdown /r"],
            'embedded null byte' => ["agent.exe /VERYSILENT\x00 -m install"],
            'implausibly long' => [str_repeat('a', 4001)],
        ];
    }

    public function test_a_missing_url_still_yields_nothing_even_when_a_command_is_present(): void
    {
        $this->configureTactical();

        $info = $this->clientReturning(['cmd' => self::WINDOWS_CMD])
            ->getInstallerInfo('Acme|Main', 'windows');

        $this->assertNull($info);
    }

    /** TacticalClient over a mock transport queued with ONLY the clients/ listing. */
    private function availabilityOnlyClient(): TacticalClient
    {
        $clients = [[
            'id' => 3,
            'name' => 'Acme',
            'sites' => [['id' => 5, 'name' => 'Main']],
        ]];

        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode($clients)),
        ]));

        return new TacticalClient(new GuzzleClient([
            'base_uri' => 'https://tactical.example.test/',
            'handler' => $stack,
            'allow_redirects' => false,
            'headers' => ['X-API-KEY' => 'secret', 'Accept' => 'application/json'],
        ]));
    }

    public function test_supports_install_answers_from_the_read_only_listing_alone(): void
    {
        $this->configureTactical();

        // The transport holds a single queued response — the clients/ GET. If
        // supportsInstall reached for the minting POST the empty queue would
        // throw, so a true here proves the check is non-minting (#857).
        $this->assertTrue($this->availabilityOnlyClient()->supportsInstall('Acme|Main', 'windows'));
    }

    public function test_supports_install_refuses_an_unknown_site_without_minting(): void
    {
        $this->configureTactical();

        $this->assertFalse($this->availabilityOnlyClient()->supportsInstall('Acme|Warehouse', 'windows'));
    }

    // ── 2. MCP surface ───────────────────────────────────────────────────────

    private function callTool(string $token, string $name, array $arguments = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    /** @return array<string, mixed> */
    private function decodedInstallerResult(string $tool = 'tactical_get_or_create_installer'): array
    {
        $this->configureTactical();
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getInstallerInfo')
            ->once()
            ->with('Acme|Main', 'windows')
            ->andReturn(new InstallerInfo(
                downloadUrl: self::DOWNLOAD_URL,
                installScript: self::WINDOWS_CMD,
                instructions: 'Two steps: download the installer below, then run the command.',
            ));
        $this->app->instance(TacticalClient::class, $tactical);

        $response = $this->callTool(McpConfig::rotateStaffToken(allowedTools: [$tool], label: 'opsbot'), $tool, [
            'client_id' => $client->id,
            'platform' => 'windows',
            'reason' => 'Generate a short-lived onboarding installer link.',
        ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true);
    }

    public function test_installer_tool_returns_the_install_command_beside_the_url(): void
    {
        $result = $this->decodedInstallerResult();

        $this->assertSame(self::DOWNLOAD_URL, $result['download_url']);
        $this->assertSame(self::WINDOWS_CMD, $result['install_command']);
        $this->assertTrue($result['requires_install_command']);
        $this->assertStringContainsString('does NOT register', $result['message']);
    }

    public function test_the_generate_alias_returns_the_command_too(): void
    {
        $result = $this->decodedInstallerResult('tactical_generate_installer');

        $this->assertSame(self::WINDOWS_CMD, $result['install_command']);
    }

    public function test_the_install_command_and_its_enrolment_token_are_never_audited(): void
    {
        $this->decodedInstallerResult();

        // Whole rows, not selected columns: a leak into a column this test did not
        // think to name is exactly the leak worth catching.
        $auditJson = json_encode([
            TechnicianActionLog::all()->toArray(),
            McpAuditLog::all()->toArray(),
        ]);

        $this->assertStringNotContainsString('3f9c1e7b-enrolment-token', (string) $auditJson);
        $this->assertStringNotContainsString('-m install', (string) $auditJson);
        $this->assertStringNotContainsString('signed-secret', (string) $auditJson);
    }

    public function test_tool_descriptions_warn_that_the_url_alone_does_not_register(): void
    {
        $this->configureTactical();
        $token = McpConfig::rotateStaffToken(
            allowedTools: ['tactical_get_or_create_installer', 'tactical_generate_installer'],
            label: 'opsbot',
        );

        $listed = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []])
            ->json('result.tools');

        $byName = collect($listed)->keyBy('name');

        foreach (['tactical_get_or_create_installer', 'tactical_generate_installer'] as $name) {
            $this->assertArrayHasKey($name, $byName);
            $this->assertStringContainsString('does NOT register', $byName[$name]['description']);
            // The contract the pre-#841 description already carried must survive.
            $this->assertStringContainsString('not retained in audits', $byName[$name]['description']);
        }
    }

    // ── 3. Portal — #857 mint gate, #864 expiry, #860 signed download ────────

    /**
     * Client + Mockery TacticalClient bound into the container. supportsInstall
     * answers true for every platform by default (the availability read);
     * getInstallerInfo expectations — the MINT — are each test's to declare.
     *
     * @return array{0: Client, 1: Mockery\MockInterface&TacticalClient}
     */
    private function portalClient(array $attributes = []): array
    {
        $this->configureTactical();

        $client = Client::factory()->create(array_merge([
            'name' => 'Acme',
            'tactical_site_id' => 'Acme|Main',
            'portal_install_token' => 'abcdef0123456789abcdef',
            'portal_primary_rmm' => 'tactical',
        ], $attributes));

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('supportsInstall')->andReturn(true)->byDefault();
        $this->app->instance(TacticalClient::class, $tactical);

        return [$client, $tactical];
    }

    private function mintedInfo(?string $cmd): InstallerInfo
    {
        return new InstallerInfo(
            downloadUrl: self::DOWNLOAD_URL,
            installScript: $cmd,
            instructions: 'Two steps.',
        );
    }

    private function signedDownloadUrl(Client $client, string $platform): string
    {
        return URL::temporarySignedRoute('portal.install.download', now()->addHour(), [
            'token' => $client->portal_install_token,
            'platform' => $platform,
        ]);
    }

    public function test_the_bare_landing_page_never_mints_an_enrolment_credential(): void
    {
        [$client, $tactical] = $this->portalClient();
        // #857: before this fix a single unauthenticated GET minted up to three
        // live enrolment tokens upstream — one per platform panel.
        $tactical->shouldReceive('getInstallerInfo')->never();

        $response = $this->get('/setup/'.$client->portal_install_token);

        $response->assertOk();
        $response->assertDontSee(self::WINDOWS_CMD, escape: true);
        $response->assertDontSee('--auth');
        $response->assertSee('Show my install command');
        // The honest two-step framing from #841 survives the gate: the page
        // still explains that the download alone does not register the device.
        $response->assertSee('does not register your device');
    }

    public function test_the_download_shortcut_no_longer_redirects_off_the_bare_page(): void
    {
        [$client, $tactical] = $this->portalClient();
        $tactical->shouldReceive('getInstallerInfo')->never();

        // Pre-#857 behaviour minted and 302'd on ?download=1 for script-less
        // installers; the parameter is now inert because answering it honestly
        // would require a mint on a bare GET.
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
            ->get('/setup/'.$client->portal_install_token.'?download=1')
            ->assertOk();
    }

    public function test_the_command_post_is_the_only_mint_point_and_mints_one_platform(): void
    {
        [$client, $tactical] = $this->portalClient();
        $tactical->shouldReceive('getInstallerInfo')
            ->once()
            ->with('Acme|Main', 'windows')
            ->andReturn($this->mintedInfo(self::WINDOWS_CMD));

        $response = $this->post('/setup/'.$client->portal_install_token.'/command', [
            'platform' => 'windows',
        ]);

        $response->assertOk();
        $response->assertSee(self::WINDOWS_CMD, escape: true);
        // The credential-bearing response inherits #841's no-store contract.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_unsupported_platform_value_is_bounced_without_minting(): void
    {
        [$client, $tactical] = $this->portalClient();
        $tactical->shouldReceive('getInstallerInfo')->never();

        $this->post('/setup/'.$client->portal_install_token.'/command', ['platform' => 'zorg'])
            ->assertRedirect(route('portal.install.show', ['token' => $client->portal_install_token]));
    }

    public function test_the_mint_is_audited_as_a_fact_never_as_the_credential(): void
    {
        [$client, $tactical] = $this->portalClient();
        $tactical->shouldReceive('getInstallerInfo')->andReturn($this->mintedInfo(self::WINDOWS_CMD));

        $this->post('/setup/'.$client->portal_install_token.'/command', ['platform' => 'windows'])
            ->assertOk();

        $this->assertDatabaseHas('portal_install_audits', [
            'client_id' => $client->id,
            'rmm' => 'tactical',
            'platform' => 'windows',
        ]);

        // Whole rows, not selected columns — same shape as the MCP audit test.
        $auditJson = (string) json_encode(PortalInstallAudit::all()->toArray());
        $this->assertStringNotContainsString('3f9c1e7b-enrolment-token', $auditJson);
        $this->assertStringNotContainsString('-m install', $auditJson);
        $this->assertStringNotContainsString('signed-secret', $auditJson);
    }

    public function test_the_landing_page_is_still_not_cacheable(): void
    {
        [$client, $tactical] = $this->portalClient();
        $tactical->shouldReceive('getInstallerInfo')->never();

        // No credential and no mint-on-access signed links on the bare page any
        // more, but it still names the client and its RMM, so no-store stays.
        $response = $this->get('/setup/'.$client->portal_install_token);

        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_windows_instructions_do_not_send_the_user_to_powershell(): void
    {
        $this->configureTactical();

        $info = $this->clientReturning(['cmd' => self::WINDOWS_CMD, 'url' => self::DOWNLOAD_URL])
            ->getInstallerInfo('Acme|Main', 'windows');

        $this->assertNotNull($info);
        // The command is cmd.exe syntax; Windows PowerShell 5.1 rejects `&&`.
        $this->assertStringContainsString('Command Prompt', (string) $info->instructions);
        $this->assertStringContainsString('not PowerShell', (string) $info->instructions);
    }

    // ── 3a. #860 — download only through a signed URL ────────────────────────

    public function test_a_constructed_download_url_fails_the_signature_check(): void
    {
        [$client, $tactical] = $this->portalClient();
        $tactical->shouldReceive('getInstallerInfo')->never();

        // Pre-#860 this bare GET redirected straight to the vendor binary —
        // token + platform were the only inputs, both guessable from a leaked
        // page URL.
        $this->get('/setup/'.$client->portal_install_token.'/download?platform=windows')
            ->assertForbidden();
    }

    public function test_a_signed_download_url_redirects_and_audits_the_mint(): void
    {
        [$client, $tactical] = $this->portalClient();
        $tactical->shouldReceive('getInstallerInfo')
            ->once()
            ->with('Acme|Main', 'windows')
            ->andReturn($this->mintedInfo(self::WINDOWS_CMD));

        $this->get($this->signedDownloadUrl($client, 'windows'))
            ->assertRedirect(self::DOWNLOAD_URL);

        // For Tactical the vendor URL is itself minted, so the download is a
        // mint and gets its audit row too.
        $this->assertDatabaseHas('portal_install_audits', [
            'client_id' => $client->id,
            'platform' => 'windows',
        ]);
    }

    public function test_the_route_guards_are_wired(): void
    {
        $routes = $this->app['router']->getRoutes();

        $this->assertContains('throttle:5,1', $routes->getByName('portal.install.command')->gatherMiddleware());
        $this->assertContains('signed', $routes->getByName('portal.install.download')->gatherMiddleware());
    }

    // ── 3b. #864 — the link itself expires ───────────────────────────────────

    public function test_an_expired_link_is_indistinguishable_from_an_invalid_one(): void
    {
        [$client, $tactical] = $this->portalClient([
            'portal_install_token_expires_at' => now()->subDay(),
        ]);
        $tactical->shouldReceive('getInstallerInfo')->never();

        $this->get('/setup/'.$client->portal_install_token)
            ->assertOk()
            ->assertSee('This setup link is not valid');

        $this->post('/setup/'.$client->portal_install_token.'/command', ['platform' => 'windows'])
            ->assertOk()
            ->assertSee('This setup link is not valid');

        // Even a still-valid signature does not outlive the token it points at.
        $this->get($this->signedDownloadUrl($client, 'windows'))
            ->assertRedirect(route('portal.install.show', ['token' => $client->portal_install_token]));
    }

    public function test_a_future_expiry_and_a_null_expiry_both_admit(): void
    {
        [, $tactical] = $this->portalClient(['portal_install_token_expires_at' => now()->addDay()]);
        $tactical->shouldReceive('getInstallerInfo')->never();
        $this->get('/setup/abcdef0123456789abcdef')->assertOk()->assertSee('Show my install command');

        // NULL is the deliberate per-row "no expiry" exception, set by hand on
        // request — never the default for a new or backfilled link.
        Client::where('portal_install_token', 'abcdef0123456789abcdef')
            ->update(['portal_install_token_expires_at' => null]);
        $this->get('/setup/abcdef0123456789abcdef')->assertOk()->assertSee('Show my install command');
    }

    public function test_generating_a_link_stamps_the_default_thirty_day_expiry(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);

        $this->actingAs($user)->post(route('clients.install-link.generate', $client))->assertRedirect();

        $client->refresh();
        $this->assertNotNull($client->portal_install_token);
        $this->assertNotNull($client->portal_install_token_expires_at);
        $this->assertTrue($client->portal_install_token_expires_at->between(
            now()->addDays(30)->subMinutes(5),
            now()->addDays(30)->addMinutes(5),
        ));
    }

    public function test_the_expiry_ttl_is_operator_tunable(): void
    {
        Setting::setValue('portal_install_token_ttl_days', '7');
        $user = User::factory()->create();
        $client = Client::factory()->create(['name' => 'Acme', 'tactical_site_id' => 'Acme|Main']);

        $this->actingAs($user)->post(route('clients.install-link.generate', $client))->assertRedirect();

        $this->assertTrue($client->refresh()->portal_install_token_expires_at->between(
            now()->addDays(7)->subMinutes(5),
            now()->addDays(7)->addMinutes(5),
        ));
    }

    public function test_rotating_a_link_restamps_its_expiry(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create([
            'name' => 'Acme',
            'tactical_site_id' => 'Acme|Main',
            'portal_install_token' => 'aboutToRotate0123456789',
            'portal_install_token_expires_at' => now()->addDay(),
        ]);

        $this->actingAs($user)->post(route('clients.install-link.rotate', $client))->assertRedirect();

        $client->refresh();
        $this->assertNotSame('aboutToRotate0123456789', $client->portal_install_token);
        $this->assertTrue($client->portal_install_token_expires_at->between(
            now()->addDays(30)->subMinutes(5),
            now()->addDays(30)->addMinutes(5),
        ));
    }

    public function test_disabling_a_link_clears_the_expiry_with_the_token(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create([
            'name' => 'Acme',
            'tactical_site_id' => 'Acme|Main',
            'portal_install_token' => 'aboutToDisable123456789',
            'portal_install_token_expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($user)->post(route('clients.install-link.disable', $client))->assertRedirect();

        $client->refresh();
        $this->assertNull($client->portal_install_token);
        $this->assertNull($client->portal_install_token_expires_at);
    }

    // ── 3c. Level — the static account key stays behind the same gate ────────

    /** @return array{0: Client, 1: Mockery\MockInterface&LevelClient} */
    private function levelPortalClient(): array
    {
        $client = Client::factory()->create([
            'name' => 'Acme',
            'level_group_id' => 'R3JvdXA6NDI=',
            'portal_install_token' => 'abcdef0123456789abcdef',
            'portal_primary_rmm' => 'level',
        ]);

        $level = Mockery::mock(LevelClient::class);
        $level->shouldReceive('supportsInstall')
            ->andReturnUsing(fn ($groupId, $platform) => $platform === 'windows');
        $this->app->instance(LevelClient::class, $level);

        return [$client, $level];
    }

    public function test_levels_permanent_account_key_is_not_on_the_bare_page(): void
    {
        [$client, $level] = $this->levelPortalClient();
        // Level "mints" by composing a PERMANENTLY valid account credential —
        // worse to leak than Tactical's 7-day token, same gate.
        $level->shouldReceive('getInstallerInfo')->never();

        $this->get('/setup/'.$client->portal_install_token)
            ->assertOk()
            ->assertDontSee(self::LEVEL_KEY)
            ->assertSee('Show my install command');
    }

    public function test_levels_key_appears_only_after_the_explicit_post_and_never_in_the_audit(): void
    {
        [$client, $level] = $this->levelPortalClient();
        $level->shouldReceive('getInstallerInfo')
            ->once()
            ->with('R3JvdXA6NDI=', 'windows')
            ->andReturn(new InstallerInfo(
                downloadUrl: 'https://downloads.level.io/install_windows.ps1',
                registrationKey: self::LEVEL_KEY,
                installScript: "\$env:LEVEL_API_KEY = '".self::LEVEL_KEY."'; install",
                instructions: 'Paste into PowerShell.',
            ));

        $this->post('/setup/'.$client->portal_install_token.'/command', ['platform' => 'windows'])
            ->assertOk()
            ->assertSee(self::LEVEL_KEY);

        $this->assertDatabaseHas('portal_install_audits', [
            'client_id' => $client->id,
            'rmm' => 'level',
            'platform' => 'windows',
        ]);
        $this->assertStringNotContainsString(
            self::LEVEL_KEY,
            (string) json_encode(PortalInstallAudit::all()->toArray()),
        );
    }
}
