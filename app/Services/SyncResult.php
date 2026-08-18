<?php

namespace App\Services;

class SyncResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $deactivated = 0;

    public int $errors = 0;

    public array $errorMessages = [];

    public array $details = [];

    /**
     * Instructions the run deliberately retired: correct outcomes that an operator
     * still needs told about, such as a queued seat reduction discarded because the
     * subscription it was written against no longer exists.
     *
     * Deliberately NOT errors. recordError() feeds `$result->errors > 0 ? FAILURE :
     * SUCCESS` in the sync commands, so routing an expected outcome through it fails
     * the nightly run and teaches operators to ignore the exit code. A withdrawal is
     * loud in the summary and silent in the exit status.
     */
    public int $withdrawn = 0;

    public array $withdrawnMessages = [];

    /**
     * Clients the run deliberately did not sync at all — an AppRiver customer whose
     * type the partner API refuses by design, for instance. Their rows are left
     * frozen, so the operator has to be told which ones and why: a client that stops
     * syncing because it was mis-typed looks exactly the same from the outside, and
     * its seat counts are what an MSP bills from.
     *
     * Same channel discipline as $withdrawn — loud in the summary, silent in the exit
     * status. Routing a by-design omission through recordError() would fail the
     * nightly run and teach operators to ignore the exit code.
     */
    public int $skipped = 0;

    public array $skippedMessages = [];

    public function recordError(string $message): void
    {
        $this->errors++;
        $this->errorMessages[] = $message;
    }

    public function recordWithdrawn(string $message): void
    {
        $this->withdrawn++;
        $this->withdrawnMessages[] = $message;
    }

    public function recordSkipped(string $message): void
    {
        $this->skipped++;
        $this->skippedMessages[] = $message;
    }

    public function total(): int
    {
        return $this->created + $this->updated;
    }

    public function summary(): string
    {
        $parts = [];
        if ($this->created) {
            $parts[] = "{$this->created} created";
        }
        if ($this->updated) {
            $parts[] = "{$this->updated} updated";
        }
        if ($this->deactivated) {
            $parts[] = "{$this->deactivated} deactivated";
        }
        if ($this->withdrawn) {
            $parts[] = "{$this->withdrawn} withdrawn";
        }
        if ($this->skipped) {
            $parts[] = "{$this->skipped} skipped";
        }
        if ($this->errors) {
            $parts[] = "{$this->errors} errors";
        }

        return implode(', ', $parts) ?: 'no changes';
    }
}
