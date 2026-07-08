<?php

namespace Tests\Feature\Agent\Intake;

use App\Enums\CallStatus;
use App\Enums\PhoneDirectoryListType;
use App\Models\Client;
use App\Models\PhoneCall;
use App\Models\PhoneDirectoryEntry;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Agent\Intake\CallerResolution;
use App\Services\Agent\Intake\CallerResolver;
use App\Services\Agent\Intake\CallIntakePipeline;
use App\Services\Agent\Intake\IntakeRouter;
use App\Services\Agent\Intake\SpamAssessor;
use App\Services\Agent\Intake\SpamVerdict;
use App\Support\AgentConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Task 6a — the UNRESOLVED branch of CallIntakePipeline splits HOLD into:
 *   - real-looking unknown   → HOLD (unchanged)
 *   - suspected spam         → persist intake_spam_score for the cockpit one-tap (SUGGEST)
 *   - suspected spam ABOVE an operator-set block threshold (+ a system user) → AUTO block
 *
 * SAFETY: the AUTO mark+block is the only semi-destructive automated path in the leg. It is
 * gated behind a threshold that DEFAULTS TO NULL = NEVER. These tests assert that property
 * explicitly, plus the system-user attribution requirement and the M1 unresolved-guard
 * hardening.
 *
 * The SpamAssessor is mocked (its own assessment logic is unit-tested) so each path is
 * exercised deterministically; the real CallerResolver drives the unresolved entry.
 */
class CallIntakeSpamTest extends TestCase
{
    use RefreshDatabase;

    /** An unknown, unresolvable caller (no client, no prior call, no caller identity). */
    private const UNKNOWN_NUMBER = '+19998887777';

    private function makeCall(array $attrs = []): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => $attrs['from_number'] ?? self::UNKNOWN_NUMBER,
            'status' => $attrs['status'] ?? CallStatus::Completed,
            'call_summary' => $attrs['call_summary'] ?? 'An unknown caller pitching SEO services.',
            'cleaned_transcript' => $attrs['cleaned_transcript'] ?? 'Hi, are you the business owner?',
            'caller_identity_confidence' => $attrs['caller_identity_confidence'] ?? null,
        ]);
        $call->client_id = $attrs['client_id'] ?? null;
        $call->save();

        return $call;
    }

    private function pipeline(): CallIntakePipeline
    {
        return app(CallIntakePipeline::class);
    }

    private function mockSpam(SpamVerdict $verdict): void
    {
        $this->mock(SpamAssessor::class)
            ->shouldReceive('assess')
            ->once()
            ->andReturn($verdict);
    }

    // ── SUGGEST: unresolved + spam, threshold null (default) → score set, NO block ──

    public function test_unresolved_spam_at_default_threshold_persists_score_and_does_not_block(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        // intake_spam_block_auto_threshold deliberately NOT set → null → NEVER auto-block

        $call = $this->makeCall();
        $this->mockSpam(new SpamVerdict(true, 0.72, 'unsolicited sales pitch'));

        $this->pipeline()->handle($call);

        $fresh = $call->fresh();
        $this->assertEqualsWithDelta(0.72, $fresh->intake_spam_score, 0.001, 'the suspicion is persisted for the one-tap');
        $this->assertNull($fresh->followed_up_at, 'SUGGEST must not follow-up (no auto-resolution)');
        $this->assertNull($fresh->ticket_id, 'a spam call is ticketless');
        $this->assertSame(0, Ticket::count(), 'no ticket for a suspected-spam call');
        $this->assertSame(0, PhoneDirectoryEntry::count(), 'SUGGEST must NEVER block the number');
        $this->assertNull($fresh->client_id, 'the call stays unknown-caller');
    }

    // ── HOLD: unresolved + not-spam → score null, plain HOLD ─────────────────

    public function test_unresolved_not_spam_holds_with_null_score(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');

        $call = $this->makeCall();
        $this->mockSpam(SpamVerdict::notSpam());

        $this->pipeline()->handle($call);

        $fresh = $call->fresh();
        $this->assertNull($fresh->intake_spam_score, 'a not-spam call carries no spam score');
        $this->assertNull($fresh->ticket_id);
        $this->assertNull($fresh->followed_up_at);
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, PhoneDirectoryEntry::count());
    }

    // ── AUTO: unresolved + spam + threshold met + system user → block ────────

    public function test_unresolved_spam_above_threshold_with_system_user_auto_blocks(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_spam_block_auto_threshold', '0.9');
        $systemUser = User::factory()->create(); // TriageConfig::systemUserId() → first user

        $call = $this->makeCall();
        $this->mockSpam(new SpamVerdict(true, 0.95, 'definite robocall'));

        $this->pipeline()->handle($call);

        $fresh = $call->fresh();
        // markFollowedUp resolved the call, attributed to the system user.
        $this->assertNotNull($fresh->followed_up_at, 'AUTO block marks the call followed-up');
        $this->assertSame($systemUser->id, $fresh->followed_up_by);

        // The caller's number is on the Blocked list, attributed to the system user.
        $entry = PhoneDirectoryEntry::where('phone_number', self::UNKNOWN_NUMBER)->first();
        $this->assertNotNull($entry, 'the number must be blocked');
        $this->assertSame(PhoneDirectoryListType::Blocked, $entry->list_type);
        $this->assertSame($systemUser->id, $entry->added_by_user_id);
        $this->assertSame('AI intake: auto-blocked suspected spam', $entry->reason);

        $this->assertSame(0, Ticket::count(), 'AUTO block creates no ticket');
        $this->assertNull($fresh->intake_spam_score, 'AUTO path resolves the call — no suggestion score needed');
    }

    public function test_auto_block_is_idempotent_for_a_repeat_spam_number(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_spam_block_auto_threshold', '0.9');
        $systemUser = User::factory()->create();

        // The number is already on the block list (e.g. a prior auto-block).
        PhoneDirectoryEntry::create([
            'phone_number' => self::UNKNOWN_NUMBER,
            'list_type' => PhoneDirectoryListType::Blocked,
            'reason' => 'previously blocked',
            'added_by_user_id' => $systemUser->id,
        ]);

        $call = $this->makeCall();
        $this->mockSpam(new SpamVerdict(true, 0.97, 'robocall again'));

        $this->pipeline()->handle($call); // must not throw on the unique number index

        $this->assertSame(1, PhoneDirectoryEntry::where('phone_number', self::UNKNOWN_NUMBER)->count(),
            'updateOrCreate must not duplicate the block entry');
        $this->assertSame('AI intake: auto-blocked suspected spam',
            PhoneDirectoryEntry::where('phone_number', self::UNKNOWN_NUMBER)->first()->reason,
            'the existing entry is updated in place');
    }

    // ── AUTO requires a system user: no user → falls back to SUGGEST ─────────

    public function test_threshold_met_but_no_system_user_falls_back_to_suggest(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        Setting::setValue('intake_spam_block_auto_threshold', '0.9');
        // No User exists and triage_system_user_id is unset → systemUserId() is null.

        $call = $this->makeCall();
        $this->mockSpam(new SpamVerdict(true, 0.95, 'robocall'));

        $this->pipeline()->handle($call);

        $fresh = $call->fresh();
        $this->assertSame(0, PhoneDirectoryEntry::count(), 'no system user → cannot attribute a block → no block');
        $this->assertNull($fresh->followed_up_at, 'no auto-follow-up without a system user');
        $this->assertEqualsWithDelta(0.95, $fresh->intake_spam_score, 0.001, 'falls back to the SUGGEST path');
    }

    // ── THE load-bearing safety property: null threshold NEVER auto-blocks ──

    public function test_null_default_threshold_never_auto_blocks_even_at_full_confidence(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');
        // threshold deliberately unset → null = NEVER auto-block.
        // A system user IS present, proving it is the NULL THRESHOLD — not a missing user —
        // that prevents the block.
        User::factory()->create();

        $this->assertNull(AgentConfig::intakeSpamBlockAutoThreshold(), 'precondition: the default threshold is null');

        $call = $this->makeCall();
        $this->mockSpam(new SpamVerdict(true, 1.0, 'unmistakable robocall'));

        $this->pipeline()->handle($call);

        $fresh = $call->fresh();
        $this->assertSame(0, PhoneDirectoryEntry::count(), 'a null threshold must NEVER auto-block, even at confidence 1.0');
        $this->assertNull($fresh->followed_up_at, 'no auto-follow-up under a null threshold');
        $this->assertEqualsWithDelta(1.0, $fresh->intake_spam_score, 0.001, 'the suspicion is persisted (SUGGEST), never blocked');
    }

    // ── M1 hardening: resolved-but-null-client routes to HOLD, no crash ─────

    public function test_resolved_but_null_client_routes_to_hold_without_crashing(): void
    {
        Bus::fake();
        Setting::setValue('intake_enabled', '1');

        $call = $this->makeCall(['from_number' => '+15550100001']); // client_id null at entry

        // Pathological resolution: resolved=true yet clientId=null (M1). Stage 4 does not
        // apply it, so the unresolved guard must still route to HOLD — not fall through to
        // routeContent($clientId=null,…) → TypeError.
        $this->mock(CallerResolver::class)
            ->shouldReceive('resolve')
            ->once()
            ->andReturn(new CallerResolution(true, null, null, 'content_company'));

        // The router must NEVER be reached for a held call.
        $this->mock(IntakeRouter::class)->shouldReceive('routeContent')->never();

        // handleUnresolved IS reached → assess once → notSpam → plain HOLD.
        $this->mockSpam(SpamVerdict::notSpam());

        $this->pipeline()->handle($call); // must not throw

        $fresh = $call->fresh();
        $this->assertSame(0, Ticket::count(), 'resolved-but-null-client must HOLD, not create a ticket');
        $this->assertNull($fresh->ticket_id);
        $this->assertNull($fresh->client_id);
        $this->assertNull($fresh->intake_spam_score);
    }

    // ── Threshold config: null-preserving with a 0.90 floor ──────────────────

    public function test_spam_block_threshold_is_null_when_unset(): void
    {
        $this->assertNull(AgentConfig::intakeSpamBlockAutoThreshold());
    }

    public function test_spam_block_threshold_blank_is_null(): void
    {
        Setting::setValue('intake_spam_block_auto_threshold', '');
        $this->assertNull(AgentConfig::intakeSpamBlockAutoThreshold());
    }

    public function test_spam_block_threshold_honors_value_above_floor(): void
    {
        Setting::setValue('intake_spam_block_auto_threshold', '0.95');
        $this->assertSame(0.95, AgentConfig::intakeSpamBlockAutoThreshold());
    }

    public function test_spam_block_threshold_clamps_below_floor_to_090(): void
    {
        Setting::setValue('intake_spam_block_auto_threshold', '0.5');
        $this->assertSame(0.90, AgentConfig::intakeSpamBlockAutoThreshold());
    }
}
