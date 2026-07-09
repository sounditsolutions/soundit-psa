<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A staff MCP bearer token. Only the sha256 hash is stored; plaintext is shown
 * once at mint. Null tools preserves legacy full-surface semantics.
 */
class McpToken extends Model
{
    public const DEFAULT_DIRECTIVE = 'You are using the Sound PSA staff MCP server. Stay within your granted tool scope and treat MCP close proposals as held for human review.';

    protected $fillable = [
        'label',
        'token_hash',
        'token_prefix',
        'tools',
        'directive',
        'ai_actor',
        'require_explicit_client_scope',
        'activated_at',
        'paused_at',
        'last_used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'ai_actor' => 'boolean',
            'require_explicit_client_scope' => 'boolean',
            'activated_at' => 'datetime',
            'paused_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Not revoked. Kept for feature-availability and listing callers; this is
     * NOT the authentication gate (a draft is "not revoked" but cannot authenticate).
     *
     * @param  Builder<McpToken>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * The authentication gate. Only a token that has been activated and is not
     * paused or revoked may resolve a request. This is where the born-safe
     * guarantee lives: a draft/paused/revoked token is never authenticated.
     *
     * @param  Builder<McpToken>  $query
     */
    public function scopeAuthenticatable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->whereNotNull('activated_at')
            ->whereNull('paused_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Whether a non-revoked token exists for the given label. This is the gate
     * for anything that assumes an agent can still poll a label (Alerts Hub MCP
     * destinations): a revoked token can never authenticate again, so a
     * destination pointing at one — or at no token at all — is orphaned.
     *
     * Draft and paused tokens count as live: both can still be activated/resumed
     * and drain a queued backlog. Only revocation is terminal.
     */
    public static function hasLiveLabel(?string $label): bool
    {
        $label = trim((string) $label);

        return $label !== ''
            && static::query()->where('label', $label)->whereNull('revoked_at')->exists();
    }

    public function isDraft(): bool
    {
        return $this->revoked_at === null
            && $this->paused_at === null
            && $this->activated_at === null;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->paused_at === null
            && $this->activated_at !== null;
    }

    public function isPaused(): bool
    {
        return $this->revoked_at === null
            && $this->paused_at !== null;
    }

    /**
     * Derived lifecycle state. Precedence: revoked > paused > active > draft.
     */
    public function state(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        if ($this->paused_at !== null) {
            return 'paused';
        }

        if ($this->activated_at !== null) {
            return 'active';
        }

        return 'draft';
    }

    public function signalDestinations(): HasMany
    {
        return $this->hasMany(SignalDestination::class, 'mcp_token_label', 'label');
    }

    public function directiveOrDefault(): string
    {
        $directive = trim((string) $this->directive);

        if ($directive !== '') {
            return $directive;
        }

        return self::defaultDirective();
    }

    public static function defaultDirective(): string
    {
        return self::DEFAULT_DIRECTIVE;
    }

    public static function importLegacyBlob(): int
    {
        try {
            $raw = Setting::getEncrypted('mcp_staff_scoped_tokens');
        } catch (\Throwable) {
            return 0;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return 0;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return 0;
        }

        $imported = 0;
        foreach ($decoded as $record) {
            if (! is_array($record)) {
                continue;
            }

            $label = trim((string) ($record['label'] ?? ''));
            $hash = trim((string) ($record['hash'] ?? ''));
            if ($label === '' || $hash === '') {
                continue;
            }

            $tools = array_values(array_filter(
                array_map(fn ($tool): string => trim((string) $tool), (array) ($record['tools'] ?? [])),
                fn (string $tool): bool => $tool !== '',
            ));

            $createdAt = ! empty($record['created_at'])
                ? Carbon::parse((string) $record['created_at'])
                : now();

            $token = static::firstOrNew(['label' => $label]);
            $isNew = ! $token->exists;
            $token->token_hash = $hash;
            $token->token_prefix = null;
            $token->tools = $tools;
            $token->revoked_at = null;
            if ($isNew) {
                $token->created_at = $createdAt;
            }
            $token->save();
            $imported++;
        }

        return $imported;
    }
}
