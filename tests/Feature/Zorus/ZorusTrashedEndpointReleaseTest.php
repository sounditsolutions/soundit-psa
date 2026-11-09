<?php

namespace Tests\Feature\Zorus;

use App\Models\Asset;
use App\Models\Client;
use App\Services\SyncResult;
use App\Services\Zorus\ZorusDeviceSyncService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * #8278 — a Zorus endpoint id held by a soft-deleted asset.
 *
 * The sync's lookups run under the SoftDeletes scope and cannot see a trashed holder,
 * but the single-column unique key on zorus_endpoint_id can, so a hostname-matched live
 * asset taking the id failed on every sync. The fix releases the id from the trashed row
 * in the same transaction as the update, only on the hostname-match path, and never from
 * a live holder.
 *
 * Synthetic names and data only.
 */
class ZorusTrashedEndpointReleaseTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private const RELEASE_MESSAGE = '[ZorusSync] Released endpoint id from a trashed asset to the hostname-matched asset';

    private function endpoint(string $name = 'Zorus-Host'): array
    {
        return [
            'uuid' => self::ENDPOINT,
            'name' => $name,
            'customerUuid' => 'cust-1',
            'groupName' => 'Default',
            'isFilteringEnabled' => true,
            'isCyberSightEnabled' => false,
            'version' => '5.0.1',
            'agentState' => 'Online',
            'lastSeenDateUtc' => '2026-10-01T12:00:00Z',
        ];
    }

    private function service(): ZorusDeviceSyncService
    {
        return app(ZorusDeviceSyncService::class);
    }

    private function trashedHolder(Client $client): Asset
    {
        $asset = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'Zorus-Host',
            'zorus_endpoint_id' => self::ENDPOINT,
            'zorus_group_name' => 'Old-Group',
        ]);
        $asset->delete();

        $this->assertSoftDeleted($asset);

        return $asset;
    }

    public function test_hostname_match_releases_the_id_from_a_trashed_holder(): void
    {
        Log::spy();

        $client = Client::factory()->create();
        $trashed = $this->trashedHolder($client);
        $live = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'Zorus-Host',
            'zorus_endpoint_id' => null,
        ]);

        $result = new SyncResult;
        $this->service()->syncEndpointsForClient($client, [$this->endpoint()], true, $result);

        $this->assertSame(self::ENDPOINT, $live->fresh()->zorus_endpoint_id);
        $this->assertSame('Default', $live->fresh()->zorus_group_name);
        $this->assertSame(1, $result->updated);

        $trashedNow = Asset::withTrashed()->find($trashed->id);
        $this->assertNull($trashedNow->zorus_endpoint_id);
        $this->assertNotNull($trashedNow->deleted_at, 'the trashed holder stays trashed');
        $this->assertSame('Old-Group', $trashedNow->zorus_group_name, 'only the id is cleared');

        Log::shouldHaveReceived('warning')->with(self::RELEASE_MESSAGE, [
            'trashed_asset_id' => $trashed->id,
            'asset_id' => $live->id,
            'endpoint_uuid' => self::ENDPOINT,
        ])->once();
    }

    public function test_a_live_holder_is_never_released_and_still_fails_loud(): void
    {
        Log::spy();

        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        // A LIVE asset under another client holds the id: the client-scoped step-1 lookup
        // cannot see it, so the hostname-matched asset tries to take the id.
        $liveHolder = Asset::factory()->create([
            'client_id' => $otherClient->id,
            'hostname' => 'Elsewhere-Host',
            'zorus_endpoint_id' => self::ENDPOINT,
        ]);
        $matched = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'Zorus-Host',
            'zorus_endpoint_id' => null,
        ]);

        try {
            $this->service()->syncEndpointsForClient($client, [$this->endpoint()], true, new SyncResult);
            $this->fail('A live holder must still fail loud on the unique key.');
        } catch (UniqueConstraintViolationException) {
            // expected
        }

        $this->assertSame(self::ENDPOINT, $liveHolder->fresh()->zorus_endpoint_id);
        $this->assertNull($matched->fresh()->zorus_endpoint_id);
        Log::shouldNotHaveReceived('warning', [self::RELEASE_MESSAGE, \Mockery::any()]);
    }

    public function test_a_non_hostname_match_releases_nothing(): void
    {
        Log::spy();

        $client = Client::factory()->create();
        $trashed = $this->trashedHolder($client);
        // No live asset carries the endpoint's hostname, so nothing is matched.
        $unrelated = Asset::factory()->create([
            'client_id' => $client->id,
            'hostname' => 'Other-Host',
            'zorus_endpoint_id' => null,
        ]);

        $result = new SyncResult;
        $this->service()->syncEndpointsForClient($client, [$this->endpoint()], true, $result);

        $this->assertSame(0, $result->updated);
        $this->assertSame(self::ENDPOINT, Asset::withTrashed()->find($trashed->id)->zorus_endpoint_id);
        $this->assertNull($unrelated->fresh()->zorus_endpoint_id);
        Log::shouldNotHaveReceived('warning', [self::RELEASE_MESSAGE, \Mockery::any()]);
    }
}
