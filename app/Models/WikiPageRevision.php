<?php

namespace App\Models;

use App\Enums\WikiAuthorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WikiPageRevision extends Model
{
    public const UPDATED_AT = null; // revisions are immutable once written

    protected $fillable = [
        'page_id', 'body_md', 'meta', 'author_type', 'author_id', 'change_summary', 'source_refs',
    ];

    protected function casts(): array
    {
        return [
            'author_type' => WikiAuthorType::class,
            'meta' => 'array',
            'source_refs' => 'array',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'page_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
