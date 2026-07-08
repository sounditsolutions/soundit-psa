<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ContractPerson extends Pivot
{
    protected $table = 'contract_person';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }
}
