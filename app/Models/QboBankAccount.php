<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QboBankAccount extends Model
{
    protected $fillable = [
        'qbo_account_id',
        'name',
        'account_sub_type',
        'classification',
        'current_balance',
        'currency',
        'active',
        'qbo_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:2',
            'active' => 'boolean',
            'qbo_synced_at' => 'datetime',
        ];
    }
}
