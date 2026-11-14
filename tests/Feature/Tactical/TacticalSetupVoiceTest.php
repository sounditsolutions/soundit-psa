<?php

namespace Tests\Feature\Tactical;

use App\Models\Client;
use App\Models\Setting;
use App\Services\Portal\InstallerInfo;
use App\Services\Tactical\TacticalClient;
use App\Services\Tactical\WindowsInstallerCommand;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The device setup page is the MSP's own site: what a client's person reads during
 * setup speaks as "we" / "us", never as a third party ("your IT provider",
 * "your technician", "your IT support team").
 */
class TacticalSetupVoiceTest extends TestCase
{
    use RefreshDatabase;

    private const THIRD_PARTY = ['your IT provider', 'your technician', 'IT support team'];

    private const WINDOWS_CMD = 'tacticalagent-v2.4.9-windows-amd64.exe /VERYSILENT /SUPPRESSMSGBOXES '
        .'&& ping 127.0.0.1 -n 7 && "C:\\Program Files\\TacticalAgent\\tacticalrmm.exe" -m install '
        .'--api https://tactical.example.test --client-id 3 --site-id 5 --agent-type workstation '
        .'--auth synthetic-token';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValue('tactical_api_url', 'https://tactical.example.test');
        Setting::setEncrypted('tactical_api_key', 'synthetic-key');
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

    private function assertNoThirdParty(string $text): void
    {
        foreach (self::THIRD_PARTY as $phrase) {
            $this->assertStringNotContainsStringIgnoringCase($phrase, $text);
        }
    }

    private function transportReturning(array $payload): TacticalClient
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([['id' => 3, 'name' => 'Acme', 'sites' => [['id' => 5, 'name' => 'Main']]]])),
            new Response(200, [], json_encode($payload)),
        ]));

        return new TacticalClient(new GuzzleClient(['base_uri' => 'https://tactical.example.test/', 'handler' => $stack]));
    }

    public function test_landing_page_speaks_as_us(): void
    {
        [$client] = $this->portal();
        $page = $this->get('/setup/'.$client->portal_install_token)->assertOk();
        foreach ([
            'If unsure, ask us. Windows ARM is not offered here.',
            'Ask us to confirm this computer has checked in to the intended organization and site.',
            'Only continue if you requested this setup from us and have confirmed the download with us.',
            'only if available and we have confirmed this file.',
            'and ask us to check the detection.',
            'ask us to verify it before using a per-file',
        ] as $line) {
            $page->assertSee($line, false);
        }
        $this->assertNoThirdParty($page->getContent());
    }

    public function test_technician_note_names_whose_note_it_is(): void
    {
        [$client] = $this->portal();
        $page = $this->get('/setup/'.$client->portal_install_token)->assertOk();
        $page->assertSee('<summary>Note for our technicians</summary>', false);
        $page->assertSee("Signature, publisher/UAC prompts and clean-machine enrollment need a technician's check.", false);
        $page->assertDontSee('Technician note');
        $page->assertDontSee('operator verification');
    }

    public function test_technician_note_keeps_its_two_middle_sentences(): void
    {
        [$client] = $this->portal();
        $page = $this->get('/setup/'.$client->portal_install_token)->assertOk();
        // Inside the note, in order, between its summary and its last sentence.
        $page->assertSeeInOrder([
            '<summary>Note for our technicians</summary>',
            'Antivirus sandboxes can execute the dynamic EXE and enroll a sandbox device.'
                ." Verify the real computer's identity and intended-site check-in rather than assuming the first new record is this computer."
                ." Signature, publisher/UAC prompts and clean-machine enrollment need a technician's check.",
            '</details>',
        ], false);
    }

    public function test_invalid_link_says_contact_us(): void
    {
        $this->get('/setup/'.str_repeat('0', 22))->assertOk()
            ->assertSee('This setup link is not valid. Contact us.', false)
            ->assertDontSee('IT support team');
    }

    public function test_invalid_link_on_command_post_says_contact_us(): void
    {
        $this->post('/setup/'.str_repeat('0', 22).'/command', [
            'platform' => 'windows', 'method' => 'manual', 'goarch' => 'amd64',
        ])->assertOk()
            ->assertSee('This setup link is not valid. Contact us.', false);
    }

    public function test_manual_windows_command_page_speaks_as_us(): void
    {
        [$client, $mock] = $this->portal();
        $filename = 'tacticalagent-v2.4.9-windows-386.exe';
        $mock->shouldReceive('getInstallerInfo')->once()->andReturn(new InstallerInfo(
            downloadUrl: 'https://downloads.example.test/agent.exe',
            installScript: $filename.' /VERYSILENT && echo synthetic-command',
            expectedFilename: $filename,
        ));
        $page = $this->post('/setup/'.$client->portal_install_token.'/command', [
            'platform' => 'windows', 'method' => 'manual', 'goarch' => '386',
        ])->assertOk();
        foreach ([
            'If setup stops or a security policy blocks it, contact us. Do not disable protection',
            'A completed process is not proof of enrollment; ask us to verify intended-site check-in.',
            'Contact us with the error, not the command or credential. Ask us to verify this computer',
        ] as $line) {
            $page->assertSee($line, false);
        }
        $this->assertNoThirdParty($page->getContent());
    }

    public function test_unprepared_command_page_says_contact_us(): void
    {
        [$client, $mock] = $this->portal();
        $mock->shouldReceive('getInstallerInfo')->once()->andReturn(new InstallerInfo(
            downloadUrl: 'https://downloads.example.test/bare-agent',
        ));
        $page = $this->post('/setup/'.$client->portal_install_token.'/command', [
            'platform' => 'windows', 'method' => 'manual', 'goarch' => 'amd64',
        ])->assertOk();
        $page->assertSee('could not be prepared. Contact us; a bare download is not an alternative.', false);
        $this->assertNoThirdParty($page->getContent());
    }

    public function test_guided_failure_says_contact_us(): void
    {
        [$client, $mock] = $this->portal();
        $page = $this->get('/setup/'.$client->portal_install_token)->assertOk();
        preg_match('/name="nonce" value="([a-f0-9]+)"/', $page->getContent(), $m);
        $mock->shouldReceive('generateWindowsInstaller')->once()->andThrow(new \RuntimeException('boom'));
        $this->post('/setup/'.$client->portal_install_token.'/command', [
            'platform' => 'windows', 'method' => 'exe', 'goarch' => '386', 'nonce' => $m[1],
        ])->assertOk()->assertSee('The installer could not be prepared. Use the manual fallback or contact us.', false);
    }

    public function test_printed_powershell_messages_speak_as_us(): void
    {
        $script = WindowsInstallerCommand::build(self::WINDOWS_CMD, 'https://downloads.example.test/a', 'amd64')['script'];
        $this->assertStringContainsString("Write-Host 'Setup finished. Ask us to verify this computer and intended-site check-in.'", $script);
        $this->assertStringContainsString("Write-Host 'Setup stopped. Do not rerun or share this command. Contact us for help; download, installation or enrollment could not be completed.'", $script);
        $this->assertNoThirdParty($script);
    }

    public function test_installer_instructions_speak_as_us(): void
    {
        $windows = $this->transportReturning(['cmd' => self::WINDOWS_CMD, 'url' => 'https://downloads.example.test/a'])
            ->getInstallerInfo('Acme|Main', 'windows', 'amd64');
        $this->assertStringEndsWith('A download alone does not register the computer; ask us to verify check-in.', (string) $windows->instructions);
        $this->assertNoThirdParty((string) $windows->instructions);

        $noCommand = $this->transportReturning(['cmd' => null, 'url' => 'https://downloads.example.test/a'])
            ->getInstallerInfo('Acme|Main', 'windows', 'amd64');
        $this->assertSame('Download the installer, then contact us to complete enrollment — this download does not register the device on its own.', (string) $noCommand->instructions);
    }

    public function test_site_unavailable_message_says_contact_us(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode([]))]));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://tactical.example.test/', 'handler' => $stack]));
        try {
            $client->generateWindowsInstaller('Acme|Main', 'amd64');
            $this->fail('An unknown site must refuse');
        } catch (\App\Services\Tactical\InstallerGenerationException $e) {
            $this->assertSame('Installer site is unavailable. Contact us.', $e->getMessage());
        }
    }

    public function test_transport_failure_says_contact_us(): void
    {
        $queue = new MockHandler([
            new Response(200, [], json_encode([['id' => 3, 'name' => 'Acme', 'sites' => [['id' => 5, 'name' => 'Main']]]])),
            new ConnectException('synthetic transport failure', new Request('POST', 'agents/installer/')),
        ]);
        $history = [];
        $stack = HandlerStack::create($queue);
        $stack->push(Middleware::history($history));
        $client = new TacticalClient(new GuzzleClient(['base_uri' => 'https://tactical.example.test/', 'handler' => $stack]));
        try {
            $client->generateWindowsInstaller('Acme|Main', 'amd64');
            $this->fail('A transport failure must refuse');
        } catch (\App\Services\Tactical\InstallerGenerationException $e) {
            $this->assertSame('The installer could not be prepared. Use the manual fallback or contact us.', $e->getMessage());
        }
        // The installer POST is reached and fails; nothing earlier in the try refuses first.
        $this->assertSame(0, $queue->count());
        $this->assertCount(2, $history);
        $this->assertSame('POST', $history[1]['request']->getMethod());
        $this->assertSame('/agents/installer/', $history[1]['request']->getUri()->getPath());
        $this->assertInstanceOf(ConnectException::class, $history[1]['error']);
    }

    /** Fixed-phrase sweep over the setup files listed below. */
    public function test_no_setup_source_names_a_third_party(): void
    {
        foreach ([
            'resources/views/portal/install/show.blade.php',
            'resources/views/portal/install/invalid.blade.php',
            'resources/views/portal/install/tactical.blade.php',
            'app/Http/Controllers/Portal/PortalInstallController.php',
            'app/Services/Tactical/TacticalClient.php',
            'app/Services/Tactical/WindowsInstallerCommand.php',
            'app/Services/Portal/PortalInstallService.php',
            'app/Services/Level/LevelClient.php',
            'app/Services/Ninja/NinjaClient.php',
        ] as $path) {
            $this->assertNoThirdParty(file_get_contents(base_path($path)));
        }
    }
}
