<?php

namespace App\Services\Agent\Intake;

use App\Models\Client;
use App\Models\Person;
use App\Models\PhoneCall;

/**
 * Pure-read service that identifies a call's client/person via a safety-tiered cascade.
 *
 * SAFETY-CRITICAL: a wrong resolution routes a call to the wrong client.
 * The cascade therefore resolves ONLY on an unambiguous single match.
 * Any ambiguity (0 or >1 matches) falls through to the next stage or returns unresolved.
 * No writes, no side effects, no dispatch.
 */
class CallerResolver
{
    /** Minimum caller_identity_confidence to enter Stage 3 at all. */
    private const BASE_FLOOR = 0.60;

    /**
     * Extra floor for an uncorroborated global name match (no company to pin the client).
     * Higher confidence required because no second signal confirms which client this is.
     */
    private const GLOBAL_NAME_FLOOR = 0.75;

    /** Sentinel strings that indicate no real name was returned by the carrier. */
    private const UNKNOWN_NAMES = ['unknown', ''];

    /**
     * Resolve the caller's client/person for the given PhoneCall.
     *
     * Runs a 4-stage cascade, short-circuiting on the FIRST resolution:
     *   Stage 1 — existing assignment (set at call creation)
     *   Stage 2 — prior-call history by phone number
     *   Stage 3 — content identity (caller-identified name + company)
     *   Stage 4 — unresolved
     */
    public function resolve(PhoneCall $call): CallerResolution
    {
        // ── Stage 1: existing ────────────────────────────────────────────────
        // client_id is set by ResolveCallerFromPeople at call creation when the
        // number already matched a Person. Nothing left to do; return immediately.
        if ($call->client_id !== null) {
            return new CallerResolution(true, $call->client_id, $call->person_id, 'existing');
        }

        // ── Stage 2: prior-call history by number ────────────────────────────
        // Find the most recent other resolved call for this number.
        // The orWhere(to_number) is insurance for seeded/legacy rows where an outbound
        // row stored the customer number in to_number instead of from_number.
        // Single-match safety is inherent: we pick the most recent already-resolved call,
        // trusting that prior resolution was itself validated.
        // Guard on client_id (not person_id): a CLIENT is what Stage 2 reuses. A prior call
        // resolved to a client but no specific person (the 'content_company' outcome —
        // client_id set, person_id null) is a valid reuse; we carry the client over and let
        // person_id stay null. Also hardens the floor — never reuse a fully-unresolved prior.
        $num = $call->from_number;
        if ($num !== null && $num !== '') {
            $prior = PhoneCall::query()
                ->where('id', '!=', $call->id)
                ->whereNotNull('client_id')
                ->where(fn ($q) => $q->where('from_number', $num)->orWhere('to_number', $num))
                ->latest('id')
                ->first();

            if ($prior !== null) {
                return new CallerResolution(true, $prior->client_id, $prior->person_id, 'call_history');
            }
        }

        // ── Stage 3: content identity ────────────────────────────────────────
        // Only entered when caller_identity_confidence is at or above the base floor
        // AND at least one of name/company is a real, non-placeholder string.
        $confidence = (float) $call->caller_identity_confidence;
        if ($confidence < self::BASE_FLOOR) {
            return CallerResolution::unresolved();
        }

        $company = $this->blankToNull($call->caller_identified_company);
        $name = $this->blankToNull($call->caller_identified_name);

        // Treat "Unknown" (and similar) as if absent — carrier placeholder, not a real name.
        if ($name !== null && in_array(strtolower($name), self::UNKNOWN_NAMES, true)) {
            $name = null;
        }

        if ($company === null && $name === null) {
            return CallerResolution::unresolved();
        }

        // 3a — Company → client (must be EXACTLY 1 match; 0 or >1 = no company candidate).
        $companyClientId = null;
        if ($company !== null) {
            $matches = Client::search($company)->limit(2)->get();
            if ($matches->count() === 1) {
                $companyClientId = $matches->first()->id;
            }
            // 0 or 2 → ambiguous or not found; $companyClientId stays null
        }

        // 3b — Name → person (token-aware, cross-DB-safe; no CONCAT or raw SQL).
        // Single-match safety: resolve ONLY on exactly 1 match. Cross-client ambiguity
        // (same name in two different clients) → $people->count() > 1 → fall through.
        // Confidence floors: company-corroborated resolves at 0.60; global match at 0.75
        // (higher floor because no second signal pins the client).
        if ($name !== null) {
            $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
            $q = Person::query();

            if (count($parts) >= 2) {
                $q->where('first_name', 'like', '%'.$parts[0].'%')
                    ->where('last_name', 'like', '%'.end($parts).'%');
            } else {
                // Single token — match either name field
                $q->where(fn ($w) => $w->where('first_name', 'like', '%'.$parts[0].'%')
                    ->orWhere('last_name', 'like', '%'.$parts[0].'%'));
            }

            if ($companyClientId !== null) {
                $q->where('client_id', $companyClientId);
            }

            $people = $q->limit(2)->get();

            if ($people->count() === 1) {
                $person = $people->first();
                // Company-corroborated: base floor (0.60) is sufficient — company provides the second signal.
                // Global (no company): demand the higher 0.75 floor — no second signal to confirm client.
                if ($companyClientId !== null || $confidence >= self::GLOBAL_NAME_FLOOR) {
                    return new CallerResolution(true, $person->client_id, $person->id, 'content_name');
                }
            }
        }

        // 3c — Company-only: known client, specific person unknown.
        // Only reached when name resolution found 0 or >1 people, or no name was given.
        if ($companyClientId !== null) {
            return new CallerResolution(true, $companyClientId, null, 'content_company');
        }

        // ── Stage 4: unresolved ──────────────────────────────────────────────
        return CallerResolution::unresolved();
    }

    /** Return null for empty/whitespace-only strings; otherwise return trimmed value. */
    private function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
