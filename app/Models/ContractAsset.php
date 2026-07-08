<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ContractAsset extends Pivot
{
    protected $table = 'contract_asset';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }
}
