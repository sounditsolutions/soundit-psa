<?php

namespace Tests\Unit;

use App\Services\Signing\AcceptanceDiff;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AcceptanceDiffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function line(): array
    {
        return [
            'id' => 1, 'version' => 1, 'sort_order' => 0, 'sku_id' => null,
            'description' => 'Example support', 'quantity_type' => 'fixed',
            'fixed_quantity' => '2.00', 'unit_price' => '125.00', 'pricing_tiers' => null,
            'is_taxable' => false, 'option_group' => null, 'term' => null,
        ];
    }

    public static function changes(): array
    {
        return [
            'sku_id' => ['sku_id', 2],
            'description' => ['description', 'Changed support'],
            'quantity_type' => ['quantity_type', 'per_user'],
            'fixed_quantity' => ['fixed_quantity', '3.00'],
            'unit_price' => ['unit_price', '126.00'],
            'pricing_tiers' => ['pricing_tiers', [['up_to' => null, 'unit_price' => '100.00']]],
            'is_taxable' => ['is_taxable', true],
            'option_group' => ['option_group', 'Example options'],
            'term' => ['term', 'Monthly'],
        ];
    }

    #[DataProvider('changes')]
    public function test_each_projected_field_has_a_named_diff(string $field, mixed $value): void
    {
        $line = $this->line();
        $changed = array_replace($line, [$field => $value]);
        $this->assertSame([
            ['line' => 1, 'field' => $field, 'accepted' => $line[$field], 'current' => $value],
        ], (new AcceptanceDiff)->compare([$line], [$changed]));
        $this->assertSame([], (new AcceptanceDiff)->compare([$line], [$line]));
    }

    public function test_identity_version_source_and_timestamps_are_not_content(): void
    {
        $line = $this->line();
        $changed = array_replace($line, ['id' => 9, 'version' => 2, 'sort_order' => 10, 'source' => 'example', 'updated_at' => '2026-01-02']);
        $this->assertSame([], (new AcceptanceDiff)->compare([$line], [$changed]));
    }

    public function test_added_removed_and_reordered_lines_are_reported(): void
    {
        $first = $this->line();
        $second = array_replace($first, ['description' => 'Another example']);
        $diff = new AcceptanceDiff;
        $added = $diff->compare([$first], [$first, $second]);
        $this->assertCount(1, $added);
        $this->assertSame('added', $added[0]['field']);
        $this->assertSame(2, $added[0]['line']);
        $this->assertNull($added[0]['accepted']);
        $this->assertSame('Another example', $added[0]['current']['description']);
        $this->assertArrayNotHasKey('id', $added[0]['current']);
        $removed = $diff->compare([$first, $second], [$first]);
        $this->assertSame('removed', $removed[0]['field']);
        $this->assertSame($added[0]['current'], $removed[0]['accepted']);
        $this->assertNull($removed[0]['current']);
        $reordered = $diff->compare([$first, $second], [$second, $first]);
        $this->assertSame([1, 2], array_column($reordered, 'line'));
        $this->assertSame(['description', 'description'], array_column($reordered, 'field'));
        $this->assertSame([], $diff->compare([], []));
    }

    public function test_tier_types_order_and_empty_values_are_not_normalized(): void
    {
        $first = array_replace($this->line(), ['pricing_tiers' => [['up_to' => 10, 'unit_price' => '25.00']]]);
        foreach ([
            [['up_to' => '10', 'unit_price' => '25.00']],
            [['unit_price' => '25.00', 'up_to' => 10]],
            [['up_to' => 10, 'unit_price' => 25]],
            [],
            null,
        ] as $tiers) {
            $changed = array_replace($first, ['pricing_tiers' => $tiers]);
            $this->assertSame([['line' => 1, 'field' => 'pricing_tiers', 'accepted' => $first['pricing_tiers'], 'current' => $tiers]], (new AcceptanceDiff)->compare([$first], [$changed]));
        }
    }
}
