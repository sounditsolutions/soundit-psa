<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client-portal AI chatbot conversation (psa-2ab).
 *
 * Hard-bound to a single client via client_id — this is the scope every tool
 * call in the chatbot is filtered by. `person_id` records which portal contact
 * owns it; ownership is re-verified on every send.
 */
class PortalChatConversation extends Model
{
    protected $fillable = [
        'client_id',
        'person_id',
        'title',
        'total_input_tokens',
        'total_output_tokens',
    ];

    protected function casts(): array
    {
        return [
            'total_input_tokens' => 'integer',
            'total_output_tokens' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PortalChatMessage::class, 'conversation_id')->orderBy('id');
    }

    public function totalTokens(): int
    {
        return $this->total_input_tokens + $this->total_output_tokens;
    }
}
