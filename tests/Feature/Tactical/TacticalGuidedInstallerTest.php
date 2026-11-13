<?php

namespace Tests\Feature\Tactical;

use App\Models\Client;
use App\Models\PortalInstallAudit;
use App\Models\Setting;
use App\Services\Tactical\InstallerGenerationException;
use App\Services\Tactical\TacticalClient;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TacticalGuidedInstallerTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'synthetic-key');
    }

    private function transport(Response $installer): TacticalClient
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([[
                'id' => 3, 'name' => 'Example', 'sites' => [['id' => 5, 'name' => 'Test']],
            ]])),
            $installer,
        ]));
        $stack->push(Middleware::history($this->history));

        return new TacticalClient(new HttpClient(['base_uri' => 'https://tactical.example.test/', 'handler' => $stack]));
    }

    private function portal(): array
    {
        $client = Client::factory()->create([
            'name' => 'Example', 'tactical_site_id' => 'Example|Test',
            'portal_primary_rmm' => 'tactical', 'portal_install_token' => 'abcdef0123456789abcdef',
        ]);
        $mock = Mockery::mock(TacticalClient::class);
        $mock->shouldReceive('supportsInstall')->andReturn(true)->byDefault();
        $this->app->instance(TacticalClient::class, $mock);

        return [$client, $mock];
    }

    private function requestBody(Client $client): array
    {
        $page = $this->get('/setup/'.$client->portal_install_token)->assertOk();
        preg_match('/name="nonce" value="([a-f0-9]+)"/', $page->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        return ['platform' => 'windows', 'method' => 'exe', 'goarch' => '386', 'nonce' => $matches[1]];
    }

    public function test_rendered_fallback_contains_actual_self_downloading_command(): void
    {
        [$client, $mock] = $this->portal();
        $command = 'tacticalagent-v2.9.1-windows-386.exe /VERYSILENT /SUPPRESSMSGBOXES && ping 127.0.0.1 -n 7'
            .' && "C:\\Program Files\\TacticalAgent\\tacticalrmm.exe" -m install --api https://rmm.example.test'
            .' --client-id 3 --site-id 5 --agent-type workstation --auth synthetic-token';
        $transport = $this->transport(new Response(200, [], json_encode(['url' => 'https://downloads.example.test/agent?token=synthetic', 'cmd' => $command])));
        $mock->shouldReceive('getInstallerInfo')->once()->with('Example|Test', 'windows', '386')
            ->andReturnUsing(fn () => $transport->getInstallerInfo('Example|Test', 'windows', '386'));
        $page = $this->post('/setup/'.$client->portal_install_token.'/command', [
            'platform' => 'windows', 'method' => 'manual', 'goarch' => '386',
        ])->assertOk();
        $page->assertSee('PowerShell as Administrator')->assertSee('Invoke-WebRequest -UseBasicParsing')
            ->assertSee('tacticalagent-v2.9.1-windows-386.exe')->assertSee('-OutFile $file -ErrorAction Stop')
            ->assertSee('$process.ExitCode -ne 0')->assertSee('$LASTEXITCODE -ne 0')
            ->assertDontSee('Download the required manual installer')->assertDontSee('Command Prompt');
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->assertCount(2, $this->history, 'No hidden secondary mint or URL validation request');
        $payload = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame('386', $payload['goarch']);
        $this->assertSame('manual', $payload['installMethod']);
        $this->assertStringNotContainsString('synthetic-token', (string) PortalInstallAudit::all()->toJson());
    }

    public function test_binary_request_contract_and_memory_only_sink(): void
    {
        $binary = 'MZ'.str_repeat('x', 2048);
        $client = $this->transport(new Response(200, ['Content-Type' => 'application/octet-stream'], $binary));
        $this->assertSame($binary, $client->generateWindowsInstaller('Example|Test', '386'));
        $this->assertCount(2, $this->history);
        $request = $this->history[1];
        $this->assertSame('*/*', $request['request']->getHeaderLine('Accept'), 'DRF negotiates before FileResponse; octet-stream-only is a 406');
        $this->assertSame('POST', $request['request']->getMethod());
        $this->assertSame('/agents/installer/', $request['request']->getUri()->getPath());
        $payload = json_decode((string) $request['request']->getBody(), true);
        $this->assertSame('exe', $payload['installMethod']);
        $this->assertSame('workstation-setup.exe', $payload['fileName']);
        $this->assertSame('386', $payload['goarch']);
        $this->assertSame(168, $payload['expires']);
        $this->assertSame(3, $payload['client']);
        $this->assertSame(5, $payload['site']);
        // install_agent reads request.data['plat'] before branching on
        // installMethod, so the EXE mint must send it exactly as the JSON
        // sibling call does.
        $this->assertSame('windows', $payload['plat']);
        $this->assertFalse($request['options']['allow_redirects']);
        // The request must explicitly supply a sink rather than Guzzle's
        // default php://temp, which can spill credentials to disk.
        $this->assertArrayHasKey('sink', $request['options']);
        $this->expectException(\RuntimeException::class);
        ($request['options']['progress'])(0, 33 * 1024 * 1024);
    }

    /** @dataProvider invalidBinaries */
    public function test_refuses_invalid_or_error_bodies(int $status, string $type, string $body, string $message): void
    {
        $client = $this->transport(new Response($status, ['Content-Type' => $type], $body));
        try {
            $client->generateWindowsInstaller('Example|Test', 'amd64');
            $this->fail('Invalid installer was delivered');
        } catch (InstallerGenerationException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertCount(2, $this->history, 'A mint must not be retried');
        }
    }

    public static function invalidBinaries(): array
    {
        $invalid = 'The installer could not be validated. Use the manual fallback or contact us.';
        $insecure = "Not available in insecure mode. Please use the 'Manual' method.";
        $generator = 'Something went wrong. Check debug error log for exact error message';

        return [
            'negotiation refusal' => [406, 'application/json', '{"detail":"sensitive upstream text"}', 'The upstream installer request was not accepted. Use the manual fallback or contact us.'],
            'generator ret never echoed' => [200, 'application/octet-stream', '{"ret":"--auth private-token https://example.test/?secret=value"}', 'The upstream installer service could not generate the guided setup. Use the manual fallback or contact us.'],
            'error returned as binary' => [200, 'application/octet-stream', str_repeat('error', 300), $invalid],
            'tiny MZ' => [200, 'application/octet-stream', 'MZ', $invalid],
            'wrong type' => [200, 'text/html', 'MZ'.str_repeat('x', 2048), $invalid],
            'bad status' => [502, 'application/octet-stream', 'MZ'.str_repeat('x', 2048), $invalid],
            'insecure refusal is JSON string' => [400, 'application/json', json_encode($insecure), $insecure],
            'generator refusal' => [400, 'application/json', json_encode($generator), $generator],
            'unknown sensitive error' => [400, 'application/json', json_encode('--auth synthetic-secret'), $invalid],
        ];
    }

    public function test_exe_click_streams_no_store_audits_and_refuses_replay(): void
    {
        [$client, $mock] = $this->portal();
        $body = $this->requestBody($client);
        $mock->shouldReceive('getInstallerInfo')->never();
        $binary = 'MZ'.str_repeat('synthetic-enrollment', 100);
        $mock->shouldReceive('generateWindowsInstaller')->once()->with('Example|Test', '386')->andReturn($binary);
        $url = '/setup/'.$client->portal_install_token.'/command';
        $response = $this->post($url, $body)->assertOk();
        $this->assertSame($binary, $response->streamedContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('workstation-setup.exe', $response->headers->get('Content-Disposition'));
        $this->assertDatabaseCount('portal_install_audits', 1);
        $this->assertStringNotContainsString('synthetic-enrollment', PortalInstallAudit::all()->toJson());
        $this->post($url, $body)->assertOk()->assertSee('expired or was already used');
        $this->assertDatabaseCount('portal_install_audits', 1);
    }

    public function test_rendered_primary_copy_has_scoped_allow_help_and_no_bare_alternative(): void
    {
        [$client, $mock] = $this->portal();
        $mock->shouldReceive('generateWindowsInstaller')->never();
        $mock->shouldReceive('getInstallerInfo')->never();
        $page = $this->get('/setup/'.$client->portal_install_token)->assertOk();
        foreach (['Download guided Windows setup', 'Ordered manual fallback', 'workstation-setup.exe',
            'No command shell is needed', 'More info', 'Run anyway', 'Allow on device',
            'Only after that confirmation', 'Never turn off antivirus', 'call',
            'not proof of enrollment', 'Antivirus sandboxes', 'Windows ARM is not offered',
            'Apple Silicon', 'can be reused until it expires'] as $copy) {
            $page->assertSee($copy);
        }
        foreach (['One-click install', 'Download installer instead', 'Open PowerShell as Administrator', '--auth'] as $bad) {
            $page->assertDontSee($bad);
        }
        $this->assertDatabaseCount('portal_install_audits', 0);
    }

    public function test_manual_windows_render_names_shell_exact_filename_and_order(): void
    {
        [$client, $mock] = $this->portal();
        $filename = 'tacticalagent-v2.4.9-windows-386.exe';
        $mock->shouldReceive('getInstallerInfo')->once()->with('Example|Test', 'windows', '386')
            ->andReturn(new \App\Services\Portal\InstallerInfo(
                downloadUrl: 'https://downloads.example.test/agent.exe',
                installScript: $filename.' /VERYSILENT && echo synthetic-command',
                expectedFilename: $filename,
            ));
        $page = $this->post('/setup/'.$client->portal_install_token.'/command', [
            'platform' => 'windows', 'method' => 'manual', 'goarch' => '386',
        ])->assertOk();
        $page->assertSee($filename)->assertSee('PowerShell as Administrator')
            ->assertSee('No separate download or change of folder is needed')
            ->assertSee('stops on failure')->assertSee('not proof of enrollment')
            ->assertDontSee('Command Prompt')->assertDontSee('Download the required manual installer');
        $html = $page->getContent();
        $this->assertLessThan(strpos($html, 'Copy and paste the command'), strpos($html, 'PowerShell as Administrator'));
        $page->assertDontSee('/download?');
    }

    public function test_mac_arm_command_renders_without_a_separate_download(): void
    {
        [$client, $mock] = $this->portal();
        $mock->shouldReceive('getInstallerInfo')->once()->with('Example|Test', 'mac', 'arm64')
            ->andReturn(new \App\Services\Portal\InstallerInfo(
                downloadUrl: 'https://downloads.example.test/bare-agent',
                installScript: 'curl -o agent https://downloads.example.test/agent && chmod +x agent && sudo ./agent',
            ));
        $page = $this->post('/setup/'.$client->portal_install_token.'/command', [
            'platform' => 'mac', 'goarch' => 'arm64',
        ])->assertOk();
        $page->assertSee('Open Terminal and run the command below')->assertSee('curl -o agent');
        $page->assertDontSee('href="https://downloads.example.test/bare-agent"', false);
    }

    /** @dataProvider refusedRequests */
    public function test_bad_architecture_missing_or_expired_nonce_never_mints(array $changes, bool $expire): void
    {
        [$client, $mock] = $this->portal();
        $body = $this->requestBody($client);
        if ($expire) {
            $this->withSession(['tactical_installer_nonce' => [
                'value' => $body['nonce'], 'expires' => time() - 1, 'client_id' => $client->id,
            ]]);
        }
        $mock->shouldReceive('generateWindowsInstaller')->never();
        $mock->shouldReceive('getInstallerInfo')->never();
        $this->post('/setup/'.$client->portal_install_token.'/command', array_replace($body, $changes))->assertOk();
        $this->assertDatabaseCount('portal_install_audits', 0);
    }

    public static function refusedRequests(): array
    {
        return [
            'arm windows' => [['goarch' => 'arm64'], false],
            'missing nonce' => [['nonce' => null], false],
            'wrong nonce' => [['nonce' => 'wrong'], false],
            'expired nonce' => [[], true],
        ];
    }

    public function test_vendor_refusal_renders_exact_safe_message_and_ordered_fallback(): void
    {
        [$client, $mock] = $this->portal();
        $body = $this->requestBody($client);
        $message = "Not available in insecure mode. Please use the 'Manual' method.";
        $mock->shouldReceive('generateWindowsInstaller')->once()->andThrow(new InstallerGenerationException($message));
        $page = $this->post('/setup/'.$client->portal_install_token.'/command', $body)->assertOk();
        $page->assertSee($message)->assertSee('Ordered manual fallback')->assertSee('Request manual fallback');
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->assertDatabaseCount('portal_install_audits', 1);
    }
}
