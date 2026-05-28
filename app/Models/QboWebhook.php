<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QboWebhook extends Model
{
    protected $fillable = [
        'entity_type',
        'entity_id',
        'operation',
        'realm_id',
        'payload',
        'status',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function markProcessed(): void
    {
        $this->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    public function markSkipped(string $reason): void
    {
        $this->update([
            'status' => 'skipped',
            'error' => $reason,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->increment('attempts');
        $this->update([
            'error' => $error,
            'status' => $this->attempts >= 3 ? 'failed' : 'pending',
        ]);
    }
}
