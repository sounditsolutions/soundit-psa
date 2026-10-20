<?php

namespace App\Models;

use App\Enums\QuantityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractLine extends Model
{
    protected $fillable = [
        'contract_id', 'version', 'source', 'source_profile_line_id', 'sku_id',
        'description', 'quantity_type', 'fixed_quantity', 'unit_price', 'pricing_tiers',
        'is_taxable', 'sort_order', 'option_group', 'term',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'quantity_type' => QuantityType::class,
            'fixed_quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'pricing_tiers' => 'array',
            'is_taxable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
