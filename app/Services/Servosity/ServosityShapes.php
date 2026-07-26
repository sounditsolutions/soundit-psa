<?php

namespace App\Services\Servosity;

/**
 * Shared Servosity response-shape proofs (psa-z30dv R5).
 *
 * PRODUCER: the official OpenAPI at
 * https://api.servosity.com/docs/?format=openapi (swagger 2.0, retrieved
 * 2026-07-26) — the producer source for a closed-source vendor per this
 * repo's vendor-shape rules. Each assertion cites the definition it
 * validates against. Assertion messages name fields and expected types
 * only — never response values (vendor bytes stay out of logs).
 *
 * ══ SERVOSITY PRODUCER-SEAM INVENTORY ═════════════════════════════════════
 * Every seam where Servosity bytes can become a user-visible or MCP-visible
 * truth claim, and its disposition: either (a) a validator citing the
 * documented producer shape runs before ANY projection, or (b) the seam
 * degrades to an explicit UNKNOWN that makes NO claim — no count, no zero,
 * no verified_live, no fresh stamp, no linkage, no deactivation. Keep this
 * list current when adding a Servosity consumer: an unlisted seam is the
 * defect (psa-z30dv round-5 ruling).
 *
 * 1. (a) ServosityReadOnlyToolset::fetchAllSummaryRows() → liveCompanyState()
 *    — GET companies/summary-ng/ → MCP live.account_counts / issue_counts /
 *    company_not_found. Per-page assertDrfEnvelope() (this class) + row
 *    object/integer-id proof; the requested company's REQUIRED count maps
 *    proven by validatedIntMap() (definitions.CompanySummaryNg) with per-map
 *    degradation to null + a why note. Page-walk COMPLETENESS —
 *    company_not_found and the truncation caveat are complete-list claims —
 *    is decided ONLY by the shared pagination proof (provenNextUrl(),
 *    enforced per page inside assertDrfEnvelope(), origin/path-bound to the
 *    resolved request URL): an undocumented `next` — wrong type, non-URI,
 *    or a URI that does not continue THIS request — is drift for the whole
 *    read, never "last page" and never "more pages".
 *    Failure → status schema_drift / unavailable, maps null — never a zero.
 * 2. (a) ServosityReadOnlyToolset::liveDrBackups() — GET dr-backups/?company=N
 *    → MCP live_dr_backups rows, per-device upstream_check (verified_live /
 *    upstream_missing), agent_linked. assertDrfEnvelope() + the toolset's
 *    assertDrBackupRows(): every documented DRBackup field the read consumes
 *    is proven by TYPE (incl. nested AgentSession.agent_session_id and
 *    ShadowProtectKey product_key/product_type), and the row's company URI
 *    must be a well-formed URI resolving to the REQUESTED company. The
 *    truncation decision — feeding upstream_missing vs unverified and the
 *    "verified zero" copy, both complete-list claims — consumes the SAME
 *    pagination proof (provenNextUrl(), origin/path-bound) plus the proven
 *    integer count, decided at fetch time inside the cached closure. Any
 *    failure → the whole read is schema_drift, every device unverified, no
 *    row projected.
 * 3. (b) ServosityReadOnlyToolset::jobRunHistory() — GET
 *    backup-jobs/{backup_id}/ is documented but its 200 response declares NO
 *    schema, so the endpoint is NOT QUERIED AT ALL: job_run_history is a
 *    constant status=unverifiable block carrying no count, no zero, and no
 *    outcome. psa-bh1i4 tracks capturing a real authenticated payload to
 *    unlock a proven read.
 * 4. (a) ServosityClient::getCompanies() → ServosityLicenseSyncService
 *    (servosity:sync-licenses cron + manual sync) → licenses table → license
 *    UI, MCP synced_account_counts (with the freshness trio), license-type
 *    billing quantities, and deactivateMissingClients() zeroing.
 *    Identity-preserving decode; per page assertDrfEnvelope() + strict
 *    assertCompanySummaryRow() (this class) + the SAME shared pagination
 *    proof as the live seams (provenNextUrl(): the documented URI string or
 *    null, URI format enforced AND origin/path-bound to the resolved
 *    request URL, so a foreign cursor cannot steer the walk — not a
 *    separate weaker check) and a bounded
 *    page walk that THROWS rather than truncating (a truncated list must
 *    never read as "client gone" and zero its licenses). Any violation
 *    aborts the sync before any write: no upsert, no deactivation, no
 *    synced_at stamp — the read surface then serves the OLD counts with
 *    their honest staleness, never a freshly-stamped zero.
 * 5. (a) ServosityClient::getCompanies() → ServosityCompanyController +
 *    ClientIntegrationService (Settings mapping surfaces): same validator +
 *    pagination proof as seam 4. Drift throws ServosityShapeDriftException
 *    (a ServosityClientException), which those surfaces already catch into
 *    an error banner — a drifted response can no longer render as an empty
 *    "no companies" list.
 * 6. (a, claim-limited) ServosityClient::isHealthy() → Integrations "Test
 *    connection" / servosity_connected_at. The claim is only "API reachable
 *    and parseable"; no shape field is projected, and invalid JSON throws
 *    (reads unhealthy).
 * 7. (out of scope — write path, psa-nfqd) ServosityDeploymentService +
 *    AssetController::enableServosityBackup + ServosityProvisionAsset /
 *    ServosityProvisionBackups + getConnectWiseDownloadUrl + the TOTP
 *    enrollment auto-detect in IntegrationsController: provisioning WRITES
 *    whose local records (assets.servosity_dr_backup_id) the read surface
 *    deliberately treats as LOCAL claims only — surfaced under the
 *    UNVERIFIABLE provisioning_freshness trio and reconciled against seam 2
 *    before any verified_live claim is made. Migrating that write path onto
 *    the audited action bus is psa-nfqd, not this read bead.
 * ══════════════════════════════════════════════════════════════════════════
 */
final class ServosityShapes
{
    /**
     * The documented DRF list envelope: a JSON OBJECT response REQUIRING
     * count (integer) + results (JSON ARRAY) — official OpenAPI
     * responses.200 for companies/summary-ng/ and dr-backups/. Callers pass
     * the identity-preserving decode (ServosityClient::getJson()), so `{}`
     * and `[]` are distinguishable here: a top-level JSON array, a `results`
     * that is a JSON object (even an empty `{}`), or a non-integer `count`
     * are each drift — never read as zero rows. The proof includes the
     * pagination cursor (provenNextUrl() below), bound to $requestUrl — the
     * absolute URL this envelope was fetched from: an envelope whose `next`
     * is unproven fails HERE, before any consumer can cache it as ok or
     * read a completeness claim from it.
     */
    public static function assertDrfEnvelope(mixed $response, string $endpoint, string $requestUrl): void
    {
        if (! $response instanceof \stdClass || ! is_int($response->count ?? null) || ! is_array($response->results ?? null)) {
            throw new ServosityShapeDriftException("Servosity {$endpoint} response did not match the documented envelope (a JSON object with integer count + array results required).");
        }
        self::provenNextUrl($response, $endpoint, $requestUrl);
    }

    /**
     * The documented DRF pagination cursor, proven before ANY completeness
     * claim (psa-z30dv.17/.18; origin/path binding .22): both documented
     * list endpoints declare `next` as a string with format uri, x-nullable
     * — so the only documented values are a URI string and null (absence is
     * the same serializer "no value"). Consumers read `next` as a
     * COMPLETENESS claim — "was that the whole list?" — and as the WALK
     * CURSOR (its query becomes the next page request), which together
     * decide verified zeros, company_not_found, upstream_missing,
     * truncation caveats and license deactivation. So an unproven cursor is
     * drift for the whole read in EVERY direction: a falsey non-null value
     * (false / 0 / "") must not end a walk as "complete", truthy junk
     * (array / object / non-URI string) must not read as "more pages", and
     * a syntactically valid URI that does not CONTINUE THIS REQUEST must
     * not steer the walk or complete it. The last is the R7 security
     * finding (psa-z30dv.22): an unrelated-origin cursor passed the R6
     * syntax-only proof, its query drove OUR page walk, and the requested
     * company's absence from that foreign-steered list minted
     * company_not_found — the exact false-clear class this surface exists
     * to refuse. The OpenAPI declares only the SYNTAX (string, format uri);
     * the binding is the semantic cursor-safety boundary on top of it,
     * required because the cursor participates in truth claims.
     *
     * $requestUrl is the absolute URL of the request that produced this
     * envelope (ServosityClient::resolvedRequestUrl() — derived via the
     * same base_uri resolution Guzzle itself applies, so it cannot drift
     * from the URL genuinely requested). The proven cursor must share its
     * ORIGIN (scheme + host + effective port) and its EXACT PATH: DRF
     * regenerates the request URL verbatim with an updated query, so any
     * divergence is undocumented behaviour and therefore drift.
     *
     * Returns the proven cursor: null = the documented end of the list,
     * string = a well-formed same-origin, same-path http(s) URL for the
     * next page. assertDrfEnvelope() runs this proof on every envelope;
     * consumers re-read the cursor ONLY through this method — never from
     * the raw field. `previous` is declared the same way but consumed by NO
     * seam, so it is deliberately not policed (the consumed-field rule;
     * unconsumed optional fields are a projection ceiling, not a drift
     * axis).
     */
    public static function provenNextUrl(\stdClass $response, string $endpoint, string $requestUrl): ?string
    {
        $next = $response->next ?? null;
        if ($next === null) {
            return null;
        }
        if (! is_string($next)
            || filter_var($next, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($next, PHP_URL_SCHEME)), ['https', 'http'], true)) {
            throw new ServosityShapeDriftException("Servosity {$endpoint} response carried a next-page cursor that is neither the documented URI string nor null — list completeness cannot be read from an unproven cursor.");
        }
        if (! self::cursorContinuesRequest($next, $requestUrl)) {
            throw new ServosityShapeDriftException("Servosity {$endpoint} response carried a next-page cursor that does not continue this request (a different origin or endpoint path) — a foreign cursor must not steer the walk or decide list completeness.");
        }

        return $next;
    }

    /**
     * Does a syntactically valid cursor CONTINUE the request that produced
     * it? Same origin — scheme and host compared case-insensitively (RFC
     * 3986 §6.2.2.1), port compared as the EFFECTIVE port (explicit, else
     * the scheme default) — and the exact request path, byte for byte: DRF
     * emits the request URL back with only the query changed, so a
     * trailing-slash, casing, or percent-encoding divergence is as
     * undocumented as a foreign host. The query is deliberately NOT
     * compared — it IS the cursor payload being proven safe to consume.
     * Neither the cursor value nor $requestUrl may appear in the drift
     * message above: the cursor is vendor bytes, and the request URL embeds
     * the configured base host (psa-z30dv.6 — both stay out of logs).
     */
    private static function cursorContinuesRequest(string $next, string $requestUrl): bool
    {
        $cursor = parse_url($next);
        $request = parse_url($requestUrl);
        if (! is_array($cursor) || ! is_array($request)) {
            return false;
        }

        $cursorScheme = strtolower($cursor['scheme'] ?? '');
        if ($cursorScheme === '' || $cursorScheme !== strtolower($request['scheme'] ?? '')) {
            return false;
        }
        $cursorHost = strtolower($cursor['host'] ?? '');
        if ($cursorHost === '' || $cursorHost !== strtolower($request['host'] ?? '')) {
            return false;
        }
        $defaultPort = $cursorScheme === 'https' ? 443 : 80;
        if (($cursor['port'] ?? $defaultPort) !== ($request['port'] ?? $defaultPort)) {
            return false;
        }
        $cursorPath = $cursor['path'] ?? '';

        return $cursorPath !== '' && $cursorPath === ($request['path'] ?? '');
    }

    /**
     * A strict CompanySummaryNg row — definitions.CompanySummaryNg REQUIRES
     * name (string, minLength 1) and account_counts / issue_counts (objects
     * of INTEGER values, additionalProperties: {type: integer}), plus the
     * read-only integer id every DRF list row carries and the license sync
     * joins clients on. Used by the write-adjacent getCompanies() path
     * (seams 4–5), where any violation must abort the caller outright — the
     * MCP read surface keeps its own softer per-map degradation (seam 1)
     * because it can scream per-section instead of aborting.
     */
    public static function assertCompanySummaryRow(mixed $row): void
    {
        if (! $row instanceof \stdClass) {
            throw new ServosityShapeDriftException('Servosity companies/summary-ng/ returned a row that is not an object (documented CompanySummaryNg shape).');
        }
        if (! is_int($row->id ?? null)) {
            throw new ServosityShapeDriftException('Servosity companies/summary-ng/ returned a row without an integer id (the read-only key rows are joined on).');
        }
        if (! is_string($row->name ?? null) || $row->name === '') {
            throw new ServosityShapeDriftException('Servosity companies/summary-ng/ returned a row without a non-empty string name (REQUIRED, minLength 1 in the documented CompanySummaryNg shape).');
        }
        self::assertIntegerMap($row->account_counts ?? null, 'account_counts');
        self::assertIntegerMap($row->issue_counts ?? null, 'issue_counts');
    }

    /**
     * Both count maps are REQUIRED objects of integer values. The empty
     * OBJECT `{}` is the documented "no counts" and passes; a JSON ARRAY
     * (`[]` included) or any non-integer value is drift — under the legacy
     * assoc decode `[]` collapsed into a clean empty map, which is exactly
     * the false verified zero this seam exists to refuse (psa-z30dv.15).
     */
    private static function assertIntegerMap(mixed $value, string $field): void
    {
        if (! $value instanceof \stdClass) {
            throw new ServosityShapeDriftException("Servosity companies/summary-ng/ returned a row whose REQUIRED {$field} is not the documented object of integer counts (a JSON array or scalar here is drift, not an empty map).");
        }
        foreach (get_object_vars($value) as $count) {
            if (! is_int($count)) {
                throw new ServosityShapeDriftException("Servosity companies/summary-ng/ returned a row whose {$field} carries a non-integer value (documented: an integer per product key).");
            }
        }
    }
}
