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
 * On people.index the Client's own address is not rendered as a labelled field
 * in the page body: it reaches that response only inside the Client badge's
 * data-bs-content popover attribute, which test three pins by stripping those
 * attributes. (Once a popover is OPENED it does show a labelled "Email:" line,
 * and the Name cell's person-badge popover shows another, so the claim is about
 * the resting page, not about every visual state.) With no labelled Client email
 * in the body beside the list, the plain "Email" column is unambiguous there and
 * stays. Both directions are asserted, so a change that qualified every list
 * would fail here.
 *
 * WHICH TEST PINS WHICH FILE. Within clients/show.blade.php the shared partial
 * is reached only by the People tab (:599 gates the include on
 * $activeTab === 'people'), while the Overview tab-pane renders its own
 * hard-coded People card on every Client-page response. So
 * test_client_page_people_table_names_the_email_as_the_persons pins the Overview
 * card only. The partial is pinned from BOTH sides: the People-tab test covers
 * its 'Person email' branch and test_global_people_index_keeps_the_plain_email_label
 * covers its 'Email' branch, because people/index.blade.php:15 includes the same
 * partial with an empty prefilter.
 *
 * KILL CHECK, measured rather than asserted: restore people/_list.blade.php to
 * 38ff0c83 (its pre-qualification revision) and run this file. Exactly
 * test_client_people_tab_list_names_the_email_as_the_persons fails. The
 * pane-scoped count below and the assertDontSee pair each detect that revert on
 * their own -- deleting either one still leaves the test red -- so neither is
 * redundant cover for the other: the count requires the partial to emit the
 * qualified header, and the DontSee pair forbids the unqualified one anywhere on
 * the response.
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

    /**
     * The People tab-pane slice of a Client-page response: everything from the
     * pane's own div to the start of the next pane. The shared partial renders
     * here and nowhere else on this response, so a substring check against this
     * slice cannot be satisfied by the Overview card's hard-coded table.
     */
    private function peoplePane(string $html): string
    {
        $start = strpos($html, '<div class="tab-pane fade show active" id="people" role="tabpanel">');
        $this->assertNotFalse($start, 'People tab-pane not found on the response.');
        $end = strpos($html, 'id="assets" role="tabpanel"', $start);
        $this->assertNotFalse($end, 'Assets tab-pane (the slice terminator) not found.');

        return substr($html, $start, $end - $start);
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

        // The Overview pane is in the DOM on this response too (hidden by CSS,
        // not omitted) and carries its own hard-coded People card, so a
        // whole-response assertion on the qualified label can be satisfied by
        // that card alone. Scope to the People tab-pane, which is the only place
        // the shared partial renders, so this assertion is about that file and
        // not about how many other surfaces happen to show a People table.
        $pane = $this->peoplePane($response->getContent());
        $this->assertStringContainsString('<th>Person email</th>', $pane);
        $this->assertStringContainsString('<span class="data-label">Person email</span>', $pane);
        $this->assertStringNotContainsString('<th>Email</th>', $pane);

        // And nowhere on the response is the People email left unqualified.
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

        // Pin the premise for keeping the plain label here, rather than asserting
        // it in prose: the Client's address IS on this response, and every
        // occurrence of it is inside a data-bs-content popover attribute. Strip
        // those attributes and it is gone -- so nothing in the page body labels a
        // Client email beside this list, and the plain column is unambiguous.
        $html = $response->getContent();
        $this->assertStringContainsString('billing@vandelay.test', $html);
        $stripped = preg_replace('/data-bs-content="[^"]*"/', '', $html);
        $this->assertStringNotContainsString('billing@vandelay.test', $stripped);

        $response->assertSee('<th>Email</th>', false);
        $response->assertDontSee('<th>Person email</th>', false);
        $response->assertSee('<span class="data-label">Email</span>', false);
        $response->assertDontSee('<span class="data-label">Person email</span>', false);
    }
}
