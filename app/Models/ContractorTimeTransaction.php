<?php

namespace App\Models;

use App\Enums\ContractorTimeSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractorTimeTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'source',
        'hours',
        'date',
        'description',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => ContractorTimeSource::class,
            'hours' => 'decimal:4',
            'date' => 'datetime',
        ];
    }

    // ── Relations ──

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    // ── Display ──

    public function formattedHours(): string
    {
        $sign = $this->hours >= 0 ? '+' : '';

        return $sign . number_format($this->hours, 2) . ' hrs';
    }
}
