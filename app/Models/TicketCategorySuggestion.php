<?php

namespace App\Models;

use App\Enums\CategorySuggestionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AI-suggested ticket category/subcategory awaiting staff approval.
 *
 * @see \App\Services\Triage\TicketCategorySuggestionService
 */
class TicketCategorySuggestion extends Model
{
    protected $fillable = [
        'ticket_id',
        'category',
        'subcategory',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CategorySuggestionStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<TicketCategorySuggestion>  $query
     * @return Builder<TicketCategorySuggestion>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CategorySuggestionStatus::Pending);
    }
}
