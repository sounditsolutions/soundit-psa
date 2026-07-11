<?php

namespace Tests\Feature;

use App\Enums\PersonType;
use App\Models\Client;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for psa-4nmp: on a narrow (mobile) viewport the people list
 * used to render only a horizontally-scrolling desktop table, so the Phone and
 * Mobile columns were clipped off-screen. The partial now renders a desktop
 * table (md and up) alongside a stacked card layout (below md) that surfaces
 * the call-critical Phone/Mobile fields without horizontal panning.
 */
class PeopleListResponsiveTest extends TestCase
{
    use RefreshDatabase;

    private function person(Client $client, array $overrides = []): Person
    {
        return Person::create(array_merge([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Art',
            'last_name' => 'Vandelay',
            'email' => 'art@vandelay.test',
            'phone' => '+15551110000',
            'phone_display' => '(555) 111-0000',
            'mobile' => '+15552220000',
            'mobile_display' => '(555) 222-0000',
            'is_active' => true,
        ], $overrides));
    }

    public function test_people_index_renders_both_desktop_table_and_mobile_stacked_layout(): void
    {
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Vandelay Industries']);
        $this->person($client);

        $response = $this->actingAs($user)->get(route('people.index'));

        $response->assertOk();

        // Both responsive layouts are present: the desktop table is hidden below
        // md, and a stacked layout is shown only below md. (Neither marker exists
        // on this page except in the people list partial.)
        $response->assertSee('d-none d-md-block', false);
        $response->assertSee('d-md-none', false);

        // The mobile stacked layout labels and surfaces the call-critical fields.
        $response->assertSee('<span class="data-label">Phone</span>', false);
        $response->assertSee('<span class="data-label">Mobile</span>', false);
        $response->assertSeeText('(555) 111-0000');
        $response->assertSeeText('(555) 222-0000');
    }

    public function test_client_people_tab_surfaces_phone_and_mobile_in_a_mobile_layout(): void
    {
        // Mirrors the reported scenario: /clients/{client}/people on a phone.
        $user = User::factory()->create();
        $client = Client::create(['name' => 'Vandelay Industries']);
        $this->person($client);

        $response = $this->actingAs($user)->get(route('clients.people', $client));

        $response->assertOk();
        $response->assertSee('d-md-none', false);
        $response->assertSeeText('(555) 111-0000');
        $response->assertSeeText('(555) 222-0000');
    }
}
