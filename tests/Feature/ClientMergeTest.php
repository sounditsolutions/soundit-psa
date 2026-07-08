<?php

namespace Tests\Feature;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ClientMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function ticket(Client $c): Ticket
    {
        return Ticket::create([
            'client_id' => $c->id,
            'subject' => 'Help',
            'type' => TicketType::ServiceRequest,
            'status' => TicketStatus::New,
            'priority' => TicketPriority::P3,
            'opened_at' => now(),
        ]);
    }

    public function test_staff_can_merge_a_duplicate_via_http(): void
    {
        $user = User::factory()->create();
        $survivor = Client::create(['name' => 'Keep Co']);
        $dup = Client::create(['name' => 'Dupe Co']);
        $ticket = $this->ticket($dup);

        $response = $this->actingAs($user)
            ->post(route('clients.merge', $survivor), ['duplicate_id' => $dup->id]);

        $response->assertRedirect(route('clients.show', $survivor));
        $response->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Merged Dupe Co into Keep Co')
            && str_contains($msg, '1 ticket'));
        $this->assertSame($survivor->id, $ticket->fresh()->client_id);
        $this->assertSoftDeleted('clients', ['id' => $dup->id]);
    }

    public function test_merge_requires_a_duplicate_id(): void
    {
        $user = User::factory()->create();
        $survivor = Client::create(['name' => 'Keep Co']);

        $this->actingAs($user)
            ->post(route('clients.merge', $survivor), [])
            ->assertSessionHasErrors('duplicate_id');
    }

    public function test_self_merge_is_rejected_with_an_error_flash(): void
    {
        $user = User::factory()->create();
        $survivor = Client::create(['name' => 'Keep Co']);

        $this->actingAs($user)
            ->post(route('clients.merge', $survivor), ['duplicate_id' => $survivor->id])
            ->assertRedirect(route('clients.show', $survivor))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('clients', ['id' => $survivor->id]);
    }

    public function test_merge_rejects_a_soft_deleted_duplicate(): void
    {
        $user = User::factory()->create();
        $survivor = Client::create(['name' => 'Keep Co']);
        $dup = Client::create(['name' => 'Dupe Co']);
        $dup->delete(); // soft-deleted → not a valid candidate

        $this->actingAs($user)
            ->post(route('clients.merge', $survivor), ['duplicate_id' => $dup->id])
            ->assertSessionHasErrors('duplicate_id');
    }

    public function test_client_show_page_renders_with_merge_modal(): void
    {
        // Renders clients.show so a Blade compile/parse error in the merge modal
        // would surface here (the redirect-based tests never render the page).
        $user = User::factory()->create();
        $survivor = Client::create(['name' => 'Keep Co']);
        Client::create(['name' => 'Dupe Co']); // a candidate so the modal renders

        $this->actingAs($user)->get(route('clients.show', $survivor))
            ->assertOk()
            ->assertSee('Merge Duplicate Client')
            ->assertSee('This cannot be undone.')
            ->assertSee('aria-labelledby="mergeClientModalLabel"', false);
    }
}
