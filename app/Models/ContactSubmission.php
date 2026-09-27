<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ContactSubmission extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payload', 'identity_hash', 'payload_hash'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'attempts' => 'integer', 'conflicts' => 'integer'];
    }
}
