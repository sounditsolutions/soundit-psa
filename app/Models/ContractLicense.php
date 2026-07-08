<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ContractLicense extends Pivot
{
    protected $table = 'contract_license';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }
}
