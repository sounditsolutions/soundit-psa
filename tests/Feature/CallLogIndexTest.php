<?php

namespace Tests\Feature;

use App\Enums\CallStatus;
use App\Models\PhoneCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallLogIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `followed_up_at` is not mass-assignable, so it is set directly.
     */
    private function makeCall(array $attrs = []): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => $attrs['from_number'] ?? '+15555550100',
            'status' => $attrs['status'] ?? CallStatus::Completed,
            'started_at' => $attrs['started_at'] ?? now(),
        ]);

        $call->followed_up_at = $attrs['followed_up_at'] ?? null;
        $call->save();

        return $call;
    }

    public function test_fresh_call_log_exposes_needs_follow_up_calls_from_earlier_days(): void
    {
        // A missed call from days ago that still needs follow-up — exactly what the
        // sidebar "needs follow-up" badge counts. The old silent "today" default
        // filter hid it, so opening Calls showed an empty table under a red badge.
        $followUp = $this->makeCall([
            'status' => CallStatus::Missed,
            'started_at' => now()->subDays(5),
            'followed_up_at' => null,
        ]);

        $response = $this->actingAs(User::factory()->create())->get('/calls');

        $response->assertOk();
        $this->assertTrue(
            $response->viewData('calls')->contains('id', $followUp->id),
            'A fresh Call Log must expose earlier calls that still need follow-up.'
        );
    }

    public function test_date_filter_still_narrows_and_clearing_reveals_all(): void
    {
        $oldCall = $this->makeCall([
            'status' => CallStatus::Missed,
            'started_at' => now()->subDays(5),
        ]);
        $user = User::factory()->create();

        // An explicit From date of today excludes the older call — filtering still works.
        $filtered = $this->actingAs($user)->get('/calls?date_from='.today()->toDateString());
        $filtered->assertOk();
        $this->assertFalse($filtered->viewData('calls')->contains('id', $oldCall->id));

        // "Clear" links back to /calls with no params — the older call reappears,
        // i.e. Clear genuinely removes the active date filter.
        $cleared = $this->actingAs($user)->get('/calls');
        $cleared->assertOk();
        $this->assertTrue($cleared->viewData('calls')->contains('id', $oldCall->id));
    }

    public function test_needs_follow_up_status_filter_returns_only_those_calls(): void
    {
        $followUp = $this->makeCall([
            'status' => CallStatus::Voicemail,
            'followed_up_at' => null,
        ]);
        $alreadyDone = $this->makeCall([
            'status' => CallStatus::Voicemail,
            'followed_up_at' => now(),
        ]);
        $completed = $this->makeCall([
            'status' => CallStatus::Completed,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get('/calls?status=needs-follow-up');

        $response->assertOk();
        $calls = $response->viewData('calls');
        $this->assertTrue($calls->contains('id', $followUp->id));
        $this->assertFalse($calls->contains('id', $alreadyDone->id));
        $this->assertFalse($calls->contains('id', $completed->id));

        // The Status dropdown now offers a matching option so the filter state is
        // consistent (previously it silently read "All").
        $response->assertSee('Needs follow-up');
    }
}
