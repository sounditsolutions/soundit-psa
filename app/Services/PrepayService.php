<?php

namespace App\Services;

use App\Enums\CallStatus;
use App\Enums\ContractStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PrepayTransactionSource;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\Invoice;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PrepayService
{
    /**
     * Create a prepay deposit from an invoice's prepaid time lines.
     * Called by InvoiceObserver::handlePaid() and BackfillPrepaidTime.
     */
    public function depositFromInvoice(Invoice $invoice, Contract $contract, ?User $user = null): ?PrepayTransaction
    {
        $userId = $user?->id ?? Auth::id();

        return DB::transaction(function () use ($invoice, $contract, $userId) {
            // This method takes the invoice lock before the contract lock.
            $lockedInvoice = Invoice::withTrashed()->whereKey($invoice->id)->lockForUpdate()->first();

            if ($lockedInvoice === null) {
                Log::warning('[Prepay] Invoice deposit refused', ['invoice_id' => $invoice->id]);

                return null;
            }

            if ($lockedInvoice->status !== InvoiceStatus::Paid) {
                return null;
            }

            // Guard: skip dollar-based contracts (auto-deposit is hours-based)
            if ($contract->has_prepay && $contract->prepay_as_amount) {
                Log::warning('[Prepay] Skipping deposit — contract uses dollar-based prepay', [
                    'contract_id' => $contract->id,
                    'invoice_id' => $invoice->id,
                ]);

                return null;
            }

            // Idempotency: at most ONE unmatched deposit at a time. Counted
            // against the reversals rather than tested for existence, because a
            // reversal is a compensating -hours row, not a delete: an existence
            // test would refuse for ever once an invoice had been reverted once,
            // so a QBO payment unapplied and then re-applied would leave the
            // invoice Paid, the client charged, and the hours they bought gone
            // (#1173). Balanced counts mean nothing is deposited right now.
            $deposits = PrepayTransaction::where('invoice_id', $invoice->id)
                ->where('source', PrepayTransactionSource::InvoiceDeposit)
                ->count();

            $reversals = PrepayTransaction::where('invoice_id', $invoice->id)
                ->where('source', PrepayTransactionSource::InvoiceReversal)
                ->count();

            if ($deposits > $reversals) {
                Log::debug('[Prepay] Deposit already exists for invoice', [
                    'invoice_id' => $invoice->id,
                ]);

                return null;
            }

            $totalMinutes = (int) $invoice->lines->sum('prepaid_time_minutes');

            if ($totalMinutes <= 0) {
                return null;
            }

            $totalHours = round($totalMinutes / 60, 4);

            // Initialize prepay on contract if this is the first deposit
            $this->ensurePrepayInitialized($contract);

            $txn = PrepayTransaction::create([
                'contract_id' => $contract->id,
                'source' => PrepayTransactionSource::InvoiceDeposit,
                'user_id' => $userId,
                'invoice_id' => $invoice->id,
                'date' => $invoice->invoice_date,
                'hours' => $totalHours,
                'description' => "Auto-deposit from {$invoice->invoice_number} ({$totalMinutes} min)",
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date,
                // A RE-deposit is a new lot restored after a reversal, so its life
                // cannot be measured from the original invoice date — see
                // restoredExpiry(). The first deposit is unchanged.
                'expiry_date' => $deposits > 0
                    ? $this->restoredExpiry($contract, $invoice)
                    : $this->expiryForCredit($contract, $invoice->invoice_date),
            ]);

            // Update denormalized balance on contract
            $contract->increment('prepay_total', $totalHours);
            $contract->increment('prepay_balance', $totalHours);

            Log::info('[Prepay] Auto-deposit from invoice', [
                'contract_id' => $contract->id,
                'invoice_id' => $invoice->id,
                'minutes' => $totalMinutes,
                'hours' => $totalHours,
            ]);

            return $txn;
        });
    }

    /**
     * Reverse a prepay deposit when its invoice stops being a paid invoice —
     * voided, or (#1173) reverted to open because QuickBooks reports it is
     * still owed. $description names which, so the prepay ledger does not tell
     * a technician an invoice was voided when it was not; absent, it keeps the
     * original void wording.
     *
     * PAIRED WITH THE DEPOSIT, NOT ONCE PER INVOICE. The reversal is a
     * compensating -hours row, not a delete, so "has this invoice ever been
     * reversed?" is the wrong question: answering it that way would make the
     * hours unrecoverable the moment a reverted invoice was paid again, which
     * on the two-way QBO pull (#1173) is an ordinary sequence — a payment
     * unapplied and re-applied. A reversal is refused only when every deposit
     * already carries one, so a repeat reversal (a void following a revert) is
     * still inert, an invoice holds the hours exactly while it is paid, and the
     * contract balance cannot drift. depositFromInvoice() counts the same pair.
     */
    public function reverseDepositForInvoice(
        Invoice $invoice,
        Contract $contract,
        ?string $description = null,
        ?User $user = null,
    ): ?PrepayTransaction {
        $userId = $user?->id ?? Auth::id();

        return DB::transaction(function () use ($invoice, $contract, $description, $userId) {
            // Take the same exclusive invoice lock as depositFromInvoice().
            $lockedInvoice = Invoice::withTrashed()->whereKey($invoice->id)->lockForUpdate()->first();

            if ($lockedInvoice === null) {
                Log::warning('[Prepay] Invoice reversal refused', ['invoice_id' => $invoice->id]);

                return null;
            }

            if ($lockedInvoice->status === InvoiceStatus::Paid) {
                return null;
            }

            // The LATEST deposit, not the first: an invoice may have been
            // deposited, reversed and deposited again across paid/open cycles, and
            // the live one is the last.
            $deposit = PrepayTransaction::where('invoice_id', $invoice->id)
                ->where('source', PrepayTransactionSource::InvoiceDeposit)
                ->latest('id')
                ->first();

            if (! $deposit) {
                return null;
            }

            // Idempotency: refuse only when every deposit already has a reversal.
            $deposits = PrepayTransaction::where('invoice_id', $invoice->id)
                ->where('source', PrepayTransactionSource::InvoiceDeposit)
                ->count();

            $reversals = PrepayTransaction::where('invoice_id', $invoice->id)
                ->where('source', PrepayTransactionSource::InvoiceReversal)
                ->count();

            if ($reversals >= $deposits) {
                Log::debug('[Prepay] Reversal already exists for invoice', [
                    'invoice_id' => $invoice->id,
                ]);

                return null;
            }

            $hours = abs((float) $deposit->hours);

            $txn = PrepayTransaction::create([
                'contract_id' => $contract->id,
                'source' => PrepayTransactionSource::InvoiceReversal,
                'user_id' => $userId,
                'invoice_id' => $invoice->id,
                'date' => now(),
                'hours' => -$hours,
                'description' => $description ?? "Reversal — invoice {$invoice->invoice_number} voided",
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date,
            ]);

            $contract->decrement('prepay_total', $hours);
            $contract->decrement('prepay_balance', $hours);

            Log::info('[Prepay] Deposit reversed for voided invoice', [
                'contract_id' => $contract->id,
                'invoice_id' => $invoice->id,
                'hours' => $hours,
            ]);

            return $txn;
        });
    }

    /**
     * Add a manual credit to a contract's prepay balance.
     */
    public function addManualCredit(
        Contract $contract,
        float $value,
        string $note,
        ?User $user = null,
        ?CarbonInterface $expiryDate = null,
    ): PrepayTransaction {
        $this->ensurePrepayInitialized($contract);

        $isAmount = $contract->prepay_as_amount;
        $userId = $user?->id ?? Auth::id();
        // Precedence: explicit override > contract policy > null (never expires).
        $expiry = $this->expiryForCredit($contract, now(), $expiryDate);

        return DB::transaction(function () use ($contract, $value, $note, $isAmount, $userId, $expiry) {
            $txn = PrepayTransaction::create([
                'contract_id' => $contract->id,
                'source' => PrepayTransactionSource::ManualCredit,
                'user_id' => $userId,
                'date' => now(),
                'hours' => $isAmount ? null : $value,
                'amount' => $isAmount ? $value : null,
                'description' => 'Manual credit',
                'note' => $note,
                'expiry_date' => $expiry,
            ]);

            $contract->increment('prepay_total', $value);
            $contract->increment('prepay_balance', $value);

            ContractActivity::create([
                'contract_id' => $contract->id,
                'user_id' => $userId,
                'action' => 'prepay_manual_credit',
                'changes' => [
                    'value' => $value,
                    'unit' => $isAmount ? 'dollars' : 'hours',
                    'note' => $note,
                    'new_balance' => (float) $contract->fresh()->prepay_balance,
                ],
                'created_at' => now(),
            ]);

            Log::info('[Prepay] Manual credit added', [
                'contract_id' => $contract->id,
                'value' => $value,
                'user_id' => $userId,
            ]);

            return $txn;
        });
    }

    /**
     * Add a manual debit (deduction) to a contract's prepay balance.
     */
    public function addManualDebit(
        Contract $contract,
        float $value,
        string $note,
        ?User $user = null,
    ): PrepayTransaction {
        $this->ensurePrepayInitialized($contract);

        $isAmount = $contract->prepay_as_amount;
        $userId = $user?->id ?? Auth::id();

        return DB::transaction(function () use ($contract, $value, $note, $isAmount, $userId) {
            // Store deductions as negative values in the hours/amount column
            $txn = PrepayTransaction::create([
                'contract_id' => $contract->id,
                'source' => PrepayTransactionSource::ManualDebit,
                'user_id' => $userId,
                'date' => now(),
                'hours' => $isAmount ? null : -abs($value),
                'amount' => $isAmount ? -abs($value) : null,
                'description' => 'Manual debit',
                'note' => $note,
            ]);

            $contract->increment('prepay_used', abs($value));
            $contract->decrement('prepay_balance', abs($value));

            ContractActivity::create([
                'contract_id' => $contract->id,
                'user_id' => $userId,
                'action' => 'prepay_manual_debit',
                'changes' => [
                    'value' => $value,
                    'unit' => $isAmount ? 'dollars' : 'hours',
                    'note' => $note,
                    'new_balance' => (float) $contract->fresh()->prepay_balance,
                ],
                'created_at' => now(),
            ]);

            Log::info('[Prepay] Manual debit added', [
                'contract_id' => $contract->id,
                'value' => $value,
                'user_id' => $userId,
            ]);

            return $txn;
        });
    }

    /**
     * Transfer prepay balance from one contract to another. Creates a matched
     * pair of ledger rows — a TransferOut debit on $from and a TransferIn credit
     * on $to — atomically, and logs a ContractActivity on each side.
     *
     * The pair mirrors manual debit/credit mechanics so the denormalized balance
     * columns stay consistent with recalculateBalanceLocked(): the transfer-out
     * counts toward $from's prepay_used, the transfer-in toward $to's
     * prepay_total. Transfers move balance between contracts of the SAME client
     * with the SAME tracking unit; the destination applies its own expiry policy
     * to the incoming credit.
     *
     * @return array{out: PrepayTransaction, in: PrepayTransaction}
     *
     * @throws \InvalidArgumentException when the transfer is not permitted
     */
    public function transfer(
        Contract $from,
        Contract $to,
        float $value,
        string $note,
        ?User $user = null,
    ): array {
        if ($from->id === $to->id) {
            throw new \InvalidArgumentException('Cannot transfer prepay to the same contract.');
        }

        if ($from->client_id !== $to->client_id) {
            throw new \InvalidArgumentException('Prepay can only be transferred between contracts of the same client.');
        }

        if (! $from->has_prepay || ! $to->has_prepay) {
            throw new \InvalidArgumentException('Both contracts must have prepay enabled to transfer.');
        }

        if ($from->prepay_as_amount !== $to->prepay_as_amount) {
            throw new \InvalidArgumentException('Prepay tracking units must match (hours vs dollars) to transfer.');
        }

        if ($value <= 0) {
            throw new \InvalidArgumentException('Transfer amount must be greater than zero.');
        }

        if (round($value, 4) > round((float) $from->prepay_balance, 4)) {
            throw new \InvalidArgumentException('Insufficient prepay balance to transfer.');
        }

        $isAmount = $from->prepay_as_amount;
        $userId = $user?->id ?? Auth::id();
        $unit = $isAmount ? 'dollars' : 'hours';

        return DB::transaction(function () use ($from, $to, $value, $note, $isAmount, $userId, $unit) {
            Contract::withTrashed()->whereIn('id', [$from->id, $to->id])->orderBy('id')->lockForUpdate()->get();

            $out = PrepayTransaction::create([
                'contract_id' => $from->id,
                'source' => PrepayTransactionSource::TransferOut,
                'user_id' => $userId,
                'date' => now(),
                'hours' => $isAmount ? null : -abs($value),
                'amount' => $isAmount ? -abs($value) : null,
                'description' => "Transfer to {$to->name} (#{$to->id})",
                'note' => $note,
            ]);

            // Transfer-out draws down the source like a debit: it reduces balance
            // and (matching recalculateBalanceLocked, which lumps every
            // non-expiration debit into "used") counts toward prepay_used.
            $from->increment('prepay_used', abs($value));
            $from->decrement('prepay_balance', abs($value));

            $in = PrepayTransaction::create([
                'contract_id' => $to->id,
                'source' => PrepayTransactionSource::TransferIn,
                'user_id' => $userId,
                'date' => now(),
                'hours' => $isAmount ? null : abs($value),
                'amount' => $isAmount ? abs($value) : null,
                'description' => "Transfer from {$from->name} (#{$from->id})",
                'note' => $note,
                // The destination applies its own forfeiture policy to the credit.
                'expiry_date' => $this->expiryForCredit($to, now()),
            ]);

            $to->increment('prepay_total', abs($value));
            $to->increment('prepay_balance', abs($value));

            ContractActivity::create([
                'contract_id' => $from->id,
                'user_id' => $userId,
                'action' => 'prepay_transfer_out',
                'changes' => [
                    'value' => $value,
                    'unit' => $unit,
                    'to_contract_id' => $to->id,
                    'to_contract' => $to->name,
                    'note' => $note,
                    'new_balance' => (float) $from->fresh()->prepay_balance,
                ],
                'created_at' => now(),
            ]);

            ContractActivity::create([
                'contract_id' => $to->id,
                'user_id' => $userId,
                'action' => 'prepay_transfer_in',
                'changes' => [
                    'value' => $value,
                    'unit' => $unit,
                    'from_contract_id' => $from->id,
                    'from_contract' => $from->name,
                    'note' => $note,
                    'new_balance' => (float) $to->fresh()->prepay_balance,
                ],
                'created_at' => now(),
            ]);

            Log::info('[Prepay] Balance transferred between contracts', [
                'from_contract_id' => $from->id,
                'to_contract_id' => $to->id,
                'value' => $value,
                'unit' => $unit,
                'user_id' => $userId,
            ]);

            return ['out' => $out, 'in' => $in];
        });
    }

    /**
     * Create or update a prepay debit from a ticket note's billable time.
     */
    public function debitFromTicketNote(TicketNote $note, bool $statusRetry = false): ?PrepayTransaction
    {
        if (! $note->exists || $note->getKey() === null) {
            return null;
        }

        $alertContract = null;
        $retry = false;
        $txn = DB::transaction(function () use ($note, &$alertContract, &$retry) {
            // Lock order: note -> existing prepay transaction -> contract.
            $lockedNote = TicketNote::withTrashed()->whereKey($note->id)->lockForUpdate()->first();
            if (! $lockedNote || $lockedNote->trashed() || ! $lockedNote->is_billable || $lockedNote->billedMinutes() <= 0) {
                $this->reverseDebitForTicketNote($note);

                return null;
            }
            $note = $lockedNote;

            // Note-level only (r2 diff:6): a contained note already carries no time, and staff
            // billable time on a held ticket debits prepay exactly as it would be invoiced.
            if ($note->isUnverifiedContactIntake()) {
                return null;
            }

            $hours = round($note->billedMinutes() / 60, 4);

            // A missing-key locking read can gap-lock unrelated new notes on InnoDB.
            $existing = PrepayTransaction::where('ticket_note_id', $note->id)->first();
            if ($existing) {
                $existing = PrepayTransaction::whereKey($existing->id)->lockForUpdate()->first();
            }

            $ticket = $note->ticket;
            $contract = null;
            $description = null;

            if ($ticket) {
                $subject = mb_substr($ticket->subject ?? 'No subject', 0, 60);
                $description = "Ticket #{$ticket->id}: {$subject}";
            }

            // The entry's stamp is the debit target (card I3EvQKUV §3). A new debit with no
            // stamp (logged before stamping, or while no contract resolved) resolves once
            // through ContractResolver and is stamped; ambiguity or pending verification holds the debit.
            // With neither, and no active contract, this pass neither debits it nor marks it held.
            if (! $existing) {
                $contract = $this->stampedOrResolvedNoteContract($note, $ticket);
                if (! $contract) {
                    return null;
                }
                // #4931: re-checked under the contract's own lock at debit time.
                $contract = $this->lockedActiveContract($contract, (int) $ticket->client_id, 'ticket_note_id', $note->id);
                if (! $contract) {
                    $retry = true;

                    return null;
                }
            }

            $alertContract = $contract;

            if (! $existing) {
                try {
                    $txn = PrepayTransaction::create([
                        'contract_id' => $contract->id,
                        'source' => PrepayTransactionSource::TicketTime,
                        'ticket_note_id' => $note->id,
                        'user_id' => $note->author_id,
                        'date' => $note->noted_at ?? $note->created_at,
                        'hours' => -$hours,
                        'description' => $description,
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    // The collided key now exists: use a current locking read to see the
                    // winner under InnoDB REPEATABLE READ, not the earlier empty snapshot.
                    $existing = PrepayTransaction::where('ticket_note_id', $note->id)->lockForUpdate()->first();
                    if (! $existing) {
                        throw $e;
                    }
                }
            }

            if ($existing) {
                $originalContract = $existing->contract()->withTrashed()->lockForUpdate()->first();
                $alertContract = $originalContract;
                if (! $originalContract) {
                    Log::warning('[Prepay] Ticket note difference refused', [
                        'ticket_note_id' => $note->id,
                        'contract_id' => $existing->contract_id,
                    ]);

                    return null;
                }
                if ($note->contract_id !== null && (int) $note->contract_id !== (int) $existing->contract_id) {
                    Log::warning('[Prepay] Ticket note stamp differs from ledger', [
                        'ticket_note_id' => $note->id,
                        'stamp_contract_id' => (int) $note->contract_id,
                        'ledger_contract_id' => (int) $existing->contract_id,
                    ]);

                    return null;
                }
                if ($note->contract_id === null) {
                    $this->stampNote($note, (int) $existing->contract_id);
                }
                // A ticket moved to another client keeps its earlier time on the old client's
                // contract (ruling Q5); that contract's ledger keeps its own description.
                $sameClient = $ticket !== null && (int) $ticket->client_id === (int) $originalContract->client_id;
                $oldHours = abs((float) $existing->hours);
                $existing->update([
                    'hours' => -$hours,
                    'description' => $sameClient ? $description : $existing->description,
                    'date' => $note->noted_at ?? $note->created_at,
                ]);

                $diff = $hours - $oldHours;
                if ($diff != 0 && $originalContract) {
                    $originalContract->increment('prepay_used', $diff);
                    $originalContract->decrement('prepay_balance', $diff);
                }

                return $existing;
            }

            $contract->increment('prepay_used', $hours);
            $contract->decrement('prepay_balance', $hours);

            Log::info('[Prepay] Ticket time debit', [
                'contract_id' => $contract->id,
                'ticket_note_id' => $note->id,
                'hours' => $hours,
            ]);

            return $txn;
        });

        if ($retry && ! $statusRetry) {
            // The contract stopped being active between resolution and debit (#4931): the
            // note re-resolves once, on committed state (its stale stamp no longer validates).
            return $this->debitFromTicketNote($note, true);
        }

        // Check alert threshold after transaction commits; a soft-deleted ledger contract
        // still takes the difference but is never alerted on.
        if ($alertContract) {
            $alertContract->refresh();
            if (! $alertContract->trashed()) {
                app(PrepayAlertService::class)->checkThreshold($alertContract);
            }
        }

        return $txn;
    }

    /**
     * A ticket note's debit target: its stamp when ContractResolver still resolves it
     * (an active contract of the ticket's client, on a ticket not awaiting contact-intake
     * verification), else one ContractResolver answer, which is stamped on the note when
     * it resolves. A stamp that does not resolve (expired, deleted, ticket moved, or the
     * ticket is awaiting verification) is never debited while the note is unledgered (r1 diff:4):
     * the note is re-resolved on this pass and debited to that answer only when it resolves;
     * ledgered time stays where it was debited. releaseHeldDebits() retries a held entry on
     * its triggers (see its docblock), including verification of a ticket that has a client.
     * When that re-resolution is NONE on a ticket not awaiting verification (no client, or the client
     * has no active contract), this method debits nothing, sets no hold marker and logs nothing;
     * a marker set by an earlier pass stays. Returns null when there is no ticket, the
     * resolution is unresolved, or the target is not an hours prepay contract.
     * Unresolved entries are held and marked for
     * releaseHeldDebits() when the client needs a contract chosen or the ticket
     * is waiting for verification.
     */
    private function stampedOrResolvedNoteContract(TicketNote $note, ?Ticket $ticket): ?Contract
    {
        if (! $ticket) {
            return null;
        }

        $resolver = app(ContractResolver::class);
        $resolution = $note->contract_id === null ? null : $resolver->forEntry($ticket, (int) $note->contract_id);
        if (! $resolution?->isResolved()) {
            $resolution = $resolver->forEntry($ticket);
        }

        if (! $resolution->isResolved()) {
            if ($resolution->isAmbiguous() || $ticket->isUnverifiedContactIntake()) {
                $this->markNoteHeld($note);
                Log::info('[Prepay] Ticket note debit held: '.$this->heldReason($note, $ticket, $resolution), [
                    'ticket_note_id' => $note->id,
                    'ticket_id' => $ticket->id,
                    'candidate_contract_ids' => $resolution->candidates->pluck('id')->all(),
                ]);
            }

            return null;
        }

        $this->stampNote($note, $resolution->contract->id);

        return $this->hoursPrepayOrNull($resolution->contract);
    }

    /**
     * The held badge and log reason: 'awaiting verification' or 'needs contract'.
     * $resolution, when given, must be forEntry($ticket) for this ticket; callers
     * that already hold it pass it so the ticket is not resolved again per entry.
     */
    public function heldReason(TicketNote|PhoneCall $entry, Ticket $ticket, ?ContractResolution $resolution = null): string
    {
        $resolver = app(ContractResolver::class);

        return $ticket->isUnverifiedContactIntake()
            && (! ($resolution ?? $resolver->forEntry($ticket))->isAmbiguous()
                || ($entry->contract_id !== null
                    && $resolver->isActiveContractOf($ticket->client_id, (int) $entry->contract_id)))
            ? 'awaiting verification'
            : 'needs contract';
    }

    private function stampNote(TicketNote $note, int $contractId): void
    {
        if ($note->contract_id !== null && (int) $note->contract_id === $contractId && $note->contract_held_at === null) {
            return;
        }

        // Query-builder write: stamping is not an edit and must not re-enter the observer.
        // A stamped note is no longer held (r1 diff:1).
        $stamp = ['contract_id' => $contractId, 'contract_held_at' => null];
        TicketNote::withTrashed()->whereKey($note->id)->update($stamp);
        foreach ($stamp as $key => $value) {
            $note->setAttribute($key, $value);
            $note->syncOriginalAttribute($key);
        }
    }

    private function markNoteHeld(TicketNote $note): void
    {
        if ($note->contract_held_at !== null) {
            return;
        }

        $heldAt = now();
        TicketNote::withTrashed()->whereKey($note->id)->update(['contract_held_at' => $heldAt]);
        $note->setAttribute('contract_held_at', $heldAt);
        $note->syncOriginalAttribute('contract_held_at');
    }

    /**
     * Re-run debits held for contract ambiguity (r1 diff:1, r2) or pending
     * verification (card VLyhWACq C10) for a client. Triggers include a default chosen
     * (ClientController); a contract created or restored active, changing status,
     * moved to another client, or soft- or force-deleted (Contract::booted); a
     * ticket given its own contract (TicketObserver), except a save inside
     * TicketObserver::withoutHeldRelease (changeTicketContract, behind both buttons
     * of the ticket's contract-change modal, and MCP update_ticket); a ticket moved
     * to another client (TicketObserver, even inside withoutHeldRelease);
     * a client merge (ClientService::mergeClients); contact-intake ticket
     * verification (ContactIntake\StaffWorkflow::act).
     * Only entries the hold path marked and that have no ledger row are touched,
     * never other unstamped history; each debit runs under the debit path's own
     * locks, so a repeated release cannot debit twice. An entry whose ticket
     * still resolves AMBIGUOUS or NONE is skipped and keeps its marker. Returns
     * the number debited.
     */
    public function releaseHeldDebits(int $clientId): int
    {
        $onClient = fn ($q) => $q->where('client_id', $clientId);
        $released = 0;
        $stillHeld = 0;
        $resolvable = [];
        $resolves = function (?Ticket $ticket, $stamp) use (&$resolvable): bool {
            if (! $ticket) {
                return false;
            }
            $resolver = app(ContractResolver::class);
            if ($stamp !== null && $resolver->forEntry($ticket, (int) $stamp)->isResolved()) {
                return true;
            }

            return $resolvable[$ticket->id] ??= $resolver->forEntry($ticket)->isResolved();
        };

        $notes = TicketNote::whereNotNull('contract_held_at')->whereHas('ticket', $onClient)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('prepay_transactions')
                ->whereColumn('prepay_transactions.ticket_note_id', 'ticket_notes.id'))
            ->orderBy('id')->get();
        foreach ($notes as $note) {
            // Still unresolved (ambiguity, no contract, or pending verification): keep the hold marker.
            if (! $resolves($note->ticket, $note->contract_id)) {
                $stillHeld++;

                continue;
            }
            try {
                if ($this->debitFromTicketNote($note)) {
                    $released++;
                }
            } catch (\Throwable $e) {
                Log::warning('[Prepay] Held ticket note debit not released', [
                    'ticket_note_id' => $note->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $calls = PhoneCall::whereNotNull('contract_held_at')->whereHas('ticket', $onClient)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('prepay_transactions')
                ->whereColumn('prepay_transactions.phone_call_id', 'phone_calls.id'))
            ->orderBy('id')->get();
        foreach ($calls as $call) {
            if (! $resolves($call->ticket, $call->contract_id)) {
                $stillHeld++;

                continue;
            }
            try {
                if ($this->debitFromPhoneCall($call)) {
                    $released++;
                }
            } catch (\Throwable $e) {
                Log::warning('[Prepay] Held phone call debit not released', [
                    'phone_call_id' => $call->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($notes->isNotEmpty() || $calls->isNotEmpty()) {
            Log::info('[Prepay] Held debits release run', [
                'client_id' => $clientId,
                'held_notes' => $notes->count(),
                'held_calls' => $calls->count(),
                'debited' => $released,
                'still_held' => $stillHeld,
            ]);
        }

        return $released;
    }

    /**
     * Move one time entry's contract (card I3EvQKUV PR 2, SPEC §4, LEDGER-SHAPE.md).
     *
     * Append-only (ruling Q4): no ledger row is deleted or re-created. The entry's
     * existing debit row stays on the old contract with its hours, date, description
     * and contract unchanged; only its entry link moves to moved_ticket_note_id /
     * moved_phone_call_id. A new EntryMovedOut credit on the old contract reverses it,
     * and a new debit linked to the entry is written on $to when $to is hours prepay.
     * Every later edit, unbill, delete or re-debit finds the entry's row by its link,
     * so it acts on $to only and never touches the old pair.
     *
     * Refused (InvalidArgumentException, nothing written) when $to is not an active
     * contract of the ticket's client, when the entry is already on $to, when its ledger
     * row's source contract is missing or belongs to another client (ruling Q5, #4988),
     * or when a staged action on the entry is awaiting approval.
     * An entry with no ledger row moves its stamp only; the ordinary debit path then
     * runs after commit, as on any entry edit. A stamp on another client's contract is
     * treated as no source contract: nothing is written on that contract, and the note
     * says 'another client's contract' (#5008).
     *
     * Lock order: entry -> prepay_transaction -> contracts in ascending id. $guard, when
     * given, runs inside the transaction once the entry and its ledger row are locked and
     * before the contracts are, with the locked entry, its row and the contract it is on
     * now; it refuses by throwing InvalidArgumentException, and nothing is written.
     *
     * drawn_hours is the draw recorded on the entry's ledger row after that follow-on debit (0 for
     * a ledgered move, or when no draw is found): the row the debit returned, or, after a caught
     * debit fault, the row read back from the ledger; null when that read-back also failed.
     * After an unledgered move, the system note and ContractActivity rows report the entry's re-read stamp (a held or unstamped entry as on no contract, to_contract_id null) and any found draw (in the note in place of the entry's priced hours, in the activity rows in place of 0).
     *
     * @return array{entry_type: string, entry_id: int, from_contract_id: ?int, to_contract_id: ?int, hours: float, ledger: bool, drawn_hours: ?float, status?: 'executed_with_fault', message?: string}
     */
    public function moveEntryContract(TicketNote|PhoneCall $entry, Contract $to, string $reason, User $by, ?\Closure $guard = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A reason is required to move a time entry.');
        }

        $isNote = $entry instanceof TicketNote;
        $type = $isNote ? 'note' : 'call';
        $key = $isNote ? 'ticket_note_id' : 'phone_call_id';
        $movedKey = $isNote ? 'moved_ticket_note_id' : 'moved_phone_call_id';
        $alert = [];
        $report = [];

        $result = DB::transaction(function () use ($entry, $to, $reason, $by, $guard, $isNote, $type, $key, $movedKey, &$alert, &$report) {
            $locked = $isNote
                ? TicketNote::withTrashed()->whereKey($entry->id)->lockForUpdate()->first()
                : PhoneCall::whereKey($entry->id)->lockForUpdate()->first();
            if (! $locked || ($isNote && $locked->trashed())) {
                throw new \InvalidArgumentException("That {$type} no longer exists.");
            }
            $ticket = $locked->ticket;
            if (! $ticket || $ticket->client_id === null) {
                throw new \InvalidArgumentException("That {$type} is not on a client's ticket.");
            }
            if (! $isNote && $this->hasPendingCallAction($locked->id)) {
                throw new \InvalidArgumentException('A staged action on this call is awaiting approval; approve or deny it first.');
            }

            $row = PrepayTransaction::where($key, $locked->id)->first();
            if ($row) {
                $row = PrepayTransaction::whereKey($row->id)->lockForUpdate()->first();
            }

            $fromId = $row ? (int) $row->contract_id : ($locked->contract_id === null ? null : (int) $locked->contract_id);
            if ($guard) {
                $guard($locked, $row, $fromId);
            }
            $ids = array_values(array_unique(array_filter([$fromId, (int) $to->id])));
            sort($ids);
            $contracts = Contract::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $target = $contracts->get($to->id);
            if (! $target || $target->trashed() || $target->status !== ContractStatus::Active
                || (int) $target->client_id !== (int) $ticket->client_id) {
                throw new \InvalidArgumentException("Contract {$to->id} is not an active contract of this ticket's client.");
            }
            if ($fromId === (int) $target->id) {
                throw new \InvalidArgumentException("That {$type} is already on {$target->name}.");
            }
            $from = $fromId === null ? null : $contracts->get($fromId);
            $foreignStampId = null;
            if (! $row && $from && (int) $from->client_id !== (int) $ticket->client_id) {
                $foreignStampId = $fromId;
                $from = null;
                $fromId = null;
            }

            $hours = 0.0;
            if ($row) {
                if (! $this->ledgerSourceMatchesClient($from, (int) $target->client_id)) {
                    // Ruling Q5 (#4988): time stays on the old client's contracts; a missing source is refused too.
                    throw new \InvalidArgumentException("That {$type}'s ledger source contract is missing or belongs to another client; nothing moved.");
                }
                if ($row->hours === null) {
                    throw new \InvalidArgumentException("That {$type}'s ledger row is not in hours.");
                }
                $hours = abs((float) $row->hours);

                // Detach: the original debit stays on the old contract, unchanged.
                PrepayTransaction::whereKey($row->id)->update([$key => null, $movedKey => $locked->id]);
                PrepayTransaction::create([
                    'contract_id' => $from->id,
                    'source' => PrepayTransactionSource::EntryMovedOut,
                    $movedKey => $locked->id,
                    'user_id' => $by->id,
                    'date' => now(),
                    'hours' => $hours,
                    'description' => mb_substr("Moved to {$target->name}: ".($row->description ?? $row->source?->label() ?? ''), 0, 255),
                    'note' => mb_substr($reason, 0, 255),
                ]);
                if ($this->hoursPrepayOrNull($from)) {
                    $from->decrement('prepay_used', $hours);
                    $from->increment('prepay_balance', $hours);
                    $alert[] = $from;
                }

                if ($this->hoursPrepayOrNull($target)) {
                    $subject = mb_substr($ticket->subject ?? 'No subject', 0, 60);
                    PrepayTransaction::create([
                        'contract_id' => $target->id,
                        'source' => $isNote ? PrepayTransactionSource::TicketTime : PrepayTransactionSource::PhoneCallTime,
                        $key => $locked->id,
                        'user_id' => $isNote ? $locked->author_id : $locked->answered_by,
                        'date' => $isNote ? ($locked->noted_at ?? $locked->created_at) : ($locked->started_at ?? $locked->created_at),
                        'hours' => -$hours,
                        'description' => $isNote ? "Ticket #{$ticket->id}: {$subject}" : "Phone call on Ticket #{$ticket->id}: {$subject}",
                    ]);
                    $target->increment('prepay_used', $hours);
                    $target->decrement('prepay_balance', $hours);
                    $alert[] = $target;
                }
            }

            // Query-builder stamp: a move is not an edit and must not re-enter the observers.
            ($isNote ? TicketNote::withTrashed() : PhoneCall::query())->whereKey($locked->id)
                ->update(['contract_id' => $target->id, 'contract_held_at' => null]);

            $changes = [
                'entry_type' => $type,
                'entry_id' => $locked->id,
                'ticket_id' => $ticket->id,
                'hours' => $hours,
                'ledger' => $row !== null,
                'reason' => $reason,
                'from_contract_id' => $fromId,
                'from_contract' => $from?->name,
                'to_contract_id' => $target->id,
                'to_contract' => $target->name,
            ];
            $changes['requested_contract_id'] = $target->id;
            foreach (array_filter([$from, $target]) as $contract) {
                $report['activity_ids'][] = ContractActivity::create([
                    'contract_id' => $contract->id,
                    'user_id' => $by->id,
                    'action' => $contract->id === $target->id ? 'entry_moved_in' : 'entry_moved_out',
                    'changes' => $changes,
                    'created_at' => now(),
                ])->id;
            }

            $what = $isNote ? "note #{$locked->id}" : "phone call #{$locked->id}";
            $report['body'] = fn (float $h, string $drew = '', ?string $destination = null) => 'Moved '.$what.' time ('.number_format($h, 2).' h) from '
                .($foreignStampId !== null ? "another client's contract" : ($from?->name ?? 'no contract')).' to '.($destination ?? $target->name).$drew.': '.$reason;
            $report['hours'] = $hours > 0 ? $hours : ($isNote ? $locked->pricedMinutes() / 60 : ($locked->effectiveDurationSeconds() ?? 0) / 3600);
            $effect = $row ? '; credited '.number_format($hours, 2).'h back to '.$from->name
                .($this->hoursPrepayOrNull($target)
                    ? ' and debited '.number_format($hours, 2).'h from '.$target->name
                    : '; no prepay hours debited from '.$target->name) : '';
            $body = $report['body']($report['hours'], $effect);
            $report['note_id'] = TicketNote::create([
                'ticket_id' => $ticket->id,
                'author_id' => $by->id,
                'body' => $body,
                'body_html' => \App\Helpers\MarkdownRenderer::render($body),
                'note_type' => \App\Enums\NoteType::System,
                'is_private' => true,
                'noted_at' => now(),
            ])->id;

            Log::info('[Prepay] Time entry contract moved', [
                $key => $locked->id,
                'foreign_stamp_contract_id' => $foreignStampId,
                'from_contract_id' => $fromId,
                'to_contract_id' => $target->id,
                'hours' => $hours,
                'ledger' => $row !== null,
                'user_id' => $by->id,
            ]);

            return [
                'entry_type' => $type,
                'entry_id' => (int) $locked->id,
                'from_contract_id' => $fromId,
                'to_contract_id' => (int) $target->id,
                'hours' => $hours,
                'ledger' => $row !== null,
            ];
        });

        $result['drawn_hours'] = 0.0;
        if (! $result['ledger']) {
            // No ledger row moved: the entry is now stamped on $to, and the ordinary debit
            // path decides (billable time on an hours-prepay $to is debited there).
            try {
                $fresh = $isNote ? TicketNote::find($entry->id) : PhoneCall::find($entry->id);
                $drawn = $fresh ? ($isNote ? $this->debitFromTicketNote($fresh) : $this->debitFromPhoneCall($fresh)) : null;
            } catch (\Throwable $e) {
                $result['status'] = 'executed_with_fault';
                // The fault can follow the debit's own commit (debitFromTicketNote and
                // debitFromPhoneCall check the alert threshold after it), so the entry's ledger
                // row is read back and any draw it records is reported as on the normal path.
                try {
                    $drawn = PrepayTransaction::where($key, $entry->id)->first();
                    $debitRecorded = $drawn && (int) $drawn->getAttribute($key) === (int) $entry->id && $drawn->hours !== null && (float) $drawn->hours < 0;
                } catch (\Throwable) {
                    $result['drawn_hours'] = null;
                    $debitRecorded = null;
                }
                $result['message'] = match ($debitRecorded) {
                    true => 'Time entry moved and its prepay debit was recorded, but a step after the debit failed; check the ledger before acting.',
                    false => 'Time entry moved, but a follow-on step failed and no prepay debit was recorded for it; check the ledger before acting.',
                    null => 'Time entry moved, but whether its follow-on prepay debit was recorded could not be confirmed; check the ledger before acting.',
                };
                Log::error('[Prepay] Contract move follow-on fault', [
                    $key => $entry->id,
                    'from_contract_id' => $result['from_contract_id'],
                    'to_contract_id' => $result['to_contract_id'],
                    'exception_class' => $e::class,
                    'debit_recorded' => $debitRecorded,
                ]);
                if ($debitRecorded !== true) {
                    return $result;
                }
            }
            if ($drawn && (int) $drawn->getAttribute($key) === (int) $entry->id && $drawn->hours !== null && (float) $drawn->hours < 0) {
                $result['drawn_hours'] = abs((float) $drawn->hours);
            } else {
                $drawn = null;
            }
            // A held entry can keep its stamp; it is reported as on no contract.
            $fresh = $isNote ? TicketNote::withTrashed()->find($entry->id) : PhoneCall::find($entry->id);
            $held = $fresh?->contract_id === null || (! $drawn && $fresh->contract_held_at !== null);
            $result['to_contract_id'] = $held ? null : (int) $fresh->contract_id;
            $this->reportMoveStamp($report, $result['to_contract_id'], $drawn, $result['drawn_hours']);
        }

        foreach ($alert as $contract) {
            $contract->refresh();
            if (! $contract->trashed() && $contract->has_prepay) {
                app(PrepayAlertService::class)->checkThreshold($contract);
            }
        }

        return $result;
    }

    /** Report the re-read stamp after an unledgered move's follow-on step. */
    private function reportMoveStamp(array $report, ?int $contractId, ?PrepayTransaction $drawn, float $hours): void
    {
        $name = $contractId === null ? null : (Contract::withTrashed()->find($contractId)?->name ?? "contract {$contractId}");
        $drawName = $drawn ? (Contract::withTrashed()->find($drawn->contract_id)?->name ?? "contract {$drawn->contract_id}") : null;
        $drew = $drawn ? '; it had no prepay ledger row, so '.number_format($hours, 2).'h was drawn from '.$drawName : '';
        $body = $report['body']($drawn ? $hours : $report['hours'], $drew, $name ?? 'no contract');
        // Query builder: a report correction is not an edit and must not re-enter the observers.
        TicketNote::whereKey($report['note_id'])->update(['body' => $body, 'body_html' => \App\Helpers\MarkdownRenderer::render($body)]);
        foreach (ContractActivity::whereKey($report['activity_ids'] ?? [])->get() as $activity) {
            $activity->update([
                'contract_id' => $activity->action === 'entry_moved_in' && $contractId !== null ? $contractId : $activity->contract_id,
                'changes' => array_merge($activity->changes ?? [], $drawn ? [
                    'hours' => $hours, 'drawn_hours' => $hours, 'drawn_contract_id' => (int) $drawn->contract_id,
                ] : [], [
                    'to_contract_id' => $contractId, 'to_contract' => $name,
                ]),
            ]);
        }
    }

    private function hasPendingCallAction(int $callId): bool
    {
        return \App\Models\PhoneCallActionProposal::where('phone_call_id', $callId)->where('state', 'pending')->exists();
    }

    /**
     * #4931: the debit target re-read under its own row lock, at debit time. Null when
     * it is no longer an active contract of the ticket's client (expired, cancelled,
     * soft-deleted or moved since it was resolved), so an inactive contract is never
     * drawn down. The caller then re-resolves once after its transaction: the stale
     * stamp fails ContractResolver's active check, so the entry takes the re-resolved answer
     * when it resolves, is held when that is ambiguous or the ticket is awaiting verification
     * (heldReason() gives the badge and log text), and otherwise (NONE on a ticket not awaiting
     * verification) is not debited.
     */
    private function lockedActiveContract(Contract $contract, int $clientId, string $entryKey, int $entryId): ?Contract
    {
        $locked = Contract::whereKey($contract->id)->lockForUpdate()->first();
        if ($locked && $locked->status === ContractStatus::Active && (int) $locked->client_id === $clientId) {
            return $locked;
        }

        Log::info('[Prepay] Debit target not active at debit', [
            $entryKey => $entryId,
            'contract_id' => $contract->id,
            'status' => $locked?->status?->value,
        ]);

        return null;
    }

    public function ledgerSourceMatchesClient(?Contract $source, int $clientId): bool
    {
        return $source !== null && (int) $source->client_id === $clientId;
    }

    public function isHoursPrepay(?Contract $contract): bool
    {
        if (! $contract || ! $contract->has_prepay || $contract->prepay_as_amount) {
            return false;
        }

        return true;
    }

    private function hoursPrepayOrNull(?Contract $contract): ?Contract
    {
        return $this->isHoursPrepay($contract) ? $contract : null;
    }

    /**
     * The contract a phone call's time belongs to, WITHOUT writing anything:
     * its existing ledger row's contract (even soft-deleted: debited time stays
     * where it was debited), else its stamp (phone_calls.contract_id) when ContractResolver
     * still resolves it (an active contract of the ticket's client, on a ticket not
     * awaiting contact-intake verification) (r1 diff:4), else the
     * ContractResolver answer for its ticket. Without a ledger row, returns
     * null when there is no ticket or no resolved contract, including contract
     * ambiguity and unverified contact intake. For those two reasons debitFromPhoneCall
     * can hold the debit pending contract choice or verification, respectively.
     */
    public function contractForPhoneCall(PhoneCall $call): ?Contract
    {
        $ledgerContractId = $call->exists
            ? PrepayTransaction::where('phone_call_id', $call->id)->value('contract_id')
            : null;
        if ($ledgerContractId !== null) {
            return Contract::withTrashed()->find($ledgerContractId);
        }

        $ticket = $call->ticket;
        if (! $ticket) {
            return null;
        }

        $resolver = app(ContractResolver::class);
        if ($call->contract_id !== null) {
            $stamped = $resolver->forEntry($ticket, (int) $call->contract_id);
            if ($stamped->isResolved()) {
                return $stamped->contract;
            }
        }

        $resolution = $resolver->forEntry($ticket);

        return $resolution->isResolved() ? $resolution->contract : null;
    }

    /**
     * THE money target for a phone call's prepay debit: the one contract
     * debitFromPhoneCall() would actually move hours on, or null if there is
     * none: contractForPhoneCall() narrowed to hours prepay. A ticket whose
     * contract changed after the call was stamped or debited does not move the
     * call's time (card I3EvQKUV). Read-only: PhoneCallActionService snapshots
     * this id for a staged action.
     */
    public function resolveContractForPhoneCall(PhoneCall $call): ?Contract
    {
        if (! $call->ticket) {
            return null;
        }

        return $this->hoursPrepayOrNull($this->contractForPhoneCall($call));
    }

    /**
     * Stamp a call with the contract its time belongs to (at link time).
     * Leaves the stamp unchanged when contractForPhoneCall returns null, for example
     * when the client needs a contract chosen or the ticket is waiting for verification.
     */
    public function stampPhoneCallContract(PhoneCall $call): ?Contract
    {
        $contract = $this->contractForPhoneCall($call);
        if ($contract && ((int) $call->contract_id !== $contract->id || $call->contract_held_at !== null)) {
            // A stamped call is no longer held (r1 diff:1).
            $stamp = ['contract_id' => $contract->id, 'contract_held_at' => null];
            PhoneCall::whereKey($call->id)->update($stamp);
            foreach ($stamp as $key => $value) {
                $call->setAttribute($key, $value);
                $call->syncOriginalAttribute($key);
            }
        }

        return $contract;
    }

    /**
     * Create or update a prepay debit from a phone call's billable duration.
     */
    public function debitFromPhoneCall(PhoneCall $call, bool $statusRetry = false): ?PrepayTransaction
    {
        $ticket = $call->ticket;

        if (! $ticket || ! $call->exists) {
            return null;
        }

        $subject = mb_substr($ticket->subject ?? 'No subject', 0, 60);
        $description = "Phone call on Ticket #{$ticket->id}: {$subject}";

        $alertContract = null;
        $retry = false;
        $txn = DB::transaction(function () use ($call, $ticket, $description, &$alertContract, &$retry) {
            // Lock the parent call row first so concurrent debits for one call queue
            // here. Without it, under InnoDB's default REPEATABLE READ a locking read
            // that finds no prepay row takes only a gap lock, both racers can hold
            // that gap lock, and their INSERTs then deadlock. The unique index is the
            // backstop for any writer that skips this lock.
            $locked = PhoneCall::whereKey($call->id)->lockForUpdate()->first();
            if (! $locked) {
                return null;
            }
            // The stamp and hold marker are read and written only under the call lock,
            // as the note path does (#4922): a racing debit cannot re-mark a call that
            // another debit has just ledgered.
            foreach (['contract_id', 'contract_held_at'] as $key) {
                $call->setAttribute($key, $locked->getAttribute($key));
                $call->syncOriginalAttribute($key);
            }

            $existing = PrepayTransaction::where('phone_call_id', $call->id)->lockForUpdate()->first();

            // Every unledgered call re-resolves here: a stamp that no longer validates is never
            // debited, and the call takes the re-resolved answer only when it resolves (r1 diff:4);
            // unresolved intake time waits for verification.
            // Ledgered time stays where it was debited.
            if (! $existing && ! $this->stampPhoneCallContract($call)) {
                $resolution = app(ContractResolver::class)->forEntry($ticket);
                if ($resolution->isAmbiguous() || (! $resolution->isResolved() && $ticket->isUnverifiedContactIntake())) {
                    // releaseHeldDebits() re-runs this marker on its triggers (see its docblock),
                    // including verification of a ticket that has a client.
                    PhoneCall::whereKey($call->id)->whereNull('contract_held_at')->update(['contract_held_at' => now()]);
                    Log::info('[Prepay] Phone call debit held: '.$this->heldReason($call, $ticket, $resolution), [
                        'phone_call_id' => $call->id,
                        'ticket_id' => $ticket->id,
                        'candidate_contract_ids' => $resolution->candidates->pluck('id')->all(),
                    ]);
                }
            }
            if ($existing && $call->contract_held_at !== null) {
                // A ledgered call is not held (#4922).
                PhoneCall::whereKey($call->id)->update(['contract_held_at' => null]);
                $call->setAttribute('contract_held_at', null);
                $call->syncOriginalAttribute('contract_held_at');
            }

            $contract = $this->resolveContractForPhoneCall($call);
            if (! $contract) {
                return null;
            }
            $alertContract = $contract;

            $durationSeconds = $call->billedSeconds();
            // Card 6ad7c912: a Voicemail call, or one whose stored duration is non-null and <= 0, never debits. Missed and null-duration calls are not gated.
            $neverDebits = $call->status === CallStatus::Voicemail
                || ($call->duration !== null && (int) $call->duration <= 0);
            if ($neverDebits || ! $call->is_billable || ! $durationSeconds || $durationSeconds <= 0) {
                $this->reverseDebitForPhoneCall($call);
                if (! $existing) {
                    $alertContract = null;
                }

                return null;
            }

            $hours = round($durationSeconds / 3600, 4);

            if (! $existing) {
                // #4931: a new debit re-checks its contract under the contract's own lock.
                $contract = $this->lockedActiveContract($contract, (int) $ticket->client_id, 'phone_call_id', $call->id);
                if (! $contract) {
                    $retry = true;
                    $alertContract = null;

                    return null;
                }
                try {
                    $txn = PrepayTransaction::create([
                        'contract_id' => $contract->id,
                        'source' => PrepayTransactionSource::PhoneCallTime,
                        'phone_call_id' => $call->id,
                        'user_id' => $call->answered_by,
                        'date' => $call->started_at ?? $call->created_at,
                        'hours' => -$hours,
                        'description' => $description,
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    $existing = PrepayTransaction::where('phone_call_id', $call->id)->lockForUpdate()->first();
                    if (! $existing) {
                        throw $e;
                    }
                }
            }

            if ($existing) {
                // Preserve the ledger's target even when this delivery resolves elsewhere.
                // Lock order remains call -> prepay transaction -> contract.
                $originalContract = $existing->contract()->withTrashed()->lockForUpdate()->first();
                $alertContract = $originalContract;
                // A call relinked to another client's ticket keeps its time on the old client's
                // contract (card I3EvQKUV); that contract's ledger keeps its own description (#4924).
                $ledgerClientId = Contract::withTrashed()->whereKey($existing->contract_id)->value('client_id');
                $sameClient = $ledgerClientId !== null && (int) $ticket->client_id === (int) $ledgerClientId;
                $oldHours = abs((float) $existing->hours);
                $existing->update([
                    'hours' => -$hours,
                    'description' => $sameClient ? $description : $existing->description,
                    'date' => $call->started_at ?? $call->created_at,
                ]);

                $diff = $hours - $oldHours;
                if ($diff != 0) {
                    if ($originalContract) {
                        $originalContract->increment('prepay_used', $diff);
                        $originalContract->decrement('prepay_balance', $diff);
                    } else {
                        Log::warning('[Prepay] Phone call difference skipped', [
                            'phone_call_id' => $call->id,
                            'contract_id' => $existing->contract_id,
                            'hours_difference' => $diff,
                        ]);
                    }
                }

                Log::info('[Prepay] Phone call ledger event', [
                    'action' => 'debit',
                    'phone_call_id' => $call->id,
                    'contract_id' => $existing->contract_id,
                    'txn_id' => $existing->id,
                    'acting_user_id' => Auth::id(),
                    'amount' => -$diff,
                ]);

                return $existing;
            }

            $contract->increment('prepay_used', $hours);
            $contract->decrement('prepay_balance', $hours);

            Log::info('[Prepay] Phone call ledger event', [
                'action' => 'debit',
                'phone_call_id' => $call->id,
                'contract_id' => $txn->contract_id,
                'txn_id' => $txn->id,
                'acting_user_id' => Auth::id(),
                'amount' => -$hours,
            ]);

            return $txn;
        });

        if ($retry && ! $statusRetry) {
            // Retry once on committed state.
            return $this->debitFromPhoneCall($call, true);
        }

        if ($alertContract) {
            $alertContract->refresh();
            if (! $alertContract->trashed()) {
                app(PrepayAlertService::class)->checkThreshold($alertContract);
            }
        }

        return $txn;
    }

    /**
     * Reverse a prepay debit for a phone call (unlinked, no longer billable, etc.).
     */
    public function reverseDebitForPhoneCall(PhoneCall $call): void
    {
        DB::transaction(function () use ($call) {
            $txn = PrepayTransaction::where('phone_call_id', $call->id)->lockForUpdate()->first();

            if (! $txn) {
                return;
            }

            $hours = abs((float) $txn->hours);
            $contract = $txn->contract()->withTrashed()->lockForUpdate()->first();

            $txn->delete();

            if ($contract) {
                $contract->decrement('prepay_used', $hours);
                $contract->increment('prepay_balance', $hours);
            }

            Log::info('[Prepay] Phone call ledger event', [
                'action' => 'reversal',
                'phone_call_id' => $call->id,
                'contract_id' => $txn->contract_id,
                'txn_id' => $txn->id,
                'acting_user_id' => Auth::id(),
                'amount' => $hours,
            ]);
        });
    }

    /**
     * Reverse a prepay debit for a ticket note (note deleted, time removed, or no longer billable).
     */
    public function reverseDebitForTicketNote(TicketNote $note): void
    {
        DB::transaction(function () use ($note) {
            // Include soft-deleted notes: the deleted observer reverses after deletion.
            TicketNote::withTrashed()->whereKey($note->id)->lockForUpdate()->first();
            $txn = PrepayTransaction::where('ticket_note_id', $note->id)->first();
            if ($txn) {
                $txn = PrepayTransaction::whereKey($txn->id)->lockForUpdate()->first();
            }

            if (! $txn) {
                return;
            }

            $hours = abs((float) $txn->hours);
            $contract = $txn->contract()->withTrashed()->lockForUpdate()->first();
            if (! $contract) {
                Log::warning('[Prepay] Ticket note reversal refused', [
                    'ticket_note_id' => $note->id,
                    'contract_id' => $txn->contract_id,
                ]);

                return;
            }

            $txn->delete();

            if ($contract) {
                $contract->decrement('prepay_used', $hours);
                $contract->increment('prepay_balance', $hours);
            }

            Log::info('[Prepay] Ticket time debit reversed', [
                'ticket_note_id' => $note->id,
                'hours' => $hours,
            ]);
        });
    }

    /**
     * Recalculate denormalized prepay fields from the transaction ledger.
     * Uses pessimistic locking to prevent concurrent modifications.
     */
    public function recalculateBalance(Contract $contract): void
    {
        if (! $contract->has_prepay) {
            return;
        }

        DB::transaction(function () use ($contract) {
            $locked = Contract::lockForUpdate()->find($contract->id);
            $this->recalculateBalanceLocked($locked);
        });
    }

    /**
     * Lock-free recalculation core. The caller MUST already hold a row lock /
     * open transaction on $contract (the public recalculateBalance() wrapper,
     * or PrepayExpirationService::expireContract()). Splitting this out avoids a
     * nested transaction + redundant double-lock when expiration recalculates
     * inline under its own lock.
     */
    public function recalculateBalanceLocked(Contract $contract): void
    {
        $field = $contract->prepay_as_amount ? 'amount' : 'hours';

        // An EntryMovedOut credit gives back hours a moved entry consumed (card I3EvQKUV
        // PR 2): it reverses usage, it is not a purchase, so it nets against prepay_used
        // rather than adding to prepay_total — the columns a reverse used to leave.
        $credits = (float) $contract->prepayTransactions()
            ->where($field, '>', 0)
            ->where(fn ($q) => $q->where('source', '!=', PrepayTransactionSource::EntryMovedOut)->orWhereNull('source'))
            ->sum($field);

        $movedBack = (float) $contract->prepayTransactions()
            ->where('source', PrepayTransactionSource::EntryMovedOut)
            ->where($field, '>', 0)
            ->sum($field);

        $debits = abs((float) $contract->prepayTransactions()
            ->where($field, '<', 0)
            ->sum($field));

        // Forfeited (expired) hours are a subset of the debits. Split them out so
        // prepay_used reflects only work consumption while prepay_expired tracks
        // forfeiture. Balance is unchanged: total − used − expired == total − |Σ−|.
        $expired = abs((float) $contract->prepayTransactions()
            ->where('source', PrepayTransactionSource::Expiration)
            ->where($field, '<', 0)
            ->sum($field));

        $consumed = round($debits - $expired - $movedBack, 4);
        $balance = round($credits + $movedBack - $debits, 4);

        $contract->update([
            'prepay_total' => $credits,
            'prepay_used' => $consumed,
            'prepay_expired' => $expired,
            'prepay_balance' => $balance,
        ]);

        Log::info('[Prepay] Balance recalculated from ledger', [
            'contract_id' => $contract->id,
            'total' => $credits,
            'used' => $consumed,
            'expired' => $expired,
            'balance' => $balance,
        ]);
    }

    /**
     * Expiry for a RE-deposit — prepaid hours restored to an invoice that was
     * reverted and then paid again (#1173).
     *
     * A second deposit only became reachable when the idempotency guard started
     * counting deposits against reversals, and the row it writes is a NEW lot:
     * PrepayExpirationService forfeits by expiry_date, so a lot created with an
     * expiry already in the past is swept away on the next run and the client
     * loses hours they were charged for — the same money-visible loss the
     * two-way pull exists to close, hidden behind a forfeiture row.
     *
     * The credit's life is therefore PAUSED while the invoice is not paid, not
     * spent: whatever was left of it when the reversal took the hours back runs
     * again from the moment they are restored. A paid → open → paid cycle inside
     * the window is neither shortened (the days the hours were not the client's
     * to use are given back) nor extended (only the unused remainder is carried,
     * so cycling a payment cannot mint life). A lot whose window had already run
     * out has no remainder to carry and is re-based on the restoration date
     * rather than created dead.
     */
    private function restoredExpiry(Contract $contract, Invoice $invoice): ?CarbonInterface
    {
        $policyExpiry = $this->expiryForCredit($contract, $invoice->invoice_date);

        if ($policyExpiry === null) {
            return null;
        }

        $restoredAt = now();

        // Measure against the LIVE lot's own expiry, not the original
        // invoice-date policy date. Each restoration writes a new expiry that
        // already carries the paused remainder, and the guard permits any
        // number of revert/re-pay cycles — so a second cycle measured against
        // the original date would discard the life the first restoration
        // granted and let the next expiration sweep forfeit hours the client
        // paid for. A live lot whose expiry_date is NULL never expires; its
        // restoration keeps that, whatever policy the contract has since
        // acquired.
        $deposit = PrepayTransaction::where('invoice_id', $invoice->id)
            ->where('source', PrepayTransactionSource::InvoiceDeposit)
            ->latest('id')
            ->first();

        if ($deposit !== null && $deposit->expiry_date === null) {
            return null;
        }

        $liveExpiry = $deposit?->expiry_date
            ? Carbon::parse($deposit->expiry_date)
            : $policyExpiry;

        $reversal = PrepayTransaction::where('invoice_id', $invoice->id)
            ->where('source', PrepayTransactionSource::InvoiceReversal)
            ->latest('id')
            ->first();

        $remainingDays = $reversal?->date
            ? (int) Carbon::parse($reversal->date)->diffInDays($liveExpiry, false)
            : 0;

        return $remainingDays > 0
            ? $restoredAt->copy()->addDays($remainingDays)
            : $this->expiryForCredit($contract, $restoredAt);
    }

    /**
     * Resolve the expiry date for a new credit. Precedence: explicit override >
     * the contract's prepay_expiry_months policy applied to $base > null (never
     * expires). Hours-based prepay only — dollar-based credits never expire.
     */
    private function expiryForCredit(
        Contract $contract,
        CarbonInterface|string|null $base,
        ?CarbonInterface $explicit = null,
    ): ?CarbonInterface {
        if ($explicit !== null) {
            return $explicit;
        }

        if ($contract->prepay_as_amount || ! $contract->prepay_expiry_months) {
            return null;
        }

        $base = $base ? Carbon::parse($base) : now();

        return $base->copy()->addMonths((int) $contract->prepay_expiry_months);
    }

    /**
     * Initialize prepay tracking on a contract if not already active.
     * Auto-deposit from invoices always creates hours-based prepay.
     */
    public function ensurePrepayInitialized(Contract $contract): void
    {
        if ($contract->has_prepay) {
            return;
        }

        $contract->update([
            'prepay_as_amount' => false,
            'prepay_total' => 0,
            'prepay_used' => 0,
            'prepay_balance' => 0,
        ]);

        $contract->refresh();
    }
}
