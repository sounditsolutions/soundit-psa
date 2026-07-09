<?php

namespace App\Services\Signals;

use App\Models\SignalDestination;
use App\Models\SignalEvent;
use App\Models\SignalRouteStep;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Resolves a route step to the concrete {@see SignalDestination} it should
 * deliver to.
 *
 * Fixed steps return their attached destination unchanged. Derived steps
 * (those carrying a {@see DerivedRecipients} kind) resolve a recipient from the
 * event's subject entity at route time and provision — or reuse — a real
 * per-user destination row, because deliveries require a concrete destination.
 * Returns null when no recipient can be derived (e.g. a non-ticket event, a
 * ticket with no assignee, or a user without an email); callers skip creating a
 * delivery in that case.
 */
class DerivedRecipientResolver
{
    /**
     * Resolve the destination for a route step, deriving the recipient when the
     * step is not tied to a fixed destination.
     */
    public function resolveForStep(SignalRouteStep $step, SignalEvent $event): ?SignalDestination
    {
        if ($step->derived_from !== null) {
            return $this->resolve($step->derived_from, $event);
        }

        return $step->destination;
    }

    /**
     * Resolve a derived-recipient kind to a concrete destination for an event.
     */
    public function resolve(string $kind, SignalEvent $event): ?SignalDestination
    {
        return match ($kind) {
            DerivedRecipients::TICKET_OWNER => $this->ticketOwner($event),
            default => null,
        };
    }

    private function ticketOwner(SignalEvent $event): ?SignalDestination
    {
        $ticket = $this->entityAs($event, Ticket::class);
        if ($ticket === null) {
            return null;
        }

        $owner = $ticket->assignee;
        if ($owner === null) {
            return null;
        }

        return $this->provisionForUser($owner);
    }

    /**
     * Load the event's subject entity if it is (a subclass of) the given model
     * class; null otherwise. Resolves through the morph map so a future
     * morphMap alias keeps working.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel|null
     */
    private function entityAs(SignalEvent $event, string $modelClass)
    {
        if ($event->entity_type === null || $event->entity_id === null) {
            return null;
        }

        $resolved = Relation::getMorphedModel($event->entity_type) ?? $event->entity_type;
        if ($resolved !== $modelClass && ! is_subclass_of($resolved, $modelClass)) {
            return null;
        }

        return $modelClass::find($event->entity_id);
    }

    /**
     * Find or provision the auto-managed email destination for a staff user.
     * Delivery-critical fields (address, label) are refreshed if the user's
     * email or name changed; the admin-controlled `enabled` flag is preserved.
     */
    public function provisionForUser(User $user): ?SignalDestination
    {
        $email = trim((string) $user->email);
        if ($email === '') {
            return null;
        }

        $destination = SignalDestination::query()->where('user_id', $user->id)->first();

        if ($destination === null) {
            try {
                return SignalDestination::create([
                    'user_id' => $user->id,
                    'label' => $this->userLabel($user),
                    'type' => 'email',
                    'address' => $email,
                    'enabled' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another worker provisioned this user's destination concurrently.
                return SignalDestination::query()->where('user_id', $user->id)->first();
            }
        }

        $updates = [];
        if ((string) $destination->address !== $email) {
            $updates['address'] = $email;
        }
        if ($destination->label !== $this->userLabel($user)) {
            $updates['label'] = $this->userLabel($user);
        }
        if ($destination->type !== 'email') {
            $updates['type'] = 'email';
        }

        if ($updates !== []) {
            $destination->forceFill($updates)->save();
        }

        return $destination;
    }

    private function userLabel(User $user): string
    {
        $name = trim((string) $user->name);

        return 'User: '.($name !== '' ? $name : $user->email);
    }
}
