<?php

namespace App\Services;

use App\Models\BillableTimeChange;
use App\Models\PhoneCall;
use App\Models\TicketNote;
use App\Models\TimeEntryBillingProposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillableTimeService
{
    public function __construct(private PrepayService $prepay) {}

    public function setNote(TicketNote $note, ?int $minutes, ?string $reason, string $source, ?User $actor, string $actorLabel): TicketNote
    {
        return $this->set($note, $minutes, $reason, $source, $actor, $actorLabel);
    }

    public function setCall(PhoneCall $call, ?int $minutes, ?string $reason, string $source, ?User $actor, string $actorLabel): PhoneCall
    {
        return $this->set($call, $minutes, $reason, $source, $actor, $actorLabel);
    }

    private function validate(TicketNote|PhoneCall $entry, ?int $minutes, ?string $reason, bool $requireReason): void
    {
        if ($entry instanceof TicketNote && $minutes !== null && (int) $entry->time_adjustment_minutes < 0) {
            throw ValidationException::withMessages(['billable_minutes' => 'Only part of this note was drawn from prepay in Halo; a billing override is not allowed.']);
        }
        $cap = $entry instanceof TicketNote ? $entry->pricedMinutes() : (int) ceil(($entry->effectiveDurationSeconds() ?? 0) / 60);
        if ($minutes !== null && ($minutes < 0 || $minutes > max(0, $cap))) {
            throw ValidationException::withMessages(['billable_minutes' => 'Bill must be between zero and the actual time.']);
        }
        if ($requireReason && (trim((string) $reason) === '' || mb_strlen((string) $reason) > 800)) {
            throw ValidationException::withMessages(['reason' => 'A reason of 1 to 800 characters is required.']);
        }
    }

    private function set(TicketNote|PhoneCall $entry, ?int $minutes, ?string $reason, string $source, ?User $actor, string $actorLabel): TicketNote|PhoneCall
    {
        return DB::transaction(function () use ($entry, $minutes, $reason, $source, $actor, $actorLabel) {
            $locked = $entry->newQuery()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($source, ['ui', 'mcp'], true) || trim($actorLabel) === '' || mb_strlen($actorLabel) > 255) {
                throw ValidationException::withMessages(['source' => 'A valid source and actor label are required.']);
            }
            $changed = $locked->billable_minutes !== $minutes;
            // An unchanged override may exceed actual after a later time edit.
            if (! $changed) {
                return $locked;
            }
            $this->validate($locked, $minutes, $reason, true);
            $old = $locked->billable_minutes;
            $locked->billable_minutes = $minutes;
            $locked->save();
            BillableTimeChange::create([
                'entry_type' => $locked instanceof TicketNote ? 'note' : 'call',
                'entry_id' => $locked->id, 'old_billable_minutes' => $old,
                'new_billable_minutes' => $minutes, 'reason' => $reason, 'source' => $source,
                'actor_user_id' => $actor?->id, 'actor_label' => $actorLabel,
            ]);
            if ($locked instanceof TicketNote) {
                $this->prepay->debitFromTicketNote($locked);
            } else {
                $this->prepay->debitFromPhoneCall($locked);
            }

            return $locked->refresh();
        });
    }

    public function propose(TicketNote|PhoneCall $entry, ?int $minutes, string $reason, ?User $actor, string $actorLabel): TimeEntryBillingProposal
    {
        return DB::transaction(function () use ($entry, $minutes, $reason, $actor, $actorLabel) {
            $locked = $entry->newQuery()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            $this->validate($locked, $minutes, $reason, true);

            return TimeEntryBillingProposal::create([
                'entry_type' => $locked instanceof TicketNote ? 'note' : 'call', 'entry_id' => $locked->id,
                'billable_minutes' => $minutes, 'reason' => $reason,
                'actor_user_id' => $actor?->id, 'actor_label' => $actorLabel,
            ]);
        });
    }

    public function approve(TimeEntryBillingProposal $proposal, User $approver): TicketNote|PhoneCall
    {
        return DB::transaction(function () use ($proposal, $approver) {
            $snapshot = TimeEntryBillingProposal::findOrFail($proposal->id);
            $entry = match ($snapshot->entry_type) {
                'note' => TicketNote::whereKey($snapshot->entry_id)->lockForUpdate()->firstOrFail(),
                'call' => PhoneCall::whereKey($snapshot->entry_id)->lockForUpdate()->firstOrFail(),
                default => throw ValidationException::withMessages(['proposal' => 'Invalid entry type.']),
            };
            $held = TimeEntryBillingProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if ($held->state !== 'pending' || ($held->expires_at !== null && $held->expires_at->isPast())) {
                throw ValidationException::withMessages(['proposal' => 'Proposal is not pending or has expired.']);
            }
            $this->validate($entry, $held->billable_minutes, $held->reason, true);
            $result = $this->set($entry, $held->billable_minutes, $held->reason, 'mcp',
                $held->actor_user_id === null ? null : User::find($held->actor_user_id), $held->actor_label);
            $held->update(['state' => 'done', 'handled_by' => $approver->id, 'handled_at' => now()]);

            return $result;
        });
    }

    public function reject(TimeEntryBillingProposal $proposal, User $actor): bool
    {
        return (bool) TimeEntryBillingProposal::whereKey($proposal->id)->where('state', 'pending')
            ->update(['state' => 'rejected', 'handled_by' => $actor->id, 'handled_at' => now()]);
    }

    public function expire(TimeEntryBillingProposal $proposal): bool
    {
        return (bool) TimeEntryBillingProposal::whereKey($proposal->id)->where('state', 'pending')
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->update(['state' => 'expired', 'handled_at' => now()]);
    }
}
