<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'size_bytes' => 'integer',
        'is_inline' => 'boolean',
    ];

    // ── Relations ──

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ── Helpers ──

    public function isImage(): bool
    {
        return in_array($this->mime_type, [
            'image/png', 'image/jpeg', 'image/gif', 'image/webp',
        ], true);
    }

    // ── Accessors ──

    public function getUrlAttribute(): string
    {
        return route('attachments.show', [$this->id, $this->filename]);
    }

    // ── Lifecycle ──

    protected static function booted(): void
    {
        static::forceDeleting(function (Attachment $attachment) {
            Storage::disk('local')->delete($attachment->storage_path);
        });
    }
}
