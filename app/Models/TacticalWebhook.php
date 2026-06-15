<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TacticalWebhook extends Model
{
    /** @use HasFactory<\Database\Factories\TacticalWebhookFactory> */
    use HasFactory;

    protected $fillable = [
        'event',
        'agent_id',
        'payload',
        'status',
        'attempts',
        'error',
        'dedup_key',
        'processed_at',
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
        // Unlike the Ninja analog, ProcessTacticalWebhook uses Laravel-native retry:
        // handle() lets exceptions propagate, so markFailed() is invoked ONLY from the
        // job's terminal failed() hook — which fires once, after the queue has already
        // exhausted $tries. The queue's $tries (not this column) gates termination, so
        // by the time we're here the webhook is definitively done: mark it failed
        // unconditionally. (attempts is still incremented for the record.) Guarding on
        // `attempts >= 3` here — as Ninja does — would wrongly leave it 'pending' forever,
        // because attempts is only 1 at the terminal hook.
        $this->increment('attempts');
        $this->update([
            'error' => $error,
            'status' => 'failed',
        ]);
    }
}
