<?php

namespace Tests\Feature\Agent\Intake;

use App\Enums\CallStatus;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Email;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmailService;
use App\Services\PhoneCallService;
use App\Services\Triage\AssetMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * psa-vggw — asset-matching on intake-created tickets.
 *
 * When the intake front door creates a ticket from a call or an email, it should
 * LINK the relevant device at creation, so the ticket carries the right asset from
 * the start (real context for triage and the agent) instead of waiting on the
 * asynchronous triage Stage-2c run — which may be delayed, or disabled entirely.
 *
 * The insertion reuses the existing triage matcher via AssetMatcher::matchAtIntake,
 * which is held-first (links only a confident match, never overrides an existing
 * link), fail-soft, and honours the same `asset_assignment` stage toggle triage uses.
 *
 * Bus::fake() throughout neutralises the async triage job dispatched on ticket
 * creation, so a linked asset proves the SYNCHRONOUS at-creation matching, not triage.
 */
class IntakeAssetMatchingTest extends TestCase
{
    use RefreshDatabase;

    /** A workstation whose last-logged-on user matches the person (Strategy 1 fallback). */
    private function workstationFor(Client $client, string $lastUser): Asset
    {
        return Asset::factory()->create([
            'client_id' => $client->id,
            'asset_type' => 'Workstation',
            'last_user' => $lastUser,
        ]);
    }

    private function person(Client $client, string $first, string $last): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'first_name' => $first,
            'last_name' => $last,
            'is_active' => true,
        ]);
    }

    /** A resolved inbound call — the post-resolution state the pipeline hands to createTicketFromCall. */
    private function resolvedCall(Client $client, Person $person): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => '+15550100055',
            'status' => CallStatus::Completed,
            'call_summary' => 'Caller needs help with their laptop.',
        ]);
        $call->client_id = $client->id;
        $call->person_id = $person->id;
        $call->save();

        return $call;
    }

    // ── Call intake ───────────────────────────────────────────────────────────

    public function test_call_intake_links_the_callers_workstation_at_creation(): void
    {
        Bus::fake(); // async triage on create is captured, not run
        User::factory()->create(); // system user so the linked note authors

        $client = Client::factory()->create();
        $person = $this->person($client, 'Katie', 'Bloom');
        $asset = $this->workstationFor($client, 'Katie Bloom');

        $ticket = app(PhoneCallService::class)->createTicketFromCall($this->resolvedCall($client, $person));

        $this->assertTrue(
            $ticket->fresh()->assets()->where('assets.id', $asset->id)->exists(),
            "The resolved caller's workstation must be linked to the ticket at creation"
        );
    }

    // ── Email intake ──────────────────────────────────────────────────────────

    public function test_email_intake_links_the_senders_workstation_at_creation(): void
    {
        Bus::fake();
        Setting::setValue('email_auto_ticket', '1');
        User::factory()->create();

        $client = Client::factory()->create();
        $person = $this->person($client, 'Dana', 'Reyes');
        $asset = $this->workstationFor($client, 'Dana Reyes');

        $email = Email::create([
            'direction' => 'inbound',
            'from_address' => 'dana@clientdomain.com',
            'from_name' => 'Dana Reyes',
            'subject' => "Laptop won't boot",
            'body_text' => 'It shows a black screen.',
            'received_at' => now(),
            'client_id' => $client->id,
            'person_id' => $person->id,
        ]);

        $ticket = app(EmailService::class)->autoCreateTicketFromEmail($email);

        $this->assertTrue(
            $ticket->fresh()->assets()->where('assets.id', $asset->id)->exists(),
            "The email sender's workstation must be linked to the ticket at creation"
        );
    }

    // ── Held-first ────────────────────────────────────────────────────────────

    public function test_intake_match_is_held_first_and_preserves_an_existing_link(): void
    {
        $client = Client::factory()->create();
        $person = $this->person($client, 'Katie', 'Bloom');

        // An asset already linked upstream (e.g. a vendor-specific hostname match).
        $preLinked = Asset::factory()->create(['client_id' => $client->id, 'asset_type' => 'Workstation']);
        // A second asset that WOULD match by last_user if the matcher were allowed to run.
        $this->workstationFor($client, 'Katie Bloom');

        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $ticket->contact_id = $person->id;
        $ticket->save();
        $ticket->assets()->syncWithoutDetaching([$preLinked->id => ['is_primary' => true]]);

        $result = AssetMatcher::matchAtIntake($ticket);

        $this->assertNull($result, 'Held-first: nothing is asserted onto a ticket that already carries an asset');
        $this->assertSame(
            [$preLinked->id],
            $ticket->fresh()->assets()->pluck('assets.id')->all(),
            'The pre-existing link is preserved and not supplemented'
        );
    }

    // ── Stage toggle ──────────────────────────────────────────────────────────

    public function test_intake_match_respects_the_asset_assignment_stage_toggle(): void
    {
        // Operator explicitly disabled asset auto-assignment — intake must honour it too.
        Setting::setValue('triage_stage_asset_assignment', '0');

        $client = Client::factory()->create();
        $person = $this->person($client, 'Katie', 'Bloom');
        $this->workstationFor($client, 'Katie Bloom');

        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $ticket->contact_id = $person->id;
        $ticket->save();

        $result = AssetMatcher::matchAtIntake($ticket);

        $this->assertNull($result, 'Gate: with the asset_assignment stage disabled, no match runs');
        $this->assertSame(0, $ticket->fresh()->assets()->count(), 'No asset is linked when the stage toggle is off');
    }
}
