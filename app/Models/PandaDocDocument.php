<?php

namespace App\Models;

use App\Enums\PandaDocStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * A document (agreement) generated and tracked through PandaDoc for a contract.
 *
 * Distinct from ContractDocument (uploaded PDFs summarized by AI) — this record
 * tracks the e-signature lifecycle of a PandaDoc-hosted document and, once
 * signed, stores the completed PDF on the local disk.
 */
class PandaDocDocument extends Model
{
    use SoftDeletes;

    protected $table = 'pandadoc_documents';

    protected $fillable = [
        'contract_id',
        'created_by',
        'pandadoc_id',
        'name',
        'status',
        'template_id',
        'template_name',
        'recipient_email',
        'recipient_name',
        'signed_disk_path',
        'metadata',
        'sent_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PandaDocStatus::class,
            'metadata' => 'array',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (PandaDocDocument $document) {
            // Only clean up the file on a hard delete — soft deletes keep it
            // so a restored record still points at a real PDF.
            if ($document->isForceDeleting() && $document->signed_disk_path) {
                Storage::disk('local')->delete($document->signed_disk_path);
            }
        });
    }

    // ── Relations ──

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Helpers ──

    public function hasSignedPdf(): bool
    {
        return $this->signed_disk_path
            && Storage::disk('local')->exists($this->signed_disk_path);
    }
}
