<?php

namespace App\Models;

use App\Enums\WikiRunStatus;
use App\Enums\WikiRunType;
use Illuminate\Database\Eloquent\Model;

class WikiRun extends Model
{
    protected $fillable = [
        'run_type', 'subject_type', 'subject_id', 'source_content_hash', 'status',
        'stages_completed', 'stage_results', 'errors', 'ai_tokens_used', 'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'run_type' => WikiRunType::class,
            'status' => WikiRunStatus::class,
            'stages_completed' => 'array',
            'stage_results' => 'array',
            'errors' => 'array',
            'ai_tokens_used' => 'array',
        ];
    }
}
