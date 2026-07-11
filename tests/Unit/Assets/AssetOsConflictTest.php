<?php

namespace Tests\Unit\Assets;

use App\Models\Asset;
use App\Models\TacticalAsset;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Unit coverage for the Device-Identity-vs-Tactical OS conflict detection
 * (psa-sp30). Pure model logic — no DB round-trip; relations are stubbed in
 * memory with setRelation().
 */
class AssetOsConflictTest extends TestCase
{
    private function assetWithTacticalOs(?string $assetOs, ?string $tacticalOs): Asset
    {
        $asset = new Asset(['os' => $assetOs]);
        $asset->setRelation(
            'tacticalAsset',
            $tacticalOs === null ? null : new TacticalAsset(['os' => $tacticalOs])
        );

        return $asset;
    }

    public function test_flags_genuine_version_mismatch(): void
    {
        // The exact scenario from the bug report.
        $asset = $this->assetWithTacticalOs(
            'Windows Server 2019',
            'Windows Server 2022'
        );

        $this->assertTrue($asset->hasTacticalOsConflict());
    }

    public function test_does_not_flag_when_only_formatting_differs(): void
    {
        // Same OS, different RMM vendor formatting (Microsoft prefix, edition,
        // bitness, build number) — must NOT read as a conflict.
        $asset = $this->assetWithTacticalOs(
            'Microsoft Windows Server 2019 Standard',
            'Windows Server 2019 Standard, 64bit (build 17763)'
        );

        $this->assertFalse($asset->hasTacticalOsConflict());
    }

    public function test_does_not_flag_when_identical(): void
    {
        $asset = $this->assetWithTacticalOs('Windows 11 Pro', 'Windows 11 Pro');

        $this->assertFalse($asset->hasTacticalOsConflict());
    }

    public function test_does_not_flag_when_tactical_os_blank(): void
    {
        $this->assertFalse($this->assetWithTacticalOs('Windows 11 Pro', null)->hasTacticalOsConflict());
        $this->assertFalse($this->assetWithTacticalOs('Windows 11 Pro', '')->hasTacticalOsConflict());
    }

    public function test_does_not_flag_when_asset_os_blank(): void
    {
        $this->assertFalse($this->assetWithTacticalOs(null, 'Windows 11 Pro')->hasTacticalOsConflict());
        $this->assertFalse($this->assetWithTacticalOs('', 'Windows 11 Pro')->hasTacticalOsConflict());
    }

    public function test_does_not_flag_when_no_tactical_agent_linked(): void
    {
        $asset = new Asset(['os' => 'Windows 11 Pro']);
        $asset->setRelation('tacticalAsset', null);

        $this->assertFalse($asset->hasTacticalOsConflict());
    }

    #[DataProvider('normalizationCases')]
    public function test_normalize_os_for_comparison(string $input, string $expected): void
    {
        $this->assertSame($expected, Asset::normalizeOsForComparison($input));
    }

    public static function normalizationCases(): array
    {
        return [
            'strips microsoft prefix' => ['Microsoft Windows Server 2019', 'windows server 2019'],
            'strips build number' => ['Windows Server 2022 Standard (build 20348)', 'windows server 2022 standard'],
            'strips bitness token' => ['Windows 10 Pro, 64bit', 'windows 10 pro'],
            'strips spaced bitness' => ['Windows 10 Pro 64 bit', 'windows 10 pro'],
            'strips x64 marker' => ['Windows 11 Pro x64', 'windows 11 pro'],
            'collapses whitespace and case' => ['  Windows   11   PRO  ', 'windows 11 pro'],
            'preserves distinct versions' => ['Windows Server 2019', 'windows server 2019'],
            'null becomes empty' => ['', ''],
        ];
    }

    public function test_normalize_handles_null(): void
    {
        $this->assertSame('', Asset::normalizeOsForComparison(null));
    }
}
