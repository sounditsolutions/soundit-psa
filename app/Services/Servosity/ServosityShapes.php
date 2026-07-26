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
 *    degradation to null + a why note. Failure → status schema_drift /
 *    unavailable, maps null — never a zero.
 * 2. (a) ServosityReadOnlyToolset::liveDrBackups() — GET dr-backups/?company=N
 *    → MCP live_dr_backups rows, per-device upstream_check (verified_live /
 *    upstream_missing), agent_linked. assertDrfEnvelope() + the toolset's
 *    assertDrBackupRows(): every documented DRBackup field the read consumes
 *    is proven by TYPE (incl. nested AgentSession.agent_session_id and
 *    ShadowProtectKey product_key/product_type), and the row's company URI
 *    must be a well-formed URI resolving to the REQUESTED company. Any
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
 *    assertCompanySummaryRow() (this class) + a type-proven next URL and a
 *    bounded page walk that THROWS rather than truncating (a truncated list
 *    must never read as "client gone" and zero its licenses). Any violation
 *    aborts the sync before any write: no upsert, no deactivation, no
 *    synced_at stamp — the read surface then serves the OLD counts with
 *    their honest staleness, never a freshly-stamped zero.
 * 5. (a) ServosityClient::getCompanies() → ServosityCompanyController +
 *    ClientIntegrationService (Settings mapping surfaces): same validator as
 *    seam 4. Drift throws ServosityShapeDriftException (a
 *    ServosityClientException), which those surfaces already catch into an
 *    error banner — a drifted response can no longer render as an empty
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
     * are each drift — never read as zero rows.
     */
    public static function assertDrfEnvelope(mixed $response, string $endpoint): void
    {
        if (! $response instanceof \stdClass || ! is_int($response->count ?? null) || ! is_array($response->results ?? null)) {
            throw new ServosityShapeDriftException("Servosity {$endpoint} response did not match the documented envelope (a JSON object with integer count + array results required).");
        }
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
