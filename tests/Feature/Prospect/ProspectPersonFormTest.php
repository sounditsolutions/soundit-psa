<?php

namespace Tests\Feature\Prospect;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Prospect clients have people too (that's the point of prospect intake) —
 * the person forms must offer them. Regression tests for psa-57wv: the
 * create/edit person forms used Client::operational(), which excludes
 * prospects, so the client dropdown rendered blank and the form could
 * never submit for a prospect.
 */
class ProspectPersonFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function staff(): User
    {
        return User::factory()->create();
    }

    private function prospect(string $name = 'Prospect Co'): Client
    {
        return Client::factory()->prospect()->create(['name' => $name]);
    }

    private function person(Client $client): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Pat',
            'last_name' => 'Prospect',
            'is_active' => true,
        ]);
    }

    public function test_person_create_form_offers_prospect_clients(): void
    {
        $prospect = $this->prospect();

        $response = $this->actingAs($this->staff())->get(route('people.create'));

        $response->assertOk();
        $this->assertTrue(
            $response->viewData('clients')->contains('id', $prospect->id),
            'Prospect client missing from the person create form client list.'
        );
    }

    public function test_person_edit_form_offers_the_persons_own_prospect_client(): void
    {
        $prospect = $this->prospect();
        $person = $this->person($prospect);

        $response = $this->actingAs($this->staff())->get(route('people.edit', $person));

        $response->assertOk();
        $this->assertTrue(
            $response->viewData('clients')->contains('id', $prospect->id),
            'Prospect client missing from the person edit form client list.'
        );
    }

    public function test_person_edit_form_includes_the_persons_current_client_even_if_inactive(): void
    {
        $inactive = Client::factory()->create(['name' => 'Archived Co', 'is_active' => false]);
        $person = $this->person($inactive);

        $response = $this->actingAs($this->staff())->get(route('people.edit', $person));

        $response->assertOk();
        $this->assertTrue(
            $response->viewData('clients')->contains('id', $inactive->id),
            "The person's own (inactive) client must stay selectable on the edit form."
        );
    }

    public function test_people_index_filter_offers_prospect_clients(): void
    {
        $prospect = $this->prospect();

        $response = $this->actingAs($this->staff())->get(route('people.index'));

        $response->assertOk();
        $this->assertTrue(
            $response->viewData('clients')->contains('id', $prospect->id),
            'Prospect client missing from the people index filter.'
        );
    }

    public function test_client_people_tab_offers_prospect_clients_in_the_filter(): void
    {
        $prospect = $this->prospect();

        $response = $this->actingAs($this->staff())->get(route('clients.people', $prospect));

        $response->assertOk();
        $this->assertTrue(
            $response->viewData('peopleClients')->contains('id', $prospect->id),
            'Prospect client missing from the client people-tab filter.'
        );
    }

    public function test_person_can_be_stored_at_a_prospect_client(): void
    {
        $prospect = $this->prospect();

        $response = $this->actingAs($this->staff())->post(route('people.store'), [
            'client_id' => $prospect->id,
            'first_name' => 'New',
            'last_name' => 'Contact',
            'email' => 'new.contact@prospect.test',
            'person_type' => PersonType::User->value,
        ]);

        $person = Person::where('email', 'new.contact@prospect.test')->first();
        $this->assertNotNull($person, 'Person was not created at the prospect client.');
        $response->assertRedirect(route('people.show', $person));
        $this->assertSame($prospect->id, $person->client_id);
    }
}
