<?php

namespace Tests\Feature\Clients;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * umsiqi8e: on the Client page the sidebar Details card is a sibling of the tab
 * column, so its own "Email" row (the CLIENT's address) renders on the same
 * response as the People list. Two unqualified "Email" labels on one screen is
 * what made a Person's email read as the Client's, which is the misreading the
 * README paragraph documents.
 *
 * The People-side label is therefore qualified as "Person email" on the Client
 * page. The Client's own field label is deliberately unchanged: it is not the
 * ambiguous half, and renaming it would cost retraining for no gain.
 *
 * On people.index the Client column already names each row's owner, and the
 * Client's own address appears only inside that badge's hover popover, never as
 * a labelled field beside the list, so the plain "Email" label stays there. Both
 * directions are asserted, so a change that qualified every list would fail here.
 *
 * WHICH TEST PINS WHICH FILE. clients.show renders the Overview tab's own
 * hard-coded People card; the shared partial is included only by the People tab
 * (clients.show gates it on $activeTab === 'people'). So the first test below
 * cannot see people/_list.blade.php at all, and only
 * test_client_people_tab_list_names_the_email_as_the_persons pins it. Its kill
 * check is a revert of people/_list.blade.php ALONE: without the assertDontSee
 * pair in that test, the Overview card -- which renders on the same response --
 * satisfies every positive assertion and the reverted partial goes unnoticed.
 */
class ClientPersonEmailLabelTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        return Client::factory()->create([
            'is_active' => true,
            'email' => 'billing@vandelay.test',
        ]);
    }

    private function person(Client $client): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Art',
            'last_name' => 'Vandelay',
            'email' => 'art@vandelay.test',
            'phone' => '+15551110000',
            'phone_display' => '(555) 111-0000',
            'is_active' => true,
        ]);
    }

    public function test_client_page_people_table_names_the_email_as_the_persons(): void
    {
        $user = User::factory()->create();
        $client = $this->client();
        $this->person($client);

        $response = $this->actingAs($user)->get(route('clients.show', $client));

        $response->assertOk();

        // Precondition: both addresses really are on this one response, which is
        // what makes the label ambiguous in the first place. Without this the
        // assertions below could pass on a page showing neither.
        $response->assertSee('art@vandelay.test', false);
        $response->assertSee('billing@vandelay.test', false);

        // The People summary table qualifies its column.
        $response->assertSee('<th>Person email</th>', false);
        $response->assertDontSee('<th>Email</th>', false);

        // The mobile stacked layout carries the same qualification.
        $response->assertSee('<span class="data-label">Person email</span>', false);
        $response->assertDontSee('<span class="data-label">Email</span>', false);

        // The Client's own Details row is untouched: it is still plain "Email",
        // so this change cannot be mistaken for a rename of the Client field.
        $response->assertSee('<th class="text-muted">Email</th>', false);
    }

    public function test_client_people_tab_list_names_the_email_as_the_persons(): void
    {
        $user = User::factory()->create();
        $client = $this->client();
        $this->person($client);

        $response = $this->actingAs($user)->get(route('clients.people', $client));

        $response->assertOk();

        // The Overview pane is in the DOM on this response too (it is hidden by
        // CSS, not omitted), and it carries its own hard-coded People card. So
        // the qualified label legitimately appears twice here: once from that
        // card and once from the shared partial. Asserting the count is what
        // distinguishes "both rendered it" from "only the hard-coded one did".
        $this->assertSame(2, substr_count($response->getContent(), '<th>Person email</th>'));
        $this->assertSame(2, substr_count($response->getContent(), '<span class="data-label">Person email</span>'));

        // The kill check for this test: reverting people/_list.blade.php alone
        // leaves the Overview card's copy in place, so the assertions above still
        // pass on one of the two, but the partial re-emits the unqualified label
        // and these fail. Without this pair the test pins nothing in that file.
        $response->assertDontSee('<th>Email</th>', false);
        $response->assertDontSee('<span class="data-label">Email</span>', false);

        // Same screen, same sidebar: the Client's row is still plain.
        $response->assertSee('<th class="text-muted">Email</th>', false);
    }

    public function test_global_people_index_keeps_the_plain_email_label(): void
    {
        $user = User::factory()->create();
        $client = $this->client();
        $this->person($client);

        $response = $this->actingAs($user)->get(route('people.index'));

        $response->assertOk();
        // Precondition: this really is the people list, and the Client column
        // that already names the owner is present.
        $response->assertSee('art@vandelay.test', false);
        $response->assertSee('<th>Client</th>', false);

        // The Client's address is on this response only inside the Client badge's
        // hover popover (a data-bs-content attribute), not as a labelled field, so
        // there is no competing visible "Email" label and this one is not qualified.
        $response->assertSee('<th>Email</th>', false);
        $response->assertDontSee('<th>Person email</th>', false);
        $response->assertSee('<span class="data-label">Email</span>', false);
        $response->assertDontSee('<span class="data-label">Person email</span>', false);
    }
}
