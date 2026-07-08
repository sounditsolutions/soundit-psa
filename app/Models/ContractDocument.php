<?php

namespace App\Models;

use App\Enums\DocumentSummaryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class ContractDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'contract_id',
        'uploaded_by',
        'original_filename',
        'disk_path',
        'mime_type',
        'file_size',
        'extracted_text',
        'ai_summary',
        'summary_status',
        'summary_tokens_used',
        'summarized_at',
    ];

    protected function casts(): array
    {
        return [
            'summary_status' => DocumentSummaryStatus::class,
            'summarized_at' => 'datetime',
            'file_size' => 'integer',
            'summary_tokens_used' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (ContractDocument $document) {
            if ($document->disk_path) {
                Storage::disk('local')->delete($document->disk_path);
            }
        });
    }

    // ── Relations ──

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
