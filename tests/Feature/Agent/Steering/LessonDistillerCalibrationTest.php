<?php

namespace Tests\Feature\Agent\Steering;

use App\Enums\ToolingGapSource;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\ToolingGap;
use App\Models\User;
use App\Services\Agent\Steering\CorrectionRecorder;
use App\Services\Agent\Steering\LessonCapture;
use App\Services\Agent\Steering\LessonDistiller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LIVE calibration of LessonDistiller's tooling classification (psa-7r1j).
 *
 * This is a REAL-AI test — it hits the configured model, so it is GATED on the
 * AI_LIVE_TEST_KEY env var and SKIPS in CI (which never sets it). Run it where a key
 * is available:
 *
 *   AI_LIVE_TEST_KEY=sk-ant-... vendor/bin/phpunit tests/Feature/Agent/Steering/LessonDistillerCalibrationTest.php
 *
 * It is the executable spec for the bug: Charlie's archetypal correction on ticket 22571 —
 * "Check recent ticket history for full context." — was classified `none` (so it never became a
 * tooling-gap). The marquee case the whole RETRIEVE loop exists for. After tuning the tooling
 * branch of the prompt, terse "you missed retrievable info / go check an available source"
 * corrections must classify `tooling`, while genuinely routine corrections stay `none`.
 *
 * Deterministic plumbing is covered separately by LessonDistillerTest (mocked AI).
 */
class LessonDistillerCalibrationTest extends TestCase
{
    use RefreshDatabase;

    /** Terse "the agent missed retrievable info / should have checked an available source" → tooling. */
    private const TOOLING_FIXTURES = [
        'Check recent ticket history for full context.',                 // ← the 22571 archetype
        'You should have checked the previous ticket on this issue.',
        "Look at the asset's prior tickets before replying.",
        'The fix for this was in an earlier ticket — check there first.',
    ];

    /** Genuinely routine, one-off corrections with no reusable lesson → none. */
    private const NONE_FIXTURES = [
        'Change the priority to high.',
        "Fix the typo in the reply — it's their not there.",
        'This looks fine, go ahead and send it.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $key = env('AI_LIVE_TEST_KEY');
        if (! $key) {
            $this->markTestSkipped('Set AI_LIVE_TEST_KEY (a real AI API key) to run the live LessonDistiller calibration.');
        }

        // RefreshDatabase wipes settings, so inject the AI config from env (the key never lives in the repo).
        Setting::setValue('ai_provider', env('AI_LIVE_TEST_PROVIDER', 'anthropic'));
        Setting::setValue('ai_model', env('AI_LIVE_TEST_MODEL', 'claude-sonnet-4-6'));
        Setting::setEncrypted('ai_api_key', $key);
    }

    private function context(string $subject): string
    {
        return "Ticket #4242 — {$subject}. The AI technician proposed a reply; the operator is correcting it.";
    }

    public function test_terse_missed_retrievable_info_corrections_classify_tooling(): void
    {
        $distiller = app(LessonDistiller::class);

        foreach (self::TOOLING_FIXTURES as $correction) {
            $result = $distiller->distill($correction, $this->context('slow computer'));

            $this->assertNotNull($result, "distill returned null for: {$correction}");
            $this->assertSame(
                'tooling',
                $result->type,
                "Expected TOOLING for a terse 'missed retrievable info' correction, got '{$result->type}': {$correction}"
            );
        }
    }

    public function test_routine_corrections_stay_none(): void
    {
        $distiller = app(LessonDistiller::class);

        foreach (self::NONE_FIXTURES as $correction) {
            $result = $distiller->distill($correction, $this->context('password reset'));

            $this->assertNotNull($result, "distill returned null for: {$correction}");
            $this->assertSame(
                'none',
                $result->type,
                "Expected NONE for a routine correction, got '{$result->type}': {$correction}"
            );
        }
    }

    /**
     * The marquee end-to-end: the 22571 correction, run through the REAL distiller via LessonCapture,
     * now produces a ToolingGap(source=correction). This is the case Charlie raised.
     */
    public function test_archetype_correction_through_lesson_capture_creates_a_tooling_gap(): void
    {
        $operator = User::factory()->create();
        $client = Client::factory()->create();
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'email' => 'contact@example.com',
            'is_active' => true,
        ]);
        $ticket = Ticket::factory()->for($client)->create(['contact_id' => $person->id, 'subject' => 'Email not syncing']);

        $conversation = app(CorrectionRecorder::class)->record(
            $ticket,
            $operator,
            'Check recent ticket history for full context.',
        );

        app(LessonCapture::class)->capture($ticket, $conversation);

        $gap = ToolingGap::where('ticket_id', $ticket->id)->where('source', ToolingGapSource::Correction)->first();
        $this->assertNotNull($gap, 'The 22571 archetype correction must produce a ToolingGap(source=correction).');
        $this->assertNotSame('', trim($gap->capability_gap), 'The captured gap must carry an abstract capability description.');
    }
}
