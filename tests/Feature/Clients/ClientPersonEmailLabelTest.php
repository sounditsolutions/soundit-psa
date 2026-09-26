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
 * Where a list has no client_id prefilter -- today only people.index, but the
 * ternary covers any such caller -- the plain "Email" column stays, because no
 * labelled Client email field renders in that page body. The Client's address is
 * on the response (the badge popover carries it, and a search echoes it back into
 * the filter input), but not under a label, and it is a label next to a label
 * that makes a column header ambiguous.
 * test_global_people_index_keeps_the_plain_email_label pins that by asserting the
 * badge's labelled "Email:" form is absent from the body, on a plain request and
 * on a request whose search term IS the Client's address. Both directions are
 * asserted, so a change that qualified every list would fail here.
 *
 * WHICH TEST PINS WHICH FILE. clients/show.blade.php reaches the shared partial
 * only from its People tab-pane (the include is gated on $activeTab === 'people'),
 * while its Overview tab-pane carries a separate hard-coded People card.
 * test_client_page_people_table_names_the_email_as_the_persons therefore pins the
 * Overview card, not the partial. The partial is pinned from BOTH sides:
 * test_client_people_tab_list_names_the_email_as_the_persons covers its
 * 'Person email' branch and test_global_people_index_keeps_the_plain_email_label
 * covers its 'Email' branch, because people/index.blade.php includes the same
 * partial with an empty prefilter.
 *
 * WHY THE PANE SLICE, and what each assertion uniquely kills. Measured, with the
 * failing line read rather than the pass/fail tally:
 *
 *   1. Restore people/_list.blade.php to 38ff0c83 (the revision before #3816
 *      qualified the label; that commit is an ancestor of main, not only of this
 *      branch). Caught twice over: with the pane assertions in place it fails
 *      there, and with them deleted it still fails at the response-level
 *      assertDontSee. For THIS mutant either mechanism would do, which is exactly
 *      why mutant 1 alone cannot justify keeping both.
 *   2. Change the qualified branch to another string -- measured with
 *      'Contact email' -- or drop the email column from the partial. The
 *      assertDontSee pair still passes, because nothing UNqualified appears, and a
 *      whole-response assertSee would be satisfied by the Overview card's copy.
 *      With the pane assertions present the test fails; with them deleted the
 *      mutant SURVIVES, all three tests green. That survival is the evidence that
 *      the slice is not ceremony.
 *   3. Remove the Overview card's own qualified header. Two tests fail:
 *      test_client_page_people_table_names_the_email_as_the_persons, whose subject
 *      that card is, and the outside-the-pane assertion here. The second is
 *      deliberate: the slice is worth its complexity only while a second surface
 *      really does render the same header on the same response, so that premise is
 *      pinned rather than asserted in prose.
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
     * Split a Client-page response into [the People tab-pane, everything else].
     * The shared partial renders inside that pane and nowhere else on the
     * response, so a check against the first half cannot be satisfied by the
     * Overview card's hard-coded table, and a check against the second half is
     * about that card alone.
     *
     * Anchored on the pane's id/role attributes only -- not on its class list,
     * which carries the conditional 'show active' -- and terminated by the next
     * pane's id/role, so reordering or restyling the tabs does not silently
     * change what is measured. If the anchors ever stop matching, the assertion
     * below fails loudly rather than the slice quietly becoming the empty string.
     *
     * @return array{0: string, 1: string}
     */
    private function splitOnPeoplePane(string $html): array
    {
        $start = strpos($html, 'id="people" role="tabpanel"');
        $this->assertNotFalse($start, 'People tab-pane anchor not found on the response.');
        $end = strpos($html, 'id="assets" role="tabpanel"', $start);
        $this->assertNotFalse($end, 'Assets tab-pane anchor (the slice terminator) not found.');

        return [substr($html, $start, $end - $start), substr($html, 0, $start).substr($html, $end)];
    }

    /**
     * The ways this codebase renders an email under a label that is NOT the
     * people list's own column: the '<small>Email:</small>' line the badge
     * components build, in both its plain form and the entity-escaped form it
     * takes when it is assembled into a popover attribute.
     *
     * Deliberately excludes '<span class="data-label">Email</span>' -- on
     * people.index that span IS the partial's own Person email label, which this
     * test asserts is PRESENT. Including it here would make the premise assertion
     * contradict the thing it exists to justify.
     *
     * @return list<string>
     */
    private function foreignLabelledEmailForms(): array
    {
        return [
            '<small class="text-muted">Email:</small>',
            '&lt;small class=&quot;text-muted&quot;&gt;Email:&lt;/small&gt;',
        ];
    }

    /**
     * The response with popover payloads removed, i.e. what the page shows before
     * any interaction. data-bs-content is an attribute whose value this codebase
     * builds with e(), so it can contain no unescaped double quote and this
     * pattern cannot run past the attribute it matches.
     */
    private function bodyWithoutPopoverAttributes(string $html): string
    {
        return (string) preg_replace('/data-bs-content="[^"]*"/', '', $html);
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
        // that card alone. Scope the positive assertions to the People tab-pane,
        // the only place the shared partial renders on this response, so they
        // fail if the partial stops emitting the qualified label even while
        // another surface still shows it.
        [$pane, $outside] = $this->splitOnPeoplePane($response->getContent());
        $this->assertStringContainsString('<th>Person email</th>', $pane);
        $this->assertStringContainsString('<span class="data-label">Person email</span>', $pane);

        // The premise that makes the slice worth its complexity, pinned rather
        // than claimed: a second surface on this same response (the Overview
        // pane's hard-coded People card) renders the same qualified header, so a
        // whole-response assertion could not tell the two apart. If this fails,
        // the slice above has become ceremony and should go.
        $this->assertStringContainsString('<th>Person email</th>', $outside);

        // Nowhere on the response is a People email column left unqualified.
        // Deliberately response-wide, not pane-scoped: the pane slice would make
        // this strictly weaker for no gain, and the sidebar's Client "Email" row
        // is not of this form.
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
        // Precondition: this really is the people list, and the Client column is
        // present.
        $response->assertSee('art@vandelay.test', false);
        $response->assertSee('<th>Client</th>', false);

        // Pin the premise for keeping the plain label here, rather than asserting
        // it in prose. The subject is a LABEL, not the address: the Client's
        // address does reach this response (the badge popover carries it, and a
        // search echoes it into the filter input), but with the popover
        // attributes stripped, nothing in the page body labels it. Asserting on
        // the label is also why a search for the Client's own address does not
        // falsify this -- see the request below.
        $body = $this->bodyWithoutPopoverAttributes($response->getContent());
        $this->assertStringNotContainsString('billing@vandelay.test', $body);
        foreach ($this->foreignLabelledEmailForms() as $form) {
            $this->assertStringNotContainsString($form, $body);
        }

        // The harder case, and the one that shows the claim is about a LABEL and
        // not about the address: search for the Client's own address. The partial
        // echoes $search back into the filter input and the paginator carries it
        // in every link, so the address IS in the body here -- but still under no
        // label, which is why the plain column stays unambiguous.
        $searched = $this->actingAs($user)->get(route('people.index', ['search' => 'billing@vandelay.test']));
        $searched->assertOk();
        $searchedBody = $this->bodyWithoutPopoverAttributes($searched->getContent());
        $this->assertStringContainsString('billing@vandelay.test', $searchedBody);
        foreach ($this->foreignLabelledEmailForms() as $form) {
            $this->assertStringNotContainsString($form, $searchedBody);
        }

        $response->assertSee('<th>Email</th>', false);
        $response->assertDontSee('<th>Person email</th>', false);
        $response->assertSee('<span class="data-label">Email</span>', false);
        $response->assertDontSee('<span class="data-label">Person email</span>', false);
    }
}
