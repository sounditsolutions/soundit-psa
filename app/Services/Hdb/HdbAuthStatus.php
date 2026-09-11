<?php

namespace App\Services\Hdb;

/**
 * The whole vocabulary a HDB portal login attempt may report.
 *
 * Four cases, closed on purpose: the portal is an HTML site, so ANY value
 * derived from its response body is attacker-influenced text that would end up
 * in a flash message, a log line and — because the integrations page assigns
 * the result through `innerHTML` — in the DOM. Nothing from the response
 * escapes {@see HdbAuthClient}; only one of these four symbols does.
 */
enum HdbAuthStatus: string
{
    case Authenticated = 'authenticated';
    case Rejected = 'rejected';
    case Unreachable = 'unreachable';
    case NotConfigured = 'not_configured';
}
