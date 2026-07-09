<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single turn in a client-portal chatbot conversation (psa-2ab).
 * `role` is 'user' or 'assistant'. Only human-visible turns are stored —
 * intermediate tool_use / tool_result blocks live only inside a single
 * AiClient tool loop and are not persisted.
 */
class PortalChatMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'input_tokens',
        'output_tokens',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(PortalChatConversation::class, 'conversation_id');
    }
}
