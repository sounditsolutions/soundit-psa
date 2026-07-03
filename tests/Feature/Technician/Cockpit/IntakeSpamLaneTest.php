<?php

namespace Tests\Feature\Technician\Cockpit;

use App\Enums\CallStatus;
use App\Enums\PhoneDirectoryListType;
use App\Enums\TechnicianRunState;
use App\Models\Client;
use App\Models\PhoneCall;
use App\Models\PhoneDirectoryEntry;
use App\Models\TechnicianRun;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Technician\Cockpit\CockpitQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 6b — the "suspected spam calls" cockpit sub-lane.
 *
 * Surfaces PhoneCalls with intake_spam_score set that are still un-actioned
 * (no followed_up_at, no ticket, no client). The one-tap action marks the
 * call followed-up and adds the caller to the Blocked directory. The lane
 * is byte-identical when quiet (not rendered when empty).
 *
 * Also covers the call-source label on the existing intake lane (📞 vs email).
 */
class IntakeSpamLaneTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** Create a suspected-spam PhoneCall record and persist it. */
    private function spamCall(array $attrs = []): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('spam_', true),
            'from_number' => $attrs['from_number'] ?? '+12223334444',
            'status' => CallStatus::Completed,
            'call_summary' => $attrs['call_summary'] ?? 'Suspected SEO spam caller.',
        ]);
        $call->intake_spam_score = $attrs['intake_spam_score'] ?? 0.85;
        $call->followed_up_at = $attrs['followed_up_at'] ?? null;
        $call->ticket_id = $attrs['ticket_id'] ?? null;
        $call->client_id = $attrs['client_id'] ?? null;
        $call->save();

        return $call;
    }

    /** Create an intake_route TechnicianRun with a given source in proposed_meta. */
    private function intakeRun(string $source = 'email'): TechnicianRun
    {
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->for($client)->create();

        return TechnicianRun::create([
            'ticket_id' => $ticket->id,
            'client_id' => $client->id,
            'action_type' => 'intake_route',
            'content_hash' => hash('sha256', 'intake-src-'.uniqid()),
            'state' => TechnicianRunState::AwaitingApproval,
            'proposed_content' => 'same issue (test)',
            'proposed_meta' => [
                'source' => $source,
                'suggested_ticket_id' => 999,
                'confidence' => 0.9,
            ],
            'tokens_used' => 0,
        ]);
    }

    // ── 1. Spam lane renders with from_number visible (real GET → catches Blade compile errors) ──

    public function test_spam_lane_renders_with_from_number_when_non_empty(): void
    {
        $this->spamCall(['from_number' => '+12223334444']);

        $this->actingAs($this->user)
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('+12223334444');
    }

    // ── 2. POST intake-spam-block stamps followed_up_at + creates a Blocked directory entry ──

    public function test_spam_block_marks_followed_up_and_blocks_number(): void
    {
        $call = $this->spamCall(['from_number' => '+12223334444']);

        $this->actingAs($this->user)
            ->post(route('cockpit.intake-spam-block', $call))
            ->assertRedirect(route('cockpit.index'));

        $call->refresh();
        $this->assertNotNull($call->followed_up_at, 'followed_up_at must be stamped');
        $this->assertSame($this->user->id, $call->followed_up_by, 'followed_up_by must be set to the acting user');

        $entry = PhoneDirectoryEntry::where('phone_number', '+12223334444')->first();
        $this->assertNotNull($entry, 'a phone directory entry must exist');
        $this->assertSame(PhoneDirectoryListType::Blocked, $entry->list_type);
        $this->assertSame($this->user->id, $entry->added_by_user_id);
    }

    // ── 3. Idempotency: two taps → second tap is a no-op and creates no duplicate ──

    public function test_spam_block_is_idempotent(): void
    {
        $call = $this->spamCall(['from_number' => '+12223334444']);

        // First tap: stamps followed_up_at and creates the blocked entry.
        $this->actingAs($this->user)->post(route('cockpit.intake-spam-block', $call));
        // Second tap: already handled, no re-stamp and no extra directory entry.
        $this->actingAs($this->user)
            ->post(route('cockpit.intake-spam-block', $call))
            ->assertSessionHas('error', 'That call was already handled.');

        $this->assertSame(
            1,
            PhoneDirectoryEntry::where('phone_number', '+12223334444')->count(),
            'repeat taps must not duplicate the block entry',
        );
    }

    public function test_spam_block_does_not_overwrite_existing_directory_entry(): void
    {
        $call = $this->spamCall(['from_number' => '+12223334444']);
        PhoneDirectoryEntry::create([
            'phone_number' => '+12223334444',
            'list_type' => PhoneDirectoryListType::Allowed,
            'reason' => 'Known vendor',
            'added_by_user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->post(route('cockpit.intake-spam-block', $call))
            ->assertRedirect(route('cockpit.index'));

        $entry = PhoneDirectoryEntry::where('phone_number', '+12223334444')->sole();
        $this->assertSame(PhoneDirectoryListType::Allowed, $entry->list_type);
        $this->assertSame('Known vendor', $entry->reason);
        $this->assertNotNull($call->fresh()->followed_up_at);
    }

    // ── 4. Actioned calls (followed_up_at / ticket_id / client_id) excluded from intakeSpamReview() ──

    public function test_followed_up_call_does_not_appear_in_spam_lane(): void
    {
        $this->spamCall(['followed_up_at' => now()]);

        $this->assertCount(0, app(CockpitQuery::class)->intakeSpamReview());
    }

    public function test_call_with_ticket_does_not_appear_in_spam_lane(): void
    {
        $ticket = Ticket::factory()->create();
        $this->spamCall(['ticket_id' => $ticket->id]);

        $this->assertCount(0, app(CockpitQuery::class)->intakeSpamReview());
    }

    public function test_call_with_client_does_not_appear_in_spam_lane(): void
    {
        $client = Client::factory()->create();
        $this->spamCall(['client_id' => $client->id]);

        $this->assertCount(0, app(CockpitQuery::class)->intakeSpamReview());
    }

    // ── 5. "Not spam — dismiss" stamps followed_up_at and removes call from lane ──

    public function test_not_spam_dismiss_stamps_followed_up_at_and_removes_from_lane(): void
    {
        $call = $this->spamCall(['from_number' => '+12223334444']);

        // prospects.dismiss stamps followed_up_at and redirects to calls.show
        $this->actingAs($this->user)
            ->post(route('prospects.dismiss', $call))
            ->assertRedirect();

        $call->refresh();
        $this->assertNotNull($call->followed_up_at, 'dismiss must stamp followed_up_at');
        $this->assertSame($this->user->id, $call->followed_up_by, 'dismiss must record the acting user');

        $lane = app(CockpitQuery::class)->intakeSpamReview();
        $this->assertCount(0, $lane, 'dismissed call must leave the spam lane');
    }

    // ── Call-source label: 📞 for call-sourced intake runs, "New ticket" for email ──

    public function test_call_source_intake_run_renders_call_lead(): void
    {
        $this->intakeRun(source: 'call');

        $this->actingAs($this->user)
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('📞 Call → ticket');
    }

    public function test_email_source_intake_run_renders_new_ticket_lead(): void
    {
        $this->intakeRun(source: 'email');

        $this->actingAs($this->user)
            ->get(route('cockpit.index'))
            ->assertOk()
            ->assertSee('New ticket');
    }
}
