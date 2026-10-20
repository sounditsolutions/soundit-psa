<?php

namespace App\Services\Contracts;

use App\Support\TieredPricing;

class ContractLineDrift
{
    public const COLUMNS = [
        'sku_id', 'description', 'quantity_type', 'fixed_quantity',
        'unit_price', 'pricing_tiers', 'is_taxable',
    ];

    /**
     * Compare raw database rows without reading or writing persistence.
     *
     * @return array<int, array{contract_line_id: ?int, profile_line_id: ?int, status: string, columns: array}>
     */
    public function compare(array $contractLines, array $profileLines): array
    {
        $remaining = array_values($profileLines);
        $unmatched = [];
        $pairs = [];
        foreach ($contractLines as $line) {
            $match = null;
            foreach ($remaining as $key => $profileLine) {
                if ($line['source_profile_line_id'] !== null && (int) $line['source_profile_line_id'] === (int) $profileLine['id']) {
                    $match = $key;
                    break;
                }
            }
            if ($match === null) {
                $unmatched[] = $line;
            } else {
                $pairs[] = [$line, $remaining[$match]];
                unset($remaining[$match]);
            }
        }

        // RecurringProfileController::update deletes/recreates even on identical saves.
        $order = fn (array $a, array $b) => [$a['sort_order'], $a['id']] <=> [$b['sort_order'], $b['id']];
        usort($unmatched, $order);
        uasort($remaining, $order);
        $result = [];
        foreach ($unmatched as $line) {
            $match = null;
            foreach ($remaining as $key => $profileLine) {
                if ($this->value($line, 'sku_id') === $this->value($profileLine, 'sku_id')
                    && $line['description'] === $profileLine['description']
                    && $line['quantity_type'] === $profileLine['quantity_type']) {
                    $match = $key;
                    break;
                }
            }
            if ($match === null) {
                $result[] = $this->entry($line, null, 'removed');
            } else {
                $pairs[] = [$line, $remaining[$match]];
                unset($remaining[$match]);
            }
        }
        foreach ($pairs as [$line, $profileLine]) {
            $columns = [];
            foreach (self::COLUMNS as $column) {
                if ($this->value($line, $column) !== $this->value($profileLine, $column)) {
                    $columns[] = $column;
                }
            }
            if ($columns !== []) {
                $result[] = $this->entry($line, $profileLine, 'changed', $columns);
            }
        }
        foreach ($remaining as $line) {
            $result[] = $this->entry(null, $line, 'added');
        }

        return $result;
    }

    private function value(array $line, string $column): mixed
    {
        $value = $line[$column];

        return match ($column) {
            'sku_id' => $value === null ? null : (int) $value,
            'fixed_quantity', 'unit_price' => $value === null ? null : number_format((float) $value, 2, '.', ''),
            'is_taxable' => (bool) $value,
            'pricing_tiers' => TieredPricing::normalize(is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : ($value ?? [])),
            default => $value,
        };
    }

    private function entry(?array $line, ?array $profileLine, string $status, array $columns = []): array
    {
        return [
            'contract_line_id' => $line === null ? null : (int) $line['id'],
            'profile_line_id' => $profileLine === null ? null : (int) $profileLine['id'],
            'status' => $status,
            'columns' => $columns,
        ];
    }
}
