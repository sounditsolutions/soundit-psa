<?php

namespace Tests\Feature\Prospect;

use App\Enums\CallStatus;
use App\Enums\ClientStage;
use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProspectCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeCall(array $attrs = []): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => $attrs['from_number'] ?? '+15550102030',
            'status' => $attrs['status'] ?? CallStatus::Completed,
        ]);
        $call->client_id = $attrs['client_id'] ?? null;
        $call->save();

        return $call;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /**
     * The unresolved-call page must show the search control (name="client_search")
     * and the "+ New client" form that posts to prospects.store.
     */
    public function test_unresolved_call_page_offers_search_first_then_new_client_fallback(): void
    {
        $user = User::factory()->create();
        $call = $this->makeCall(['client_id' => null, 'from_number' => '+15550102030']);

        $resp = $this->actingAs($user)->get(route('calls.show', $call))->assertOk();

        $resp->assertSee('name="client_search"', false);          // search control is present
        $resp->assertSee(route('prospects.store'), false);         // "+ New client" posts to provision
    }

    /**
     * Posting with confirm_new=1 provisions client+person+ticket and the call
     * gets linked to the new prospect client.
     */
    public function test_creating_a_prospect_from_a_call_provisions_client_person_ticket(): void
    {
        $user = User::factory()->create();
        $call = $this->makeCall(['client_id' => null, 'from_number' => '+15550102030']);

        $this->actingAs($user)->post(route('prospects.store'), [
            'phone_call_id' => $call->id,
            'name' => 'Cascade Dental',
            'confirm_new' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('clients', [
            'name' => 'Cascade Dental',
            'stage' => 'prospect',
        ]);

        // Call gets linked to the new client
        $call->refresh();
        $this->assertNotNull($call->client_id);
        $this->assertNotNull($call->ticket_id);
    }

    /**
     * Confirm-dedup: posting WITHOUT confirm_new when matchByNumber finds an
     * existing client must NOT create a new client — it must redirect back with
     * the "attach to existing?" warning instead.
     */
    public function test_confirm_dedup_blocks_creation_when_number_already_belongs_to_a_client(): void
    {
        $user = User::factory()->create();

        // Create an existing client whose person owns the caller number
        $existing = Client::factory()->create(['name' => 'Existing Corp', 'stage' => ClientStage::Active->value]);
        Person::create([
            'client_id' => $existing->id,
            'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'phone' => PhoneNumber::normalize('+15550102030'),
            'is_active' => true,
            'portal_enabled' => false,
        ]);

        $call = $this->makeCall(['client_id' => null, 'from_number' => '+15550102030']);

        // Post WITHOUT confirm_new
        $response = $this->actingAs($user)->post(route('prospects.store'), [
            'phone_call_id' => $call->id,
            'name' => 'New Client Name',
            // confirm_new intentionally omitted
        ]);

        // Must redirect back — not to a new ticket
        $response->assertRedirect(route('calls.show', $call));

        // No new client must have been created
        $this->assertDatabaseMissing('clients', ['name' => 'New Client Name']);

        // The call must still be unresolved
        $call->refresh();
        $this->assertNull($call->client_id);

        // The flash session must surface the existing client name
        $response->assertSessionHas('dedup_client_name', 'Existing Corp');
    }

    /**
     * When confirm-dedup blocks creation, the controller must also flash the
     * matched person's id (dedup_person_id) — the person who actually owns the
     * caller number — so the call page can offer a one-click "Attach to
     * [client]" action. Without it the warning poses a question it gives no
     * way to answer affirmatively (psa-wjlv).
     */
    public function test_confirm_dedup_flashes_matched_person_id_for_one_click_attach(): void
    {
        $user = User::factory()->create();

        $existing = Client::factory()->create(['name' => 'Existing Corp', 'stage' => ClientStage::Active->value]);
        $person = Person::create([
            'client_id' => $existing->id,
            'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'phone' => PhoneNumber::normalize('+15550102030'),
            'is_active' => true,
            'portal_enabled' => false,
        ]);

        $call = $this->makeCall(['client_id' => null, 'from_number' => '+15550102030']);

        $response = $this->actingAs($user)->post(route('prospects.store'), [
            'phone_call_id' => $call->id,
            'name' => 'New Client Name',
        ]);

        $response->assertRedirect(route('calls.show', $call));
        $response->assertSessionHas('dedup_client_id', $existing->id);
        $response->assertSessionHas('dedup_person_id', $person->id);
    }

    /**
     * The call page must render an actionable "Attach to [client]" control
     * (posting to the existing, tested calls.update-person path with the
     * flashed person_id) alongside "Create new client anyway", so the dedup
     * prompt's suggested remediation is answerable in one click (psa-wjlv).
     */
    public function test_dedup_warning_offers_one_click_attach_to_existing_client(): void
    {
        $user = User::factory()->create();

        $existing = Client::factory()->create(['name' => 'Existing Corp', 'stage' => ClientStage::Active->value]);
        $person = Person::create([
            'client_id' => $existing->id,
            'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'phone' => PhoneNumber::normalize('+15550102030'),
            'is_active' => true,
            'portal_enabled' => false,
        ]);

        $call = $this->makeCall(['client_id' => null, 'from_number' => '+15550102030']);

        $response = $this->actingAs($user)
            ->withSession([
                'error' => 'This number is already on Existing Corp — attach to that client instead?',
                'dedup_call_id' => $call->id,
                'dedup_client_id' => $existing->id,
                'dedup_client_name' => 'Existing Corp',
                'dedup_person_id' => $person->id,
            ])
            ->get(route('calls.show', $call));

        $response->assertOk();
        // The affordance the prompt promises — a one-click attach control.
        $response->assertSee('Attach to Existing Corp');
        // It must carry the matched person's id so the click resolves the call
        // to the right caller (the manual-caller form is not sufficient).
        $response->assertSee('name="person_id" value="'.$person->id.'"', false);
    }

    /**
     * The dedup flash is session-scoped, so it must be keyed to the call it was
     * raised on — otherwise a second tab rendering a different call consumes it
     * and offers a one-click attach that would bind the wrong call to the
     * matched client.
     */
    public function test_dedup_attach_is_not_offered_on_a_different_call(): void
    {
        $user = User::factory()->create();

        $existing = Client::factory()->create(['name' => 'Existing Corp', 'stage' => ClientStage::Active->value]);
        $person = Person::create([
            'client_id' => $existing->id,
            'person_type' => \App\Enums\PersonType::User,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'phone' => PhoneNumber::normalize('+15550102030'),
            'is_active' => true,
            'portal_enabled' => false,
        ]);

        $callA = $this->makeCall(['client_id' => null, 'from_number' => '+15550102030']);
        $callB = $this->makeCall(['client_id' => null, 'from_number' => '+15550109999']);

        $response = $this->actingAs($user)
            ->withSession([
                'error' => 'This number is already on Existing Corp — attach to that client instead?',
                'dedup_call_id' => $callA->id,
                'dedup_client_id' => $existing->id,
                'dedup_client_name' => 'Existing Corp',
                'dedup_person_id' => $person->id,
            ])
            ->get(route('calls.show', $callB));

        $response->assertOk();
        $response->assertDontSee('Attach to Existing Corp');
        $response->assertDontSee('name="person_id" value="'.$person->id.'"', false);
    }

    /**
     * When the caller number is owned by more than one contact the dedup warning
     * must NOT offer a one-click attach: picking an arbitrary row would commit
     * the call (and person_confirmed) to a contact — and a client — nobody chose.
     */
    public function test_confirm_dedup_withholds_one_click_attach_when_the_number_is_ambiguous(): void
    {
        $user = User::factory()->create();

        $existing = Client::factory()->create(['name' => 'Existing Corp', 'stage' => ClientStage::Active->value]);
        $rival = Client::factory()->create(['name' => 'Rival Ltd', 'stage' => ClientStage::Active->value]);

        foreach ([[$existing, 'Jane', 'Smith'], [$rival, 'Bob', 'Jones']] as [$client, $first, $last]) {
            Person::create([
                'client_id' => $client->id,
                'person_type' => \App\Enums\PersonType::User,
                'first_name' => $first,
                'last_name' => $last,
                'phone' => PhoneNumber::normalize('+15550102030'),
                'is_active' => true,
                'portal_enabled' => false,
            ]);
        }

        $call = $this->makeCall(['client_id' => null, 'from_number' => '+15550102030']);

        $response = $this->actingAs($user)->post(route('prospects.store'), [
            'phone_call_id' => $call->id,
            'name' => 'New Client Name',
        ]);

        $response->assertRedirect(route('calls.show', $call));
        $response->assertSessionHas('dedup_call_id', $call->id);
        $response->assertSessionMissing('dedup_person_id');
        $this->assertDatabaseMissing('clients', ['name' => 'New Client Name']);
    }
}
