<?php

namespace Tests\Feature;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Guards the mobile-viewport overflow fix (psa-n24l): the person detail
 * key-value tables (Contact Info, Details, M365 Details) must carry the
 * Bootstrap `text-break` utility so long unbreakable values — email
 * addresses, M365 UPNs — wrap instead of forcing the table past a narrow
 * viewport and requiring horizontal panning.
 */
class PersonDetailResponsiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_person_detail_key_value_tables_wrap_long_values(): void
    {
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Vandelay Industries']);
        $person = Person::create([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'George',
            'last_name' => 'Costanza',
            'email' => 'george.costanza@vandelay.example.com',
            'is_active' => true,
            // Presence of cipp_user_id is what renders the M365 Details card.
            'cipp_user_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'cipp_upn' => 'george.costanza@vandelay.example.com',
        ]);

        $html = $this->actingAs($user)
            ->get(route('people.show', $person))
            ->assertOk()
            // The long values that overflow on mobile must render...
            ->assertSee('george.costanza@vandelay.example.com')
            ->getContent();

        // ...and every key-value detail table must carry the wrap utility.
        // There are three on the overview tab: Contact Info, Details, M365.
        $this->assertSame(
            3,
            substr_count($html, 'table table-borderless mb-0 text-break'),
            'All three person-detail key-value tables should carry text-break so long values wrap on mobile.'
        );
    }
}
