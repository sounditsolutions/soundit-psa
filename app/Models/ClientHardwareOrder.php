<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientHardwareOrder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'response_keys' => 'array', 'estimated_total' => 'decimal:2'];
    }
}
