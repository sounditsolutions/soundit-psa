<?php

namespace Tests\Feature\Mesh;

use App\Models\Client;
use App\Models\Setting;
use App\Models\TechnicianActionLog;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mcp\StaffMeshAdminToolExecutor;
use App\Services\Mesh\MeshClient;
use App\Services\Mesh\MeshWriteClient;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #6213: the #6161 rule ('not configured' only for a key that is really
 * absent) on every operator text that reads MeshConfig::isConfigured():
 * mesh:sync-licenses, the sync button, the customer-mapping page, the
 * settings badge and placeholder, the staff tool refusal and the approval
 * audit row. For a stored '0' each says the key is set but unusable; for
 * a blank key each still says 'not configured' (the control row).
 *
 * G-5: stray Http requests prevented; no row reaches Mesh (the clients are
 * Mockery doubles that would fail on any call). Synthetic data (G-13).
 */
class MeshUnusableKeySiblingTextTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->instance(MeshClient::class, Mockery::mock(MeshClient::class));
        $this->app->instance(MeshWriteClient::class, Mockery::mock(MeshWriteClient::class));
    }

    /** @return array<string, array{0: string, 1: bool}> stored key, set but unusable */
    public static function stored(): array
    {
        return [
            "'0' key (set, unusable)" => ['0', true],
            "' 0' key (set, unusable)" => [' 0', true],
            'blank key (control: not configured)' => ['   ', false],
        ];
    }

    private function store(string $key): void
    {
        Setting::setValue('mesh_enabled', '1');
        Setting::setEncrypted('mesh_api_key', $key);
    }

    private static function unusable(): string
    {
        return 'The Mesh API key '.MeshClient::UNUSABLE_KEY.'. Replace it in Settings → Integrations.';
    }

    #[DataProvider('stored')]
    public function test_the_sync_licenses_command(string $key, bool $unusable): void
    {
        $this->store($key);

        $this->artisan('mesh:sync-licenses')
            ->expectsOutput($unusable ? self::unusable() : 'Mesh is not configured. Add API key in Settings → Integrations.')
            ->assertExitCode(1);
    }

    #[DataProvider('stored')]
    public function test_the_sync_button_and_the_customer_page(string $key, bool $unusable): void
    {
        $this->store($key);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from(route('settings.integrations'))
            ->post(route('settings.integrations.mesh.sync'))
            ->assertSessionHas('error', $unusable ? self::unusable() : 'Mesh is not configured.');

        $this->actingAs($admin)->get(route('settings.mesh-customers.index'))
            ->assertRedirect(route('settings.integrations'))
            ->assertSessionHas('error', $unusable ? self::unusable() : 'Mesh is not configured. Add API key first.');
    }

    #[DataProvider('stored')]
    public function test_the_settings_badge_and_placeholder(string $key, bool $unusable): void
    {
        $this->store($key);

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.integrations'))->assertOk()->getContent();
        $start = (int) strpos($html, 'Mesh Email Security');
        $card = substr($html, $start, (int) strpos($html, 'id="mesh_base_url"', $start) - $start);

        $this->assertStringContainsString('id="mesh_api_key"', $card, 'premise: the Mesh card');
        if ($unusable) {
            $this->assertStringContainsString('Key unusable</span>', $card);
            $this->assertStringContainsString('placeholder="Stored key is unusable; enter a new one"', $card);
            $this->assertStringNotContainsString('Not configured</span>', $card);
        } else {
            $this->assertStringContainsString('Not configured</span>', $card);
            $this->assertStringContainsString('placeholder="Enter API key"', $card);
            $this->assertStringNotContainsString('Key unusable', $card);
        }
    }

    #[DataProvider('stored')]
    public function test_the_staff_tool_refusal(string $key, bool $unusable): void
    {
        $this->store($key);

        $out = app(StaffMeshAdminToolExecutor::class)->execute('mesh_list_allow_rules', [], null, '[Test]');

        $this->assertSame(
            $unusable ? 'Mesh Email Security has a stored API key the PSA does not send (zero or true)' : 'Mesh Email Security is not configured',
            $out['error'] ?? null,
        );
    }

    #[DataProvider('stored')]
    public function test_the_approval_audit_row(string $key, bool $unusable): void
    {
        // Staged with a usable key, approved after the key was replaced.
        $this->store('SECRET_FIXTURE-6213');
        $actor = User::factory()->create(['name' => 'AI Actor']);
        Setting::setValue('triage_system_user_id', (string) $actor->id);
        $client = Client::factory()->create(['name' => 'Example Client', 'mesh_customer_id' => self::TENANT]);
        $ticket = Ticket::factory()->for($client)->create(['subject' => 'Vendor mail quarantined']);
        $token = McpConfig::rotateStaffToken(allowedTools: ['mesh_add_allow_rule:staged'], label: 'opsbot');
        $result = json_decode((string) $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'mesh_add_allow_rule', 'arguments' => [
                    'client_id' => $client->id,
                    'ticket_id' => $ticket->id,
                    'sender' => 'billing@vendor.example.test',
                    'confirm_domain' => 'vendor.example.test',
                    'reason' => 'Vendor invoices are being quarantined; approved by the client contact.',
                ]],
            ])->json('result.content.0.text'), true) ?? [];
        $this->assertArrayHasKey('run_id', $result, 'staging failed: '.json_encode($result));
        $run = TechnicianRun::findOrFail($result['run_id']);

        Setting::setEncrypted('mesh_api_key', $key);
        $this->actingAs($actor)->post(route('cockpit.approve', $run));

        $summary = (string) TechnicianActionLog::where('result_status', 'blocked')->value('summary');
        $this->assertSame(
            ($unusable ? 'Mesh has a stored API key the PSA does not send (zero or true)' : 'Mesh is not configured').'; staged allow rule refused.',
            $summary,
        );
    }
}
