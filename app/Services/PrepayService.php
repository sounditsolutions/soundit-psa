<?php

namespace App\Services;

use App\Enums\PrepayTransactionSource;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\Invoice;
use App\Models\PhoneCall;
use App\Models\PrepayTransaction;
use App\Models\TicketNote;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PrepayService
{
    /**
     * Create a prepay deposit from an invoice's prepaid time lines.
     * Called by BillingService::generateInvoice() after lines are created.
     */
    public function depositFromInvoice(Invoice $invoice, Contract $contract): ?PrepayTransaction
    {
        // Guard: skip dollar-based contracts (auto-deposit is hours-based)
        if ($contract->has_prepay && $contract->prepay_as_amount) {
            Log::warning('[Prepay] Skipping deposit — contract uses dollar-based prepay', [
                'contract_id' => $contract->id,
                'invoice_id' => $invoice->id,
            ]);

            return null;
        }

        // Idempotency: skip if deposit already exists for this invoice
        $existing = PrepayTransaction::where('invoice_id', $invoice->id)
            ->where('source', PrepayTransactionSource::InvoiceDeposit)
            ->exists();

        if ($existing) {
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
            'invoice_id' => $invoice->id,
            'date' => $invoice->invoice_date,
            'hours' => $totalHours,
            'description' => "Auto-deposit from {$invoice->invoice_number} ({$totalMinutes} min)",
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date,
            'expiry_date' => $this->expiryForCredit($contract, $invoice->invoice_date),
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
    }

    /**
     * Reverse a prepay deposit when its invoice is voided.
     */
    public function reverseDepositForInvoice(Invoice $invoice, Contract $contract): ?PrepayTransaction
    {
        // Find the original deposit
        $deposit = PrepayTransaction::where('invoice_id', $invoice->id)
            ->where('source', PrepayTransactionSource::InvoiceDeposit)
            ->first();

        if (! $deposit) {
            return null;
        }

        // Idempotency: skip if reversal already exists
        $existingReversal = PrepayTransaction::where('invoice_id', $invoice->id)
            ->where('source', PrepayTransactionSource::InvoiceReversal)
            ->exists();

        if ($existingReversal) {
            Log::debug('[Prepay] Reversal already exists for invoice', [
                'invoice_id' => $invoice->id,
            ]);

            return null;
        }

        $hours = abs((float) $deposit->hours);

        $txn = PrepayTransaction::create([
            'contract_id' => $contract->id,
            'source' => PrepayTransactionSource::InvoiceReversal,
            'invoice_id' => $invoice->id,
            'date' => now(),
            'hours' => -$hours,
            'description' => "Reversal — invoice {$invoice->invoice_number} voided",
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
     * Create or update a prepay debit from a ticket note's billable time.
     */
    public function debitFromTicketNote(TicketNote $note): ?PrepayTransaction
    {
        $ticket = $note->ticket;

        if (! $ticket) {
            return null;
        }

        // Priority: note's contract → ticket's contract → client's hours-based prepay contract
        $contract = $note->contract_id ? $note->contract : null;

        if (! $contract && $ticket->contract_id) {
            $contract = $ticket->contract;
        }

        if (! $contract && $ticket->client_id) {
            $contract = Contract::where('client_id', $ticket->client_id)
                ->where('status', 'active')
                ->whereNotNull('prepay_balance')
                ->where('prepay_as_amount', false)
                ->first();
        }

        if (! $contract || ! $contract->has_prepay || $contract->prepay_as_amount) {
            return null;
        }

        if (! $note->is_billable || ! $note->time_minutes || $note->time_minutes <= 0) {
            // If note is no longer billable/has no time, reverse any existing debit
            $this->reverseDebitForTicketNote($note);

            return null;
        }

        $hours = round($note->time_minutes / 60, 4);
        $subject = mb_substr($ticket->subject ?? 'No subject', 0, 60);
        $description = "Ticket #{$ticket->id}: {$subject}";

        $txn = DB::transaction(function () use ($contract, $note, $hours, $description) {
            $existing = PrepayTransaction::where('ticket_note_id', $note->id)->first();

            if ($existing) {
                // Update existing debit — adjust balance by difference
                $oldHours = abs((float) $existing->hours);
                $existing->update([
                    'hours' => -$hours,
                    'description' => $description,
                    'date' => $note->noted_at ?? $note->created_at,
                ]);

                $diff = $hours - $oldHours;
                if ($diff != 0) {
                    $contract->increment('prepay_used', $diff);
                    $contract->decrement('prepay_balance', $diff);
                }

                return $existing;
            }

            // Create new debit
            $txn = PrepayTransaction::create([
                'contract_id' => $contract->id,
                'source' => PrepayTransactionSource::TicketTime,
                'ticket_note_id' => $note->id,
                'user_id' => $note->author_id,
                'date' => $note->noted_at ?? $note->created_at,
                'hours' => -$hours,
                'description' => $description,
            ]);

            $contract->increment('prepay_used', $hours);
            $contract->decrement('prepay_balance', $hours);

            Log::info('[Prepay] Ticket time debit', [
                'contract_id' => $contract->id,
                'ticket_note_id' => $note->id,
                'hours' => $hours,
            ]);

            return $txn;
        });

        // Check alert threshold after transaction commits
        $contract->refresh();
        app(PrepayAlertService::class)->checkThreshold($contract);

        return $txn;
    }

    /**
     * Create or update a prepay debit from a phone call's billable duration.
     */
    public function debitFromPhoneCall(PhoneCall $call): ?PrepayTransaction
    {
        $ticket = $call->ticket;

        if (! $ticket) {
            return null;
        }

        // Resolve prepay contract: ticket's contract → client's hours-based prepay contract
        $contract = $ticket->contract_id ? $ticket->contract : null;

        if (! $contract && $ticket->client_id) {
            $contract = Contract::where('client_id', $ticket->client_id)
                ->where('status', 'active')
                ->whereNotNull('prepay_balance')
                ->where('prepay_as_amount', false)
                ->first();
        }

        if (! $contract || ! $contract->has_prepay || $contract->prepay_as_amount) {
            return null;
        }

        $durationSeconds = $call->effectiveDurationSeconds();
        if (! $call->is_billable || ! $durationSeconds || $durationSeconds <= 0) {
            $this->reverseDebitForPhoneCall($call);

            return null;
        }

        $hours = round($durationSeconds / 3600, 4);
        $subject = mb_substr($ticket->subject ?? 'No subject', 0, 60);
        $description = "Phone call on Ticket #{$ticket->id}: {$subject}";

        $txn = DB::transaction(function () use ($contract, $call, $hours, $description) {
            $existing = PrepayTransaction::where('phone_call_id', $call->id)->first();

            if ($existing) {
                $oldHours = abs((float) $existing->hours);
                $existing->update([
                    'hours' => -$hours,
                    'description' => $description,
                    'date' => $call->started_at ?? $call->created_at,
                ]);

                $diff = $hours - $oldHours;
                if ($diff != 0) {
                    $contract->increment('prepay_used', $diff);
                    $contract->decrement('prepay_balance', $diff);
                }

                return $existing;
            }

            $txn = PrepayTransaction::create([
                'contract_id' => $contract->id,
                'source' => PrepayTransactionSource::PhoneCallTime,
                'phone_call_id' => $call->id,
                'user_id' => $call->answered_by,
                'date' => $call->started_at ?? $call->created_at,
                'hours' => -$hours,
                'description' => $description,
            ]);

            $contract->increment('prepay_used', $hours);
            $contract->decrement('prepay_balance', $hours);

            Log::info('[Prepay] Phone call time debit', [
                'contract_id' => $contract->id,
                'phone_call_id' => $call->id,
                'hours' => $hours,
            ]);

            return $txn;
        });

        $contract->refresh();
        app(PrepayAlertService::class)->checkThreshold($contract);

        return $txn;
    }

    /**
     * Reverse a prepay debit for a phone call (unlinked, no longer billable, etc.).
     */
    public function reverseDebitForPhoneCall(PhoneCall $call): void
    {
        $txn = PrepayTransaction::where('phone_call_id', $call->id)->first();

        if (! $txn) {
            return;
        }

        $hours = abs((float) $txn->hours);
        $contract = $txn->contract;

        $txn->delete();

        if ($contract) {
            $contract->decrement('prepay_used', $hours);
            $contract->increment('prepay_balance', $hours);
        }

        Log::info('[Prepay] Phone call time debit reversed', [
            'phone_call_id' => $call->id,
            'hours' => $hours,
        ]);
    }

    /**
     * Reverse a prepay debit for a ticket note (note deleted, time removed, or no longer billable).
     */
    public function reverseDebitForTicketNote(TicketNote $note): void
    {
        $txn = PrepayTransaction::where('ticket_note_id', $note->id)->first();

        if (! $txn) {
            return;
        }

        $hours = abs((float) $txn->hours);
        $contract = $txn->contract;

        $txn->delete();

        if ($contract) {
            $contract->decrement('prepay_used', $hours);
            $contract->increment('prepay_balance', $hours);
        }

        Log::info('[Prepay] Ticket time debit reversed', [
            'ticket_note_id' => $note->id,
            'hours' => $hours,
        ]);
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

        $credits = (float) $contract->prepayTransactions()
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

        $consumed = round($debits - $expired, 4);

        $contract->update([
            'prepay_total' => $credits,
            'prepay_used' => $consumed,
            'prepay_expired' => $expired,
            'prepay_balance' => round($credits - $debits, 4),
        ]);

        Log::info('[Prepay] Balance recalculated from ledger', [
            'contract_id' => $contract->id,
            'total' => $credits,
            'used' => $consumed,
            'expired' => $expired,
            'balance' => round($credits - $debits, 4),
        ]);
    }

    /**
     * Resolve the expiry date for a new credit. Precedence: explicit override >
     * the effective expiry-months policy (per-contract override falling back to
     * the global `prepay_expiry_months` default) applied to $base > null (never
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

        $months = $contract->effectivePrepayExpiryMonths();

        if ($contract->prepay_as_amount || ! $months) {
            return null;
        }

        $base = $base ? Carbon::parse($base) : now();

        return $base->copy()->addMonths($months);
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
