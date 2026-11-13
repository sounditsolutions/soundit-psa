<?php

namespace App\Services\Mcp;

final class StaffCarbonHardwareTools
{
    public static function handles(string $name): bool
    {
        return in_array($name, array_column(self::definitions(), 'name'), true);
    }

    public static function definitions(): array
    {
        $part = ['type' => 'string', 'description' => 'Exact manufacturer part number.'];
        $model = ['type' => 'integer', 'minimum' => 1, 'description' => 'Approved model id within this client.'];
        $sell = ['type' => 'string', 'pattern' => '^\\d{1,8}(\\.\\d{1,2})?$', 'description' => 'Sell price in dollars, at most two decimal places.'];
        $confirm = ['type' => 'boolean', 'description' => 'Explicit confirmation to save below the cached Carbon price.'];
        $definitions = [];
        foreach ([
            ['hardware_list_approved_models', 'List the client approved hardware models with cached staff cost and sell prices.', [], []],
            ['hardware_add_approved_model', 'Add a plain part or a saved fixed build to the client approved list using a read-only Carbon product lookup.', ['mfg_part_no' => $part, 'sell_price' => $sell, 'confirm_below_cost' => $confirm], ['mfg_part_no', 'sell_price']],
            ['hardware_remove_approved_model', 'Remove a model from the client approved hardware list.', ['model_id' => $model], ['model_id']],
            ['hardware_set_sell_price', 'Set the sell price of a client approved model; below-cost prices require explicit confirmation.', ['model_id' => $model, 'sell_price' => $sell, 'confirm_below_cost' => $confirm], ['model_id', 'sell_price']],
        ] as [$name, $description, $properties, $required]) {
            $definitions[] = ['name' => $name, 'description' => $description, 'input_schema' => ['type' => 'object', 'properties' => ['client_id' => ['type' => 'integer', 'minimum' => 1]] + $properties, 'required' => array_merge(['client_id'], $required)]];
        }

        return $definitions;
    }
}
