<?php

namespace App\Services;

use App\Enums\ContractorTimeSource;
use App\Models\ContractorTimeTransaction;
use App\Models\TicketNote;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ContractorTimeService
{
    public function addCredit(User $contractor, float $hours, string $description, User $recordedBy): ContractorTimeTransaction
    {
        return ContractorTimeTransaction::create([
            'user_id' => $contractor->id,
            'source' => ContractorTimeSource::ManualCredit,
            'hours' => abs($hours),
            'date' => now(),
            'description' => $description,
            'recorded_by' => $recordedBy->id,
        ]);
    }

    public function addDebit(User $contractor, float $hours, string $description, User $recordedBy): ContractorTimeTransaction
    {
        return ContractorTimeTransaction::create([
            'user_id' => $contractor->id,
            'source' => ContractorTimeSource::ManualDebit,
            'hours' => -abs($hours),
            'date' => now(),
            'description' => $description,
            'recorded_by' => $recordedBy->id,
        ]);
    }

    public function addInitialBalance(User $contractor, float $hours, string $description, User $recordedBy): ContractorTimeTransaction
    {
        return ContractorTimeTransaction::create([
            'user_id' => $contractor->id,
            'source' => ContractorTimeSource::InitialBalance,
            'hours' => $hours,
            'date' => now(),
            'description' => $description,
            'recorded_by' => $recordedBy->id,
        ]);
    }

    /**
     * Total hours credited (positive transactions).
     */
    public function getCreditTotal(User $contractor): float
    {
        return (float) ContractorTimeTransaction::where('user_id', $contractor->id)
            ->where('hours', '>', 0)
            ->sum('hours');
    }

    /**
     * Total hours debited (negative transactions), returned as a positive number.
     */
    public function getDebitTotal(User $contractor): float
    {
        return (float) abs(
            ContractorTimeTransaction::where('user_id', $contractor->id)
                ->where('hours', '<', 0)
                ->sum('hours')
        );
    }

    /**
     * Hours consumed by the contractor's time entries on tickets.
     * Computed from ticket_notes — not ledgered.
     */
    public function getConsumedHours(User $contractor, ?Carbon $from = null, ?Carbon $to = null): float
    {
        $query = TicketNote::where('author_id', $contractor->id)
            ->where('time_minutes', '>', 0);

        if ($from) {
            $query->where('noted_at', '>=', $from);
        }
        if ($to) {
            $query->where('noted_at', '<=', $to);
        }

        return round((float) $query->sum('time_minutes') / 60, 4);
    }

    /**
     * Current balance: credits - debits - consumed.
     */
    public function getBalance(User $contractor): float
    {
        $netTransactions = (float) ContractorTimeTransaction::where('user_id', $contractor->id)
            ->sum('hours');

        $consumed = $this->getConsumedHours($contractor);

        return round($netTransactions - $consumed, 4);
    }

    /**
     * Time entries by this contractor, with ticket info.
     */
    public function getTimeEntries(User $contractor, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $query = TicketNote::where('author_id', $contractor->id)
            ->where('time_minutes', '>', 0)
            ->with(['ticket:id,subject,client_id', 'ticket.client:id,name'])
            ->orderByDesc('noted_at');

        if ($from) {
            $query->where('noted_at', '>=', $from);
        }
        if ($to) {
            $query->where('noted_at', '<=', $to);
        }

        return $query->get();
    }

    /**
     * Hours consumed in the current calendar month.
     */
    public function getBurnRate(User $contractor): float
    {
        return $this->getConsumedHours(
            $contractor,
            now()->startOfMonth(),
            now()
        );
    }

    /**
     * All credit/debit transactions for the contractor, newest first.
     */
    public function getTransactions(User $contractor): Collection
    {
        return ContractorTimeTransaction::where('user_id', $contractor->id)
            ->with('recorder:id,name')
            ->orderByDesc('date')
            ->get();
    }
}
