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

    public function recordError(string $message): void
    {
        $this->errors++;
        $this->errorMessages[] = $message;
    }

    public function total(): int
    {
        return $this->created + $this->updated;
    }

    public function summary(): string
    {
        $parts = [];
        if ($this->created) $parts[] = "{$this->created} created";
        if ($this->updated) $parts[] = "{$this->updated} updated";
        if ($this->deactivated) $parts[] = "{$this->deactivated} deactivated";
        if ($this->errors) $parts[] = "{$this->errors} errors";

        return implode(', ', $parts) ?: 'no changes';
    }
}
