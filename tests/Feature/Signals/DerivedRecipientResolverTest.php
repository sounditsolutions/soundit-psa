<?php

namespace Tests\Feature\Signals;

use App\Models\SignalEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Signals\DerivedRecipientResolver;
use App\Services\Signals\DerivedRecipients;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DerivedRecipientResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_ticket_owner_to_a_provisioned_per_user_email_destination(): void
    {
        $owner = User::factory()->create(['name' => 'Dana Owner', 'email' => 'dana@example.test']);
        $event = $this->ticketEvent($this->ticketFor($owner));

        $destination = $this->resolver()->resolve(DerivedRecipients::TICKET_OWNER, $event);

        $this->assertNotNull($destination);
        $this->assertSame('email', $destination->type);
        $this->assertSame('dana@example.test', $destination->address);
        $this->assertSame($owner->id, $destination->user_id);
        $this->assertTrue($destination->enabled);
        $this->assertSame('User: Dana Owner', $destination->label);
        $this->assertDatabaseCount('signal_destinations', 1);
    }

    public function test_reuses_the_same_destination_across_events_for_the_same_owner(): void
    {
        $owner = User::factory()->create();

        $first = $this->resolver()->resolve(DerivedRecipients::TICKET_OWNER, $this->ticketEvent($this->ticketFor($owner)));
        $second = $this->resolver()->resolve(DerivedRecipients::TICKET_OWNER, $this->ticketEvent($this->ticketFor($owner)));

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('signal_destinations', 1);
    }

    public function test_returns_null_when_ticket_has_no_owner(): void
    {
        $event = $this->ticketEvent($this->ticketFor(null));

        $this->assertNull($this->resolver()->resolve(DerivedRecipients::TICKET_OWNER, $event));
        $this->assertDatabaseCount('signal_destinations', 0);
    }

    public function test_returns_null_for_a_non_ticket_entity(): void
    {
        $event = SignalEvent::create([
            'type_key' => 'agent.run_failed',
            'entity_type' => User::class,
            'entity_id' => User::factory()->create()->id,
            'summary' => 'run failed',
            'context' => [],
            'occurred_at' => now(),
        ]);

        $this->assertNull($this->resolver()->resolve(DerivedRecipients::TICKET_OWNER, $event));
        $this->assertDatabaseCount('signal_destinations', 0);
    }

    public function test_returns_null_when_the_event_has_no_entity(): void
    {
        $event = SignalEvent::create([
            'type_key' => 'digest.daily',
            'entity_type' => null,
            'entity_id' => null,
            'summary' => 'daily digest',
            'context' => [],
            'occurred_at' => now(),
        ]);

        $this->assertNull($this->resolver()->resolve(DerivedRecipients::TICKET_OWNER, $event));
    }

    public function test_refreshes_address_when_owner_email_changes_but_preserves_admin_disabled_flag(): void
    {
        $owner = User::factory()->create(['email' => 'old@example.test']);
        $destination = $this->resolver()->provisionForUser($owner);
        // An admin disables the auto-provisioned destination.
        $destination->forceFill(['enabled' => false])->save();

        $owner->forceFill(['email' => 'new@example.test'])->save();
        $refreshed = $this->resolver()->provisionForUser($owner->fresh());

        $this->assertNotNull($refreshed);
        $this->assertSame($destination->id, $refreshed->id);
        $this->assertSame('new@example.test', $refreshed->address);
        $this->assertFalse($refreshed->enabled, 'the admin-controlled enabled flag must be preserved');
        $this->assertDatabaseCount('signal_destinations', 1);
    }

    public function test_returns_null_when_owner_has_no_email(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['email' => ''])->save();

        $this->assertNull($this->resolver()->provisionForUser($owner->fresh()));
        $this->assertDatabaseCount('signal_destinations', 0);
    }

    public function test_unknown_derived_kind_resolves_to_null(): void
    {
        $event = $this->ticketEvent($this->ticketFor(User::factory()->create()));

        $this->assertNull($this->resolver()->resolve('not_a_kind', $event));
    }

    private function resolver(): DerivedRecipientResolver
    {
        return app(DerivedRecipientResolver::class);
    }

    private function ticketFor(?User $assignee): Ticket
    {
        return Model::withoutEvents(fn () => Ticket::factory()->create([
            'assignee_id' => $assignee?->id,
        ]));
    }

    private function ticketEvent(Ticket $ticket): SignalEvent
    {
        return SignalEvent::create([
            'type_key' => 'ticket.created',
            'entity_type' => $ticket->getMorphClass(),
            'entity_id' => $ticket->id,
            'summary' => 'Ticket created',
            'context' => [],
            'occurred_at' => now(),
        ]);
    }
}
