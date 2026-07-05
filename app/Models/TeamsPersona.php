<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A single AI-staff Teams persona (Gus first) — the source-of-truth identity
 * row for the multi-bot Teams feature. A row is DORMANT (invisible to
 * TeamsBotConfig::appIds()/forAppId(), see TeamsPersonaConfig) until it has
 * bot credentials AND enabled=true, so seeding draft personas is always safe.
 */
class TeamsPersona extends Model
{
    protected $fillable = [
        'persona_key',
        'display_name',
        'role_blurb',
        'avatar_ref',
        'bot_app_id',
        'bot_client_secret',
        'tenant_id',
        'mcp_token_label',
        'actor_user_id',
        'conversation_refs',
        'enabled',
    ];

    /**
     * The `encrypted` cast DECRYPTS bot_client_secret on toArray()/toJson(), so
     * hiding it from array/JSON serialization is the only guard against a
     * casual response()->json($persona) / @json / ->toArray() leaking the
     * plaintext client secret. Defense-in-depth for the "no reveal, ever"
     * invariant ahead of the P2 provisioning wizard. Internal reads
     * (hasSecret() via getRawOriginal, TeamsBotClient::token() via property
     * access) are unaffected — $hidden only touches serialization.
     */
    protected $hidden = [
        'bot_client_secret',
    ];

    protected function casts(): array
    {
        return [
            'bot_client_secret' => 'encrypted',
            'conversation_refs' => 'array',
            'enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TeamsPersona $persona) {
            if (filled($persona->mcp_token_label) && ! McpToken::where('label', $persona->mcp_token_label)->exists()) {
                throw new InvalidArgumentException(
                    "mcp_token_label [{$persona->mcp_token_label}] does not match any McpToken.label."
                );
            }
        });
    }

    /**
     * @param  Builder<TeamsPersona>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /**
     * Whether a client secret is stored. Checks the raw (still-encrypted)
     * original attribute so presence can be tested without decrypting.
     */
    public function hasSecret(): bool
    {
        return filled($this->getRawOriginal('bot_client_secret'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
