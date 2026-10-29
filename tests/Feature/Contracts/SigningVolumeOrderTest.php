<?php

namespace Tests\Feature\Contracts;

use App\Models\BackupStorageTier;
use App\Models\SignableDocument;
use App\Models\Sku;
use App\Services\Signing\AcceptanceDiff;
use App\Services\Signing\TemplateMerge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SigningVolumeOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function rateCell(array $tiers): string
    {
        $document = new SignableDocument(['rendered_lines' => [[
            'description' => 'Example storage', 'quantity_type' => 'per_backup_storage_gb',
            'sku_volume_tiers' => $tiers,
        ]]]);
        $html = app(TemplateMerge::class)->merge('{{lines_table}}', [], $document)['html'];
        preg_match('~<tbody><tr>(.*?)</tr>~s', $html, $row);
        preg_match_all('~<td>(.*?)</td>~s', $row[1], $cells);

        return $cells[1][3];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cards')]
    public function test_whole_rate_cell_with_and_without_unbounded_tier(bool $unlimited): void
    {
        $tiers = [['up_to_gb' => 500, 'unit_price' => '0.18'], ['up_to_gb' => 2000, 'unit_price' => '0.14']];
        if ($unlimited) {
            $tiers[] = ['up_to_gb' => null, 'unit_price' => '0.10'];
        }
        $bands = 'DRAFT — up to 500 GB: 0.18; DRAFT — up to 2000 GB: 0.14; DRAFT — above that: '.($unlimited ? '0.10' : '0.14');
        $this->assertSame(htmlspecialchars('DRAFT — Volume pricing, every GB at one rate: '.$bands, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $this->rateCell($tiers));
        $this->assertSame($bands, TemplateMerge::volumeBands($tiers));
        $this->assertSame($bands, AcceptanceDiff::displayValue('sku_volume_tiers', $tiers));
    }

    public static function cards(): array
    {
        return ['unbounded' => [true], 'bounded only' => [false]];
    }

    public function test_duplicate_bounds_bill_the_first_band_even_when_loaded_backwards(): void
    {
        $sku = Sku::create(['name' => 'Example storage', 'sku_code' => 'EXAMPLE-ORDER', 'unit_price' => '0', 'unit_cost' => '0']);
        foreach ([[40, 500, '0.20'], [20, 500, '0.18'], [80, null, '0.05'], [60, null, '0.10']] as [$id, $bound, $price]) {
            $tier = new BackupStorageTier(['sku_id' => $sku->id, 'up_to_gb' => $bound, 'unit_price' => $price]);
            $tier->id = $id;
            $tier->save();
        }
        $loaded = $sku->backupStorageTiers()->reorder()->orderByDesc('id')->get();
        $sku->setRelation('backupStorageTiers', $loaded);
        $this->assertSame(0.18, $sku->priceForStorageGb(400));
        $this->assertSame(0.10, $sku->priceForStorageGb(600));
        $expected = 'DRAFT — Volume pricing, every GB at one rate: DRAFT — up to 500 GB: 0.18; DRAFT — up to 500 GB: 0.20; DRAFT — above that: 0.10; DRAFT — above that: 0.05';
        $rendered = $this->rateCell($loaded->toArray());
        $this->assertSame($expected, $rendered);
        preg_match('/up to 500 GB: ([0-9.]+)/', $rendered, $first);
        $this->assertSame($sku->priceForStorageGb(400), (float) $first[1]);
        $sku->unsetRelation('backupStorageTiers');
        $this->assertSame($rendered, $this->rateCell($sku->backupStorageTiers->toArray()));
        $this->assertSame(0.18, $sku->priceForStorageGb(400));
        $sorted = $loaded->sort([BackupStorageTier::class, 'compareRateCard'])->values();
        $this->assertSame([20, 40, 60, 80], $sorted->modelKeys());
        $this->assertSame($sorted->modelKeys(), $sku->backupStorageTiers()->get()->modelKeys());
        // SQLite rowid ordering can mask a missing SQL tie-break; pin the SQL contract too.
        $this->assertStringContainsString('up_to_gb IS NULL, up_to_gb ASC, id ASC', $sku->backupStorageTiers()->toSql());
        $this->assertSame(TemplateMerge::volumeBands($loaded->toArray()), AcceptanceDiff::displayValue('sku_volume_tiers', $loaded->toArray()));
    }

    public function test_display_helpers_keep_fallback_json_and_empty_cells(): void
    {
        $this->assertSame('unit_price', AcceptanceDiff::displayField('unit_price'));
        $this->assertSame('DRAFT — Product volume rate card', AcceptanceDiff::displayField('sku_volume_tiers'));
        foreach ([null, []] as $value) {
            $this->assertSame('', AcceptanceDiff::displayValue('sku_volume_tiers', $value));
        }
        foreach ([null, [], '0.18', ['example' => '—']] as $value) {
            $this->assertSame(json_encode($value, JSON_UNESCAPED_UNICODE), AcceptanceDiff::displayValue('unit_price', $value));
        }
        $tiers = [['up_to_gb' => 500, 'unit_price' => '0.20'], ['up_to_gb' => 500, 'unit_price' => '0.18']];
        $this->assertSame('DRAFT — up to 500 GB: 0.20; DRAFT — up to 500 GB: 0.18; DRAFT — above that: 0.18', TemplateMerge::volumeBands($tiers));
        $this->assertSame('', TemplateMerge::volumeBands([]));
    }
}
