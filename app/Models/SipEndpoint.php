<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SipEndpoint extends Model
{
    protected $fillable = [
        'sip_uri',
        'sip_username',
        'sip_password',
        'plivo_endpoint_id',
        'user_id',
        'label',
        'is_active',
    ];

    protected $hidden = ['sip_password'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sip_password' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(PhoneCall::class, 'sip_endpoint', 'sip_uri');
    }
}
