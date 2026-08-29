<?php

namespace Tests\Feature\Tactical;

use App\Models\Client;
use App\Models\McpAuditLog;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\User;
use App\Services\Portal\InstallerInfo;
use App\Services\Tactical\TacticalClient;
use App\Support\McpConfig;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // ── 3. Portal ────────────────────────────────────────────────────────────

    private function tacticalClientWithPortalToken(?string $cmd): Client
    {
        $this->configureTactical();

        $client = Client::factory()->create([
            'name' => 'Acme',
            'tactical_site_id' => 'Acme|Main',
            'portal_install_token' => 'abcdef0123456789abcdef',
            'portal_primary_rmm' => 'tactical',
        ]);

        $tactical = Mockery::mock(TacticalClient::class);
        $tactical->shouldReceive('getInstallerInfo')
            ->andReturn(new InstallerInfo(
                downloadUrl: self::DOWNLOAD_URL,
                installScript: $cmd,
                instructions: 'Two steps.',
            ));
        $this->app->instance(TacticalClient::class, $tactical);

        return $client;
    }

    public function test_auto_download_does_not_bypass_the_page_carrying_the_command(): void
    {
        $client = $this->tacticalClientWithPortalToken(self::WINDOWS_CMD);

        $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
            ->get('/setup/'.$client->portal_install_token.'?download=1');

        // Before #841 this was a 302 straight to the non-installing binary.
        $response->assertOk();
        $response->assertSee(self::WINDOWS_CMD, escape: true);
    }

    public function test_auto_download_still_redirects_when_there_is_no_command_to_show(): void
    {
        $client = $this->tacticalClientWithPortalToken(null);

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
            ->get('/setup/'.$client->portal_install_token.'?download=1')
            ->assertRedirect(self::DOWNLOAD_URL);
    }

    public function test_the_explicit_download_button_still_works_for_a_script_installer(): void
    {
        $client = $this->tacticalClientWithPortalToken(self::WINDOWS_CMD);

        // The landing page deliberately offers the binary under "Or download and
        // run the installer manually" — gating that too would break the page.
        $this->get('/setup/'.$client->portal_install_token.'/download?platform=windows')
            ->assertRedirect(self::DOWNLOAD_URL);
    }
}
