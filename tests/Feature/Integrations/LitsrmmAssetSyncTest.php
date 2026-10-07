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
                && $context === ['client_id' => $this->client->id, 'rule' => 'a', 'listed' => 0, 'linked' => 2, 'unlisted' => 2, 'seats_held' => 0, 'seats_read' => 0])
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
        $row = $this->device('1');
        $this->details[$row['id']] = 404;

        $result = $this->service()->sync();

        $this->assertSame(0, Asset::count());
        $this->assertSame(0, $result->created);
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

    public function test_devices_the_list_says_are_retired_explain_a_seat_drop(): void
    {
        // A seat that went because the vendor lists its device retired is not
        // a short read.
        $this->seatsMostlyUnlinked(10, 2);
        foreach (array_keys($this->rows) as $i) {
            if ($i >= 2) {
                $this->rows[$i] = array_merge($this->rows[$i], ['enrollmentState' => 'retired', 'availabilityState' => 'retired', 'retiredAt' => '2026-09-30T10:00:00.000Z']);
            }
        }

        $result = $this->service()->sync();

        $this->assertSame(0, $result->errors, implode('; ', $result->errorMessages));
        $this->assertSame(2, $this->licenses($this->client)['workstation']->quantity);
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

    public function test_an_accepted_short_read_syncs_that_client_for_that_run_only(): void
    {
        Log::spy();
        $assets = $this->linkedAssets(5);
        $this->service()->sync();
        $this->rows = array_slice($this->rows, 0, 1);

        $result = $this->service()->sync(null, [(string) $this->client->id]);

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

        $result = $this->service()->sync(null, [$this->client->id]);

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
        $this->service()->sync(null, [$this->client->id]);
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
                && $context === ['client_id' => $this->client->id, 'reads' => 4, 'not_found' => 4])
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
}
