<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WikiLink extends Model
{
    protected $fillable = ['from_page_id', 'to_page_id', 'target_slug', 'anchor_text'];

    public function fromPage(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'from_page_id');
    }

    public function toPage(): BelongsTo
    {
        return $this->belongsTo(WikiPage::class, 'to_page_id');
    }
}
