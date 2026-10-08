<?php

namespace Tests\Feature\Integrations;

use App\Enums\ClientStage;
use App\Models\Asset;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Setting;
use App\Models\TacticalAsset;
use App\Models\User;
use App\Services\AssetService;
use App\Services\Litsrmm\LitsrmmAssetSyncService;
use App\Services\Litsrmm\LitsrmmClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * LITSRMM stage 3: devices become PSA assets.
 *
 * LITSRMM is an RMM of record, so unlike AutoElevate or Control D it CREATES
 * assets, as LevelSyncService does. It takes its safeguards from
 * AutoElevateAssetSyncService instead of Level: every match is scoped to the
 * one mapped client; a guess is refused rather than made; a failed list read,
 * or a list too short to believe, changes nothing; a failed detail read costs
 * only that device's hardware; a device that leaves loses only OUR link and
 * never the asset; a deleted asset is never revived.
 *
 * Every response comes from a fake transport routed by path, so nothing here
 * reaches a real host and no test depends on request order.
 */
class LitsrmmAssetSyncTest extends TestCase
{
    use RefreshDatabase;

    private const VENDOR_CLIENT = '2f12dedb-b4cf-48cf-86bb-9f63b5b8f7af';

    private const OTHER_VENDOR_CLIENT = 'c0a80101-0000-4000-8000-000000000009';

    private string $fakeBearer;

    /** @var list<array<string, mixed>> list rows the fake vendor serves */
    private array $rows = [];

    /** A $details value: the detail read answers about a different device. */
    private const WRONG_DEVICE = 'wrong-device';

    /** @var array<string, array|int|string> device id => inventory, an HTTP status to fail with, or WRONG_DEVICE */
    private array $details = [];

    private ?int $listStatus = null;

    private array $history = [];

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeBearer = 'fake-'.bin2hex(random_bytes(8));
        Setting::setEncrypted('litsrmm_api_key', $this->fakeBearer);
        Setting::setValue('litsrmm_base_url', 'https://litsrmm.test');

        $this->client = $this->mappedClient(self::VENDOR_CLIENT);
    }

    private function mappedClient(?string $vendorClientId): Client
    {
        return Client::factory()->create([
            'stage' => ClientStage::Active,
            'is_active' => true,
            'litsrmm_client_id' => $vendorClientId,
        ]);
    }

    private function service(): LitsrmmAssetSyncService
    {
        $handler = function (RequestInterface $request) {
            $path = $request->getUri()->getPath();

            if ($path === '/v1/devices') {
                if ($this->listStatus !== null) {
                    return Create::promiseFor(new Response($this->listStatus, [], '{"error":"no"}'));
                }

                return Create::promiseFor(self::respond(['devices' => $this->rows, 'nextCursor' => null]));
            }

            $id = substr($path, strlen('/v1/devices/'));
            $detail = $this->details[$id] ?? [];

            if (is_int($detail)) {
                return Create::promiseFor(new Response($detail, [], '{"error":"no"}'));
            }

            $row = collect($this->rows)->firstWhere('id', $id);

            if ($detail === self::WRONG_DEVICE) {
                // The vendor answering about another device: LitsrmmClient
                // refuses it with no HTTP status.
                return Create::promiseFor(self::respond(array_merge($row, ['id' => 'f0f0f0f0-0000-4000-8000-000000000000', 'inventory' => (object) []])));
            }

            return Create::promiseFor(self::respond(array_merge($row, ['inventory' => (object) $detail])));
        };

        $stack = HandlerStack::create($handler);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new LitsrmmAssetSyncService(new LitsrmmClient([
            'api_key' => $this->fakeBearer,
            'base_url' => 'https://litsrmm.test',
            'handler' => $stack,
            'request_timeout' => 5,
        ]));
    }

    private static function respond(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** A list row shaped like the vendor capture; an agent device unless told otherwise. */
    private function device(string $n, array $overrides = []): array
    {
        $row = array_merge([
            'id' => sprintf('8f14e45f-ceea-467a-9f38-%012d', (int) $n),
            'clientId' => self::VENDOR_CLIENT,
            'clientName' => 'Leif IT Solutions',
            'hostname' => "WORKSTATION-{$n}",
            'serial' => "SN{$n}REAL0",
            'osName' => 'Win 11 Pro',
            'osVersion' => 'Microsoft Windows NT 10.0.26200.0',
            'osBuild' => '26200',
            'agentVersion' => '1.14.0+92a5b34',
            'lastUser' => 'exampleuser',
            'firstSeen' => '2026-09-22T03:47:40Z',
            'lastSeen' => '2026-09-30T10:00:00.123Z',
            'enrollmentState' => 'enrolled',
            'availabilityState' => 'online',
            'retiredAt' => null,
        ], $overrides);

        $this->rows[] = $row;

        return $row;
    }

    private static function inventory(): array
    {
        return [
            'hardware' => ['collectedAt' => '2026-09-30T09:58:35.835Z', 'payload' => ['cpu' => 'AMD Ryzen 7 5700G with Radeon Graphics', 'ramBytes' => 16424173568]],
            'disks' => ['collectedAt' => '2026-09-30T09:58:35.835Z', 'payload' => [['drive' => 'C:', 'totalBytes' => 499496030208, 'freeBytes' => 89943642112]]],
            'network' => ['collectedAt' => '2026-09-30T09:58:35.842Z', 'payload' => [['name' => 'Ethernet', 'mac' => '00:00:5E:00:53:01', 'ipv4' => ['192.0.2.10'], 'up' => true]]],
            'system' => ['collectedAt' => '2026-09-30T09:58:35.835Z', 'payload' => ['bootTimeUtc' => '2026-09-30T03:33:21Z', 'pendingReboot' => true]],
        ];
    }

    private function detailRequests(): int
    {
        return collect($this->history)
            ->filter(fn ($h) => $h['request']->getUri()->getPath() !== '/v1/devices')
            ->count();
    }

    // ---- creating ----

    public function test_a_device_of_a_mapped_client_becomes_an_asset_with_its_hardware(): void
    {
        $row = $this->device('1');
        $this->details[$row['id']] = self::inventory();

        $result = $this->service()->sync();

        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));

        $asset = Asset::sole();
        $this->assertSame($this->client->id, $asset->client_id);
        $this->assertSame($row['id'], $asset->litsrmm_device_id);
        $this->assertNotNull($asset->litsrmm_synced_at);
        $this->assertSame('WORKSTATION-1', $asset->name);
        $this->assertSame('WORKSTATION-1', $asset->hostname);
        $this->assertSame('SN1REAL0', $asset->serial_number);
        $this->assertSame('Win 11 Pro (build 26200)', $asset->os, 'the build tells 22H2 from 23H2; Level carried it too');
        $this->assertSame('exampleuser', $asset->last_user);
        $this->assertSame('Windows Workstation', $asset->asset_type);
        $this->assertTrue($asset->rmm_online);
        $this->assertTrue($asset->is_active);
        $this->assertSame('2026-09-30 10:00:00', $asset->last_seen_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('AMD Ryzen 7 5700G with Radeon Graphics', $asset->cpu);
        $this->assertSame('15.30', $asset->ram_gb);
        $this->assertSame('C: 465 GB (18% free)', $asset->disk_summary);
        $this->assertSame('192.0.2.10', $asset->ip_address);
        $this->assertSame('2026-09-30 03:33:21', $asset->last_boot_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($asset->needs_reboot);
    }

    public function test_an_os_without_a_build_is_written_as_reported(): void
    {
        $this->device('1', ['osBuild' => null]);

        $this->service()->sync();

        $this->assertSame('Win 11 Pro', Asset::sole()->os);
    }

    // ---- an asset merge carries our link ----

    public function test_a_merge_carries_the_link_to_the_survivor_and_the_next_sync_follows_it(): void
    {
        // Seen in testing: a linked asset (#136) was merged INTO the
        // older #132. AssetService's identity list did not know litsrmm_device_id,
        // so the link stayed on the retired tombstone and #132 was left unlinked.
        $row = $this->device('1');
        $this->service()->sync();
        $linked = Asset::where('litsrmm_device_id', $row['id'])->sole();
        $older = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1-OLD', 'serial_number' => null]);

        app(AssetService::class)->mergeAssets($older->fresh(), $linked->fresh(), User::factory()->create()->id);

        $this->assertSame($row['id'], $older->fresh()->litsrmm_device_id, 'the survivor takes the link');
        $this->assertNotNull($older->fresh()->litsrmm_synced_at);
        $this->assertNull(Asset::withTrashed()->find($linked->id)->litsrmm_device_id, 'the tombstone gives it up');

        $result = $this->service()->sync();

        $this->assertSame(0, $result->created, 'the next sync follows the link, it does not recreate the device');
        $this->assertSame(1, Asset::count());
    }

    public function test_two_different_links_refuse_to_merge(): void
    {
        // Two live LITSRMM devices are two machines, not one machine twice.
        $a = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => '8f14e45f-ceea-467a-9f38-000000000001']);
        $b = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => '8f14e45f-ceea-467a-9f38-000000000002']);

        $this->assertArrayHasKey('litsrmm_device_id', app(AssetService::class)->assetMergeIdentityConflicts($a, $b));
    }

    public function test_a_server_os_is_typed_as_a_server(): void
    {
        $this->device('1', ['osName' => 'Windows Server 2022 Standard']);

        $this->service()->sync();

        $this->assertSame('Windows Server', Asset::sole()->asset_type);
    }

    public function test_an_agentless_device_is_created_without_a_detail_read(): void
    {
        // Most of the vendor's devices have no agent: nothing was ever
        // collected, so asking costs a request and returns nothing.
        $this->device('1', ['agentVersion' => null, 'osName' => null, 'lastUser' => null, 'lastSeen' => null, 'availabilityState' => 'offline']);

        $result = $this->service()->sync();

        $this->assertSame(1, $result->created);
        $this->assertSame(0, $this->detailRequests());
        $this->assertNull(Asset::sole()->cpu);
        $this->assertFalse(Asset::sole()->rmm_online);
    }

    public function test_nobody_signed_in_never_replaces_the_last_real_user(): void
    {
        // The vendor's agent reports "(none)" when the machine sits at the
        // sign-in or lock screen: a fact about NOW, not a user. The asset's
        // last_user means the last person who used it (Level's
        // last_logged_in_user), so "(none)" must not overwrite that. Seen on
        // a rehearsal against real data: two machines both read
        // "(none)" while nobody was signed in.
        $row = $this->device('1', ['lastUser' => '(none)']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => $row['id'], 'last_user' => 'user1']);
        $this->device('2', ['lastUser' => '(none)']);

        $this->service()->sync();

        $this->assertSame('user1', $asset->fresh()->last_user);
        $this->assertNull(Asset::where('hostname', 'WORKSTATION-2')->sole()->last_user, 'a new asset gets no user rather than "(none)"');
    }

    public function test_a_placeholder_serial_is_never_written(): void
    {
        // Level matches on serial_number ESTATE-WIDE. A stored "System Serial
        // Number" would let it merge any other machine with the same firmware
        // filler into this asset.
        $this->device('1', ['serial' => 'System Serial Number']);

        $this->service()->sync();

        $this->assertNull(Asset::sole()->serial_number);
    }

    // ---- tenancy ----

    public function test_a_device_of_an_unmapped_vendor_client_is_never_touched(): void
    {
        $this->device('1', ['clientId' => self::OTHER_VENDOR_CLIENT]);

        $result = $this->service()->sync();

        $this->assertSame(0, Asset::count());
        $this->assertSame(0, $result->created);
        $this->assertSame(0, $this->detailRequests(), 'not even read: it is not ours');
    }

    public function test_each_device_lands_only_in_the_client_it_belongs_to(): void
    {
        $other = $this->mappedClient(self::OTHER_VENDOR_CLIENT);
        $this->device('1');
        $this->device('2', ['clientId' => self::OTHER_VENDOR_CLIENT]);

        $this->service()->sync();

        $this->assertSame('WORKSTATION-1', Asset::where('client_id', $this->client->id)->sole()->hostname);
        $this->assertSame('WORKSTATION-2', Asset::where('client_id', $other->id)->sole()->hostname);
    }

    public function test_a_serial_in_another_client_is_never_matched(): void
    {
        $other = $this->mappedClient(null);
        $foreign = Asset::factory()->create(['client_id' => $other->id, 'hostname' => 'SOMEONE-ELSE', 'serial_number' => 'SN1REAL0']);
        $this->device('1');

        $this->service()->sync();

        $this->assertNull($foreign->fresh()->litsrmm_device_id);
        $this->assertSame(1, Asset::where('client_id', $this->client->id)->count());
    }

    public function test_a_client_that_is_not_operational_is_not_synced(): void
    {
        $this->client->forceFill(['stage' => ClientStage::Prospect])->save();
        $this->device('1');

        $this->service()->sync();

        $this->assertSame(0, Asset::count());
    }

    // ---- matching an existing asset ----

    public function test_a_linked_asset_follows_its_device_through_a_rename(): void
    {
        $row = $this->device('1', ['hostname' => 'RENAMED-PC']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'OLD-NAME', 'name' => 'Front desk PC', 'litsrmm_device_id' => $row['id']]);

        $result = $this->service()->sync();

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->created);
        $asset->refresh();
        $this->assertSame('RENAMED-PC', $asset->hostname);
        $this->assertSame('Front desk PC', $asset->name, 'a name a person chose is never overwritten');
    }

    public function test_an_unlinked_asset_is_linked_by_a_real_serial(): void
    {
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'DIFFERENT-NAME', 'serial_number' => 'sn1real0']);
        $row = $this->device('1');

        $result = $this->service()->sync();

        $this->assertSame(1, $result->updated);
        $this->assertSame($row['id'], $asset->fresh()->litsrmm_device_id);
        $this->assertSame(1, Asset::count());
    }

    public function test_an_unlinked_asset_is_linked_by_hostname(): void
    {
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'workstation-1.corp.local', 'serial_number' => null]);
        $row = $this->device('1', ['serial' => null]);

        $this->service()->sync();

        $this->assertSame($row['id'], $asset->fresh()->litsrmm_device_id);
        $this->assertSame(1, Asset::count());
    }

    public function test_a_placeholder_serial_never_links_two_different_machines(): void
    {
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WS-ECHO', 'serial_number' => 'Default string']);
        $this->device('1', ['serial' => 'Default string']);

        $result = $this->service()->sync();

        $this->assertNull($asset->fresh()->litsrmm_device_id);
        $this->assertSame(1, $result->created, 'a different machine gets its own asset');
    }

    public function test_a_deleted_asset_is_never_revived(): void
    {
        // A deletion is a person's decision; a sync does not overturn it.
        $deleted = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'serial_number' => 'SN1REAL0']);
        $deleted->delete();
        $this->device('1');

        $result = $this->service()->sync();

        $this->assertSame(1, $result->created);
        $this->assertTrue($deleted->fresh()->trashed());
        $this->assertNull($deleted->fresh()->litsrmm_device_id);
    }

    public function test_two_assets_answering_to_one_name_are_reported_not_guessed(): void
    {
        Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'serial_number' => null]);
        Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'workstation-1', 'serial_number' => null]);
        $this->device('1', ['serial' => null]);

        $result = $this->service()->sync();

        $this->assertSame(0, $result->created, 'creating a third would duplicate one of them');
        $this->assertSame(0, Asset::whereNotNull('litsrmm_device_id')->count());
        $this->assertSame(1, $result->skipped);
        $this->assertStringContainsString('WORKSTATION-1', $result->skippedMessages[0]);
    }

    public function test_two_devices_answering_to_one_existing_name_are_reported_not_guessed(): void
    {
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'SHARED', 'serial_number' => null]);
        $this->device('1', ['hostname' => 'SHARED', 'serial' => null]);
        $this->device('2', ['hostname' => 'SHARED', 'serial' => null]);

        $result = $this->service()->sync();

        $this->assertNull($asset->fresh()->litsrmm_device_id);
        $this->assertSame(0, $result->created);
        $this->assertSame(2, $result->skipped);
    }

    public function test_an_asset_linked_to_another_device_is_not_taken(): void
    {
        $taken = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'serial_number' => 'SN1REAL0', 'litsrmm_device_id' => '8f14e45f-ceea-467a-9f38-000000000077']);
        $this->device('77', ['hostname' => 'OTHER', 'serial' => null]);
        $this->device('1');

        $this->service()->sync();

        $this->assertSame('8f14e45f-ceea-467a-9f38-000000000077', $taken->fresh()->litsrmm_device_id);
        $this->assertSame(2, Asset::count());
    }

    // ---- what a sync must not destroy ----

    /** Review of #4485 (smaller 7): a malformed category is drift, and is logged, not silently skipped. */
    public function test_a_malformed_hardware_category_is_logged_as_drift(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        $row = $this->device('1');
        $inventory = self::inventory();
        $inventory['disks'] = 'nonsense';
        $this->details[$row['id']] = $inventory;

        $this->service()->sync();

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'malformed')
                && $context === ['client_id' => $this->client->id, 'malformed_categories' => 1])
            ->once();
    }

    public function test_a_missing_category_leaves_the_existing_value_alone(): void
    {
        $row = $this->device('1');
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => $row['id'], 'cpu' => 'Known CPU', 'disk_summary' => 'C: 100 GB']);
        $inventory = self::inventory();
        unset($inventory['hardware']);
        $this->details[$row['id']] = $inventory;

        $this->service()->sync();

        $asset->refresh();
        $this->assertSame('Known CPU', $asset->cpu);
        $this->assertSame('C: 465 GB (18% free)', $asset->disk_summary);
    }

    public function test_a_failed_detail_read_still_writes_the_list_facts_and_keeps_the_hardware(): void
    {
        $row = $this->device('1', ['lastUser' => 'newuser']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => $row['id'], 'cpu' => 'Known CPU']);
        $this->details[$row['id']] = 500;

        $result = $this->service()->sync();

        $asset->refresh();
        $this->assertSame('newuser', $asset->last_user);
        $this->assertSame('Known CPU', $asset->cpu);
        $this->assertSame(1, $result->errors, 'an operator must hear that the hardware was not refreshed');
    }

    public function test_a_failed_list_read_changes_nothing(): void
    {
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => '8f14e45f-ceea-467a-9f38-000000000001']);
        $this->listStatus = 500;

        $result = $this->service()->sync();

        $this->assertSame('8f14e45f-ceea-467a-9f38-000000000001', $asset->fresh()->litsrmm_device_id);
        $this->assertSame(1, $result->errors);
    }

    public function test_a_device_that_left_loses_only_our_link(): void
    {
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => '8f14e45f-ceea-467a-9f38-000000000099', 'cpu' => 'Known CPU', 'litsrmm_synced_at' => now()]);
        // A second linked device the read still lists: one of two links
        // unlisted is a shrink inside the bound, not every link gone (#5576).
        $this->linkedAssets(1);

        $result = $this->service()->sync();

        $asset->refresh();
        $this->assertFalse($asset->trashed());
        $this->assertNull($asset->litsrmm_device_id);
        $this->assertNull($asset->litsrmm_synced_at);
        $this->assertSame('Known CPU', $asset->cpu);
        $this->assertSame(1, $result->deactivated);
    }

    public function test_a_retired_device_is_not_created_and_its_asset_keeps_its_link(): void
    {
        $row = $this->device('1', ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
        $this->device('2', ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => $row['id']]);

        $result = $this->service()->sync();

        $this->assertSame(1, Asset::count(), 'no asset is created for a retired device');
        $this->assertSame($row['id'], $asset->fresh()->litsrmm_device_id, 'a retired asset keeps its link');
        $this->assertFalse((bool) $asset->fresh()->is_active);
        $this->assertFalse($asset->fresh()->trashed());
        $this->assertSame(0, $this->detailRequests());
        $this->assertSame(1, $result->details['retired'], '#5361: an asset made inactive is counted on its own');
        $this->assertSame(0, $result->deactivated, '#5361: and not folded into the released links');
    }

    public function test_a_half_retired_device_is_left_exactly_as_it_was(): void
    {
        // The vendor says retirement sets both states at once. One without the
        // other is a shape nobody has described, so nothing acts on it.
        $row = $this->device('1', ['enrollmentState' => 'enrolled', 'availabilityState' => 'retired', 'lastUser' => 'changed']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => $row['id'], 'last_user' => 'before']);

        $result = $this->service()->sync();

        $asset->refresh();
        $this->assertSame($row['id'], $asset->litsrmm_device_id);
        $this->assertSame('before', $asset->last_user);
        $this->assertSame(1, $result->skipped);
    }

    public function test_an_asset_whose_client_is_no_longer_mapped_loses_its_link(): void
    {
        $formerly = $this->mappedClient(null);
        $asset = Asset::factory()->create(['client_id' => $formerly->id, 'litsrmm_device_id' => '8f14e45f-ceea-467a-9f38-000000000050']);

        $result = $this->service()->sync();

        $this->assertNull($asset->fresh()->litsrmm_device_id);
        $this->assertSame(1, $result->deactivated);
    }

    public function test_a_second_run_changes_nothing_it_did_not_have_to(): void
    {
        $row = $this->device('1');
        $this->details[$row['id']] = self::inventory();

        $this->service()->sync();
        $second = $this->service()->sync();

        $this->assertSame(1, Asset::count());
        $this->assertSame(0, $second->created);
        $this->assertSame(1, $second->updated);
        $this->assertSame(0, $second->deactivated);
    }

    public function test_the_sync_only_ever_reads(): void
    {
        $row = $this->device('1');
        $this->details[$row['id']] = self::inventory();

        $this->service()->sync();

        foreach ($this->history as $h) {
            $this->assertSame('GET', $h['request']->getMethod());
        }
    }

    // ---- licenses: one seat per device running the vendor's agent ----

    /** @return array{server: ?License, workstation: ?License} */
    // ---- Review of #4485 (Sound IT, 2026-10-01) ----

    /** Item 2: an asset whose link names a device this read does not list live is free to match. */
    public function test_a_reenrolled_machine_takes_back_its_own_asset(): void
    {
        $old = $this->device('1', ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'serial_number' => 'SN1REAL0', 'litsrmm_device_id' => $old['id']]);
        $new = $this->device('2', ['hostname' => 'WORKSTATION-1', 'serial' => 'SN1REAL0']);

        $result = $this->service()->sync();

        $this->assertSame(1, Asset::count(), 'no second, billable asset');
        $this->assertSame($new['id'], $asset->fresh()->litsrmm_device_id);
        $this->assertTrue((bool) $asset->fresh()->is_active);
        $this->assertSame(0, $result->created);
    }

    public function test_an_asset_linked_to_a_device_that_is_gone_is_free_to_match(): void
    {
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'OTHER-NAME', 'serial_number' => 'SN1REAL0', 'litsrmm_device_id' => '8f14e45f-ceea-467a-9f38-000000000777']);
        $row = $this->device('1');

        $this->service()->sync();

        $this->assertSame(1, Asset::count());
        $this->assertSame($row['id'], $asset->fresh()->litsrmm_device_id);
    }

    /** Item 3: two live devices with one real serial are reported, and neither takes or creates an asset. */
    public function test_two_devices_sharing_a_real_serial_are_reported_not_guessed(): void
    {
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'SOMETHING-ELSE', 'serial_number' => 'SHARED123']);
        $this->device('1', ['serial' => 'SHARED123']);
        $this->device('2', ['serial' => 'SHARED123']);

        $result = $this->service()->sync();

        $this->assertSame(1, Asset::count(), 'no duplicate carrying the same serial');
        $this->assertNull($asset->fresh()->litsrmm_device_id);
        $this->assertSame(0, $result->created);
        $this->assertCount(2, array_filter($result->skippedMessages, fn ($m) => str_contains($m, 'serial')));
    }

    /** Item 4: a replacement PC that reuses a hostname does not take over the old machine's asset. */
    public function test_the_hostname_does_not_match_when_the_real_serials_differ(): void
    {
        $old = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'serial_number' => 'OLDSERIAL9']);
        $row = $this->device('1');

        $result = $this->service()->sync();

        $this->assertNull($old->fresh()->litsrmm_device_id);
        $this->assertSame('OLDSERIAL9', $old->fresh()->serial_number);
        $this->assertSame(1, $result->created);
        $this->assertSame('SN1REAL0', Asset::where('litsrmm_device_id', $row['id'])->sole()->serial_number);
    }

    public function test_the_hostname_still_matches_when_only_one_side_has_a_real_serial(): void
    {
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'serial_number' => 'System Serial Number']);
        $row = $this->device('1');

        $this->service()->sync();

        $this->assertSame($row['id'], $asset->fresh()->litsrmm_device_id);
        $this->assertSame(1, Asset::count());
    }

    /** Follow-up item 4: a retired device's asset gets a reversible inactive status. */
    public function test_a_retired_device_marks_its_asset_inactive_reversibly(): void
    {
        $row = $this->device('1', ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'is_active' => true, 'litsrmm_device_id' => $row['id']]);

        $this->service()->sync();

        $asset->refresh();
        $this->assertFalse((bool) $asset->is_active);
        $this->assertNotNull($asset->litsrmm_retired_at);
        $this->assertSame($row['id'], $asset->litsrmm_device_id);
        $this->assertFalse($asset->trashed());
    }

    public function test_a_retired_asset_is_not_rematched_by_hostname(): void
    {
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'serial_number' => null, 'is_active' => false, 'litsrmm_retired_at' => now()]);
        $this->device('1', ['serial' => null]);

        $this->service()->sync();

        $this->assertNull($asset->fresh()->litsrmm_device_id);
        $this->assertFalse((bool) $asset->fresh()->is_active);
    }

    public function test_a_retired_asset_reclaimed_by_its_real_serial_is_reactivated(): void
    {
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'OLD-NAME', 'serial_number' => 'SN1REAL0', 'is_active' => false, 'litsrmm_retired_at' => now()]);
        $row = $this->device('1');

        $this->service()->sync();

        $asset->refresh();
        $this->assertSame($row['id'], $asset->litsrmm_device_id);
        $this->assertTrue((bool) $asset->is_active);
        $this->assertNull($asset->litsrmm_retired_at);
    }

    public function test_an_asset_an_operator_made_inactive_is_not_reactivated(): void
    {
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'OLD-NAME', 'serial_number' => 'SN1REAL0', 'is_active' => false, 'litsrmm_retired_at' => null]);
        $this->device('1');

        $this->service()->sync();

        $this->assertFalse((bool) $asset->fresh()->is_active);
    }

    // ---- Review of #4485 round 2: retirement is reversible on every path ----

    public function test_a_device_back_from_retirement_reactivates_its_own_asset(): void
    {
        $row = $this->device('1', ['serial' => 'Default string', 'enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'serial_number' => null, 'is_active' => true, 'litsrmm_device_id' => $row['id']]);
        $this->service()->sync();
        $this->assertFalse((bool) $asset->fresh()->is_active);

        $this->rows = [];
        $this->device('1', ['serial' => 'Default string']);
        $result = $this->service()->sync();

        $asset->refresh();
        $this->assertSame(1, Asset::count(), 'no second, billable asset');
        $this->assertSame($row['id'], $asset->litsrmm_device_id);
        $this->assertTrue((bool) $asset->is_active);
        $this->assertNull($asset->litsrmm_retired_at);
        $this->assertSame(0, $result->created);
    }

    public function test_a_machine_reenrolled_after_retirement_takes_back_its_asset_by_hostname(): void
    {
        $retired = ['serial' => 'Default string', 'enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z'];
        $old = $this->device('1', $retired);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'serial_number' => null, 'is_active' => true, 'litsrmm_device_id' => $old['id']]);
        $this->service()->sync();
        $this->assertFalse((bool) $asset->fresh()->is_active);

        $this->rows = [];
        $this->device('1', $retired);
        $new = $this->device('2', ['hostname' => 'WORKSTATION-1', 'serial' => 'Default string']);
        $result = $this->service()->sync();

        $asset->refresh();
        $this->assertSame(1, Asset::count(), 'no second, billable asset');
        $this->assertSame($new['id'], $asset->litsrmm_device_id);
        $this->assertTrue((bool) $asset->is_active);
        $this->assertNull($asset->litsrmm_retired_at);
        $this->assertSame(0, $result->created);
    }

    public function test_a_person_reactivating_a_retired_asset_ends_the_syncs_claim(): void
    {
        $row = $this->device('1', ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'is_active' => true, 'litsrmm_device_id' => $row['id']]);
        $this->service()->sync();
        $this->assertNotNull($asset->fresh()->litsrmm_retired_at);

        $asset->fresh()->forceFill(['is_active' => true])->save();

        $asset->refresh();
        $this->assertNull($asset->litsrmm_retired_at);
        $this->assertNull($asset->litsrmm_device_id);

        $result = $this->service()->sync();

        $asset->refresh();
        $this->assertTrue((bool) $asset->is_active, 'the device still reads retired; the person decided');
        $this->assertNull($asset->litsrmm_retired_at);
        $this->assertSame(0, $result->deactivated);
    }

    public function test_a_retired_asset_a_person_reactivated_then_made_inactive_stays_inactive(): void
    {
        $row = $this->device('1', ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'serial_number' => 'SN1REAL0', 'is_active' => true, 'litsrmm_device_id' => $row['id']]);
        $this->service()->sync();

        $asset->fresh()->forceFill(['is_active' => true])->save();
        $asset->fresh()->forceFill(['is_active' => false])->save();

        $this->rows = [];
        $this->device('1');
        $this->service()->sync();

        $asset->refresh();
        $this->assertFalse((bool) $asset->is_active, 'an asset a person made inactive is never reactivated');
        $this->assertNull($asset->litsrmm_retired_at);
    }

    private function licenses(Client $client): array
    {
        $of = fn (string $sku) => License::where('client_id', $client->id)
            ->whereHas('licenseType', fn ($q) => $q->where('vendor', 'litsrmm')->where('vendor_sku_id', $sku))
            ->first();

        return ['server' => $of('rmm_server'), 'workstation' => $of('rmm_workstation')];
    }

    public function test_agent_devices_become_server_and_workstation_seats(): void
    {
        $this->device('1');
        $this->device('2');
        $this->device('3', ['osName' => 'Windows Server 2022 Standard']);

        $this->service()->sync();

        $seats = $this->licenses($this->client);
        $this->assertSame(2, $seats['workstation']->quantity);
        $this->assertSame('active', $seats['workstation']->status);
        $this->assertSame(1, $seats['server']->quantity);
        $this->assertSame(self::VENDOR_CLIENT, $seats['server']->vendor_ref);
        $this->assertNotNull($seats['server']->synced_at);
        $this->assertSame('LITSRMM — Workstation', LicenseType::where('vendor', 'litsrmm')->where('vendor_sku_id', 'rmm_workstation')->sole()->name);
    }

    public function test_devices_without_the_agent_and_retired_devices_are_not_seats(): void
    {
        // The owner's ruling: only machines the RMM actually manages are billed.
        // The vendor's list also carries machines known only from Huntress or
        // Control D.
        $this->device('1');
        $this->device('2', ['agentVersion' => null, 'osName' => null, 'lastUser' => null, 'lastSeen' => null, 'availabilityState' => 'offline']);
        $this->device('3', ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
        $this->device('4', ['enrollmentState' => 'enrolled', 'availabilityState' => 'retired']);

        $this->service()->sync();

        $seats = $this->licenses($this->client);
        $this->assertSame(1, $seats['workstation']->quantity);
        $this->assertSame(0, $seats['server']->quantity);
        $this->assertSame('suspended', $seats['server']->status);
    }

    public function test_a_failed_read_leaves_the_seats_alone(): void
    {
        $this->device('1');
        $this->service()->sync();
        $this->listStatus = 500;

        $this->service()->sync();

        $this->assertSame(1, $this->licenses($this->client)['workstation']->quantity);
    }

    public function test_an_unmapped_client_loses_its_seats(): void
    {
        $this->device('1');
        $this->service()->sync();
        $this->client->update(['litsrmm_client_id' => null]);

        $this->service()->sync();

        $seat = $this->licenses($this->client)['workstation'];
        $this->assertSame(0, $seat->quantity);
        $this->assertSame('suspended', $seat->status);
    }

    // ---- one client at a time (the client page's Sync button) ----

    public function test_a_single_client_sync_touches_only_that_client(): void
    {
        $other = $this->mappedClient(self::OTHER_VENDOR_CLIENT);
        $otherAsset = Asset::factory()->create(['client_id' => $other->id, 'litsrmm_device_id' => '8f14e45f-ceea-467a-9f38-000000000050', 'last_user' => 'untouched']);
        $formerly = $this->mappedClient(null);
        $orphan = Asset::factory()->create(['client_id' => $formerly->id, 'litsrmm_device_id' => '8f14e45f-ceea-467a-9f38-000000000060']);
        $this->device('1');
        $this->device('50', ['clientId' => self::OTHER_VENDOR_CLIENT, 'lastUser' => 'changed']);

        $result = $this->service()->sync($this->client);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, Asset::where('client_id', $this->client->id)->count());
        $this->assertSame('untouched', $otherAsset->fresh()->last_user, 'another client is not written');
        $this->assertNotNull($orphan->fresh()->litsrmm_device_id, 'the estate-wide unmapped sweep belongs to a full sync');
        $this->assertNull($this->licenses($other)['workstation'], 'nor are its seats');
        $this->assertSame(1, $this->detailRequests(), 'only this client\'s device is read in detail');
    }

    public function test_a_single_client_sync_of_an_unmapped_client_is_refused(): void
    {
        $unmapped = $this->mappedClient(null);
        $this->device('1');

        $result = $this->service()->sync($unmapped);

        $this->assertSame(1, $result->errors);
        $this->assertStringContainsString('not mapped', $result->errorMessages[0]);
        $this->assertSame(0, count($this->history), 'nothing is read for it');
    }

    // ---- #5343: a short or empty list is not the truth ----

    /** @return list<Asset> one linked asset per device number, with a known last_user */
    private function linkedAssets(int $count): array
    {
        $assets = [];
        for ($n = 1; $n <= $count; $n++) {
            $row = $this->device((string) $n);
            $assets[] = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => "WORKSTATION-{$n}", 'litsrmm_device_id' => $row['id'], 'last_user' => 'before']);
        }

        return $assets;
    }

    public function test_an_empty_list_leaves_every_link_and_seat_untouched(): void
    {
        $assets = $this->linkedAssets(2);
        $this->service()->sync();
        $this->assertSame(2, $this->licenses($this->client)['workstation']->quantity);

        $this->rows = [];
        $result = $this->service()->sync();

        foreach ($assets as $asset) {
            $this->assertNotNull($asset->fresh()->litsrmm_device_id, 'an empty answer releases nothing');
            $this->assertTrue((bool) $asset->fresh()->is_active);
        }
        $seat = $this->licenses($this->client)['workstation'];
        $this->assertSame(2, $seat->quantity, 'nor zeroes a seat');
        $this->assertSame('active', $seat->status);
        $this->assertSame(0, $result->deactivated);
        $this->assertSame(1, $result->errors, 'a refusal is never a silent pass');
        $this->assertSame(1, $result->details['short_read_refused']);
        $this->assertStringContainsString('refused as a short read (rule a', $result->errorMessages[0]);
    }

    public function test_an_empty_list_with_live_seats_but_no_links_is_refused(): void
    {
        $this->device('1', ['serial' => null]);
        $this->service()->sync();
        Asset::query()->update(['litsrmm_device_id' => null]);

        $this->rows = [];
        $result = $this->service()->sync();

        $this->assertSame(1, $this->licenses($this->client)['workstation']->quantity);
        $this->assertSame(1, $result->errors);
    }

    public function test_an_empty_list_for_a_client_with_nothing_linked_is_not_refused(): void
    {
        // Positive control: the guard refuses a read that contradicts what we
        // hold, not every empty answer.
        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(0, $this->licenses($this->client)['workstation']->quantity);
    }

    public function test_a_short_list_past_the_bound_is_refused(): void
    {
        $assets = $this->linkedAssets(5);
        $this->rows = array_slice($this->rows, 0, 1);
        $this->rows[0]['lastUser'] = 'changed';

        $result = $this->service()->sync();

        foreach ($assets as $asset) {
            $this->assertNotNull($asset->fresh()->litsrmm_device_id);
            $this->assertSame('before', $asset->fresh()->last_user, 'nothing of a refused client is written');
        }
        $this->assertNull($this->licenses($this->client)['workstation'], 'no seat is written either');
        $this->assertSame(0, $this->detailRequests(), 'nor read in detail');
        $this->assertSame(1, $result->errors);
        $this->assertSame(0, $result->deactivated);
    }

    public function test_a_shrink_within_the_bound_still_releases(): void
    {
        $assets = $this->linkedAssets(5);
        $this->rows = array_slice($this->rows, 0, 2);

        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(3, $result->deactivated, 'three is the floor, so three links still release');
        $this->assertNull($assets[4]->fresh()->litsrmm_device_id);
        $this->assertNotNull($assets[0]->fresh()->litsrmm_device_id);
        $this->assertSame(2, $this->licenses($this->client)['workstation']->quantity);
    }

    public function test_half_of_a_large_estate_leaving_still_releases(): void
    {
        // Past the floor but not past half: a real decommissioning wave.
        $this->linkedAssets(8);
        $this->rows = array_slice($this->rows, 0, 4);

        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(4, $result->deactivated);
    }

    public function test_a_refused_client_is_logged_by_its_psa_id_and_counts_only(): void
    {
        Log::spy();
        $this->linkedAssets(2);
        $this->rows = [];

        $this->service()->sync();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] client refused: short read'
                && $context === ['client_id' => $this->client->id, 'rule' => 'a', 'listed' => 0, 'linked' => 2, 'unlisted' => 2, 'seats_held' => 0, 'seats_read' => 0, 'retired_listed' => 0, 'code' => "{$this->client->id}:a:0:2:2:0:0:0"])
            ->once();
    }

    // ---- #5341 / #5342: an asset a person made inactive ----

    public function test_a_person_made_inactive_asset_is_not_taken_over_by_hostname(): void
    {
        // A replacement PC reusing the name of a decommissioned one.
        $old = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'FRONTDESK', 'serial_number' => null, 'is_active' => false, 'cpu' => 'Old CPU']);
        $row = $this->device('1', ['hostname' => 'FRONTDESK', 'serial' => '5CD123ABC']);
        $this->details[$row['id']] = self::inventory();

        $result = $this->service()->sync();

        $old->refresh();
        $this->assertNull($old->litsrmm_device_id);
        $this->assertNull($old->serial_number);
        $this->assertSame('Old CPU', $old->cpu);
        $this->assertSame(1, $result->created, 'the new machine gets its own, active asset');
        $this->assertTrue((bool) Asset::where('litsrmm_device_id', $row['id'])->sole()->is_active);
    }

    public function test_a_person_made_inactive_asset_is_not_taken_by_a_serialless_device_either(): void
    {
        $old = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'FRONTDESK', 'serial_number' => null, 'is_active' => false]);
        $this->device('1', ['hostname' => 'FRONTDESK', 'serial' => null]);

        $result = $this->service()->sync();

        $this->assertNull($old->fresh()->litsrmm_device_id);
        $this->assertSame(1, $result->created);
    }

    public function test_an_inactive_asset_behind_a_retired_device_is_never_stamped(): void
    {
        // The retirement UPDATE's is_active guard: a stamp here would let the
        // next serial match reactivate an asset a person made inactive.
        $row = $this->device('1', ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'serial_number' => 'SN1REAL0', 'is_active' => false, 'litsrmm_device_id' => $row['id']]);

        $result = $this->service()->sync();

        $this->assertNull($asset->fresh()->litsrmm_retired_at);
        $this->assertFalse((bool) $asset->fresh()->is_active);
        $this->assertSame(0, $result->details['retired']);

        $this->rows = [];
        $this->device('1');
        $this->service()->sync();

        $this->assertFalse((bool) $asset->fresh()->is_active, 'and so it is never reactivated');
    }

    public function test_a_retired_link_retaken_by_a_live_device_is_not_stamped(): void
    {
        // The $kept branch the old UPDATE credited: a live device that takes
        // the asset back rewrites its link, so the retired id no longer names
        // it and it stays active with no stamp.
        $old = $this->device('1', ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'serial_number' => 'SN1REAL0', 'litsrmm_device_id' => $old['id']]);
        $new = $this->device('2', ['hostname' => 'WORKSTATION-1', 'serial' => 'SN1REAL0']);

        $result = $this->service()->sync();

        $this->assertSame($new['id'], $asset->fresh()->litsrmm_device_id);
        $this->assertNull($asset->fresh()->litsrmm_retired_at);
        $this->assertSame(0, $result->details['retired']);
    }

    // ---- #5350: a detail read that fails ----

    public function test_a_device_that_left_before_its_detail_read_is_left_alone_and_not_an_error(): void
    {
        // One 404 among reads that otherwise succeed (#5685: a client whose
        // every read answers 404 is a degraded read instead).
        $this->device('2');
        $row = $this->device('1', ['lastUser' => 'changed']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => $row['id'], 'last_user' => 'before', 'cpu' => 'Known CPU']);
        $this->details[$row['id']] = 404;

        $result = $this->service()->sync();

        $asset->refresh();
        $this->assertSame($row['id'], $asset->litsrmm_device_id, 'its link is kept');
        $this->assertSame('before', $asset->last_user, 'nothing is written for it');
        $this->assertSame(0, $result->errors, 'a device leaving is not a failure');
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->deactivated);
    }

    public function test_a_new_device_that_left_before_its_detail_read_is_not_created(): void
    {
        $this->device('2');
        $row = $this->device('1');
        $this->details[$row['id']] = 404;

        $result = $this->service()->sync();

        $this->assertSame(0, Asset::where('litsrmm_device_id', $row['id'])->count());
        $this->assertSame(1, Asset::count(), 'only the device that answered is created');
        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->skipped);
    }

    // ---- #5360: what the scheduled path must still see ----

    public function test_a_failed_detail_read_is_logged_status_only_by_psa_client_id(): void
    {
        Log::spy();
        $row = $this->device('1');
        $this->details[$row['id']] = 500;

        $this->service()->sync();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] device detail read failed'
                && $context === ['client_id' => $this->client->id, 'kind' => 'http', 'status' => 500])
            ->once();
    }

    public function test_a_refused_guess_is_logged_by_psa_client_id_and_reason_only(): void
    {
        Log::spy();
        Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'serial_number' => null]);
        Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'workstation-1', 'serial_number' => null]);
        $this->device('1', ['serial' => null]);

        $this->service()->sync();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] device not written'
                && $context === ['client_id' => $this->client->id, 'reason' => 'ambiguous_match'])
            ->once();
    }

    public function test_a_malformed_category_reaches_the_result(): void
    {
        $row = $this->device('1');
        $inventory = self::inventory();
        $inventory['disks'] = 'nonsense';
        $this->details[$row['id']] = $inventory;

        $result = $this->service()->sync();

        $this->assertSame(1, $result->errors);
        $this->assertSame("client {$this->client->id}: a device's detail read sent 1 inventory category in a shape this sync does not read; those were not refreshed", $result->errorMessages[0]);
        $this->assertStringContainsString($result->errorMessages[0], LitsrmmAssetSyncService::describe($result));
    }

    // ---- #5361: assets made inactive are counted on their own ----

    public function test_retired_assets_and_released_links_are_counted_apart(): void
    {
        $retired = ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z'];
        $a = $this->device('1', $retired);
        $b = $this->device('2', $retired);
        $this->device('3');
        Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => $a['id']]);
        Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => $b['id']]);
        Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => '8f14e45f-ceea-467a-9f38-000000000099']);

        $result = $this->service()->sync();

        $this->assertSame(2, $result->details['retired']);
        $this->assertSame(1, $result->deactivated, 'only the released link');
        $this->assertStringContainsString('2 asset(s) marked inactive', LitsrmmAssetSyncService::describe($result));
        $this->assertStringContainsString('1 deactivated', LitsrmmAssetSyncService::describe($result));
    }

    public function test_describe_says_nothing_of_retirement_when_none_happened(): void
    {
        $this->device('1');

        $result = $this->service()->sync();

        $this->assertStringNotContainsString('marked inactive', LitsrmmAssetSyncService::describe($result));
    }

    // ---- #5575 / #5576: seats and small clients are bounded too ----

    /**
     * $total agent devices synced once (assets, links and seats made), then
     * every link but the first $keepLinked cleared, so the client's seats are
     * mostly backed by devices the sync never linked (shared serials,
     * ambiguous matches and the like).
     */
    private function seatsMostlyUnlinked(int $total, int $keepLinked): void
    {
        for ($n = 1; $n <= $total; $n++) {
            $this->device((string) $n);
        }
        $this->service()->sync();
        $this->assertSame($total, $this->licenses($this->client)['workstation']->quantity);

        Asset::where('client_id', $this->client->id)
            ->whereNotIn('litsrmm_device_id', array_map(fn ($r) => $r['id'], array_slice($this->rows, 0, $keepLinked)))
            ->update(['litsrmm_device_id' => null]);
    }

    public function test_a_short_list_that_would_cut_the_seats_past_the_bound_is_refused(): void
    {
        // #5575: both linked devices are listed, so no link rule fires; only
        // the seat drop (10 to 3) shows the read is short.
        $this->seatsMostlyUnlinked(10, 2);
        $this->rows = array_slice($this->rows, 0, 3);

        $result = $this->service()->sync();

        $this->assertSame(10, $this->licenses($this->client)['workstation']->quantity, 'the seat is not cut');
        $this->assertSame(1, $result->errors);
        $this->assertSame(1, $result->details['short_read_refused']);
        $this->assertStringContainsString('(rule d:', $result->errorMessages[0]);
        $this->assertStringContainsString('seats 10 held, 3 read', $result->errorMessages[0]);
        $this->assertSame(0, $this->detailRequests(), 'nothing of it is read in detail');
    }

    public function test_a_seat_shrink_within_the_bound_still_applies(): void
    {
        $this->seatsMostlyUnlinked(10, 2);
        $this->rows = array_slice($this->rows, 0, 7);

        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(7, $this->licenses($this->client)['workstation']->quantity, 'three seats is the floor');
    }

    public function test_devices_the_list_has_long_reported_retired_do_not_hide_a_seat_cut(): void
    {
        // Devices retired on earlier runs back none of the seats held but stay
        // on the list; a truncated read returning them with the two linked
        // live devices must not cut 8 seats to 2.
        $this->seatsMostlyUnlinked(8, 2);
        $this->rows = array_slice($this->rows, 0, 2);
        for ($n = 21; $n <= 26; $n++) {
            $this->retiredDevice((string) $n);
        }

        $result = $this->service()->sync();

        $this->assertSame(8, $this->licenses($this->client)['workstation']->quantity, 'the seat is not cut');
        $this->assertSame(1, $result->details['short_read_refused']);
        $this->assertStringContainsString('(rule d:', $result->errorMessages[0]);
        $this->assertSame(0, $this->detailRequests(), 'nothing of it is read in detail');
    }

    public function test_a_read_listing_only_retired_devices_does_not_zero_the_seats(): void
    {
        // A seats-only client: no link, and the read lists only retired devices.
        $this->device('1', ['serial' => null]);
        $this->service()->sync();
        Asset::query()->update(['litsrmm_device_id' => null]);
        $this->rows = [];
        $this->retiredDevice('21');
        $this->retiredDevice('22');

        $result = $this->service()->sync();

        $this->assertSame(1, $this->licenses($this->client)['workstation']->quantity, 'the seat is not cut');
        $this->assertSame(1, $result->details['short_read_refused']);
        $this->assertStringContainsString('(rule d:', $result->errorMessages[0]);
    }

    public function test_a_retirement_wave_past_the_bound_goes_through_the_accept(): void
    {
        $this->seatsMostlyUnlinked(10, 2);
        foreach (array_keys($this->rows) as $i) {
            if ($i >= 2) {
                $this->rows[$i] = array_merge($this->rows[$i], ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
            }
        }

        $refused = $this->service()->sync();

        $this->assertSame(10, $this->licenses($this->client)['workstation']->quantity, 'refused without the accept');
        $this->assertSame(1, $refused->details['short_read_refused']);

        $accepted = $this->service()->sync(null, [self::acceptCode($refused)]);

        $this->assertSame(0, $accepted->errors, implode('; ', $accepted->errorMessages));
        $this->assertSame(2, $this->licenses($this->client)['workstation']->quantity);
        $this->assertSame(1, $accepted->details['short_read_accepted']);
    }

    public function test_a_small_client_whose_every_link_is_unlisted_is_refused(): void
    {
        // #5576: three links, a truncated list holding one unrelated new
        // device. (b) never fires at three links; (c) does.
        $assets = $this->linkedAssets(3);
        $this->service()->sync();
        $this->rows = [];
        $this->device('9');

        $result = $this->service()->sync();

        foreach ($assets as $asset) {
            $this->assertNotNull($asset->fresh()->litsrmm_device_id, 'no link is released');
        }
        $this->assertSame(3, $this->licenses($this->client)['workstation']->quantity, 'nor is the seat cut');
        $this->assertSame(3, Asset::count(), 'nor is the new device created');
        $this->assertSame(1, $result->errors);
        $this->assertSame(1, $result->details['short_read_refused']);
        $this->assertStringContainsString('(rule c:', $result->errorMessages[0]);
    }

    public function test_a_small_client_shrink_within_the_bound_still_releases(): void
    {
        $assets = $this->linkedAssets(3);
        $this->service()->sync();
        $this->rows = array_slice($this->rows, 0, 2);

        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(1, $result->deactivated);
        $this->assertNull($assets[2]->fresh()->litsrmm_device_id);
        $this->assertSame(2, $this->licenses($this->client)['workstation']->quantity);
    }

    public function test_a_seat_only_refusal_names_the_seats_as_its_cause(): void
    {
        // #5585: no link at all, so the message must show the seat count.
        $this->device('1', ['serial' => null]);
        $this->service()->sync();
        Asset::query()->update(['litsrmm_device_id' => null]);
        $this->rows = [];

        $result = $this->service()->sync();

        $this->assertStringContainsString('0 of 0 linked asset(s) unlisted', $result->errorMessages[0]);
        $this->assertStringContainsString('seats 1 held, 0 read', $result->errorMessages[0]);
    }

    // ---- #5577: the admin's per-run, per-client way through ----

    /**
     * The --accept-short-read value the refusal message of $result names: of
     * $clientId when given, else of its only refusal. It is the message's
     * last line taken as it is, untrimmed (#5786): what an admin copies.
     */
    private static function acceptCode(\App\Services\SyncResult $result, ?int $clientId = null): string
    {
        foreach ($result->errorMessages as $message) {
            $lines = explode("\n", $message);
            $line = end($lines);
            if (count($lines) > 1 && LitsrmmAssetSyncService::isRefusalCode($line)
                && ($clientId === null || str_starts_with($line, "{$clientId}:"))) {
                return $line;
            }
        }

        throw new \RuntimeException('no refusal names an accept code: '.implode('; ', $result->errorMessages));
    }

    public function test_an_accepted_short_read_syncs_that_client_for_that_run_only(): void
    {
        Log::spy();
        $assets = $this->linkedAssets(5);
        $this->service()->sync();
        $this->rows = array_slice($this->rows, 0, 1);
        $code = self::acceptCode($this->service()->sync());

        $result = $this->service()->sync(null, [$code]);

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(4, $result->deactivated, 'the accepted shrink releases');
        $this->assertNull($assets[4]->fresh()->litsrmm_device_id);
        $this->assertSame(1, $this->licenses($this->client)['workstation']->quantity, 'and writes the seats');
        $this->assertSame(1, $result->details['short_read_accepted']);
        $this->assertStringContainsString('accepted it for this run', LitsrmmAssetSyncService::describe($result));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] client short read accepted by the operator for this run'
                && ($context['client_id'] ?? null) === $this->client->id && ($context['rule'] ?? null) === 'b')
            ->once();
    }

    public function test_accepting_one_client_does_not_accept_another(): void
    {
        $other = $this->mappedClient(self::OTHER_VENDOR_CLIENT);
        $otherAssets = [];
        for ($n = 51; $n <= 55; $n++) {
            $row = $this->device((string) $n, ['clientId' => self::OTHER_VENDOR_CLIENT]);
            $otherAssets[] = Asset::factory()->create(['client_id' => $other->id, 'litsrmm_device_id' => $row['id']]);
        }
        $this->linkedAssets(5);
        $this->service()->sync();
        $keep = fn ($r) => in_array(substr($r['id'], -2), ['01', '51'], true);
        $this->rows = array_values(array_filter($this->rows, $keep));
        $refused = $this->service()->sync();
        $this->assertSame(2, $refused->errors);
        $code = self::acceptCode($refused, $this->client->id);

        $result = $this->service()->sync(null, [$code]);

        $this->assertSame(1, $result->errors, 'the other client is still refused');
        $this->assertStringContainsString("client {$other->id}: refused", $result->errorMessages[0]);
        foreach ($otherAssets as $asset) {
            $this->assertNotNull($asset->fresh()->litsrmm_device_id);
        }
        $this->assertSame(1, $result->details['short_read_accepted']);
    }

    public function test_the_acceptance_is_not_remembered_by_the_next_run(): void
    {
        $assets = $this->linkedAssets(5);
        $this->rows = [];
        $this->device('9');
        $code = self::acceptCode($this->service()->sync());
        $this->service()->sync(null, [$code]);
        $this->assertNull($assets[0]->fresh()->litsrmm_device_id);

        // The next run, without the option, applies the bound again.
        $this->rows = [];
        $result = $this->service()->sync();

        $this->assertSame(1, $result->errors);
        $this->assertSame(1, $result->details['short_read_refused']);
    }

    public function test_a_machine_reenrolled_under_a_new_id_is_not_counted_unlisted(): void
    {
        // #5577 (1): a re-enrollment wave keeps each machine's real serial, so
        // it takes its own asset back instead of being refused every run.
        $assets = [];
        for ($n = 1; $n <= 5; $n++) {
            $assets[] = Asset::factory()->create(['client_id' => $this->client->id, 'serial_number' => "SN{$n}REAL0", 'litsrmm_device_id' => sprintf('8f14e45f-ceea-467a-9f38-%012d', 100 + $n)]);
            $this->device((string) $n);
        }

        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(5, Asset::count(), 'no second, billable asset');
        $this->assertSame($this->rows[0]['id'], $assets[0]->fresh()->litsrmm_device_id);
    }

    // ---- #5578: many 404s are a degraded read ----

    public function test_a_detail_endpoint_answering_404_for_every_device_is_a_degraded_read(): void
    {
        Log::spy();
        $assets = $this->linkedAssets(3);
        $this->device('4');
        foreach ($this->rows as $row) {
            $this->details[$row['id']] = 404;
        }

        $result = $this->service()->sync();

        $this->assertSame(1, $result->errors, 'a degraded read is never a silent pass');
        $this->assertSame(1, $result->details['degraded_detail_read']);
        $this->assertSame("client {$this->client->id}: 4 of 4 device detail reads answered 404; treated as a degraded read, so nothing of this client was changed", $result->errorMessages[0]);
        $this->assertSame(0, $result->skipped, 'not reported as devices leaving');
        $this->assertSame(3, Asset::count(), 'nothing created');
        foreach ($assets as $asset) {
            $this->assertNotNull($asset->fresh()->litsrmm_device_id);
        }
        $this->assertNull($this->licenses($this->client)['workstation'], 'no seat written');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] client refused: degraded detail read'
                && $context === ['client_id' => $this->client->id, 'reads' => 4, 'not_found' => 4, 'failed_other' => 0])
            ->once();
    }

    public function test_one_404_in_several_reads_stays_a_skip(): void
    {
        $this->linkedAssets(3);
        $this->details[$this->rows[0]['id']] = 404;

        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(1, $result->skipped);
        $this->assertArrayNotHasKey('degraded_detail_read', $result->details);
    }

    public function test_half_the_reads_answering_404_is_not_yet_degraded(): void
    {
        $this->linkedAssets(4);
        $this->details[$this->rows[0]['id']] = 404;
        $this->details[$this->rows[1]['id']] = 404;

        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(2, $result->skipped);
    }

    // ---- C-56: result messages and logs carry PSA ids and counts only ----

    public function test_a_failed_detail_read_message_carries_no_vendor_text_or_hostname(): void
    {
        // #5579: the client's exception message names the vendor endpoint and
        // the device id; none of it reaches the operator text.
        $row = $this->device('1');
        $this->details[$row['id']] = 500;

        $result = $this->service()->sync();

        $this->assertSame("client {$this->client->id}: a device's hardware was not refreshed (detail read failed: HTTP 500); its list facts were still written", $result->errorMessages[0]);
    }

    public function test_a_failure_without_an_http_status_is_logged_as_its_own_kind(): void
    {
        // #5597: a wrong-device answer carries code 0, which is not a status.
        Log::spy();
        $row = $this->device('1');
        $this->details[$row['id']] = self::WRONG_DEVICE;

        $result = $this->service()->sync();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] device detail read failed'
                && $context === ['client_id' => $this->client->id, 'kind' => 'no_http_status'])
            ->once();
        $this->assertStringContainsString('(detail read failed: no HTTP status)', $result->errorMessages[0]);
    }

    public function test_a_failed_list_read_is_status_only(): void
    {
        Log::spy();
        $this->listStatus = 500;

        $result = $this->service()->sync();

        $this->assertSame('Failed to read LITSRMM devices (HTTP 500); nothing was changed', $result->errorMessages[0]);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] device list read failed'
                && $context === ['kind' => 'http', 'status' => 500])
            ->once();
    }

    public function test_refusal_and_drift_messages_name_no_client_or_hostname(): void
    {
        // #5596: the client's name and the device hostnames never reach the
        // result, which feeds describe(), the flash and the command output.
        $this->client->update(['name' => 'Fixture Client Name']);
        $row = $this->device('1', ['hostname' => 'FIXTURE-HOST-1']);
        $inventory = self::inventory();
        $inventory['disks'] = 'nonsense';
        $this->details[$row['id']] = $inventory;
        $drift = $this->service()->sync();

        $this->rows = [];
        $refused = $this->service()->sync();

        foreach ([LitsrmmAssetSyncService::describe($drift), LitsrmmAssetSyncService::describe($refused)] as $text) {
            $this->assertStringNotContainsString('Fixture Client Name', $text);
            $this->assertStringNotContainsString('FIXTURE-HOST-1', $text);
            $this->assertStringNotContainsString($row['id'], $text);
            $this->assertStringContainsString("client {$this->client->id}:", $text);
        }
        $this->assertStringNotContainsString('disks', LitsrmmAssetSyncService::describe($drift));
    }

    public function test_an_unmapped_single_client_is_named_by_its_psa_id(): void
    {
        $unmapped = $this->mappedClient(null);
        $unmapped->update(['name' => 'Fixture Client Name']);

        $result = $this->service()->sync($unmapped);

        $this->assertSame("client {$unmapped->id} is not mapped to LITSRMM, or is not an active client; nothing was read.", $result->errorMessages[0]);
    }

    // ---- #5368: another RMM's live link keeps an asset active ----

    /** Synthetic credential for the other RMMs, so their integration reads as configured. */
    private const OTHER_RMM_CREDENTIAL_FIXTURE = 'fixture-not-a-credential';

    /**
     * Makes $rmm available for the client (Client::availableRmms(): mapped,
     * switched on, configured) and returns the asset columns of its link.
     * 'tactical_reverse' links only by tactical_assets.asset_id (#5591): the
     * returned closure runs after the asset exists.
     *
     * @return array{0: array<string, mixed>, 1: \Closure(Asset): void}
     */
    private function liveOtherRmm(string $rmm): array
    {
        $none = function (Asset $asset): void {};

        switch ($rmm) {
            case 'level':
                Setting::setEncrypted('level_api_key', self::OTHER_RMM_CREDENTIAL_FIXTURE);
                $this->client->update(['level_group_id' => 'level-group-fixture']);

                return [['level_id' => 'level-fixture-01'], $none];
            case 'ninja':
                Setting::setValue('ninja_enabled', '1');
                Setting::setValue('ninja_client_id', 'ninja-client-fixture');
                Setting::setEncrypted('ninja_client_secret', self::OTHER_RMM_CREDENTIAL_FIXTURE);
                $this->client->update(['ninja_org_id' => 4242]);

                return [['ninja_id' => 4242], $none];
            default:
                Setting::setValue('tactical_api_url', 'https://tactical.test');
                Setting::setEncrypted('tactical_api_key', self::OTHER_RMM_CREDENTIAL_FIXTURE);
                $this->client->update(['tactical_site_id' => 7]);
                if ($rmm === 'tactical') {
                    return [['tactical_asset_id' => TacticalAsset::create(['agent_id' => 'agent-fixture-01', 'hostname' => 'WORKSTATION-1'])->id], $none];
                }

                return [[], function (Asset $asset): void {
                    TacticalAsset::create(['agent_id' => 'agent-fixture-02', 'hostname' => 'WORKSTATION-1', 'asset_id' => $asset->id]);
                }];
        }
    }

    /** @return array<string, array{0: string}> */
    public static function otherRmmLinks(): array
    {
        return [
            'level' => ['level'],
            'ninja' => ['ninja'],
            'tactical column' => ['tactical'],
            'tactical_assets.asset_id only (#5591)' => ['tactical_reverse'],
        ];
    }

    private function retiredDevice(string $n): array
    {
        return $this->device($n, ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
    }

    #[DataProvider('otherRmmLinks')]
    public function test_a_retired_device_does_not_deactivate_an_asset_another_rmm_links(string $rmm): void
    {
        [$columns, $after] = $this->liveOtherRmm($rmm);
        $row = $this->retiredDevice('1');
        $asset = Asset::factory()->create(array_merge(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'is_active' => true, 'litsrmm_device_id' => $row['id']], $columns));
        $after($asset);

        $result = $this->service()->sync();

        $asset->refresh();
        $this->assertTrue((bool) $asset->is_active, 'another RMM still reports it');
        $this->assertNull($asset->litsrmm_retired_at);
        $this->assertSame($row['id'], $asset->litsrmm_device_id, 'our link is kept, not released');
        $this->assertSame(0, $result->details['retired']);
        $this->assertSame(0, $result->deactivated);
        $this->assertSame(1, $result->skipped);
        $this->assertStringContainsString('another RMM', $result->skippedMessages[0]);
    }

    /** @return array<string, array{0: string, 1: \Closure(): void}> */
    public static function staleOtherRmmLinks(): array
    {
        return [
            'level, client no longer mapped' => ['level', fn () => Client::query()->update(['level_group_id' => null])],
            'level, integration switched off' => ['level', fn () => Setting::setValue('level_enabled', '0')],
            'ninja, integration switched off' => ['ninja', fn () => Setting::setValue('ninja_enabled', '0')],
            'tactical, client no longer mapped' => ['tactical', fn () => Client::query()->update(['tactical_site_id' => null])],
            'tactical reverse, switched off' => ['tactical_reverse', fn () => Setting::setValue('tactical_enabled', '0')],
        ];
    }

    /** #5581: a column left behind by an RMM the client no longer uses does not hold the asset. */
    #[DataProvider('staleOtherRmmLinks')]
    public function test_a_stale_other_rmm_link_does_not_hold_a_retired_asset(string $rmm, \Closure $stale): void
    {
        [$columns, $after] = $this->liveOtherRmm($rmm);
        $stale();
        $row = $this->retiredDevice('1');
        $asset = Asset::factory()->create(array_merge(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'is_active' => true, 'litsrmm_device_id' => $row['id']], $columns));
        $after($asset);

        $result = $this->service()->sync();

        $asset->refresh();
        $this->assertFalse((bool) $asset->is_active, 'no live RMM reports it, so it is retired');
        $this->assertNotNull($asset->litsrmm_retired_at);
        $this->assertSame($row['id'], $asset->litsrmm_device_id);
        $this->assertSame(1, $result->details['retired']);
        $this->assertSame(0, $result->skipped, implode('; ', $result->skippedMessages));
    }

    public function test_an_empty_level_id_does_not_hold_a_retired_asset(): void
    {
        $this->liveOtherRmm('level');
        $row = $this->retiredDevice('1');
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'is_active' => true, 'litsrmm_device_id' => $row['id'], 'level_id' => '']);

        $result = $this->service()->sync();

        $this->assertFalse((bool) $asset->fresh()->is_active);
        $this->assertSame(1, $result->details['retired']);
        $this->assertSame(0, $result->skipped);
    }

    /** #5594: an inactive asset is never reported as "left active", whatever links it. */
    public function test_an_inactive_asset_another_rmm_links_is_not_reported_left_active(): void
    {
        [$columns] = $this->liveOtherRmm('level');
        $row = $this->retiredDevice('1');
        $asset = Asset::factory()->create(array_merge(['client_id' => $this->client->id, 'is_active' => false, 'litsrmm_device_id' => $row['id']], $columns));

        $result = $this->service()->sync();

        $this->assertFalse((bool) $asset->fresh()->is_active);
        $this->assertNull($asset->fresh()->litsrmm_retired_at);
        $this->assertSame(0, $result->skipped, implode('; ', $result->skippedMessages));
        $this->assertSame(0, $result->details['retired']);
    }

    // ---- #5692 / #5686: rule (d)'s zero arm refuses any retirement to 0 seats ----

    public function test_a_single_device_decommission_is_refused_until_accepted(): void
    {
        // The client's only machine, linked and billed, is retired in LITSRMM.
        $assets = $this->linkedAssets(1);
        $this->service()->sync();
        $this->assertSame(1, $this->licenses($this->client)['workstation']->quantity);
        $this->rows[0] = array_merge($this->rows[0], ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);

        $first = $this->service()->sync();
        $again = $this->service()->sync();

        foreach ([$first, $again] as $refused) {
            $this->assertSame(1, $refused->errors, 'every run refuses it again: never a silent pass');
            $this->assertSame(1, $refused->details['short_read_refused']);
            $this->assertStringContainsString('(rule d:', $refused->errorMessages[0]);
            $this->assertStringContainsString('a read leaving 0 seats is refused whatever the drop, a single-device decommission included', $refused->errorMessages[0]);
            $this->assertStringContainsString('so a later read refused the same way is refused again', $refused->errorMessages[0]);
            $this->assertStringEndsWith("pasted exactly (a bare client id is rejected):\n{$this->client->id}:d:1:1:0:1:0:1", $refused->errorMessages[0]);
        }
        $this->assertTrue((bool) $assets[0]->fresh()->is_active, 'not marked inactive while refused');
        $this->assertSame(1, $this->licenses($this->client)['workstation']->quantity, 'nor the seat cut');

        $accepted = $this->service()->sync(null, [self::acceptCode($again)]);

        $this->assertSame(0, $accepted->errors, implode('; ', $accepted->errorMessages));
        $this->assertSame(1, $accepted->details['short_read_accepted']);
        $this->assertFalse((bool) $assets[0]->fresh()->is_active, 'the accepted decommission retires the asset');
        $this->assertNotNull($assets[0]->fresh()->litsrmm_retired_at);
        $this->assertSame(0, $this->licenses($this->client)['workstation']->quantity);
    }

    public function test_a_retirement_that_leaves_seats_is_not_named_as_the_zero_arm(): void
    {
        // The non-zero arm's message does not claim the zero arm fired.
        $this->seatsMostlyUnlinked(10, 2);
        $this->rows = array_slice($this->rows, 0, 3);

        $result = $this->service()->sync();

        $this->assertStringContainsString('(rule d:', $result->errorMessages[0]);
        $this->assertStringNotContainsString('a read leaving 0 seats', $result->errorMessages[0]);
    }

    // ---- #5684: both conjuncts of rule (d)'s non-zero arm ----

    public function test_a_seat_drop_inside_the_floor_is_not_refused_even_past_half(): void
    {
        // 4 held, 1 read: a drop of 3 is past half but not past the floor.
        $this->seatsMostlyUnlinked(4, 1);
        $this->rows = array_slice($this->rows, 0, 1);

        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(1, $this->licenses($this->client)['workstation']->quantity);
    }

    public function test_a_seat_drop_past_the_floor_but_not_past_half_is_not_refused(): void
    {
        // 10 held, 6 read: a drop of 4 is past the floor but not past half.
        $this->seatsMostlyUnlinked(10, 2);
        $this->rows = array_slice($this->rows, 0, 6);

        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(6, $this->licenses($this->client)['workstation']->quantity);
    }

    public function test_a_half_retired_device_backs_no_seat_and_is_counted_in_the_refusal(): void
    {
        // #5684 / #5687: a seats-only client whose read lists one half-retired
        // and one retired device: 0 seats read, both reported.
        Log::spy();
        $this->device('1', ['serial' => null]);
        $this->service()->sync();
        Asset::query()->update(['litsrmm_device_id' => null]);
        $this->rows = [];
        $this->device('21', ['enrollmentState' => 'enrolled', 'availabilityState' => 'retired']);
        $this->retiredDevice('22');

        $result = $this->service()->sync();

        $this->assertSame(1, $this->licenses($this->client)['workstation']->quantity, 'the seat is not cut');
        $this->assertStringContainsString('seats 1 held, 0 read; 2 listed device(s) retired or half-retired, backing no seat', $result->errorMessages[0]);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] client refused: short read'
                && $context === ['client_id' => $this->client->id, 'rule' => 'd', 'listed' => 2, 'linked' => 0, 'unlisted' => 0, 'seats_held' => 1, 'seats_read' => 0, 'retired_listed' => 2, 'code' => "{$this->client->id}:d:2:0:0:1:0:2"])
            ->once();
    }

    public function test_the_retired_count_tells_two_equal_seat_counts_apart(): void
    {
        // #5687: 10 held, 3 read, refused; the message carries the listed
        // retired devices, which back no seat.
        $this->seatsMostlyUnlinked(10, 2);
        $this->rows = array_slice($this->rows, 0, 3);
        for ($n = 21; $n <= 27; $n++) {
            $this->retiredDevice((string) $n);
        }

        $result = $this->service()->sync();

        $this->assertStringContainsString('seats 10 held, 3 read; 7 listed device(s) retired or half-retired', $result->errorMessages[0]);
    }

    // ---- #5680 / #5688: the accept passes only the refusal the admin checked ----

    public function test_an_accept_does_not_pass_a_different_refusal_of_the_same_client(): void
    {
        // Run 1 refuses under rule b; the admin checks it. Their accepted run
        // reads an empty page instead (rule a), which must stay refused.
        $assets = $this->linkedAssets(6);
        $this->service()->sync();
        $this->rows = array_slice($this->rows, 0, 1);
        $code = self::acceptCode($this->service()->sync());
        $this->assertStringStartsWith("{$this->client->id}:b:", $code);

        $this->rows = [];
        $result = $this->service()->sync(null, [$code]);

        foreach ($assets as $asset) {
            $this->assertNotNull($asset->fresh()->litsrmm_device_id, 'the empty page releases nothing');
        }
        $this->assertSame(6, $this->licenses($this->client)['workstation']->quantity, 'nor zeroes the seats');
        $this->assertSame(1, $result->details['short_read_refused']);
        $this->assertArrayNotHasKey('short_read_accepted', $result->details);
        $this->assertStringContainsString('(rule a:', $result->errorMessages[0]);
        $this->assertStringContainsString('named a different refusal, so it was not passed', $result->errorMessages[0]);
        $this->assertSame([$this->client->id], $result->details['short_read_accept_unused']);
    }

    public function test_an_accept_does_not_pass_the_same_rule_with_other_counts(): void
    {
        // Same rule (b), but the read is shorter than the one the admin checked.
        $this->linkedAssets(8);
        $this->service()->sync();
        $all = $this->rows;
        $this->rows = array_slice($all, 0, 3);
        $code = self::acceptCode($this->service()->sync());

        $this->rows = array_slice($all, 0, 1);
        $result = $this->service()->sync(null, [$code]);

        $this->assertSame(1, $result->details['short_read_refused']);
        $this->assertStringContainsString('(rule b:', $result->errorMessages[0]);
        $this->assertSame(8, $this->licenses($this->client)['workstation']->quantity);
    }

    public function test_an_accept_naming_no_refusal_is_reported_unused(): void
    {
        Log::spy();
        $this->linkedAssets(2);
        $other = $this->mappedClient(self::OTHER_VENDOR_CLIENT);

        $result = $this->service()->sync($this->client, ["{$other->id}:a:0:1:1:0:0:0", "{$this->client->id}:a:0:2:2:0:0:0", '999999:a:0:1:1:0:0:0']);

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertArrayNotHasKey('short_read_accepted', $result->details);
        $ids = [$this->client->id, $other->id, 999999];
        sort($ids);
        $this->assertSame($ids, $result->details['short_read_accept_unused']);
        $this->assertStringContainsString('For each of PSA client ID(s) '.implode(', ', $ids).', an --accept-short-read code given for it passed nothing.', LitsrmmAssetSyncService::describe($result));
        $this->assertStringNotContainsString('synced past the short-read bound', LitsrmmAssetSyncService::describe($result));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] for each of these PSA client ids, an --accept-short-read code given for it passed nothing'
                && $context === ['client_ids' => $ids])
            ->once();
    }

    public function test_a_used_accept_is_not_reported_unused(): void
    {
        $this->linkedAssets(5);
        $this->service()->sync();
        $this->rows = array_slice($this->rows, 0, 1);
        $code = self::acceptCode($this->service()->sync());

        $result = $this->service()->sync(null, [$code]);

        $this->assertSame(1, $result->details['short_read_accepted']);
        $this->assertArrayNotHasKey('short_read_accept_unused', $result->details);
        $this->assertStringNotContainsString('passed nothing', LitsrmmAssetSyncService::describe($result));
    }

    public function test_an_accepted_client_then_degraded_is_not_reported_as_synced(): void
    {
        // #5681: rule b accepted, then most detail reads answer 404.
        Log::spy();
        $this->linkedAssets(5);
        $this->service()->sync();
        $this->rows = array_slice($this->rows, 0, 1);
        $this->device('9');
        $this->device('10');
        $code = self::acceptCode($this->service()->sync());
        foreach ($this->rows as $row) {
            $this->details[$row['id']] = 404;
        }

        $result = $this->service()->sync(null, [$code]);

        $this->assertSame(1, $result->details['degraded_detail_read']);
        $this->assertArrayNotHasKey('short_read_accepted', $result->details);
        $this->assertStringNotContainsString('synced past the short-read bound', LitsrmmAssetSyncService::describe($result));
        $this->assertSame([$this->client->id], $result->details['short_read_accept_unused']);
        $this->assertSame(5, Asset::whereNotNull('litsrmm_device_id')->count(), 'nothing released');
        // #5790: at every level and through Log::log / Log::write, not only warning.
        self::assertNeverLogged('[LitsrmmAssetSync] client short read accepted by the operator for this run');
    }

    // ---- #5685 / #5691 / #5682: degraded detail reads ----

    public function test_a_one_device_client_whose_only_detail_read_answers_404_is_degraded(): void
    {
        $assets = $this->linkedAssets(1);
        $this->details[$this->rows[0]['id']] = 404;

        $result = $this->service()->sync();

        $this->assertSame(1, $result->errors, 'never a silent pass');
        $this->assertSame(1, $result->details['degraded_detail_read']);
        $this->assertSame("client {$this->client->id}: 1 of 1 device detail reads answered 404; treated as a degraded read, so nothing of this client was changed", $result->errorMessages[0]);
        $this->assertSame(0, $result->skipped);
        $this->assertNull($this->licenses($this->client)['workstation'], 'no seat written');
        $this->assertNotNull($assets[0]->fresh()->litsrmm_device_id);
    }

    public function test_a_mostly_failed_detail_read_mixing_404_and_5xx_is_degraded(): void
    {
        // The issue's 404, 404, 500, 500: four of four failed.
        Log::spy();
        $assets = $this->linkedAssets(4);
        $this->details[$this->rows[0]['id']] = 404;
        $this->details[$this->rows[1]['id']] = 404;
        $this->details[$this->rows[2]['id']] = 500;
        $this->details[$this->rows[3]['id']] = 500;

        $result = $this->service()->sync();

        $this->assertSame(1, $result->details['degraded_detail_read']);
        $this->assertSame(["client {$this->client->id}: 2 of 4 device detail reads answered 404 and 2 failed otherwise; treated as a degraded read, so nothing of this client was changed"], $result->errorMessages, '#5682: no message claims list facts were written');
        $this->assertNull($this->licenses($this->client)['workstation']);
        foreach ($assets as $asset) {
            $this->assertSame('before', $asset->fresh()->last_user, 'nothing written');
        }
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] client refused: degraded detail read'
                && $context === ['client_id' => $this->client->id, 'reads' => 4, 'not_found' => 2, 'failed_other' => 2])
            ->once();
    }

    public function test_one_404_and_one_5xx_in_three_reads_is_degraded(): void
    {
        // Two of three failed, one of them a 404: more than one and more than half.
        $this->linkedAssets(3);
        $this->details[$this->rows[0]['id']] = 404;
        $this->details[$this->rows[1]['id']] = 500;

        $result = $this->service()->sync();

        $this->assertSame(1, $result->details['degraded_detail_read'] ?? 0);
        $this->assertNull($this->licenses($this->client)['workstation']);
    }

    public function test_one_404_and_one_5xx_in_four_reads_is_not_degraded(): void
    {
        // Half failed: not more than half, so the client is written.
        $this->linkedAssets(4);
        $this->details[$this->rows[0]['id']] = 404;
        $this->details[$this->rows[1]['id']] = 500;

        $result = $this->service()->sync();

        $this->assertArrayNotHasKey('degraded_detail_read', $result->details);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(["client {$this->client->id}: a device's hardware was not refreshed (detail read failed: HTTP 500); its list facts were still written"], $result->errorMessages);
        $this->assertSame(4, $this->licenses($this->client)['workstation']->quantity);
    }

    public function test_5xx_answers_with_no_404_are_not_a_degraded_read(): void
    {
        // Ruling #5578 is about 404s: without one, failures cost only hardware.
        $this->linkedAssets(2);
        $this->details[$this->rows[0]['id']] = 500;
        $this->details[$this->rows[1]['id']] = 500;

        $result = $this->service()->sync();

        $this->assertArrayNotHasKey('degraded_detail_read', $result->details);
        $this->assertSame(2, $result->errors);
        $this->assertSame(2, $this->licenses($this->client)['workstation']->quantity);
    }

    public function test_a_single_404_in_a_larger_healthy_client_stays_a_skip(): void
    {
        $assets = $this->linkedAssets(5);
        $this->details[$this->rows[2]['id']] = 404;

        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(1, $result->skipped);
        $this->assertArrayNotHasKey('degraded_detail_read', $result->details);
        $this->assertSame(5, $this->licenses($this->client)['workstation']->quantity);
        $this->assertNotNull($assets[2]->fresh()->litsrmm_device_id);
    }

    // ---- #5683: the re-enrolled-by-serial exemption is one-to-one ----

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function devicesThatReenrollNothing(): array
    {
        return [
            'agentless' => [['agentVersion' => null]],
            'half-retired' => [['enrollmentState' => 'enrolled', 'availabilityState' => 'retired']],
            'retired' => [['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']],
        ];
    }

    /**
     * Four links to device ids the read no longer lists, on assets whose real
     * serials the read carries only on devices the bound does not take as a
     * re-enrollment. syncClient() would still relink by an agentless
     * device's serial (#5796); the bound errs towards refusing. The
     * 'retired' row is a control: it was refused before #5683 too (#5792).
     */
    #[DataProvider('devicesThatReenrollNothing')]
    public function test_a_device_the_bound_does_not_take_as_re_enrolled_does_not_exempt_a_link(array $state): void
    {
        $this->linkedAssets(1);
        for ($n = 1; $n <= 4; $n++) {
            Asset::factory()->create(['client_id' => $this->client->id, 'serial_number' => "SN{$n}REAL0", 'litsrmm_device_id' => sprintf('8f14e45f-ceea-467a-9f38-%012d', 100 + $n)]);
            $this->device((string) (10 + $n), array_merge(['serial' => "SN{$n}REAL0"], $state));
        }

        $result = $this->service()->sync();

        $this->assertSame(1, $result->details['short_read_refused'] ?? 0, implode('; ', $result->errorMessages));
        $this->assertStringContainsString('(rule b:', $result->errorMessages[0]);
        $this->assertStringContainsString('4 of 5 linked asset(s) unlisted', $result->errorMessages[0]);
        $this->assertSame(5, Asset::whereNotNull('litsrmm_device_id')->count(), 'no link released');
    }

    public function test_one_listed_serial_does_not_exempt_several_links(): void
    {
        // The issue's case: five links share one serial; the read lists one
        // device carrying it, already linked by id to a sixth asset.
        $row = $this->device('1', ['serial' => 'SNSHARED01']);
        Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => $row['id']]);
        for ($n = 1; $n <= 5; $n++) {
            Asset::factory()->create(['client_id' => $this->client->id, 'serial_number' => 'SNSHARED01', 'litsrmm_device_id' => sprintf('8f14e45f-ceea-467a-9f38-%012d', 100 + $n)]);
        }

        $result = $this->service()->sync();

        $this->assertSame(1, $result->details['short_read_refused'] ?? 0, implode('; ', $result->errorMessages));
        $this->assertStringContainsString('5 of 6 linked asset(s) unlisted', $result->errorMessages[0]);
        $this->assertSame(6, Asset::whereNotNull('litsrmm_device_id')->count(), 'no link released');
    }

    public function test_one_unlinked_device_does_not_exempt_two_links_sharing_its_serial(): void
    {
        $this->linkedAssets(1);
        for ($n = 1; $n <= 4; $n++) {
            Asset::factory()->create(['client_id' => $this->client->id, 'serial_number' => 'SNSHARED01', 'litsrmm_device_id' => sprintf('8f14e45f-ceea-467a-9f38-%012d', 100 + $n)]);
        }
        $this->device('9', ['serial' => 'SNSHARED01']);

        $result = $this->service()->sync();

        $this->assertSame(1, $result->details['short_read_refused'] ?? 0, implode('; ', $result->errorMessages));
        $this->assertStringContainsString('4 of 5 linked asset(s) unlisted', $result->errorMessages[0]);
    }

    public function test_a_404_on_the_re_enrolled_device_keeps_the_link_the_exemption_spared(): void
    {
        // Seven links: three listed, three gone, and one whose machine the
        // read lists re-enrolled under a new id, whose detail read answers
        // 404. Rule (b) counts 3 of 7 unlisted, so at most those 3 may go.
        $this->linkedAssets(3);
        for ($n = 1; $n <= 3; $n++) {
            Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => sprintf('8f14e45f-ceea-467a-9f38-%012d', 100 + $n)]);
        }
        $oldId = sprintf('8f14e45f-ceea-467a-9f38-%012d', 104);
        $reenrolled = Asset::factory()->create(['client_id' => $this->client->id, 'serial_number' => 'SN9REAL0', 'litsrmm_device_id' => $oldId]);
        $row = $this->device('9');
        $this->details[$row['id']] = 404;

        $result = $this->service()->sync();

        $this->assertSame(0, $result->details['short_read_refused'] ?? 0, implode('; ', $result->errorMessages));
        $this->assertSame(0, $result->details['degraded_detail_read'] ?? 0);
        $this->assertSame(0, $result->created);
        $this->assertSame($oldId, $reenrolled->fresh()->litsrmm_device_id, 'the link rule (b) did not count is kept');
        $this->assertSame(4, Asset::whereNotNull('litsrmm_device_id')->count(), 'only the 3 unlisted links are released');
    }

    public function test_two_listed_devices_sharing_a_serial_exempt_no_link(): void
    {
        // Each serial is on two live devices, which syncClient() refuses as a
        // shared serial, so none of the four links is re-enrolled.
        $this->linkedAssets(1);
        for ($n = 1; $n <= 4; $n++) {
            Asset::factory()->create(['client_id' => $this->client->id, 'serial_number' => "SN{$n}REAL0", 'litsrmm_device_id' => sprintf('8f14e45f-ceea-467a-9f38-%012d', 100 + $n)]);
            $this->device((string) (10 + $n), ['serial' => "SN{$n}REAL0"]);
            $this->device((string) (20 + $n), ['serial' => "SN{$n}REAL0"]);
        }

        $result = $this->service()->sync();

        $this->assertSame(1, $result->details['short_read_refused'] ?? 0, implode('; ', $result->errorMessages));
        $this->assertStringContainsString('4 of 5 linked asset(s) unlisted', $result->errorMessages[0]);
    }

    // ---- #5560 residuals in these files: #5583, #5584, #5586, #5589, #5593 ----

    public function test_a_404_skip_names_what_was_observed_not_a_cause(): void
    {
        // #5583: a 404 does not prove the device left.
        Log::spy();
        $this->linkedAssets(3);
        $this->details[$this->rows[0]['id']] = 404;

        $result = $this->service()->sync();

        $this->assertSame(['WORKSTATION-1: its detail read answered 404 (not found); left as it was'], $result->skippedMessages);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] device not written'
                && $context === ['client_id' => $this->client->id, 'reason' => 'detail_read_not_found'])
            ->once();
    }

    public function test_a_malformed_category_leaves_the_assets_facts_for_it_unchanged(): void
    {
        // #5584: "not refreshed" means the existing disk facts stay.
        $row = $this->device('1');
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => $row['id'], 'disk_summary' => 'C: 100 GB', 'cpu' => 'Old CPU']);
        $inventory = self::inventory();
        $inventory['disks'] = 'nonsense';
        $this->details[$row['id']] = $inventory;

        $this->service()->sync();

        $asset->refresh();
        $this->assertSame('C: 100 GB', $asset->disk_summary, 'the malformed category is not refreshed');
        $this->assertSame('AMD Ryzen 7 5700G with Radeon Graphics', $asset->cpu, 'the well-formed ones are');
    }

    public function test_a_real_serial_links_a_person_made_inactive_asset_and_leaves_it_inactive(): void
    {
        // #5586
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'OLD-NAME', 'serial_number' => 'SN1REAL0', 'is_active' => false, 'litsrmm_retired_at' => null]);
        $row = $this->device('1');

        $result = $this->service()->sync();

        $asset->refresh();
        $this->assertSame($row['id'], $asset->litsrmm_device_id, 'linked by its real serial');
        $this->assertFalse((bool) $asset->is_active, 'and left inactive');
        $this->assertNull($asset->litsrmm_retired_at);
        $this->assertSame(0, $result->created, 'no second asset');
        $this->assertSame(1, Asset::count());
    }

    public function test_a_device_whose_detail_read_answered_404_still_counts_for_seats(): void
    {
        // #5589: seats come from the list.
        $this->linkedAssets(3);
        $this->details[$this->rows[0]['id']] = 404;

        $this->service()->sync();

        $this->assertSame(3, $this->licenses($this->client)['workstation']->quantity);
    }

    public function test_an_empty_list_does_not_refuse_a_client_whose_links_the_sync_retired(): void
    {
        // CONTROL (#5792): green before #5745 too. #5593 (1): a retired asset keeps its link whatever the read says, so
        // it is not a link the empty read could release.
        $row = $this->retiredDevice('1');
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => $row['id']]);
        $this->service()->sync();
        $this->assertNotNull($asset->fresh()->litsrmm_retired_at);

        $this->rows = [];
        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame($row['id'], $asset->fresh()->litsrmm_device_id);
    }

    public function test_an_empty_list_does_not_refuse_a_client_whose_seats_are_already_0(): void
    {
        // CONTROL (#5792): green before #5745 too. #5593 (2): a suspended, zero-quantity seat is not a seat held.
        $this->device('1', ['serial' => null]);
        $this->service()->sync();
        Asset::query()->update(['litsrmm_device_id' => null]);
        License::query()->update(['quantity' => 0, 'status' => 'suspended']);

        $this->rows = [];
        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
    }

    public function test_another_vendors_seats_do_not_make_an_empty_list_refused(): void
    {
        // CONTROL (#5792): green before #5745 too. #5593 (3): only LITSRMM seats count.
        $type = LicenseType::create(['vendor' => 'other-vendor-fixture', 'vendor_sku_id' => 'seat', 'name' => 'Other', 'is_active' => true]);
        License::create(['license_type_id' => $type->id, 'client_id' => $this->client->id, 'vendor_ref' => 'fixture', 'quantity' => 5, 'status' => 'active', 'synced_at' => now()]);

        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
    }

    /** #5689, ruled (a): an offline Tactical agent row still holds, and the operator is told every run. CONTROL (#5792). */
    public function test_an_offline_tactical_agent_row_still_holds_a_retired_asset(): void
    {
        $this->liveOtherRmm('tactical');
        $row = $this->retiredDevice('1');
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'WORKSTATION-1', 'is_active' => true, 'litsrmm_device_id' => $row['id']]);
        TacticalAsset::create(['agent_id' => 'agent-fixture-03', 'hostname' => 'WORKSTATION-1', 'asset_id' => $asset->id, 'status' => 'offline', 'last_seen_at' => now()->subDays(400)]);

        foreach ([1, 2] as $run) {
            $result = $this->service()->sync();

            $this->assertTrue((bool) $asset->fresh()->is_active, "run {$run}: held");
            $this->assertSame(1, $result->skipped, "run {$run}: reported");
        }
    }

    // ---- L4 (#5745 residuals): the copyable code, the commit ordering ----

    /** Every level the logger exposes, and the two level-first generic calls (G-14). */
    private const LOG_LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** No record at any level, nor through Log::log / Log::write, carries $message. */
    private static function assertNeverLogged(string $message): void
    {
        foreach (self::LOG_LEVELS as $level) {
            Log::shouldNotHaveReceived($level, fn ($m = null) => $m === $message);
        }
        foreach (['log', 'write'] as $generic) {
            Log::shouldNotHaveReceived($generic, fn ($level = null, $m = null) => $m === $message);
        }
    }

    /** The lines of $text that are a refusal code exactly, untrimmed: what an admin copies. */
    private static function codeLines(string $text): array
    {
        return array_values(array_filter(explode("\n", $text), fn (string $line) => LitsrmmAssetSyncService::isRefusalCode($line)));
    }

    public function test_the_code_line_of_the_persisted_record_is_accepted_as_copied(): void
    {
        // #5786: the error record (what the command prints and a Sync button
        // flashes) ends with the code on a line of its own, nothing after it.
        $assets = $this->linkedAssets(5);
        $this->service()->sync();
        $this->rows = array_slice($this->rows, 0, 1);

        $refused = $this->service()->sync();

        $lines = explode("\n", $refused->errorMessages[0]);
        $copied = end($lines);
        $this->assertSame("{$this->client->id}:b:1:5:4:5:1:0", $copied, 'the last line is the code and nothing else');
        // G-14: the instruction holds on the flash too, where the line break shows as a space.
        $this->assertStringContainsString('set to the code that follows, pasted exactly (a bare client id is rejected):', $refused->errorMessages[0]);
        $this->assertStringNotContainsString('next line', LitsrmmAssetSyncService::describe($refused));
        $this->assertTrue(LitsrmmAssetSyncService::isRefusalCode($copied));

        $accepted = $this->service()->sync(null, [$copied]);

        $this->assertSame(0, $accepted->errors, implode('; ', $accepted->errorMessages));
        $this->assertSame(1, $accepted->details['short_read_accepted']);
        $this->assertNull($assets[4]->fresh()->litsrmm_device_id);
    }

    public function test_the_button_text_keeps_every_code_on_a_line_of_its_own(): void
    {
        // describe() is the flash: two refused clients. No '.' or ';' may
        // follow a code on its line.
        $other = $this->mappedClient(self::OTHER_VENDOR_CLIENT);
        for ($n = 51; $n <= 55; $n++) {
            $row = $this->device((string) $n, ['clientId' => self::OTHER_VENDOR_CLIENT]);
            Asset::factory()->create(['client_id' => $other->id, 'litsrmm_device_id' => $row['id']]);
        }
        $this->linkedAssets(5);
        $this->service()->sync();
        $keep = fn ($r) => in_array(substr($r['id'], -2), ['01', '51'], true);
        $this->rows = array_values(array_filter($this->rows, $keep));

        $text = LitsrmmAssetSyncService::describe($this->service()->sync());

        $codes = self::codeLines($text);
        sort($codes);
        $expected = ["{$this->client->id}:b:1:5:4:5:1:0", "{$other->id}:b:1:5:4:5:1:0"];
        sort($expected);
        $this->assertSame($expected, $codes, $text);
        $lines = explode("\n", $text);
        $this->assertTrue(LitsrmmAssetSyncService::isRefusalCode(end($lines)), 'nothing is appended after the last code');

        $accepted = $this->service()->sync(null, $codes);

        $this->assertSame(0, $accepted->errors, implode('; ', $accepted->errorMessages));
        $this->assertSame(2, $accepted->details['short_read_accepted'], 'both codes, as copied from the flash, pass');
    }

    public function test_the_flash_puts_refusals_last_so_nothing_follows_a_code(): void
    {
        // #5786: a refusal beside another error and a skip.
        $refusal = "client 12: refused as a short read (rule b: fixture); nothing of this client was changed.\n12:b:1:5:4:5:1:0";
        $result = new \App\Services\SyncResult;
        $result->recordError($refusal);
        $result->recordError('client 13: a fixture error');
        $result->recordSkipped('WORKSTATION-1: a fixture skip');

        $text = LitsrmmAssetSyncService::describe($result);

        $this->assertStringEndsWith(" Errors: client 13: a fixture error; {$refusal}", $text);
        $this->assertStringContainsString(' Skipped: WORKSTATION-1: a fixture skip.', $text);
        $this->assertSame(['12:b:1:5:4:5:1:0'], self::codeLines($text));
    }

    public function test_the_scheduled_path_finds_the_code_in_the_log_file_as_copied(): void
    {
        // #5786: a scheduled run's output goes to /dev/null, so the log file
        // is its record. The real channel and formatter, no spy.
        $path = storage_path('logs/litsrmm-l4-fixture-'.bin2hex(random_bytes(4)).'.log');
        config(['logging.channels.l4fixture' => ['driver' => 'single', 'path' => $path, 'level' => 'debug'], 'logging.default' => 'l4fixture']);
        $assets = $this->linkedAssets(5);
        $this->service()->sync();
        $this->rows = array_slice($this->rows, 0, 1);

        try {
            $this->service()->sync();
            $codes = self::codeLines((string) file_get_contents($path));
        } finally {
            @unlink($path);
        }

        $this->assertSame(["{$this->client->id}:b:1:5:4:5:1:0"], $codes, 'one line of the file is the code and nothing else');

        $accepted = $this->service()->sync(null, $codes);

        $this->assertSame(1, $accepted->details['short_read_accepted']);
        $this->assertNull($assets[4]->fresh()->litsrmm_device_id);
    }

    public function test_a_plain_refusal_claims_no_accept_and_no_zero_arm(): void
    {
        // #5791: the two single-arm clauses stay off when their arm is off.
        // CONTROL (#5792): green at base 6144b475 too; it kills the
        // predicate-weakening mutants, it does not show a change.
        $this->linkedAssets(5);
        $this->service()->sync();
        $this->rows = array_slice($this->rows, 0, 1);

        $message = $this->service()->sync()->errorMessages[0];

        $this->assertStringContainsString('(rule b:', $message);
        $this->assertStringNotContainsString('named a different refusal', $message);
        $this->assertStringNotContainsString('0 seats', $message);
    }

    public function test_an_empty_read_of_a_client_without_seats_claims_no_zero_arm(): void
    {
        // #5791 / #5797: seats 0 held and 0 read is no cut, so no warning.
        // CONTROL (#5792): green at base 6144b475 too.
        $this->linkedAssets(2);
        $this->rows = [];

        $message = $this->service()->sync()->errorMessages[0];

        $this->assertStringContainsString('(rule a:', $message);
        $this->assertStringContainsString('seats 0 held, 0 read', $message);
        $this->assertStringNotContainsString('0 seats', $message);
    }

    public function test_accepting_a_link_rule_that_also_zeroes_the_seats_says_so(): void
    {
        // #5797: rule (b) fires first; the read's only devices are agentless,
        // so it would also write 0 of 5 seats.
        $assets = $this->linkedAssets(5);
        $this->service()->sync();
        $this->rows = [];
        for ($n = 11; $n <= 15; $n++) {
            $this->device((string) $n, ['agentVersion' => null, 'serial' => null]);
        }

        $refused = $this->service()->sync();

        $message = $refused->errorMessages[0];
        $this->assertStringContainsString('(rule b:', $message);
        $this->assertStringContainsString('seats 5 held, 0 read', $message);
        $this->assertStringContainsString("and this read would also leave 0 seats, so accepting it sets the client's LITSRMM seats to 0", $message);
        $this->assertStringNotContainsString('refused whatever the drop', $message, 'not rule (d)\'s wording');

        $accepted = $this->service()->sync(null, [self::acceptCode($refused)]);

        $this->assertSame(0, $this->licenses($this->client)['workstation']->quantity, 'the warning was true');
        $this->assertNull($assets[0]->fresh()->litsrmm_device_id);
    }

    public function test_an_empty_page_that_zeroes_the_seats_says_so(): void
    {
        // #5797: rule (a) with seats held.
        $this->linkedAssets(2);
        $this->service()->sync();
        $this->rows = [];

        $message = $this->service()->sync()->errorMessages[0];

        $this->assertStringContainsString('(rule a:', $message);
        $this->assertStringContainsString('and this read would also leave 0 seats', $message);
    }

    public function test_a_rolled_back_accept_is_not_logged_or_marked_used(): void
    {
        // #5783: the accepted client's transaction throws; nothing of it was
        // synced, so no "accepted" record at any level, and the code is
        // reported as passing nothing.
        Log::spy();
        $assets = $this->linkedAssets(5);
        $this->service()->sync();
        $this->rows = array_slice($this->rows, 0, 1);
        $code = self::acceptCode($this->service()->sync());
        License::saving(fn () => throw new \RuntimeException('fixture: the seat write fails'));

        try {
            $this->service()->sync(null, [$code]);
            $this->fail('the throw reaches the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('fixture: the seat write fails', $e->getMessage());
        }

        $this->assertNotNull($assets[4]->fresh()->litsrmm_device_id, 'rolled back: no link released');
        self::assertNeverLogged('[LitsrmmAssetSync] client short read accepted by the operator for this run');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] for each of these PSA client ids, an --accept-short-read code given for it passed nothing'
                && $context === ['client_ids' => [$this->client->id]])
            ->once();
    }

    public function test_a_failed_detail_read_of_a_skipped_device_does_not_claim_its_facts_were_written(): void
    {
        // #5784: two unlinked devices share a serial, so syncClient() skips
        // both; one of their detail reads failed with a 500.
        $this->linkedAssets(3);
        $a = $this->device('8', ['serial' => 'SNSHARED01']);
        $this->device('9', ['serial' => 'SNSHARED01']);
        $this->details[$a['id']] = 500;

        $result = $this->service()->sync();

        $this->assertSame(2, $result->skipped, implode('; ', $result->skippedMessages));
        $this->assertSame(["client {$this->client->id}: a device's hardware was not refreshed (detail read failed: HTTP 500); it was also skipped, so its list facts were not written either"], $result->errorMessages);
        $this->assertSame(3, Asset::count(), 'nothing created');
    }

    public function test_a_second_code_for_a_client_another_code_passed_is_reported_unused(): void
    {
        // #5789: per code, not per client.
        Log::spy();
        $this->linkedAssets(5);
        $this->service()->sync();
        $this->rows = array_slice($this->rows, 0, 1);
        $code = self::acceptCode($this->service()->sync());

        $result = $this->service()->sync(null, [$code, "{$this->client->id}:d:2:0:0:3:0:2"]);

        $this->assertSame(1, $result->details['short_read_accepted']);
        $this->assertSame([$this->client->id], $result->details['short_read_accept_unused']);
        // G-14: its other code passed, so nothing says the client passed nothing.
        $text = LitsrmmAssetSyncService::describe($result);
        $this->assertStringContainsString("For each of PSA client ID(s) {$this->client->id}, an --accept-short-read code given for it passed nothing.", $text);
        $this->assertStringNotContainsString('passed nothing for', $text);
        self::assertNeverLogged('[LitsrmmAssetSync] --accept-short-read passed nothing for these PSA client ids');
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] for each of these PSA client ids, an --accept-short-read code given for it passed nothing'
                && $context === ['client_ids' => [$this->client->id]])
            ->once();
    }

    public function test_an_accept_given_when_the_list_read_fails_is_reported_unused(): void
    {
        // #5788: nothing was examined. CONTROL (#5792): base reported it
        // unused too; the fix is the command's wording (its own test).
        $this->linkedAssets(1);
        $this->listStatus = 503;

        $result = $this->service()->sync(null, ["{$this->client->id}:a:0:1:1:0:0:0"]);

        $this->assertSame('Failed to read LITSRMM devices (HTTP 503); nothing was changed', $result->errorMessages[0]);
        $this->assertSame([$this->client->id], $result->details['short_read_accept_unused']);
    }

    public function test_a_refusal_code_with_a_leading_zero_is_not_a_code(): void
    {
        // #5793: no refusal prints one, so none could match.
        $this->assertFalse(LitsrmmAssetSyncService::isRefusalCode('012:b:1:5:4:5:1:0'));
        $this->assertFalse(LitsrmmAssetSyncService::isRefusalCode('12:b:01:5:4:5:1:0'));
        $this->assertFalse(LitsrmmAssetSyncService::isRefusalCode("12:b:1:5:4:5:1:0\n"));
        $this->assertFalse(LitsrmmAssetSyncService::isRefusalCode('12:b:1:5:4:5:1:0.'));
        $this->assertTrue(LitsrmmAssetSyncService::isRefusalCode('12:b:1:5:4:5:1:0'));
        $this->assertTrue(LitsrmmAssetSyncService::isRefusalCode('12:d:0:0:0:10:0:0'));
    }

    public function test_exactly_half_the_reads_answering_404_is_reported_and_keeps_every_link(): void
    {
        // #5795, documented not degraded: "more than half" is the ruled bound.
        // Two 404s of four reads are each a skip with a log line, never silent.
        // CONTROL (#5792): pins base behaviour, which this batch documents.
        Log::spy();
        $assets = $this->linkedAssets(4);
        $this->details[$this->rows[0]['id']] = 404;
        $this->details[$this->rows[1]['id']] = 404;

        $result = $this->service()->sync();

        $this->assertArrayNotHasKey('degraded_detail_read', $result->details);
        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(2, $result->skipped);
        foreach ($assets as $asset) {
            $this->assertNotNull($asset->fresh()->litsrmm_device_id, 'every link kept');
        }
        $this->assertSame('before', $assets[0]->fresh()->last_user, 'a 404 device is not written');
        $this->assertSame(4, $this->licenses($this->client)['workstation']->quantity);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === '[LitsrmmAssetSync] device not written'
                && $context === ['client_id' => $this->client->id, 'reason' => 'detail_read_not_found'])
            ->twice();
    }
}
