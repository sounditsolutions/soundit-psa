<?php

namespace Tests\Feature\ScreenConnect;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Setting;
use App\Services\ScreenConnect\ScreenConnectSyncService;
use App\Support\McpConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * psa-ikzqz — ScreenConnect webhook ingest must fail CLOSED when it cannot attribute a
 * session to a specific client. The security lens (psa-514da) found that resolveAsset()
 * fell back to an UNSCOPED hostname match: two clients sharing a short hostname
 * (WS-FRONT-01) plus a webhook whose company is missing/unmatched linked one client's
 * live session/online-state/activity onto the other client's asset. The new read tools
 * then faithfully surfaced the poisoned row — the read-path client_id scoping cannot save
 * it because the persisted asset_id is already wrong. The boundary is the INGEST, so the
 * regression drives the webhook and (for the end-to-end proof psa-ikzqz asks for) the real
 * /api/mcp/staff read across two duplicate-hostname clients.
 */
class ScreenConnectIngestCrossClientTest extends TestCase
{
    use RefreshDatabase;

    private function configureScreenConnect(): void
    {
        Setting::setValue('screenconnect_enabled', '1');
        Setting::setValue('screenconnect_base_url', 'https://sc.example.test');
        Setting::setValue('screenconnect_webhook_secret', 'test-secret');
    }

    /** A flat ScreenConnect webhook payload (the legacy template shape processWebhook accepts as-is). */
    private function connectedPayload(string $sessionId, string $hostname, ?string $company): array
    {
        return [
            'event_type' => 'Connected',
            'session_id' => $sessionId,
            'session_type' => 'Access',
            'company' => $company,
            'guest_machine_name' => $hostname,
            'guest_client_version' => '23.1.4.8794',
        ];
    }

    private function callTool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/mcp/staff', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
    }

    public function test_a_webhook_with_no_resolvable_client_never_links_a_session_across_clients(): void
    {
        // Two clients own a device with the SAME short hostname. Acme is seeded first, so
        // it has the lower id and is the row the unscoped fallback's ->first() would pick.
        $acme = Client::factory()->create(['name' => 'Acme Co']);
        $beta = Client::factory()->create(['name' => 'Beta Inc']);
        $acmeAsset = Asset::factory()->create(['client_id' => $acme->id, 'hostname' => 'WS-FRONT-01']);
        $betaAsset = Asset::factory()->create(['client_id' => $beta->id, 'hostname' => 'WS-FRONT-01']);

        // A webhook for Beta's device arrives with a missing company, so the client cannot
        // be resolved and the session id is new (no prior link).
        $result = app(ScreenConnectSyncService::class)
            ->processWebhook($this->connectedPayload('sess-cross-01', 'WS-FRONT-01', ''));

        // Fail closed: with no trusted client evidence the session must attach to NOBODY.
        $this->assertStringContainsString('No matching asset', $result);

        $this->assertNull($acmeAsset->fresh()->screenconnect_session_id, 'Acme must not inherit an unattributable session');
        $this->assertNull($betaAsset->fresh()->screenconnect_session_id, 'Beta must not be linked without client evidence either');
        $this->assertNotTrue($acmeAsset->fresh()->screenconnect_online, 'the Connected state must not leak onto Acme');
    }

    public function test_a_company_scoped_webhook_still_links_to_the_named_client_only(): void
    {
        // The fix must not break correct, client-evidenced linking (step 2).
        $acme = Client::factory()->create(['name' => 'Acme Co']);
        $beta = Client::factory()->create(['name' => 'Beta Inc']);
        $acmeAsset = Asset::factory()->create(['client_id' => $acme->id, 'hostname' => 'WS-FRONT-01']);
        $betaAsset = Asset::factory()->create(['client_id' => $beta->id, 'hostname' => 'WS-FRONT-01']);

        $result = app(ScreenConnectSyncService::class)
            ->processWebhook($this->connectedPayload('sess-beta-77', 'WS-FRONT-01', 'Beta Inc'));

        $this->assertStringContainsString("asset #{$betaAsset->id}", $result);
        $this->assertSame('sess-beta-77', $betaAsset->fresh()->screenconnect_session_id, 'the named client is linked');
        $this->assertNull($acmeAsset->fresh()->screenconnect_session_id, 'the same-hostname rival is untouched');
    }

    public function test_the_mcp_read_never_surfaces_another_clients_session_after_an_unattributable_webhook(): void
    {
        $this->configureScreenConnect();

        $acme = Client::factory()->create(['name' => 'Acme Co']);
        $beta = Client::factory()->create(['name' => 'Beta Inc']);
        Asset::factory()->create(['client_id' => $acme->id, 'hostname' => 'WS-FRONT-01']);
        Asset::factory()->create(['client_id' => $beta->id, 'hostname' => 'WS-FRONT-01']);

        // Ingest an unattributable (no-company) session for the shared hostname.
        app(ScreenConnectSyncService::class)
            ->processWebhook($this->connectedPayload('sess-leak-99', 'WS-FRONT-01', ''));

        // Acme's technician reads their WS-FRONT-01 over the real staff MCP transport.
        $token = McpConfig::rotateStaffToken(allowedTools: ['screenconnect_get_session_state'], label: 'opsbot');
        $response = $this->callTool($token, 'screenconnect_get_session_state', [
            'client_id' => $acme->id,
            'hostname' => 'WS-FRONT-01',
        ]);
        $response->assertOk();

        $body = (string) $response->json('result.content.0.text');
        $this->assertStringNotContainsString('sess-leak-99', $body, 'the unattributed session must never surface under Acme');
        $result = json_decode($body, true) ?? [];
        $this->assertNotSame('online', $result['state'] ?? null, 'Acme was never connected — no leaked online state');
    }
}
