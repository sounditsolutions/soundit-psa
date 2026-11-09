<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientHardwareModel extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sell_price' => 'decimal:2', 'carbon_price' => 'decimal:2', 'synced_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
