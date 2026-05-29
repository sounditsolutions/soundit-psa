<?php

namespace Tests\Feature;

use App\Enums\PersonType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PersonMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function client(string $name = 'Acme'): Client
    {
        return Client::create(['name' => $name]);
    }

    private function person(Client $client, array $overrides = []): Person
    {
        return Person::create(array_merge([
            'client_id' => $client->id,
            'person_type' => PersonType::User,
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => true,
        ], $overrides));
    }

    public function test_staff_can_merge_a_duplicate_via_http(): void
    {
        $user = User::factory()->create();
        $c = $this->client();
        $survivor = $this->person($c, ['first_name' => 'Keep', 'email' => 'keep@acme.test']);
        $dup = $this->person($c, ['first_name' => 'Dupe', 'email' => 'dupe@acme.test']);
        $ticket = Ticket::create([
            'client_id' => $c->id,
            'contact_id' => $dup->id,
            'subject' => 'Help',
            'type' => TicketType::ServiceRequest,
            'status' => TicketStatus::New,
            'priority' => TicketPriority::P3,
            'opened_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->post(route('people.merge', $survivor), ['duplicate_id' => $dup->id]);

        $response->assertRedirect(route('people.show', $survivor));
        $response->assertSessionHas('success');
        $this->assertSame($survivor->id, $ticket->fresh()->contact_id);
        $this->assertSoftDeleted('people', ['id' => $dup->id]);
    }

    public function test_merge_requires_a_duplicate_id(): void
    {
        $user = User::factory()->create();
        $c = $this->client();
        $survivor = $this->person($c, ['email' => 'keep@acme.test']);

        $this->actingAs($user)
            ->post(route('people.merge', $survivor), [])
            ->assertSessionHasErrors('duplicate_id');
    }

    public function test_cannot_merge_a_contact_from_another_client(): void
    {
        $user = User::factory()->create();
        $a = $this->client('Acme');
        $b = $this->client('Beta');
        $survivor = $this->person($a, ['email' => 'a@acme.test']);
        $other = $this->person($b, ['email' => 'b@beta.test']);

        // The scoped exists rule rejects a cross-client id before the service runs
        $this->actingAs($user)
            ->post(route('people.merge', $survivor), ['duplicate_id' => $other->id])
            ->assertSessionHasErrors('duplicate_id');

        $this->assertNotSoftDeleted('people', ['id' => $other->id]);
    }

    public function test_self_merge_is_rejected_with_an_error_flash(): void
    {
        $user = User::factory()->create();
        $c = $this->client();
        $survivor = $this->person($c, ['email' => 'keep@acme.test']);

        $this->actingAs($user)
            ->post(route('people.merge', $survivor), ['duplicate_id' => $survivor->id])
            ->assertRedirect(route('people.show', $survivor))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('people', ['id' => $survivor->id]);
    }

    public function test_flash_warns_when_portal_login_email_changes(): void
    {
        $user = User::factory()->create();
        $c = $this->client();
        $survivor = $this->person($c, ['email' => 'keep@acme.test']);
        $dup = $this->person($c, ['email' => 'dupe@acme.test', 'portal_enabled' => true]);
        $dup->password = 's3cret-pw';
        $dup->save();

        $this->actingAs($user)
            ->post(route('people.merge', $survivor), ['duplicate_id' => $dup->id])
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Portal sign-in')
                && str_contains($msg, 'keep@acme.test'));
    }
}
