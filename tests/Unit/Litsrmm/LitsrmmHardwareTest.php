<?php

namespace Tests\Unit\Litsrmm;

use App\Services\Litsrmm\LitsrmmHardware;
use PHPUnit\Framework\TestCase;

/**
 * The `inventory` block of LITSRMM `GET /v1/devices/{id}`, turned into the
 * asset columns a Level-fed asset already carries.
 *
 * The payload below is Zachary-PC's REAL inventory as the vendor stored it on
 * 2026-09-30 (MAC and IP are LAN-private). Expected values were worked out by
 * hand from it, not read back from the code.
 */
class LitsrmmHardwareTest extends TestCase
{
    private static function inventory(): array
    {
        return [
            'hardware' => ['collectedAt' => '2026-09-30T09:58:35.835Z', 'payload' => [
                'cpu' => 'AMD Ryzen 7 5700G with Radeon Graphics',
                'ramBytes' => 16424173568,
                'manufacturer' => 'ASUS',
                'model' => 'System Product Name',
                'board' => 'PRIME B450M-A II',
                'machineGuid' => '0b6729d1-0763-4f93-a00c-bf2b27de42d9',
            ]],
            'disks' => ['collectedAt' => '2026-09-30T09:58:35.835Z', 'payload' => [
                ['drive' => 'C:', 'label' => null, 'fileSystem' => 'NTFS', 'totalBytes' => 499496030208, 'freeBytes' => 89943642112],
            ]],
            'network' => ['collectedAt' => '2026-09-30T09:58:35.842Z', 'payload' => [
                ['name' => 'Ethernet', 'description' => 'Realtek PCIe GbE Family Controller', 'mac' => 'A0:36:BC:25:C3:A7', 'ipv4' => ['192.168.0.10'], 'up' => true],
                ['name' => 'Bluetooth Network Connection', 'description' => 'Bluetooth Device (Personal Area Network)', 'mac' => '04:7F:0E:00:2E:3D', 'ipv4' => ['169.254.13.219'], 'up' => false],
            ]],
            'system' => ['collectedAt' => '2026-09-30T09:58:35.835Z', 'payload' => [
                'bootTimeUtc' => '2026-09-30T03:33:21Z',
                'pendingReboot' => true,
                'pendingRebootReasons' => ['pendingFileRename'],
            ]],
        ];
    }

    public function test_a_real_inventory_maps_to_the_columns_a_level_asset_carries(): void
    {
        $hw = LitsrmmHardware::fromInventory(self::inventory());

        $this->assertSame('AMD Ryzen 7 5700G with Radeon Graphics', $hw->cpu);
        // 16424173568 / 1024^3 = 15.296...
        $this->assertSame(15.30, $hw->ramGb);
        // 465 GB total, 84 GB free, 84/465 = 18% - the same rounding LevelSyncService uses.
        $this->assertSame('C: 465 GB (18% free)', $hw->diskSummary);
        $this->assertSame('192.168.0.10', $hw->ipAddress);
        $this->assertSame('2026-09-30T03:33:21+00:00', $hw->lastBootAt?->format(DATE_ATOM));
        $this->assertTrue($hw->needsReboot);
    }

    public function test_the_columns_it_writes_are_only_the_ones_it_knows(): void
    {
        $this->assertSame(
            ['cpu', 'ram_gb', 'disk_summary', 'ip_address', 'last_boot_at', 'needs_reboot'],
            array_keys(LitsrmmHardware::fromInventory(self::inventory())->columns()),
        );
    }

    public function test_a_category_never_collected_writes_nothing_rather_than_null(): void
    {
        // Absent means nothing ever asked the machine. Writing null would erase
        // what the asset already knows and claim the machine reported nothing.
        $inventory = self::inventory();
        unset($inventory['disks'], $inventory['system']);

        $columns = LitsrmmHardware::fromInventory($inventory)->columns();

        $this->assertArrayNotHasKey('disk_summary', $columns);
        $this->assertArrayNotHasKey('last_boot_at', $columns);
        $this->assertArrayNotHasKey('needs_reboot', $columns);
        $this->assertSame('192.168.0.10', $columns['ip_address']);
    }

    public function test_an_agentless_device_has_no_columns_at_all(): void
    {
        $this->assertSame([], LitsrmmHardware::fromInventory([])->columns());
    }

    public function test_a_link_local_address_is_never_chosen(): void
    {
        // 169.254/16 is Windows' "DHCP failed" address; it identifies nothing.
        $inventory = self::inventory();
        $inventory['network']['payload'] = [
            ['name' => 'Wi-Fi', 'mac' => 'AA:BB:CC:DD:EE:FF', 'ipv4' => ['169.254.1.2'], 'up' => true],
        ];

        $this->assertNull(LitsrmmHardware::fromInventory($inventory)->ipAddress);
    }

    public function test_an_adapter_that_is_up_wins_over_one_listed_first_that_is_down(): void
    {
        $inventory = self::inventory();
        $inventory['network']['payload'] = [
            ['name' => 'Old VPN', 'mac' => 'AA:BB:CC:DD:EE:01', 'ipv4' => ['10.8.0.2'], 'up' => false],
            ['name' => 'Ethernet', 'mac' => 'AA:BB:CC:DD:EE:02', 'ipv4' => ['192.168.0.10'], 'up' => true],
        ];

        $this->assertSame('192.168.0.10', LitsrmmHardware::fromInventory($inventory)->ipAddress);
    }

    public function test_every_fixed_volume_is_listed(): void
    {
        $inventory = self::inventory();
        $inventory['disks']['payload'][] = ['drive' => 'D:', 'label' => 'Data', 'fileSystem' => 'NTFS', 'totalBytes' => 1000204886016, 'freeBytes' => 500102443008];

        // D: 931.5 -> 932 GB, free 465.8 -> 466 GB, 466/932 = 50%.
        $this->assertSame('C: 465 GB (18% free), D: 932 GB (50% free)', LitsrmmHardware::fromInventory($inventory)->diskSummary);
    }

    public function test_a_malformed_payload_is_ignored_not_fatal(): void
    {
        // One bad category must not cost the asset the others.
        $inventory = self::inventory();
        $inventory['hardware']['payload'] = 'not an object';
        $inventory['disks'] = 'nonsense';

        $hw = LitsrmmHardware::fromInventory($inventory);

        $this->assertNull($hw->cpu);
        $this->assertNull($hw->ramGb);
        $this->assertNull($hw->diskSummary);
        $this->assertSame('192.168.0.10', $hw->ipAddress);
    }

    public function test_an_unparseable_boot_time_is_dropped(): void
    {
        $inventory = self::inventory();
        $inventory['system']['payload']['bootTimeUtc'] = 'last Tuesday';

        $hw = LitsrmmHardware::fromInventory($inventory);

        $this->assertNull($hw->lastBootAt);
        $this->assertTrue($hw->needsReboot, 'the rest of the category still counts');
    }
}
