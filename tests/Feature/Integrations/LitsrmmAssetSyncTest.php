<?php

namespace Tests\Feature\Integrations;

use App\Enums\ClientStage;
use App\Models\Asset;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Setting;
use App\Models\User;
use App\Services\AssetService;
use App\Services\Litsrmm\LitsrmmAssetSyncService;
use App\Services\Litsrmm\LitsrmmClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * LITSRMM stage 3: devices become PSA assets.
 *
 * LITSRMM is an RMM of record, so unlike AutoElevate or Control D it CREATES
 * assets, as LevelSyncService does. It takes its safeguards from
 * AutoElevateAssetSyncService instead of Level: every match is scoped to the
 * one mapped client; a guess is refused rather than made; a failed read
 * changes nothing; a device that leaves loses only OUR link and never the
 * asset; a deleted asset is never revived.
 *
 * Every response comes from a fake transport routed by path, so nothing here
 * reaches a real host and no test depends on request order.
 */
class LitsrmmAssetSyncTest extends TestCase
{
    use RefreshDatabase;

    private const VENDOR_CLIENT = 'a362168a-46c7-4b95-8800-c46162185469';

    private const OTHER_VENDOR_CLIENT = 'c0a80101-0000-4000-8000-000000000009';

    private string $fakeBearer;

    /** @var list<array<string, mixed>> list rows the fake vendor serves */
    private array $rows = [];

    /** @var array<string, array|int> device id => inventory, or an HTTP status to fail with */
    private array $details = [];

    private int|null $listStatus = null;

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
            'network' => ['collectedAt' => '2026-09-30T09:58:35.842Z', 'payload' => [['name' => 'Ethernet', 'mac' => 'A0:36:BC:25:C3:A7', 'ipv4' => ['192.168.0.10'], 'up' => true]]],
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
        $this->assertSame('192.168.0.10', $asset->ip_address);
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
        // Seen live 2026-10-01: DESKTOP-O21BE8G #36 (linked) was merged INTO the
        // older #32. AssetService's identity list did not know litsrmm_device_id,
        // so the link stayed on the retired tombstone and #32 was left unlinked.
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
        // the rehearsal against real data: Zachary-PC and Ryan PC both read
        // "(none)" while nobody was signed in.
        $row = $this->device('1', ['lastUser' => '(none)']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => $row['id'], 'last_user' => 'zleif']);
        $this->device('2', ['lastUser' => '(none)']);

        $this->service()->sync();

        $this->assertSame('zleif', $asset->fresh()->last_user);
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
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'hostname' => 'BANDC-DESKTOP', 'serial_number' => 'Default string']);
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
        $this->device('1');

        $result = $this->service()->sync();

        $asset->refresh();
        $this->assertFalse($asset->trashed());
        $this->assertNull($asset->litsrmm_device_id);
        $this->assertNull($asset->litsrmm_synced_at);
        $this->assertSame('Known CPU', $asset->cpu);
        $this->assertSame(1, $result->deactivated);
    }

    public function test_a_retired_device_is_not_created_and_loses_its_link(): void
    {
        $row = $this->device('1', ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
        $this->device('2', ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
        $asset = Asset::factory()->create(['client_id' => $this->client->id, 'litsrmm_device_id' => $row['id']]);

        $result = $this->service()->sync();

        $this->assertSame(1, Asset::count(), 'no asset is created for a retired device');
        $this->assertNull($asset->fresh()->litsrmm_device_id);
        $this->assertFalse($asset->fresh()->trashed());
        $this->assertSame(0, $this->detailRequests());
        $this->assertSame(1, $result->deactivated);
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
        $this->assertSame(1, $this->detailRequests(),'only this client\'s device is read in detail');
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
}
