<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class McpToken extends Model
{
    public const DEFAULT_DIRECTIVE = 'You are using the Sound PSA staff MCP server. Stay within your granted tool scope and treat MCP close proposals as held for human review.';

    protected $fillable = [
        'label',
        'token_hash',
        'token_prefix',
        'tools',
        'directive',
        'last_used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @param Builder<McpToken> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function signalDestinations(): HasMany
    {
        return $this->hasMany(SignalDestination::class);
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
