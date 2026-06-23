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
}
