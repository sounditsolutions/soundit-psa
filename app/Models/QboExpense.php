<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QboExpense extends Model
{
    protected $fillable = [
        'qbo_purchase_id',
        'txn_date',
        'payment_type',
        'account_name',
        'payee_name',
        'total_amount',
        'currency',
        'doc_number',
        'memo',
        'qbo_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'txn_date' => 'date',
            'total_amount' => 'decimal:2',
            'qbo_synced_at' => 'datetime',
        ];
    }
}
