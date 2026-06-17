<?php

namespace Tests\Unit\Transcription;

use App\Services\TranscriptionService;
use PHPUnit\Framework\TestCase;

/**
 * Decision 2026-06-17: transcripts are ALWAYS generated and cleaned, regardless
 * of call length. Whisper already transcribes the whole call; the AI analysis
 * cleans the transcript (fixing Whisper's name/speaker errors). The old
 * 30-minute cutoff that omitted the cleaned transcript for long calls existed
 * only to stay under the 8000-token output cap — that cap was raised in
 * analyzeWithAi(), so the prompt must now request the full cleaned transcript
 * unconditionally.
 */
class AlwaysCleanPromptTest extends TestCase
{
    public function test_prompt_always_requests_the_full_cleaned_transcript(): void
    {
        $prompt = TranscriptionService::CALL_TRANSCRIPTION_PROMPT;

        $this->assertStringContainsString('## Transcription', $prompt);
        $this->assertStringContainsString('Always include the full cleaned transcript', $prompt);
    }

    public function test_prompt_has_no_length_gated_omission(): void
    {
        $prompt = TranscriptionService::CALL_TRANSCRIPTION_PROMPT;

        // The old cutoff — which omitted the transcript and branched the summary
        // on a 30-minute boundary — must be gone.
        $this->assertStringNotContainsString('30 minutes', $prompt);
        $this->assertStringNotContainsStringIgnoringCase('ONLY for calls under', $prompt);
        $this->assertStringNotContainsStringIgnoringCase('expand the summary instead', $prompt);
    }
}
