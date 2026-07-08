<?php

namespace Tests\Feature\Prospect;

use App\Enums\CallStatus;
use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DismissTest extends TestCase
{
    use RefreshDatabase;

    private function makeCall(array $attrs = []): PhoneCall
    {
        $call = new PhoneCall([
            'call_uuid' => uniqid('test_', true),
            'from_number' => '+15555550199',
            'status' => $attrs['status'] ?? CallStatus::Completed,
        ]);

        $call->client_id = $attrs['client_id'] ?? null;
        $call->ticket_id = $attrs['ticket_id'] ?? null;
        $call->followed_up_at = $attrs['followed_up_at'] ?? null;
        $call->save();

        return $call;
    }

    /**
     * Dismissing stamps followed_up_at — which removes the call from the
     * unknown-caller facet (client_id IS NULL AND followed_up_at IS NULL).
     */
    public function test_dismiss_sets_followed_up_at(): void
    {
        $user = User::factory()->create();
        $call = $this->makeCall(['client_id' => null, 'followed_up_at' => null]);

        $this->assertNull($call->followed_up_at);

        $this->actingAs($user)
            ->post(route('prospects.dismiss', $call))
            ->assertRedirect();

        $call->refresh();
        $this->assertNotNull($call->followed_up_at);
    }

    /**
     * Dismiss creates NO Client, Person, or Ticket records.
     */
    public function test_dismiss_creates_no_client_person_or_ticket(): void
    {
        $user = User::factory()->create();
        $call = $this->makeCall(['client_id' => null]);

        $clientsBefore = Client::count();
        $peopleBefore = Person::count();
        $ticketsBefore = Ticket::count();

        $this->actingAs($user)
            ->post(route('prospects.dismiss', $call))
            ->assertRedirect();

        $this->assertSame($clientsBefore, Client::count(), 'No Client should be created');
        $this->assertSame($peopleBefore, Person::count(), 'No Person should be created');
        $this->assertSame($ticketsBefore, Ticket::count(), 'No Ticket should be created');
    }

    /**
     * The call still exists in the full Call Log (not soft-deleted).
     */
    public function test_dismiss_does_not_delete_the_call(): void
    {
        $user = User::factory()->create();
        $call = $this->makeCall(['client_id' => null]);

        $this->actingAs($user)
            ->post(route('prospects.dismiss', $call))
            ->assertRedirect();

        $this->assertDatabaseHas('phone_calls', ['id' => $call->id]);
    }

    /**
     * After dismiss the call is absent from the unknown-caller facet.
     */
    public function test_dismissed_call_is_absent_from_unknown_caller_facet(): void
    {
        $user = User::factory()->create();
        $call = $this->makeCall(['client_id' => null, 'followed_up_at' => null]);

        // Before: call is in the facet
        $this->assertTrue(PhoneCall::unknownCaller()->pluck('id')->contains($call->id));

        $this->actingAs($user)
            ->post(route('prospects.dismiss', $call))
            ->assertRedirect();

        // After: call is absent from the facet
        $this->assertFalse(PhoneCall::unknownCaller()->pluck('id')->contains($call->id));
    }

    public function test_dismiss_handles_ticket_linked_unknown_caller(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $call = $this->makeCall([
            'client_id' => null,
            'ticket_id' => $ticket->id,
            'followed_up_at' => null,
        ]);

        $this->assertTrue(PhoneCall::unknownCaller()->pluck('id')->contains($call->id));

        $this->actingAs($user)
            ->post(route('prospects.dismiss', $call))
            ->assertRedirect()
            ->assertSessionHas('success', 'Call dismissed — removed from Unknown callers.');

        $this->assertNotNull($call->fresh()->followed_up_at);
        $this->assertFalse(PhoneCall::unknownCaller()->pluck('id')->contains($call->id));
    }

    /**
     * The dismiss button is visible on the call detail page for unknown callers.
     */
    public function test_dismiss_button_appears_on_call_show_for_unknown_caller(): void
    {
        $user = User::factory()->create();
        $call = $this->makeCall(['client_id' => null, 'followed_up_at' => null]);

        $response = $this->actingAs($user)->get(route('calls.show', $call));

        $response->assertOk();
        $response->assertSee(route('prospects.dismiss', $call), false);
    }
}
