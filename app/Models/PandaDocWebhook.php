<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Store-then-dispatch record for an inbound PandaDoc webhook event.
 * Mirrors QboWebhook: the controller persists the raw event, then a queued
 * job processes it, so a slow downstream sync never blocks the webhook 200.
 */
class PandaDocWebhook extends Model
{
    protected $table = 'pandadoc_webhooks';

    protected $fillable = [
        'event_type',
        'document_id',
        'document_status',
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
