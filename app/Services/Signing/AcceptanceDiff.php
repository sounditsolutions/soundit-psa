<?php

namespace App\Services\Signing;

use Illuminate\Support\Facades\Lang;

class AcceptanceDiff
{
    private const COLUMNS = [
        'sku_id', 'description', 'quantity_type', 'fixed_quantity', 'unit_price',
        'pricing_tiers', 'sku_volume_tiers', 'is_taxable', 'option_group', 'term',
    ];

    public static function displayField(string $field): string
    {
        $key = 'signing.rate_card.diff_'.$field;

        return Lang::has($key) ? __($key) : $field;
    }

    public static function displayValue(string $field, mixed $value): string|false
    {
        if ($field === 'sku_volume_tiers') {
            return is_array($value) && $value !== [] ? TemplateMerge::volumeBands($value) : '';
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Accepted lines are the acceptance snapshot's stored lines. Current lines are
     * SigningService::renderedLine() output: attributesToArray() plus synthesized
     * sku_volume_tiers from the live SKU card when it applies.
     * pricing_tiers and sku_volume_tiers are compared without normalization.
     *
     * @return array<int, array{line:int,field:string,accepted:mixed,current:mixed}>
     */
    public function compare(array $accepted, array $current): array
    {
        $accepted = array_values($accepted);
        $current = array_values($current);
        $diff = [];
        for ($index = 0; $index < max(count($accepted), count($current)); $index++) {
            if (! array_key_exists($index, $accepted) || ! array_key_exists($index, $current)) {
                $diff[] = [
                    'line' => $index + 1,
                    'field' => array_key_exists($index, $accepted) ? 'removed' : 'added',
                    'accepted' => isset($accepted[$index]) ? $this->project($accepted[$index]) : null,
                    'current' => isset($current[$index]) ? $this->project($current[$index]) : null,
                ];

                continue;
            }
            $before = $this->project($accepted[$index]);
            $after = $this->project($current[$index]);
            foreach (self::COLUMNS as $field) {
                if ($before[$field] !== $after[$field]) {
                    $diff[] = ['line' => $index + 1, 'field' => $field, 'accepted' => $before[$field], 'current' => $after[$field]];
                }
            }
        }

        return $diff;
    }

    private function project(array $line): array
    {
        $projection = [];
        foreach (self::COLUMNS as $field) {
            $projection[$field] = $line[$field] ?? null;
        }

        return $projection;
    }
}
