<?php

namespace App\Services\Tactical;

use Illuminate\Support\Carbon;

/**
 * The outcome of a single-asset detail read (syncDeviceDetail / refresh-now).
 *
 * Distinct from the bulk SyncResult (created/updated counts): refresh-now is a
 * per-asset READ whose UI (chunk 2) needs {status, freshAsOf, degraded,
 * message}. `ok=false` is a NORMAL outcome (agent offline / Tactical
 * unreachable) — the caller renders a degraded "couldn't reach the agent"
 * state, NOT a 500. The prior snapshot is left intact on failure.
 */
final readonly class DetailSyncResult
{
    public function __construct(
        public bool $ok,
        public ?string $status = null,
        public ?Carbon $freshAsOf = null,
        public ?string $message = null,
    ) {}

    public static function success(?string $status, ?Carbon $freshAsOf): self
    {
        return new self(ok: true, status: $status, freshAsOf: $freshAsOf);
    }

    public static function degraded(string $message, ?string $status = null, ?Carbon $freshAsOf = null): self
    {
        return new self(ok: false, status: $status, freshAsOf: $freshAsOf, message: $message);
    }
}
