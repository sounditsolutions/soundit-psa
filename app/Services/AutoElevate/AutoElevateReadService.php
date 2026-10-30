<?php

namespace App\Services\AutoElevate;

use Carbon\CarbonImmutable;

/**
 * Stage 2 read service: companies and computers, paged to completion, each row proven
 * against the vendor's documented shape before it becomes a screen truth.
 *
 * Producer: AutoElevate Partner API (Beta) 1.0.0 OpenAPI, components/schemas/Company and
 * Computer (verified 2026-09-17), corroborated by a read-only production probe the same day:
 *   Company  {id: uuid, name: string, initials: string|null, managementSystemCompanyId:
 *             string|null, createdAt: int ms, updatedAt: int ms}
 *   Computer {id: uuid, machineName: string|null, operatingSystem: {name: string|null,
 *             version: string|null}|null, locationId: uuid, companyId: uuid,
 *             elevationMode: audit|live|policy|technicianBypass|null,
 *             lastCheckedInAt: int ms|null, createdAt: int ms, updatedAt: int ms}
 * Timestamps are EPOCH MILLISECONDS, never ISO strings or seconds.
 * `managementSystemCompanyId` is a partner-supplied free string (usually the company name
 * again) and is NOT a usable foreign key; matching is by normalized name + manual override.
 *
 * RATE LIMITS (#3420). What is known, and how:
 *   - Documented: the same OpenAPI's "Rate Limits" section (re-read 2026-09-25) gives a
 *     per-MSP, per-method, per-route leaky bucket, beta default 20 requests per minute
 *     (refill about one request every 3 s), answered with 429 + Retry-After, and "may change".
 *   - Measured 2026-09-23 on production: 57 back-to-back computer reads got 33 × 429;
 *     reads spaced 4-8 s apart got 8 of 8 ok. That fits a short-window limiter.
 *   - An hourly bucket (earlier comments here said "100/hour") is UNVERIFIED: it is not in
 *     the current document and has not been measured. Page-cost reasoning below still holds
 *     whatever the window; do not size anything to 100/hour.
 */
class AutoElevateReadService
{
    public const ELEVATION_MODES = ['audit', 'live', 'policy', 'technicianBypass'];

    /** Hard bound on pages per read: 50 × 200 = 10,000 rows, far above any measured count. */
    public const MAX_PAGES = 50;

    /**
     * Plausibility floor for a vendor timestamp: 2000-01-01T00:00:00Z in epoch ms.
     * AutoElevate did not exist before it, so nothing it reports can predate it. Its real
     * job is unit detection: a SECONDS value for any date this century (~1.7e9) read as
     * milliseconds lands in January 1970 — comfortably under this floor — so the commonest
     * unit error is refused instead of rendering as a plausible-looking 1970 check-in.
     */
    public const PLAUSIBLE_FLOOR_MS = 946684800000;

    public function __construct(private readonly AutoElevateClient $client) {}

    /**
     * Every company, sorted by name. Rows are {id, name} only.
     *
     * @return list<array{id: string, name: string}>
     *
     * @throws AutoElevateReadException
     */
    public function companies(): array
    {
        $rows = [];
        foreach ($this->allItems('/api/v1/companies', []) as $item) {
            $id = $item['id'] ?? null;
            $name = $item['name'] ?? null;
            if (! is_string($id) || ! $this->isUuid($id) || ! is_string($name)) {
                throw new AutoElevateReadException('row_drift');
            }
            $rows[] = ['id' => strtolower($id), 'name' => $name];
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Every computer scoped to one company, normalized for display.
     *
     * @return list<array{id: string, machine_name: ?string, os_name: ?string, os_version: ?string,
     *                    elevation_mode: ?string, elevation_mode_known: bool, last_checked_in_at: ?CarbonImmutable}>
     *
     * @throws AutoElevateReadException
     */
    public function computersForCompany(string $companyId): array
    {
        if (! $this->isUuid($companyId)) {
            throw new AutoElevateReadException('invalid_company_id');
        }

        $rows = [];
        foreach ($this->allItems('/api/v1/computers', ['companyId' => $companyId]) as $item) {
            $rows[] = $this->normalizeComputer($item, $companyId);
        }
        usort($rows, fn ($a, $b) => strcasecmp((string) $a['machine_name'], (string) $b['machine_name']));

        return $rows;
    }

    /**
     * One computer by id: GET /api/v1/computers/{id} ("Get a single computer", Partner API
     * 1.0.0 OpenAPI, re-read 2026-10-30). Its 200 body is the same Computer schema the list
     * returns, so it is proven through the same normalizeComputer(), INCLUDING the scope proof:
     * a row whose companyId is not $expectedCompanyId is `row_drift` and nothing is returned.
     * The vendor's 404 is deliberately ambiguous (missing, deleted or another MSP's), so the
     * caller must not read it as "gone". One request, never retried (AutoElevateClient::getOne).
     *
     * @return array{id: string, machine_name: ?string, os_name: ?string, os_version: ?string,
     *               elevation_mode: ?string, elevation_mode_known: bool, last_checked_in_at: ?CarbonImmutable}
     *
     * @throws AutoElevateReadException
     */
    public function computer(string $computerId, string $expectedCompanyId): array
    {
        if (! $this->isUuid($computerId)) {
            throw new AutoElevateReadException('invalid_computer_id');
        }
        if (! $this->isUuid($expectedCompanyId)) {
            throw new AutoElevateReadException('invalid_company_id');
        }

        $item = $this->client->getOne('/api/v1/computers/'.strtolower($computerId));
        try {
            $row = $this->normalizeComputer($item, $expectedCompanyId);
        } catch (AutoElevateReadException $e) {
            // normalizeComputer() is the ONLY scope proof. When it refused a row whose companyId
            // is a well-formed uuid of ANOTHER company, name that case (`other_company`) so the
            // caller can say "another client's device" instead of "unrecognised shape".
            $rowCompany = $item['companyId'] ?? null;
            if ($e->reason === 'row_drift' && is_string($rowCompany) && $this->isUuid($rowCompany)
                && strcasecmp($rowCompany, $expectedCompanyId) !== 0) {
                throw new AutoElevateReadException('other_company');
            }
            throw $e;
        }
        // The vendor must answer for the computer that was asked for.
        if ($row['id'] !== strtolower($computerId)) {
            throw new AutoElevateReadException('row_drift');
        }

        return $row;
    }

    /**
     * One documented Computer row → display row. Every consumed field is type-proven;
     * a wrong type is drift for the whole read (never filtered into apparent truth).
     *
     * @param  array<string, mixed>  $item
     * @return array{id: string, machine_name: ?string, os_name: ?string, os_version: ?string,
     *               elevation_mode: ?string, elevation_mode_known: bool, last_checked_in_at: ?CarbonImmutable}
     */
    public function normalizeComputer(array $item, string $expectedCompanyId): array
    {
        foreach (['id', 'machineName', 'operatingSystem', 'companyId', 'elevationMode', 'lastCheckedInAt'] as $required) {
            if (! array_key_exists($required, $item)) {
                throw new AutoElevateReadException('row_drift');
            }
        }

        $id = $item['id'];
        if (! is_string($id) || ! $this->isUuid($id)) {
            throw new AutoElevateReadException('row_drift');
        }

        // Scope proof: a row from an untrusted response must belong to the requested company.
        // Not optional — there is no caller, test or production, that may skip it.
        $rowCompany = $item['companyId'];
        if (! is_string($rowCompany) || ! $this->isUuid($rowCompany)
            || strcasecmp($rowCompany, $expectedCompanyId) !== 0) {
            throw new AutoElevateReadException('row_drift');
        }

        $machineName = $item['machineName'];
        if ($machineName !== null && ! is_string($machineName)) {
            throw new AutoElevateReadException('row_drift');
        }

        // Nested object or null — never a string.
        $os = $item['operatingSystem'];
        $osName = null;
        $osVersion = null;
        if ($os !== null) {
            if (! is_array($os) || array_is_list($os)
                || ! array_key_exists('name', $os) || ! array_key_exists('version', $os)
                || ($os['name'] !== null && ! is_string($os['name']))
                || ($os['version'] !== null && ! is_string($os['version']))) {
                throw new AutoElevateReadException('row_drift');
            }
            $osName = $os['name'];
            $osVersion = $os['version'];
        }

        $mode = $item['elevationMode'];
        if ($mode !== null && ! is_string($mode)) {
            throw new AutoElevateReadException('row_drift');
        }

        return [
            'id' => strtolower($id),
            'machine_name' => $machineName,
            'os_name' => $osName,
            'os_version' => $osVersion,
            'elevation_mode' => $mode,
            // An unrecognised non-null mode is shown verbatim and flagged, not hidden.
            'elevation_mode_known' => $mode === null || in_array($mode, self::ELEVATION_MODES, true),
            'last_checked_in_at' => self::fromEpochMs($item['lastCheckedInAt']),
        ];
    }

    /**
     * Epoch MILLISECONDS → UTC instant. The vendor's timestamps are integer ms since the
     * Unix epoch (`example: 1716900000000`); treating them as seconds lands in year 56,000
     * and an ISO parser rejects them. Null means "never reported in". Anything but int|null
     * is drift (`timestamp_drift`).
     *
     * An in-range integer is then held to a PLAUSIBILITY WINDOW, refused as
     * `timestamp_implausible`:
     *   floor   PLAUSIBLE_FLOOR_MS (2000-01-01Z) — nothing this vendor reports predates it.
     *           NOTE this also refuses epoch `0`, which the previous guard admitted and
     *           rendered as 1970-01-01. The vendor documents `null` for "never reported in",
     *           so a literal 0 is not a contract value; if a tenant is ever observed sending
     *           0 as a sentinel, the right answer is to map it to null here, not to widen the
     *           floor — rendering 1970-01-01 as a check-in date is the defect, not the cure.
     *   ceiling now + 1 year — a check-in cannot be meaningfully in the future. The year is
     *           deliberately far LOOSER than real clock skew (seconds to hours): the ceiling
     *           is a nonsense bound, not a skew bound, and is set wide on purpose so that no
     *           merely-odd value fails a whole tenant's read. Every unit slip this actually
     *           targets (seconds, micro, nano, .NET ticks) is caught by the floor or lands
     *           far beyond a year, so tightening it would add refusals without adding catches.
     *
     * BLAST RADIUS, stated because it is a real behaviour change: this throws for the whole
     * read, so ONE implausible row fails the entire tenant's list rather than degrading that
     * row. That matches the existing `timestamp_drift` escalation and C-56 (a degraded read
     * screams), but per-row quarantine would be the kinder shape and is a live follow-up.
     *
     * Measured 2026-09-18 on Carbon 3.11.1, which is why the window is a range and not a
     * mere magnitude cap: `CarbonImmutable::createFromTimestampMsUTC()` throws for NO
     * integer magnitude — PHP_INT_MAX renders as year 292278994 and 99999999999999999 as
     * year 3170843, both silently. The defect this guards is therefore always a rendered
     * absurdity, never an exception (an earlier claim that Carbon would 500 here is false).
     * The regression that will actually occur is a UNIT slip: `1716900000` (the example
     * value in SECONDS) renders as 1970-01-20 — a plausible-looking date in a "last checked
     * in" column, which a magnitude cap alone would pass. The floor catches it.
     */
    public static function fromEpochMs(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new AutoElevateReadException('timestamp_drift');
        }
        if ($value < self::PLAUSIBLE_FLOOR_MS || $value > CarbonImmutable::now()->addYear()->getTimestampMs()) {
            throw new AutoElevateReadException('timestamp_implausible');
        }

        return CarbonImmutable::createFromTimestampMsUTC($value);
    }

    /** Lowercase, strip everything non-alphanumeric — the match key used for auto-match. */
    public static function normalizeName(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($name)) ?? '';
    }

    /**
     * Walk every page of a list endpoint. Honours `totalCount` and the documented 200 cap:
     * asks for exactly MAX_TAKE, advances `skip` by the rows actually received, and stops
     * only when the received count reaches the vendor's latest `totalCount`. A short page
     * before that point, or more pages than MAX_PAGES, is drift — the caller gets an
     * exception, never a truncated list (C-56).
     *
     * Four ways this refuses rather than hands back a quietly wrong list:
     *   paging_over_cap        the FIRST page's `totalCount` already exceeds what this walk
     *                          can ever collect (MAX_PAGES × take = 10,000 rows). Known from
     *                          one request, so the tenant is named immediately instead of
     *                          after spending 49 more rate-limited requests (see RATE LIMITS).
     *   paging_incomplete      a short page while the vendor still claims more rows.
     *   paging_bound           MAX_PAGES spent without reaching the total. Still reachable,
     *                          and still needed: `totalCount` may GROW mid-walk, so a walk
     *                          that started under the cap can run out of pages. The first-page
     *                          check cannot see that; this one can.
     *   paging_count_mismatch  the unique rows collected do not equal the vendor's latest
     *                          `totalCount`. Duplicate ids across pages are the shape this
     *                          catches: an unstably-sorted vendor can repeat a row on page N+1
     *                          and omit another, which silently DROPS a machine while the row
     *                          count still looks right. De-duplicating alone would hide that
     *                          as a short list; reconciling turns it into a failed read.
     *                          What it does NOT prove: see reconciled().
     *
     * De-duplication is by `id`, but `skip` ADVANCES BY ROWS RECEIVED, never by unique rows
     * kept: `skip` is the vendor's cursor into its own result set. Advancing it by the smaller
     * unique count would re-request rows already seen, re-spending rate-limited requests (see
     * RATE LIMITS) and pushing the walk into `paging_bound` without ever reaching the end. (It would
     * not spin forever — the MAX_PAGES bound always terminates — so the cursor choice is about
     * coverage and request cost, not termination. Do not read it as the loop's safety net.)
     * On a repeat, the FIRST copy seen is kept and later copies are discarded; the vendor does
     * not promise a later page is a fresher snapshot, so neither ordering is defensibly "more
     * correct" and first-seen is simply the stated choice.
     * Rows whose `id` is absent or not a string are kept as-is for the per-row validators to
     * reject (`row_drift`); paging never silently discards a row it cannot key.
     *
     * @param  array<string, int|string>  $query
     * @return list<array<string, mixed>>
     */
    private function allItems(string $path, array $query): array
    {
        $items = [];
        $seenIds = [];
        $skip = 0;
        $take = AutoElevateClient::MAX_TAKE;
        $total = 0;

        // `$take`/`$skip` are OURS to set: PHP's `+` keeps the LEFT operand's keys, so a
        // caller passing either in $query would silently win the request while every bound
        // below (the over-cap threshold, the short-page test) kept computing on MAX_TAKE.
        // Refuse that rather than page against one number and reason about another.
        if (array_key_exists('take', $query) || array_key_exists('skip', $query)) {
            throw new AutoElevateReadException('paging_contract');
        }

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->client->getPage($path, ['take' => $take, 'skip' => $skip] + $query);
            $received = count($result['items']);
            $total = $result['totalCount'];

            if ($page === 0 && $total > self::MAX_PAGES * $take) {
                throw new AutoElevateReadException('paging_over_cap');
            }

            foreach ($result['items'] as $item) {
                $id = $item['id'] ?? null;
                if (is_string($id)) {
                    $key = strtolower($id);
                    if (isset($seenIds[$key])) {
                        continue;
                    }
                    $seenIds[$key] = true;
                }
                $items[] = $item;
            }

            // The vendor's cursor, not our kept count. See the docblock.
            $skip += $received;

            if ($skip >= $total) {
                // Reached the vendor's own total (documented as 0 once `skip` is past the end).
                return $this->reconciled($items, $total);
            }

            if ($received < $take) {
                // The vendor says more rows exist but handed back a short page.
                throw new AutoElevateReadException('paging_incomplete');
            }
        }

        throw new AutoElevateReadException('paging_bound');
    }

    /**
     * The collected unique rows must account for exactly the vendor's latest `totalCount`.
     * Fewer is consistent with rows having been repeated and others dropped; more means the
     * vendor handed back rows it does not admit to having. Either way the list is not the
     * tenant's machines, and a wrong list must scream rather than render (C-56).
     *
     * WHAT THIS DOES NOT PROVE. It is a CARDINALITY check, not a coverage check, and the
     * honest limit is worth stating because the reason label sounds stronger than it is:
     * any drift that removes one row and adds another distinct one leaves the count intact
     * and passes here. A vendor whose set SHRINKS mid-walk moves both sides of the
     * comparison together and can likewise reconcile cleanly while a machine is missing.
     * So this catches the repeat-and-drop shape #2115 was opened for; it is not a guarantee
     * that the list is complete. Only a vendor-side cursor or a stable sort would give that.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function reconciled(array $items, int $total): array
    {
        if (count($items) !== $total) {
            throw new AutoElevateReadException('paging_count_mismatch');
        }

        return $items;
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }
}
