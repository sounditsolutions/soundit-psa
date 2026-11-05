<?php

namespace Tests\Feature\Portal;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Card FhGlCXPw: the staff portal toggle enforces "at most one portal-enabled
 * active contact per email" when ENABLING, with the same refusal invite() gives.
 * Disabling is always allowed.
 */
class PortalToggleDuplicateEmailTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'shared-inbox@example.test';

    private const REFUSAL = 'Another portal user already exists with this email address.';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        Setting::setValue('portal_enabled', '1');
    }

    private function contact(Client $client, bool $portalEnabled, string $lastName, bool $active = true): Person
    {
        return Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Synthetic',
            'last_name' => $lastName,
            'email' => self::EMAIL,
            'is_active' => $active,
            'portal_enabled' => $portalEnabled,
        ])->fresh();
    }

    private function toggle(Person $person)
    {
        return $this->actingAs(User::factory()->create())
            ->post(route('clients.portal.toggle', $person->client_id), ['person_id' => $person->id]);
    }

    public function test_enabling_a_second_portal_user_on_a_shared_email_is_refused(): void
    {
        $client = Client::factory()->create();
        $portalUser = $this->contact($client, true, 'PortalUser');
        $sibling = $this->contact($client, false, 'Sibling');

        $this->toggle($sibling)->assertRedirect()->assertSessionHas('error', self::REFUSAL);

        $this->assertFalse($sibling->fresh()->portal_enabled, 'the sibling stays disabled');
        $this->assertTrue($portalUser->fresh()->portal_enabled);
    }

    public function test_enabling_is_allowed_when_the_only_portal_enabled_sibling_is_inactive(): void
    {
        $client = Client::factory()->create();
        $this->contact($client, true, 'Inactive', active: false);
        $sibling = $this->contact($client, false, 'Sibling');

        $this->toggle($sibling)->assertRedirect()->assertSessionHas('success');

        $this->assertTrue($sibling->fresh()->portal_enabled);
    }

    public function test_disabling_a_portal_user_on_a_shared_email_is_always_allowed(): void
    {
        $client = Client::factory()->create();
        // Two portal-enabled active contacts (legacy data the check never saw):
        // switching either off must still work.
        $first = $this->contact($client, true, 'First');
        $second = $this->contact($client, true, 'Second');

        $this->toggle($second)->assertRedirect()->assertSessionHas('success');

        $this->assertFalse($second->fresh()->portal_enabled);
        $this->assertTrue($first->fresh()->portal_enabled);
    }

    public function test_enabling_a_contact_with_no_email_is_not_refused_as_a_duplicate(): void
    {
        $client = Client::factory()->create();
        $this->contact($client, true, 'NoEmailPortal')->forceFill(['email' => null])->save();
        $person = $this->contact($client, false, 'NoEmail');
        $person->forceFill(['email' => null])->save();

        $this->toggle($person)->assertRedirect()->assertSessionHas('success');

        $this->assertTrue($person->fresh()->portal_enabled);
    }

    /** The row is enabled concurrently after the controller read it: its only match is itself. */
    public function test_enabling_a_contact_whose_only_portal_enabled_match_is_itself_is_allowed(): void
    {
        $client = Client::factory()->create();
        $person = $this->contact($client, false, 'Self');
        $raced = false;
        Person::retrieved(function (Person $p) use ($person, &$raced): void {
            if (! $raced && $p->id === $person->id) {
                $raced = true;
                Person::whereKey($p->id)->update(['portal_enabled' => true]);
            }
        });

        $this->toggle($person)->assertRedirect()->assertSessionHas('success');

        $this->assertTrue($raced, 'the concurrent enable ran');
        $this->assertTrue($person->fresh()->portal_enabled);
    }

    /** Control: a contact with a unique email is enabled as before. */
    public function test_control_enabling_a_contact_with_no_shared_email_succeeds(): void
    {
        $client = Client::factory()->create();
        $this->contact($client, false, 'Sibling');
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Synthetic',
            'last_name' => 'Unique',
            'email' => 'unique@example.test',
            'is_active' => true,
            'portal_enabled' => false,
        ]);

        $this->toggle($person)->assertRedirect()->assertSessionHas('success');

        $this->assertTrue($person->fresh()->portal_enabled);
    }
}
