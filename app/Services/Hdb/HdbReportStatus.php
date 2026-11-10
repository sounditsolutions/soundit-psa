<?php

namespace App\Services\Hdb;

/**
 * The whole vocabulary one HelpDesk Buttons press-report fetch may report.
 *
 * Status and reason symbols are controlled locally, never vendor text.
 *
 * DEGRADED is the case this enum exists for. docs/ARCHITECTURE.md § "Vendor
 * response shapes" rule 3 forbids a degraded read from returning a clean empty
 * result: diagnostics that arrived without a section this integration was
 * built to read is a BUG, not "that machine had no event log". So the payload
 * is still handed back — dropping it would be its own data loss — carried by a
 * status that is not Fetched and a reason that NAMES the missing sections.
 * A caller that treats Degraded as Fetched is writing a silent false all-clear
 * onto a ticket.
 */
enum HdbReportStatus: string
{
    /** Everything asked for arrived and was readable. */
    case Fetched = 'fetched';

    /**
     * The fetch succeeded but what came back is not whole — see the class
     * docblock. The payload is present; the reason names what is missing.
     */
    case Degraded = 'degraded';

    /** The API has not supplied a recognized complete status. */
    case Incomplete = 'incomplete';

    /** Note authorization, API ticket binding or redaction validation refused. */
    case Refused = 'refused';

    /** The API key is missing, rejected or lacks permission. */
    case Unauthenticated = 'unauthenticated';

    /** Transport failure or an upstream error; no usable report. */
    case Unreachable = 'unreachable';

    /**
     * The API answered, and what it answered is not a shape this client can
     * read. Never treated as "no data" — fail closed, and name it.
     */
    case Malformed = 'malformed';
}
