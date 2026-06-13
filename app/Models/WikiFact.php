<?php

namespace App\Models;

use App\Enums\WikiFactSource;
use App\Enums\WikiFactStatus;
use App\Enums\WikiFactVolatility;
use App\Enums\WikiScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WikiFact extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope', 'client_id', 'page_id', 'section_anchor', 'subject_key', 'statement',
        'status', 'pinned', 'volatility', 'source_type', 'source_refs', 'confidence',
        'last_affirmed_at', 'confirmed_by', 'disputed_with_fact_id',
        'superseded_by_fact_id', 'dismissed_evidence',
    ];

    protected function casts(): array
    {
        return [
            'scope' => WikiScope::class,
            'status' => WikiFactStatus::class,
            'volatility' => WikiFactVolatility::class,
            'source_type' => WikiFactSource::class,
            'source_refs' => 'array',
            'dismissed_evidence' => 'array',
            'pinned' => 'boolean',
            'confidence' => 'decimal:2',
            'last_affirmed_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'page_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function disputedWith(): BelongsTo
    {
        return $this->belongsTo(self::class, 'disputed_with_fact_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_fact_id');
    }
}
