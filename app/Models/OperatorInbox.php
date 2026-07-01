<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatorInbox extends Model
{
    protected $table = 'operator_inbox';

    protected $fillable = [
        'conversation_id',
        'sender_user_id',
        'text',
        'ts',
        'direct_mention',
        'authorized_steer',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'ts' => 'datetime',
            'delivered_at' => 'datetime',
            'direct_mention' => 'boolean',
            'authorized_steer' => 'boolean',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
