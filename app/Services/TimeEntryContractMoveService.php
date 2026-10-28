<?php

namespace App\Services;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\PhoneCall;
use App\Models\PhoneCallActionProposal;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\TimeEntryMoveProposal;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ticket-level face of PrepayService::moveEntryContract (card I3EvQKUV PR 2,
 * SPEC §4): which time entries a contract change offers to move, the
 * change-and-move the modal submits, and the held agent move (ruling Q9).
 */
class TimeEntryContractMoveService
{
    public function __construct(private PrepayService $prepay) {}

    /**
     * The ticket's time entries as the modal and update_ticket list them: billable
     * notes with time and billable calls with a duration, with the contract each is
     * logged against (its ledger row's, else its stamp) and whether it may be moved.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function entries(Ticket $ticket, ?int $toContractId = null): Collection
    {
        $notes = TicketNote::where('ticket_id', $ticket->id)->where('is_billable', true)
            ->with('author')->orderBy('noted_at')->orderBy('id')->get()
            ->filter(fn (TicketNote $note) => $note->billedMinutes() > 0);
        $calls = PhoneCall::where('ticket_id', $ticket->id)->where('is_billable', true)
            ->with('answeredBy')->orderBy('started_at')->orderBy('id')->get()
            ->filter(fn (PhoneCall $c) => ($c->billedSeconds() ?? 0) > 0);

        $noteRows = PrepayTransaction::whereIn('ticket_note_id', $notes->pluck('id'))->get()->keyBy('ticket_note_id');
        $callRows = PrepayTransaction::whereIn('phone_call_id', $calls->pluck('id'))->get()->keyBy('phone_call_id');
        $pendingCalls = PhoneCallActionProposal::whereIn('phone_call_id', $calls->pluck('id'))->where('state', 'pending')
            ->pluck('phone_call_id')->flip();
        $pendingMoves = TimeEntryMoveProposal::where('ticket_id', $ticket->id)->where('state', 'pending')->get()
            ->mapWithKeys(fn ($p) => [$p->entry_type.':'.$p->entry_id => true]);

        $rows = collect();
        foreach ($notes as $note) {
            $row = $noteRows->get($note->id);
            $rows->push($this->row('note', $note->id, mb_substr(trim(strip_tags((string) $note->body)), 0, 60), $note->noted_at ?? $note->created_at,
                $note->author?->name, round($note->billedMinutes() / 60, 2), $row, $note->contract_id, false, isset($pendingMoves['note:'.$note->id]), $toContractId,
                $this->drawHoursIfMoved($note, $row), $ticket->client_id === null ? null : (int) $ticket->client_id));
        }
        foreach ($calls as $call) {
            $row = $callRows->get($call->id);
            $label = 'Phone call: '.($call->direction?->value ?? 'call');
            $rows->push($this->row('call', $call->id, $label, $call->started_at ?? $call->created_at,
                $call->answeredBy?->name, round(($call->billedSeconds() ?? 0) / 3600, 2), $row, $call->contract_id,
                isset($pendingCalls[$call->id]), isset($pendingMoves['call:'.$call->id]), $toContractId, $this->drawHoursIfMoved($call, $row),
                $ticket->client_id === null ? null : (int) $ticket->client_id));
        }

        $contracts = Contract::withTrashed()->whereIn('id', $rows->pluck('contract_id')->filter()->unique())->get()->keyBy('id');

        return $rows->sortBy('date')->values()->map(function (array $r) use ($contracts) {
            $c = $r['contract_id'] ? $contracts->get($r['contract_id']) : null;
            $r['contract_name'] = $c?->name;
            $r['contract_prepay'] = $this->prepay->isHoursPrepay($c);
            $r['contract_balance'] = (float) ($c?->prepay_balance ?? 0);

            return $r;
        });
    }

    private function row(string $type, int $id, string $label, $date, ?string $by, float $hours, ?PrepayTransaction $ledger,
        $stamp, bool $pendingCallAction, bool $pendingMove, ?int $toContractId, float $drawHours, ?int $clientId): array
    {
        $contractId = $ledger ? (int) $ledger->contract_id : ($stamp === null ? null : (int) $stamp);
        $locked = match (true) {
            $pendingCallAction => 'Locked: a staged billable action is awaiting approval',
            $pendingMove => 'Locked: a staged contract move is awaiting approval',
            $ledger !== null && $clientId !== null && ! $this->prepay->ledgerSourceMatchesClient(Contract::withTrashed()->find($contractId), $clientId) => 'Locked: ledger source contract is missing or belongs to another client',
            $toContractId !== null && $contractId === $toContractId => 'Already on the new contract',
            default => null,
        };

        return [
            'type' => $type, 'id' => $id, 'label' => $type === 'note' ? 'Note: '.($label !== '' ? $label : 'time entry') : $label,
            'date' => $date, 'by' => $by, 'hours' => $hours, 'ledger_hours' => $ledger ? abs((float) $ledger->hours) : 0.0,
            'contract_id' => $contractId, 'locked' => $locked, 'ledger' => $ledger !== null, 'draw_hours' => $drawHours,
        ];
    }

    /**
     * Hours the ordinary debit path draws, right after the move commits, from an
     * hours-prepay target for an entry that has NO ledger row yet (Jeeves 2026-10-02
     * 21:38 PT: the move keeps that follow-on debit and must state it). Zero when the
     * entry has a ledger row (that move is the credit/debit pair), is not billable,
     * carries no time, or is an unverified contact-intake note (never debited).
     */
    public function drawHoursIfMoved(TicketNote|PhoneCall $entry, ?PrepayTransaction $ledger): float
    {
        if ($ledger !== null || ! $entry->is_billable) {
            return 0.0;
        }
        if ($entry instanceof TicketNote) {
            return $entry->isUnverifiedContactIntake() || $entry->billedMinutes() <= 0 ? 0.0 : round($entry->billedMinutes() / 60, 4);
        }
        $seconds = $entry->billedSeconds() ?? 0;

        return $seconds > 0 ? round($seconds / 3600, 4) : 0.0;
    }

    /** The modal's and the verb's statement of that draw. */
    public static function drawStatement(float $hours, string $contractName, bool $done): string
    {
        return ($done ? 'It had no prepay ledger row, so it drew ' : 'It has no prepay ledger row, so the move will draw ')
            .number_format($hours, 2).'h from '.$contractName.'. '.self::INVOICED_ADVICE;
    }

    public const INVOICED_ADVICE = 'If this time was already invoiced by hand, untick billable instead of moving.';

    /**
     * Entries logged against a contract other than the ticket's (update_ticket's
     * entries_on_other_contracts).
     *
     * @return list<array{type: string, id: int, minutes: int, contract_id: ?int}>
     */
    public function entriesOnOtherContracts(Ticket $ticket): array
    {
        return $this->entries($ticket)
            ->filter(fn ($r) => $r['contract_id'] !== ($ticket->contract_id === null ? null : (int) $ticket->contract_id))
            ->map(fn ($r) => ['type' => $r['type'], 'id' => $r['id'], 'minutes' => (int) round($r['hours'] * 60), 'contract_id' => $r['contract_id']])
            ->values()->all();
    }

    /**
     * Change the ticket's contract and, when entries are ticked, move each through
     * moveEntryContract (one transaction per entry, after the ticket change commits).
     * The ticket change alone moves no money, and releases no held entry.
     *
     * @param  list<array{type: string, id: int}>  $moves
     * @return array{moved: int, hours: ?float, errors: list<string>}
     */
    public function changeTicketContract(Ticket $ticket, ?int $contractId, array $moves, ?string $reason, User $by): array
    {
        if ($moves === [] && $contractId === $ticket->contract_id) {
            return ['moved' => 0, 'hours' => 0.0, 'errors' => []];
        }

        $to = $contractId === null ? null : Contract::withTrashed()->whereKey($contractId)->where('client_id', $ticket->client_id)->first();
        if ($contractId !== null && ! $to) {
            throw new \InvalidArgumentException("Contract {$contractId} is not a contract of this ticket's client.");
        }
        if ($moves === [] && $to && ($to->trashed() || $to->status !== ContractStatus::Active)) {
            throw new \InvalidArgumentException("Contract {$contractId} is not an ACTIVE contract of this ticket's client.");
        }
        if ($moves !== [] && (! $to || $to->trashed() || $to->status !== ContractStatus::Active)) {
            throw new \InvalidArgumentException('Entries can only be moved to an active contract.');
        }
        if ($moves !== [] && trim((string) $reason) === '') {
            throw new \InvalidArgumentException('A reason is required to move time entries.');
        }

        $old = $ticket->contract;
        if ((int) $ticket->contract_id !== (int) $contractId) {
            DB::transaction(function () use ($ticket, $contractId, $old, $to, $by) {
                \App\Observers\TicketObserver::withoutHeldRelease(fn () => app(TicketService::class)->updateTicket($ticket, ['contract_id' => $contractId]));
                $body = 'Ticket contract changed from '.($old?->name ?? 'none').' to '.($to?->name ?? 'none')
                    .'.';
                TicketNote::create([
                    'ticket_id' => $ticket->id, 'author_id' => $by->id, 'body' => $body,
                    'body_html' => \App\Helpers\MarkdownRenderer::render($body),
                    'note_type' => \App\Enums\NoteType::System, 'is_private' => true, 'noted_at' => now(),
                ]);
            });
        }

        $moved = 0;
        $hours = 0.0;
        $unread = false;
        $errors = [];
        foreach ($moves as $move) {
            $entry = $this->entry($ticket, $move['type'] ?? '', (int) ($move['id'] ?? 0));
            if (! $entry) {
                $errors[] = 'Entry '.($move['type'] ?? '?').' #'.(int) ($move['id'] ?? 0).' is not a time entry on this ticket.';

                continue;
            }
            if ($this->hasPendingMove($move['type'], (int) $entry->id)) {
                $errors[] = 'A staged contract move on this entry is awaiting approval.';

                continue;
            }
            try {
                $result = $this->prepay->moveEntryContract($entry, $to, (string) $reason, $by);
                $moved++;
                if (($result['status'] ?? null) === 'executed_with_fault') {
                    $errors[] = 'Entry '.$move['type'].' #'.$move['id'].': '.$result['message'];
                }
                // A ledgered move reports the hours it moved; an unledgered one, its drawn_hours (the hours
                // the follow-on debit drew, 0.0 when none was recorded). A null drawn_hours (the ledger
                // read-back after a caught fault failed) leaves the batch total unknown, so hours is null.
                if (! $result['ledger'] && $result['drawn_hours'] === null) {
                    $unread = true;
                } else {
                    $hours += $result['ledger'] ? $result['hours'] : $result['drawn_hours'];
                }
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return ['moved' => $moved, 'hours' => $unread ? null : round($hours, 4), 'errors' => $errors];
    }

    public function entry(Ticket $ticket, string $type, int $id): TicketNote|PhoneCall|null
    {
        return match ($type) {
            'note' => TicketNote::where('ticket_id', $ticket->id)->whereKey($id)->first(),
            'call' => PhoneCall::where('ticket_id', $ticket->id)->whereKey($id)->first(),
            default => null,
        };
    }

    /**
     * move_time_entry_contract / stage_move_time_entry_contract (ruling Q9). The
     * controller's mode gate has already downgraded a non-:immediate grant to staged.
     * Staged writes one pending proposal and moves nothing; approval re-validates.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function agentMove(array $arguments, string $actor, bool $staged): array
    {
        if (\App\Support\TechnicianConfig::killSwitchEngaged()) {
            return ['error' => 'Technician kill-switch engaged.'];
        }
        $allowed = ['entry_type', 'entry_id', 'contract_id', 'reason'];
        if (array_diff(array_keys($arguments), $allowed) !== []) {
            return ['error' => 'move_time_entry_contract accepts only: '.implode(', ', $allowed).'.'];
        }
        $type = $arguments['entry_type'] ?? null;
        $id = $arguments['entry_id'] ?? null;
        $contractId = $arguments['contract_id'] ?? null;
        $reason = $arguments['reason'] ?? null;
        if (! in_array($type, ['note', 'call'], true) || ! is_int($id) || $id < 1 || ! is_int($contractId) || $contractId < 1
            || ! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 800) {
            return ['error' => 'entry_type (note|call), positive integer entry_id and contract_id, and a reason (1-800 characters) are required.'];
        }

        // Read before the entry, so a move written after the entry read sorts above the watermark (movedSince()).
        $watermark = \App\Models\ContractActivity::max('id') ?? 0;
        $entry = $type === 'note' ? TicketNote::find($id) : PhoneCall::find($id);
        $ticket = $entry?->ticket;
        if (! $entry || ! $ticket) {
            return ['error' => "No {$type} {$id} on a ticket."];
        }
        $refusal = $this->entryRefusal($entry, $type);
        if ($refusal !== null) {
            return ['error' => $refusal];
        }
        $refusal = $this->moveRefusal($ticket, $entry, $type, $contractId);
        if ($refusal !== null) {
            return ['error' => $refusal, 'error_code' => 'contract_not_allowed'];
        }
        $from = $this->loggedContractId($entry, $type);

        if (! $staged) {
            $by = User::find(\App\Support\TechnicianConfig::requiredAiActorUserId());
            try {
                $result = $this->prepay->moveEntryContract($entry, Contract::findOrFail($contractId), $reason, $by);
            } catch (\InvalidArgumentException $e) {
                return ['error' => $e->getMessage()];
            }

            if (($result['status'] ?? null) === 'executed_with_fault') {
                return ['success' => false, 'staged' => false, 'ticket_id' => $ticket->id] + $result;
            }

            $message = 'Time entry moved. '.$this->ledgeredMoveStatement($result);
            if (! $result['ledger']) {
                $drawn = PrepayTransaction::where($type === 'note' ? 'ticket_note_id' : 'phone_call_id', $entry->id)->first();
                $message = $drawn
                    ? 'Time entry moved. '.self::drawStatement(abs((float) $drawn->hours),
                        Contract::withTrashed()->find($drawn->contract_id)?->name ?? "contract {$drawn->contract_id}", true)
                    : 'Time entry moved. It had no prepay ledger row and no prepay hours were drawn.';
                $result['drawn_hours'] = $drawn ? abs((float) $drawn->hours) : 0.0;
            }

            return ['success' => true, 'staged' => false, 'ticket_id' => $ticket->id] + $result + ['message' => $message];
        }

        $hash = hash('sha256', json_encode([$type, $id, $from, $contractId, trim($reason)], JSON_THROW_ON_ERROR));
        $proposal = TimeEntryMoveProposal::firstOrCreate(
            ['content_hash' => $hash, 'state' => 'pending', 'drafted_by' => $actor],
            ['entry_type' => $type, 'entry_id' => $id, 'ticket_id' => $ticket->id, 'from_contract_id' => $from,
                'to_contract_id' => $contractId, 'reason' => trim($reason),
                'activity_watermark' => $watermark],
        );

        $draw = $this->stagedDrawHours($entry, $type, $contractId);
        $message = 'Move held for staff approval in the technician cockpit; no prepay hours moved.';
        $message .= ' On approval: '.$this->approvalEffect($proposal);

        return [
            'success' => true, 'staged' => true, 'proposal_id' => $proposal->id, 'ticket_id' => $ticket->id,
            'entry_type' => $type, 'entry_id' => $id, 'from_contract_id' => $from, 'to_contract_id' => $contractId,
            'draw_hours_on_approval' => $draw, 'message' => $message,
        ];
    }

    /**
     * Approve a held move. The stale check and the claim run as moveEntryContract's guard,
     * under the entry and ledger-row locks and in its one transaction, so they see the entry
     * as it is moved, and its after-commit debit and alerts really run after commit. Stale
     * when the entry left the ticket, is on another contract than staged, or was moved at
     * all since staging (A -> C -> A included).
     */
    public function approve(TimeEntryMoveProposal $proposal, User $approver): array
    {
        $p = TimeEntryMoveProposal::find($proposal->id);
        if (! $p || $p->state !== 'pending') {
            return ['error' => 'Proposal already handled or not found.'];
        }
        $stale = fn (string $message) => TimeEntryMoveProposal::whereKey($p->id)->where('state', 'pending')
            ->update(['state' => 'stale', 'handled_at' => now(), 'approved_by' => $approver->id])
            ? ['error' => $message] : ['error' => 'Proposal already handled or not found.'];
        $entry = $p->entry_type === 'note' ? TicketNote::find($p->entry_id) : PhoneCall::find($p->entry_id);
        $to = Contract::withTrashed()->find($p->to_contract_id);
        if (! $entry || ! $to) {
            return $stale('The entry or the new contract no longer exists; nothing moved.');
        }

        $handled = false;
        $guard = function (TicketNote|PhoneCall $locked, ?PrepayTransaction $row, ?int $fromId) use ($p, $approver, &$handled) {
            $claim = TimeEntryMoveProposal::whereKey($p->id)->lockForUpdate()->first();
            if (! $claim || $claim->state !== 'pending') {
                $handled = true;

                throw new \InvalidArgumentException('Proposal already handled or not found.');
            }
            if ((int) $locked->ticket_id !== (int) $p->ticket_id || $fromId !== $this->stagedFrom($p) || $this->movedSince($p)) {
                throw new \InvalidArgumentException('The entry changed since this move was staged; nothing moved. Re-stage it if it is still wanted.');
            }
            $claim->update(['state' => 'done', 'handled_at' => now(), 'approved_by' => $approver->id]);
        };
        try {
            $result = $this->prepay->moveEntryContract($entry, $to, $p->reason.' (staged by '.$p->drafted_by.')', $approver, $guard);
        } catch (\InvalidArgumentException $e) {
            if ($handled) {
                return ['error' => $e->getMessage()];
            }
            // A staged action on the call is refused before the guard runs and is transient: the
            // move stays pending, to be approved once that action is approved or denied.
            if ($p->entry_type === 'call' && $e->getMessage() === 'A staged action on this call is awaiting approval; approve or deny it first.') {
                return ['error' => $e->getMessage().' This move stays pending.'];
            }

            return $stale($e->getMessage());
        }

        if (($result['status'] ?? null) !== 'executed_with_fault') {
            $result['message'] = 'Time entry moved. '.($result['ledger']
                ? $this->ledgeredMoveStatement($result)
                : ($result['drawn_hours'] > 0
                    ? self::drawStatement($result['drawn_hours'], Contract::withTrashed()->find($result['to_contract_id'])?->name ?? "contract {$result['to_contract_id']}", true)
                    : 'It had no prepay ledger row and no prepay hours were drawn.'));
        }

        return ['success' => ($result['status'] ?? null) !== 'executed_with_fault'] + $result;
    }

    /**
     * The done move's statement for an entry that had a ledger row, on approvalEffect()'s
     * branches: the credit back to the old contract, and the debit on the new one only when a
     * debit linked to the entry was written there (moveEntryContract writes it only when the
     * new contract is hours prepay).
     *
     * @param  array{entry_type: string, entry_id: int, from_contract_id: ?int, to_contract_id: int, hours: float, ledger: bool}  $result
     */
    private function ledgeredMoveStatement(array $result): string
    {
        $name = fn (?int $id) => $id === null ? 'no contract' : (Contract::withTrashed()->find($id)?->name ?? "contract {$id}");
        $hours = number_format($result['hours'], 2).'h';
        $debited = PrepayTransaction::where($result['entry_type'] === 'note' ? 'ticket_note_id' : 'phone_call_id', $result['entry_id'])
            ->where('contract_id', $result['to_contract_id'])->exists();

        return 'It credited '.$hours.' back to '.$name($result['from_contract_id'])
            .($debited ? ' and debited '.$hours.' from '.$name($result['to_contract_id']).'.'
                : '; '.$name($result['to_contract_id']).' is not an hours-prepay contract, so nothing was debited.');
    }

    private function stagedFrom(TimeEntryMoveProposal $p): ?int
    {
        return $p->from_contract_id === null ? null : (int) $p->from_contract_id;
    }

    /** Whether the entry was moved after the proposal was staged. */
    private function movedSince(TimeEntryMoveProposal $p): bool
    {
        return \App\Models\ContractActivity::where('action', 'entry_moved_in')
            ->where('changes->entry_type', $p->entry_type)->where('changes->entry_id', (int) $p->entry_id)
            ->when($p->activity_watermark !== null,
                fn ($query) => $query->where('id', '>', $p->activity_watermark),
                fn ($query) => $query->where('created_at', '>=', $p->created_at))
            ->exists();
    }

    /**
     * What approving a held move does, as the cockpit card states it: the refusal, the
     * credit/debit pair for an entry with a ledger row (no debit when the new contract is
     * not hours prepay), or the draw for one without, with the invoiced advice.
     */
    public function approvalEffect(TimeEntryMoveProposal $p): string
    {
        $entry = $p->entry_type === 'note' ? TicketNote::find($p->entry_id) : PhoneCall::find($p->entry_id);
        $to = Contract::withTrashed()->find($p->to_contract_id);
        $toPrepay = $to && $to->has_prepay && ! $to->prepay_as_amount;
        $ledger = $entry ? PrepayTransaction::where($p->entry_type === 'note' ? 'ticket_note_id' : 'phone_call_id', $entry->id)->first() : null;
        $from = $ledger ? Contract::withTrashed()->find($ledger->contract_id) : null;

        if ($entry && $to && $p->entry_type === 'call' && $entry->ticket?->client_id !== null && $this->hasPendingCallAction((int) $entry->id)) {
            // moveEntryContract refuses this before any other check; approve() leaves the move pending.
            $effect = 'A staged action on this call is awaiting approval, so approving refuses this move and leaves it pending; approve or deny that action first.';
        } elseif (! $entry || ! $to || (int) $entry->ticket_id !== (int) $p->ticket_id
            || $this->loggedContractId($entry, $p->entry_type) !== $this->stagedFrom($p) || $this->movedSince($p)) {
            $effect = 'The entry changed since this move was staged, or it or the new contract is gone, so approving refuses the move as stale.';
        } elseif ((int) $to->client_id !== (int) Ticket::whereKey($p->ticket_id)->value('client_id')) {
            // moveEntryContract's own check: the new contract must belong to the ticket's
            // current client; approve() turns that refusal into a stale resolution.
            $effect = $to->name." is not a contract of the ticket's current client, so approving refuses the move as stale.";
        } elseif ($to->trashed() || $to->status !== ContractStatus::Active) {
            $effect = $to->name.' is no longer an active contract, so approving refuses the move.';
        } elseif ($ledger && (! $from || (int) $from->client_id !== (int) $to->client_id)) {
            $effect = 'Its ledger source contract is missing or belongs to another client, so approving refuses the move.';
        } elseif ($ledger && $ledger->hours === null) {
            $effect = 'Its ledger row is not in hours, so approving refuses the move.';
        } elseif ($ledger) {
            $hours = number_format(abs((float) $ledger->hours), 2).'h';
            $effect = 'Approving credits '.$hours.' back to '.$from->name
                .($toPrepay ? ' and debits '.$hours.' from '.$to->name.'.' : '; '.$to->name.' is not an hours-prepay contract, so nothing is debited.');
        } elseif ($toPrepay && ($draw = $this->drawHoursIfMoved($entry, null)) > 0) {
            $effect = self::drawStatement($draw, $to->name, false);
        } else {
            $effect = 'It has no prepay ledger row; approving draws no prepay hours.';
        }

        return $effect.' Nothing has moved yet.';
    }

    /** Refusals about the entry itself, not the contract; agentMove returns them without an error_code. */
    private function entryRefusal(TicketNote|PhoneCall $entry, string $type): ?string
    {
        if ($this->hasPendingMove($type, (int) $entry->id)) {
            return 'A staged contract move on this entry is awaiting approval.';
        }
        if ($entry instanceof TicketNote) {
            if ($entry->note_type === \App\Enums\NoteType::System) {
                return 'System notes cannot be moved.';
            }
            if ($entry->billedMinutes() <= 0) {
                return 'This note has no billed minutes to move.';
            }
            if (! $entry->is_billable) {
                return 'This note is not billable.';
            }
        }

        return null;
    }

    private function moveRefusal(Ticket $ticket, TicketNote|PhoneCall $entry, string $type, int $contractId): ?string
    {
        $active = Contract::whereKey($contractId)->where('client_id', $ticket->client_id)
            ->where('status', ContractStatus::Active)->exists();
        if (! $active) {
            return "contract_id must be an ACTIVE contract of this ticket's client; contract {$contractId} is not. Call list_client_contracts for valid ids.";
        }
        if ($this->loggedContractId($entry, $type) === $contractId) {
            return "That {$type} is already on contract {$contractId}.";
        }

        return null;
    }

    /** Hours an approved move would draw for an entry with no ledger row onto an hours-prepay contract. */
    private function stagedDrawHours(TicketNote|PhoneCall $entry, string $type, int $contractId): float
    {
        $target = Contract::find($contractId);
        if (! $target || ! $target->has_prepay || $target->prepay_as_amount) {
            return 0.0;
        }
        $ledger = PrepayTransaction::where($type === 'note' ? 'ticket_note_id' : 'phone_call_id', $entry->id)->first();

        return $this->drawHoursIfMoved($entry, $ledger);
    }

    private function loggedContractId(TicketNote|PhoneCall $entry, string $type): ?int
    {
        $ledger = PrepayTransaction::where($type === 'note' ? 'ticket_note_id' : 'phone_call_id', $entry->id)->value('contract_id');
        $id = $ledger ?? $entry->contract_id;

        return $id === null ? null : (int) $id;
    }

    private function hasPendingMove(string $type, int $id): bool
    {
        return TimeEntryMoveProposal::where('entry_type', $type)->where('entry_id', $id)
            ->where('state', 'pending')->exists();
    }

    private function hasPendingCallAction(int $callId): bool
    {
        return PhoneCallActionProposal::where('phone_call_id', $callId)->where('state', 'pending')->exists();
    }

    public function deny(int $id, User $approver): array
    {
        $changed = TimeEntryMoveProposal::whereKey($id)->where('state', 'pending')
            ->update(['state' => 'denied', 'handled_at' => now(), 'approved_by' => $approver->id]);

        return $changed ? ['success' => true, 'message' => 'Time entry move denied; nothing moved.'] : ['error' => 'Proposal already handled or not found.'];
    }
}
