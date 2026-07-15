<?php

namespace Tests\Unit\Support;

use App\Enums\QuantityType;
use App\Models\BackupStorageTier;
use App\Models\Sku;
use App\Support\PricingModelOverride;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

/**
 * The single predicate behind every surface that tells the operator WHICH rate
 * card prices a line. It is deliberately narrow: the override needs all THREE
 * facts at once, because the SKU's volume rate card is only ever read for
 * backup-storage lines. Any wider and the UI would announce an override that
 * never actually happens — which is its own way of lying about money.
 */
class PricingModelOverrideTest extends TestCase
{
    private const BANDS = [
        ['up_to' => 100, 'unit_price' => 1.00],
        ['up_to' => null, 'unit_price' => 0.60],
    ];

    private function sku(bool $withVolumeTiers): Sku
    {
        $sku = new Sku(['name' => 'Cloud Backup Storage']);

        $sku->setRelation('backupStorageTiers', new Collection(
            $withVolumeTiers
                ? [
                    new BackupStorageTier(['up_to_gb' => 100, 'unit_price' => '1.00']),
                    new BackupStorageTier(['up_to_gb' => null, 'unit_price' => '0.60']),
                ]
                : []
        ));

        return $sku;
    }

    public function test_all_three_facts_together_are_an_active_override(): void
    {
        $this->assertTrue(PricingModelOverride::active(
            QuantityType::PerBackupStorageGb,
            self::BANDS,
            $this->sku(withVolumeTiers: true),
        ));
    }

    public function test_no_graduated_bands_is_no_override(): void
    {
        $this->assertFalse(PricingModelOverride::active(
            QuantityType::PerBackupStorageGb,
            [],
            $this->sku(withVolumeTiers: true),
        ));
    }

    public function test_no_volume_rate_card_is_no_override(): void
    {
        $this->assertFalse(PricingModelOverride::active(
            QuantityType::PerBackupStorageGb,
            self::BANDS,
            $this->sku(withVolumeTiers: false),
        ));
    }

    /**
     * The volume rate card is only ever consulted for backup-storage lines
     * (BillingService::resolveUnitPrice). A graduated per-user line on the same
     * SKU overrides nothing, so no surface may claim it does.
     */
    public function test_a_non_backup_storage_quantity_type_never_reads_the_volume_card(): void
    {
        foreach ([QuantityType::Fixed, QuantityType::PerUser, QuantityType::PerWorkstation, QuantityType::Overage] as $type) {
            $this->assertFalse(
                PricingModelOverride::active($type, self::BANDS, $this->sku(withVolumeTiers: true)),
                "{$type->value} lines do not consult the SKU volume rate card — must not be flagged.",
            );
        }
    }

    public function test_a_line_with_no_sku_cannot_override(): void
    {
        $this->assertFalse(PricingModelOverride::active(
            QuantityType::PerBackupStorageGb,
            self::BANDS,
            null,
        ));
    }

    /** Bands that normalize away to nothing are not bands. */
    public function test_unusable_bands_are_not_an_override(): void
    {
        $this->assertFalse(PricingModelOverride::active(
            QuantityType::PerBackupStorageGb,
            [['up_to' => 100, 'unit_price' => '']], // no price → dropped by normalize()
            $this->sku(withVolumeTiers: true),
        ));
    }
}
