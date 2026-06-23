<?php

namespace Tests\Feature\Prospect;

use App\Enums\CallStatus;
use App\Models\Client;
use App\Models\PhoneCall;
use App\Services\PhoneCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnknownCallerFacetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a PhoneCall for testing.
     * `client_id` and `followed_up_at` are not mass-assignable, so we set them directly.
     */
    private function makeCall(array $attrs = []): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => '+15555550199',
            'status' => $attrs['status'] ?? CallStatus::Completed,
        ]);

        $call->client_id = $attrs['client_id'] ?? null;
        $call->followed_up_at = $attrs['followed_up_at'] ?? null;
        $call->save();

        return $call;
    }

    // ── scope tests ──────────────────────────────────────────────────────────

    public function test_unknown_caller_facet_includes_answered_calls_not_just_voicemails(): void
    {
        $answered = $this->makeCall([
            'client_id' => null,
            'followed_up_at' => null,
            'status' => CallStatus::Completed,
        ]);
        $resolved = $this->makeCall([
            'client_id' => Client::factory()->create()->id,
            'followed_up_at' => null,
            'status' => CallStatus::Completed,
        ]);

        $ids = PhoneCall::unknownCaller()->pluck('id');

        $this->assertTrue($ids->contains($answered->id));   // answered unknown caller IS in facet
        $this->assertFalse($ids->contains($resolved->id));  // linked call is NOT in facet
    }

    public function test_followed_up_unknown_caller_is_excluded(): void
    {
        $done = $this->makeCall([
            'client_id' => null,
            'followed_up_at' => now(),
            'status' => CallStatus::Missed,
        ]);

        $ids = PhoneCall::unknownCaller()->pluck('id');

        $this->assertFalse($ids->contains($done->id));
    }

    public function test_voicemail_unknown_caller_is_included(): void
    {
        $voicemail = $this->makeCall([
            'client_id' => null,
            'followed_up_at' => null,
            'status' => CallStatus::Voicemail,
        ]);

        $ids = PhoneCall::unknownCaller()->pluck('id');

        $this->assertTrue($ids->contains($voicemail->id));
    }

    public function test_scope_is_independent_of_unfollowed_up_scope(): void
    {
        // An answered (Completed) unknown caller is HIDDEN by scopeUnfollowedUp
        // because that scope requires status IN (Missed, Voicemail).
        // scopeUnknownCaller must surface it anyway.
        $answered = $this->makeCall([
            'client_id' => null,
            'followed_up_at' => null,
            'status' => CallStatus::Completed,
        ]);

        // Confirm scopeUnfollowedUp does NOT include the answered call
        $unfollowedIds = PhoneCall::unfollowedUp()->pluck('id');
        $this->assertFalse($unfollowedIds->contains($answered->id));

        // Confirm scopeUnknownCaller DOES include it
        $unknownIds = PhoneCall::unknownCaller()->pluck('id');
        $this->assertTrue($unknownIds->contains($answered->id));
    }

    // ── service / filter-branch tests ────────────────────────────────────────

    public function test_get_recent_calls_unknown_caller_filter_returns_only_unknown_callers(): void
    {
        $service = app(PhoneCallService::class);

        $unknown = $this->makeCall([
            'client_id' => null,
            'followed_up_at' => null,
            'status' => CallStatus::Completed,
        ]);
        $known = $this->makeCall([
            'client_id' => Client::factory()->create()->id,
            'followed_up_at' => null,
            'status' => CallStatus::Completed,
        ]);

        $results = $service->getRecentCalls(50, ['status' => 'unknown-caller']);

        $ids = $results->pluck('id');
        $this->assertTrue($ids->contains($unknown->id));
        $this->assertFalse($ids->contains($known->id));
    }
}
