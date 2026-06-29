<?php

namespace Tests\Unit\Transcription;

use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\User;
use App\Services\TranscriptionService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Task 2 — Structured caller-ID (Part A) + Whisper name-bias seed (Part B / Q2a).
 *
 * Part A: the AI analysis now emits a `## Caller Identity` block; parseCallerIdentity()
 * extracts name/company/confidence from it and the service saves them to the DB.
 *
 * Part B: buildWhisperNameHint() builds a comma-joined participant name string;
 * the four Whisper leaf/chunker methods accept ?string $namePrompt = null and
 * append it to the multipart array only when non-null/non-empty (byte-identical
 * request when null).
 *
 * The HTTP multipart wiring is not asserted here (would need Guzzle mock + file
 * fixtures — disproportionate for a unit suite). Covered: parser correctness,
 * hint-builder output, and method signature reflection. Integration verified
 * manually / in future feature tests against a real Whisper call.
 */
class TranscriptionCallerIdentityTest extends TestCase
{
    // ── Reflection helper ────────────────────────────────────────────────────

    /** @param mixed[] $args */
    private function callPrivate(object $instance, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($instance, $method);

        return $ref->invoke($instance, ...$args);
    }

    // ── parseCallerIdentity — standard block ─────────────────────────────────

    public function test_parse_caller_identity_standard_block(): void
    {
        $service = new TranscriptionService;

        $analysis = <<<'ANALYSIS'
## Caller Identity
- Name: John Smith
- Company: Acme Corp
- Confidence: 0.9
- Signals: caller said "this is John from Acme"

## Sentiment Score
- Score: 7
ANALYSIS;

        $result = $this->callPrivate($service, 'parseCallerIdentity', [$analysis]);

        $this->assertSame('John Smith', $result['name']);
        $this->assertSame('Acme Corp', $result['company']);
        $this->assertIsFloat($result['confidence']);
        $this->assertSame(0.9, $result['confidence']);
    }

    // ── parseCallerIdentity — "Unknown" / "N/A" sentinel mapping ─────────────

    public function test_parse_caller_identity_unknown_and_na_map_to_null(): void
    {
        $service = new TranscriptionService;

        $analysis = <<<'ANALYSIS'
## Caller Identity
- Name: Unknown
- Company: N/A
- Confidence: 0.0
- Signals: none

## Sentiment Score
ANALYSIS;

        $result = $this->callPrivate($service, 'parseCallerIdentity', [$analysis]);

        $this->assertNull($result['name']);
        $this->assertNull($result['company']);
        // Confidence is numeric — still parsed even when name/company are nulled
        $this->assertSame(0.0, $result['confidence']);
    }

    // ── parseCallerIdentity — "none" sentinel for company ────────────────────

    public function test_parse_caller_identity_none_company_maps_to_null(): void
    {
        $service = new TranscriptionService;

        $analysis = <<<'ANALYSIS'
## Caller Identity
- Name: Bob Jones
- Company: none
- Confidence: 0.5
- Signals: addressed as Bob during call
ANALYSIS;

        $result = $this->callPrivate($service, 'parseCallerIdentity', [$analysis]);

        $this->assertSame('Bob Jones', $result['name']);
        $this->assertNull($result['company']);
        $this->assertSame(0.5, $result['confidence']);
    }

    // ── parseCallerIdentity — missing section returns all-null (no crash) ────

    public function test_parse_caller_identity_missing_section_returns_all_null(): void
    {
        $service = new TranscriptionService;

        $analysis = <<<'ANALYSIS'
## Call Summary
No caller identity section here.

## Sentiment Score
- Score: 5
ANALYSIS;

        $result = $this->callPrivate($service, 'parseCallerIdentity', [$analysis]);

        $this->assertNull($result['name']);
        $this->assertNull($result['company']);
        $this->assertNull($result['confidence']);
    }

    // ── parseCallerIdentity — bold/decorated format tolerance ────────────────

    public function test_parse_caller_identity_bold_format_variant(): void
    {
        $service = new TranscriptionService;

        $analysis = <<<'ANALYSIS'
## Caller Identity
- **Name**: Jane Doe
- **Company**: TechStart Inc
- **Confidence**: 1.0
- **Signals**: explicit self-identification

## Next Steps
ANALYSIS;

        $result = $this->callPrivate($service, 'parseCallerIdentity', [$analysis]);

        $this->assertSame('Jane Doe', $result['name']);
        $this->assertSame('TechStart Inc', $result['company']);
        $this->assertSame(1.0, $result['confidence']);
    }

    // ── parseCallerIdentity — confidence boundary values ─────────────────────

    public function test_parse_caller_identity_confidence_is_float_at_boundaries(): void
    {
        $service = new TranscriptionService;

        foreach (['0' => 0.0, '1' => 1.0, '0.75' => 0.75] as $raw => $expected) {
            $analysis = "## Caller Identity\n- Name: Test\n- Company: Corp\n- Confidence: {$raw}\n";
            $result = $this->callPrivate($service, 'parseCallerIdentity', [$analysis]);
            $this->assertIsFloat($result['confidence'], "Confidence should be float for raw '{$raw}'");
            $this->assertSame($expected, $result['confidence']);
        }
    }

    // ── buildWhisperNameHint — returns joined names ──────────────────────────

    public function test_build_whisper_name_hint_returns_comma_joined_names(): void
    {
        $service = new TranscriptionService;

        $call = new PhoneCall;

        $person = new Person;
        $person->first_name = 'Alice';
        $person->last_name = 'Walker';

        $client = new Client;
        $client->name = 'BlueTier IT';

        $agent = new User;
        $agent->name = 'Bob Technician';

        // Pre-load relations so loadMissing() doesn't fire DB queries
        $call->setRelation('person', $person);
        $call->setRelation('client', $client);
        $call->setRelation('answeredBy', $agent);

        $hint = $this->callPrivate($service, 'buildWhisperNameHint', [$call]);

        $this->assertIsString($hint);
        $this->assertStringContainsString('Alice Walker', $hint);
        $this->assertStringContainsString('BlueTier IT', $hint);
        $this->assertStringContainsString('Bob Technician', $hint);
    }

    // ── buildWhisperNameHint — returns null when no participants known ────────

    public function test_build_whisper_name_hint_returns_null_when_no_names(): void
    {
        $service = new TranscriptionService;

        $call = new PhoneCall;
        // Explicitly set all three relations to null so loadMissing() won't hit the DB
        $call->setRelation('person', null);
        $call->setRelation('client', null);
        $call->setRelation('answeredBy', null);

        $hint = $this->callPrivate($service, 'buildWhisperNameHint', [$call]);

        $this->assertNull($hint);
    }

    // ── B (Q2a): method signature reflection — null default ──────────────────

    public function test_whisper_transcribe_accepts_optional_name_prompt(): void
    {
        $ref = new ReflectionMethod(TranscriptionService::class, 'whisperTranscribe');
        $params = $ref->getParameters();

        // filePath, apiKey, namePrompt
        $this->assertCount(3, $params);
        $p = $params[2];
        $this->assertSame('namePrompt', $p->getName());
        $this->assertTrue($p->isOptional(), 'namePrompt must have a default value');
        $this->assertNull($p->getDefaultValue(), 'default must be null (byte-identical base case)');
        $this->assertTrue($p->allowsNull());
    }

    public function test_whisper_transcribe_with_words_accepts_optional_name_prompt(): void
    {
        $ref = new ReflectionMethod(TranscriptionService::class, 'whisperTranscribeWithWords');
        $params = $ref->getParameters();

        // filePath, apiKey, namePrompt
        $this->assertCount(3, $params);
        $p = $params[2];
        $this->assertSame('namePrompt', $p->getName());
        $this->assertTrue($p->isOptional());
        $this->assertNull($p->getDefaultValue());
    }

    public function test_whisper_transcribe_all_accepts_optional_name_prompt(): void
    {
        $ref = new ReflectionMethod(TranscriptionService::class, 'whisperTranscribeAll');
        $params = $ref->getParameters();

        // filePath, apiKey, &tempFiles, namePrompt
        $this->assertCount(4, $params);
        $p = $params[3];
        $this->assertSame('namePrompt', $p->getName());
        $this->assertTrue($p->isOptional());
        $this->assertNull($p->getDefaultValue());
    }

    public function test_whisper_transcribe_all_words_accepts_optional_name_prompt(): void
    {
        $ref = new ReflectionMethod(TranscriptionService::class, 'whisperTranscribeAllWords');
        $params = $ref->getParameters();

        // filePath, apiKey, &tempFiles, namePrompt
        $this->assertCount(4, $params);
        $p = $params[3];
        $this->assertSame('namePrompt', $p->getName());
        $this->assertTrue($p->isOptional());
        $this->assertNull($p->getDefaultValue());
    }

    // ── Prompt shape ─────────────────────────────────────────────────────────

    public function test_prompt_contains_caller_identity_section(): void
    {
        $prompt = TranscriptionService::CALL_TRANSCRIPTION_PROMPT;

        $this->assertStringContainsString('## Caller Identity', $prompt);
        $this->assertStringContainsString('Name:', $prompt);
        $this->assertStringContainsString('Company:', $prompt);
        $this->assertStringContainsString('Confidence:', $prompt);
    }

    public function test_caller_identity_section_appears_between_call_summary_and_sentiment(): void
    {
        $prompt = TranscriptionService::CALL_TRANSCRIPTION_PROMPT;

        $summaryPos = strpos($prompt, '## Call Summary');
        $identityPos = strpos($prompt, '## Caller Identity');
        $sentimentPos = strpos($prompt, '## Sentiment Score');

        $this->assertNotFalse($summaryPos, '## Call Summary must exist');
        $this->assertNotFalse($identityPos, '## Caller Identity must exist');
        $this->assertNotFalse($sentimentPos, '## Sentiment Score must exist');

        // Order: Call Summary < Caller Identity < Sentiment Score
        $this->assertGreaterThan($summaryPos, $identityPos,
            '## Caller Identity must appear after ## Call Summary');
        $this->assertGreaterThan($identityPos, $sentimentPos,
            '## Sentiment Score must appear after ## Caller Identity');
    }
}
