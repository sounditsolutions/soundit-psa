<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Person;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Captures field-level ticket changes into the ticket_activities audit trail.
 *
 * Driven by TicketObserver::updated(), which fires while getOriginal() still
 * holds the pre-save values and wasChanged() reflects the current save — so we
 * can diff old → new for every tracked column in a single pass. Auditing is
 * best-effort: a logging failure is swallowed (and logged) so it can never
 * break the ticket save it is observing.
 */
class TicketActivityLogger
{
    public function record(Ticket $ticket): void
    {
        try {
            $actorId = auth()->id();
            $now = now();
            $rows = [];

            foreach (TicketActivity::TRACKED_FIELDS as $field) {
                if (! $ticket->wasChanged($field)) {
                    continue;
                }

                $old = $this->formatValue($field, $ticket->getOriginal($field));
                $new = $this->formatValue($field, $ticket->getAttribute($field));

                // Skip churn where two distinct raw values render to the same
                // label (or where empty-string/null normalize to the same thing).
                if ($old === $new) {
                    continue;
                }

                $rows[] = [
                    'ticket_id' => $ticket->id,
                    'user_id' => $actorId,
                    'field' => $field,
                    'old_value' => $old,
                    'new_value' => $new,
                    'created_at' => $now,
                ];
            }

            if ($rows !== []) {
                TicketActivity::insert($rows);
            }
        } catch (\Throwable $e) {
            Log::warning('[TicketActivity] Failed to record change', [
                'ticket_id' => $ticket->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Render a raw attribute value into the human-readable string stored in the
     * audit trail. Foreign keys resolve to the referenced entity's display name
     * (with a "#id" fallback if it has since been deleted); enums to their
     * label(); datetimes to app-timezone text. Empty values become null.
     */
    private function formatValue(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'assignee_id' => User::find($value)?->name ?? "User #{$value}",
            'client_id' => Client::find($value)?->name ?? "Client #{$value}",
            'contact_id' => Person::find($value)?->full_name ?? "Contact #{$value}",
            'contract_id' => Contract::find($value)?->name ?? "Contract #{$value}",
            'status', 'priority', 'type' => $value instanceof BackedEnum
                ? (method_exists($value, 'label') ? $value->label() : (string) $value->value)
                : (string) $value,
            'due_at', 'response_due_at' => $value instanceof CarbonInterface
                ? $value->toAppTz()->format('M j, Y g:i A')
                : (string) $value,
            default => (string) $value,
        };
    }
}
