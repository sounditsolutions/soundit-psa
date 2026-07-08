<?php

namespace App\Services\Tactical;

/**
 * Per-section AVAILABILITY of a signal group in an EndpointInsight (amendment A,
 * §11.7). DISTINCT from freshness: it answers "did we get this data at all?",
 * not "how old is it?".
 *
 *  - Live        — refreshed just now via a bounded live read.
 *  - Snapshot    — served from the tactical_assets snapshot / local DB (the
 *                  instant base). Honest, just not real-time.
 *  - Unavailable — the live read was attempted and failed (offline/timeout/HTTP
 *                  error). The signal could NOT be read; "couldn't fetch checks"
 *                  must never be presented as "0 failing checks" (clean).
 *
 * The crucial honesty rule: Unavailable != a clean/empty result.
 */
enum SignalState: string
{
    case Live = 'live';
    case Snapshot = 'snapshot';
    case Unavailable = 'unavailable';
}
