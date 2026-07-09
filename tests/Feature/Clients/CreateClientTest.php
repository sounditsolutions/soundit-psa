<?php

namespace Tests\Feature\Clients;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the staff "create a new client" flow (QA scenario client-search-create).
 *
 * The reported failure — "Create Client submit hangs and does not save" — was a
 * QA-harness interaction problem, not a server defect: a broad button[type=submit]
 * selector resolved to the topbar logout button (first in the DOM, hidden inside a
 * collapsed dropdown) instead of the "Create Client" button. These tests lock in
 * that (a) the primary submit action is uniquely identifiable and (b) the store
 * endpoint actually persists the client and redirects to its detail page.
 */
class CreateClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_renders_a_uniquely_identifiable_submit_button(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('clients.create'))->assertOk();

        // A stable id makes the primary action unambiguous to click, so a broad
        // "submit button" selector can no longer collide with the topbar logout.
        $response->assertSee('id="client-form-submit"', false);
        $response->assertSee('Create Client');
    }

    public function test_store_creates_client_and_redirects_to_detail(): void
    {
        $user = User::factory()->create();

        $name = 'QA Autonomous Client 20260709';
        $email = 'qa-client@example.test';

        $response = $this->actingAs($user)->post(route('clients.store'), [
            'name' => $name,
            'notes' => 'Created via the new-client form.',
            'phone' => '555-123-9876',
            'email' => $email,
        ]);

        $client = Client::where('name', $name)->firstOrFail();

        $response->assertRedirect(route('clients.show', $client));
        $this->assertDatabaseHas('clients', ['name' => $name, 'email' => $email]);
        $this->assertNotNull($client->phone_display, 'phone should be normalized into phone_display');

        // Following the redirect lands on the detail page showing the entered fields.
        $this->actingAs($user)->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee($name)
            ->assertSee($email);
    }

    public function test_store_requires_a_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('clients.create'))
            ->post(route('clients.store'), ['email' => 'no-name@example.test'])
            ->assertRedirect(route('clients.create'))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('clients', 0);
    }
}
