<?php

namespace App\Models;

use App\Enums\WikiAuthorType;
use App\Enums\WikiPageKind;
use App\Enums\WikiScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WikiPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope', 'client_id', 'slug', 'title', 'kind', 'parent_page_id',
        'body_md', 'meta', 'is_archived', 'created_by_type',
    ];

    protected function casts(): array
    {
        return [
            'scope' => WikiScope::class,
            'kind' => WikiPageKind::class,
            'created_by_type' => WikiAuthorType::class,
            'meta' => 'array',
            'is_archived' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_page_id');
    }

    public function deviations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_page_id');
    }

    public function facts(): HasMany
    {
        return $this->hasMany(WikiFact::class, 'page_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WikiPageRevision::class, 'page_id')->latest('id');
    }

    public function linksFrom(): HasMany
    {
        return $this->hasMany(WikiLink::class, 'from_page_id');
    }

    public function backlinks(): HasMany
    {
        return $this->hasMany(WikiLink::class, 'to_page_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    public function scopeGlobalScope(Builder $query): Builder
    {
        return $query->where('scope', WikiScope::Global->value);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('scope', WikiScope::Client->value)->where('client_id', $clientId);
    }
}
