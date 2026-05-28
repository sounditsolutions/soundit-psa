<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TriageRun extends Model
{
    protected $fillable = [
        'ticket_id',
        'mode',
        'status',
        'stages_completed',
        'stage_results',
        'errors',
        'triggered_by',
        'triggered_by_user_id',
        'started_at',
        'completed_at',
        'duration_ms',
        'ai_tokens_used',
        'feedback_correct',
        'feedback_note',
        'feedback_submitted_by',
        'feedback_submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'stages_completed' => 'array',
            'stage_results' => 'array',
            'errors' => 'array',
            'ai_tokens_used' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'feedback_correct' => 'boolean',
            'feedback_submitted_at' => 'datetime',
        ];
    }

    // ── Relationships ──

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function feedbackSubmittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'feedback_submitted_by');
    }

    // ── Helpers ──

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Get the result data for a specific stage.
     */
    public function stageResult(string $stage): ?array
    {
        return $this->stage_results[$stage] ?? null;
    }

    /**
     * Check if a specific stage was completed in this run.
     */
    public function stageCompleted(string $stage): bool
    {
        return in_array($stage, $this->stages_completed ?? []);
    }

    /**
     * Get classification result shorthand.
     */
    public function classification(): ?array
    {
        return $this->stageResult('classification');
    }

    /**
     * Get the total tokens used across all AI calls in this run.
     */
    public function totalTokensUsed(): int
    {
        $tokens = $this->ai_tokens_used ?? [];

        return ($tokens['input_tokens'] ?? 0) + ($tokens['output_tokens'] ?? 0);
    }
}
