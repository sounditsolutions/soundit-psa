<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SignableDocument extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'rendered_lines' => 'array',
            'requires_countersign' => 'boolean',
            'token_expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            if ($document->isDirty(['rendered_lines', 'rendered_lines_sha256', 'requires_countersign', 'pdf_path', 'pdf_sha256'])) {
                throw new LogicException('signing_document_evidence_immutable');
            }
        });
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SigningSnapshot::class);
    }

    public function isFinal(): bool
    {
        return $this->status === 'countersigned'
            || ($this->status === 'signed' && ! $this->requires_countersign);
    }

    public function isVoidable(): bool
    {
        return in_array($this->status, ['draft', 'sent', 'viewed', 'signed'], true) && ! $this->isFinal();
    }
}
