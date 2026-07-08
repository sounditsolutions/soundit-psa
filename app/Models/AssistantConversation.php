<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantConversation extends Model
{
    protected $fillable = [
        'user_id',
        'context_type',
        'context_id',
        'title',
        'external_key',
        'total_input_tokens',
        'total_output_tokens',
    ];

    protected $casts = [
        'context_id' => 'integer',
        'total_input_tokens' => 'integer',
        'total_output_tokens' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id')->orderBy('id');
    }

    public function totalTokens(): int
    {
        return $this->total_input_tokens + $this->total_output_tokens;
    }

    public function resolveTicket(): ?Ticket
    {
        if ($this->context_type !== 'ticket' || ! $this->context_id) {
            return null;
        }

        return Ticket::find($this->context_id);
    }

    public function resolveClient(): ?Client
    {
        if ($this->context_type === 'client' && $this->context_id) {
            return Client::find($this->context_id);
        }

        if ($this->context_type === 'ticket' && $this->context_id) {
            $ticket = $this->resolveTicket();

            return $ticket?->client;
        }

        return null;
    }

    public function resolveClientId(): ?int
    {
        if ($this->context_type === 'client') {
            return $this->context_id;
        }

        if ($this->context_type === 'ticket') {
            return $this->resolveTicket()?->client_id;
        }

        return null;
    }
}
