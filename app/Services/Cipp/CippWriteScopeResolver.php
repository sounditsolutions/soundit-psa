<?php

namespace App\Services\Cipp;

use App\Models\Asset;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Person;
use App\Models\Ticket;

class CippWriteScopeResolver
{
    private const INTUNE_DEVICE_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function resolveCippTenant(Client $client): string
    {
        $tenant = trim((string) $client->cipp_tenant_domain);
        if ($tenant === '') {
            throw new CippWriteScopeException('Client has no CIPP tenant mapping');
        }

        return $tenant;
    }

    public function resolveCippPerson(int $clientId, mixed $personIdValue): ResolvedCippPerson
    {
        $personId = $this->positiveInteger($personIdValue);
        if ($personId === null) {
            throw new CippWriteScopeException('person_id is required');
        }

        $person = Person::query()
            ->whereKey($personId)
            ->where('client_id', $clientId)
            ->first();

        if (! $person) {
            throw new CippWriteScopeException('Person not found or belongs to a different client');
        }

        $userId = trim((string) $person->cipp_user_id);
        $upn = trim((string) $person->cipp_upn);
        if ($userId === '' || $upn === '') {
            throw new CippWriteScopeException('Person has no CIPP user mapping');
        }

        return new ResolvedCippPerson($person, $userId, $upn);
    }

    /**
     * REFUSE a tenant-scoped write target that IS mapped to a PSA person.
     *
     * The tenant-scoped licence shape exists for a user with NO PSA person
     * record — its tool text and its approval card both state that as fact —
     * and it reaches upstream without confirm_upn, without any of the
     * person-scoped gates, and with a null person linkage in the audit.
     * Documented but unenforced, that made "type the address instead of the
     * person_id" a caller-selectable bypass of the person path's rails on a
     * billing write. The premise has to be ENFORCED, not merely described —
     * the same rule the shape mutual-exclusion guard already follows.
     *
     * EITHER mapping column MATCHES — the object id the SERVER read out of the
     * tenant against cipp_user_id, and the address against cipp_upn — so a
     * renamed UPN cannot walk around the check. But only a COMPLETE mapping
     * refuses, and that qualifier is load-bearing: this gate defers to the
     * person path, and resolveCippPerson() (above) requires BOTH columns,
     * throwing 'Person has no CIPP user mapping' when either is blank.
     * Half-mapped rows are system-produced, not hypothetical —
     * CippContactSyncService::syncUser() sets cipp_user_id unconditionally
     * while an array_filter drops cipp_upn when the tenant row carries no
     * userPrincipalName, and rows predating the enrichment migration carry a
     * null cipp_upn too. Refusing on one of those would close the tenant shape
     * while the person shape cannot express the target at all: NO path assigns
     * the seat, and the refusal sends the operator to the shape that is
     * guaranteed to refuse. A half-mapped person therefore stays on the tenant
     * shape, which served them before this gate existed; the fix for them is
     * repairing the person's mapping, not blocking a licence.
     *
     * INACTIVE PEOPLE DO NOT REFUSE, and that qualifier is load-bearing for the
     * same reason completeness is. The premise that used to justify refusing
     * them — "the licence person path uses the loose resolveCippPerson(), so it
     * can still name them" — is FALSE: that path resolves through
     * resolveActiveCippPerson() and refuses a deactivated person outright. The
     * stale sweep deactivates a leaver's row without ever clearing cipp_upn or
     * cipp_user_id, so a new starter created at the freed address matches a dead
     * mapping. Refusing here would close the tenant shape while the person shape
     * refuses too: NO path assigns the seat, and the refusal sends the operator
     * to the shape that is guaranteed to refuse — the exact deadlock the
     * completeness qualifier above exists to prevent, reintroduced through the
     * other door.
     *
     * There is also nothing left to bypass. The rails this gate protects (typed
     * confirm_upn, the person-scoped gates) belong to the person path, and an
     * inactive person has no person path to be diverted from. What still applies
     * to them is the tenant shape's own gates, in full: the address must be
     * present in the resolved tenant's LIVE user listing, and the account must be
     * enabled — so a genuine leaver whose M365 account is disabled is still
     * refused, by the gate that can actually tell the difference between a leaver
     * and their replacement.
     */
    public function assertNoPsaPersonMapping(int $clientId, string $upn, string $userId): void
    {
        $upn = mb_strtolower(trim($upn));
        $userId = mb_strtolower(trim($userId));
        if ($upn === '' && $userId === '') {
            return;
        }

        // COMPLETE mappings only — a person missing either column is one
        // resolveCippPerson() refuses, so refusing here too would leave the
        // target unassignable by every shape (see the docblock).
        //
        // THE MATCH AND THE COMPLETENESS TEST ARE BOTH DECIDED IN PHP, AND THAT
        // IS THE POINT. Both are questions resolveCippPerson() already answers,
        // and it answers them with PHP trim(), which strips tabs, newlines, CR,
        // NUL and vertical tab — while SQL TRIM/btrim strips the SPACE character
        // only, on sqlite (what phpunit.xml runs), MySQL and Postgres alike.
        // Asking either half in SQL forks one question into two answers, and it
        // fails in opposite — both wrong — directions:
        //
        //  - COMPLETENESS in SQL (whereRaw("TRIM(COALESCE(col,'')) <> ''"), the
        //    first cut): a cipp_user_id holding just a tab reads COMPLETE here
        //    and BLANK in the resolver, so this gate refuses the tenant shape,
        //    the person path refuses for want of a mapping, and the seat is
        //    unassignable by every route — the deadlock the completeness
        //    qualifier exists to prevent.
        //  - The MATCH in SQL (whereRaw('LOWER(cipp_upn) = ?'), the second cut):
        //    the sync stores these columns raw, so a cipp_upn of
        //    "alex@acme.example\n" is a COMPLETE, USABLE mapping as far as the
        //    resolver is concerned, yet it never equals the caller's trimmed
        //    value. The gate finds nothing and a fully PSA-mapped person walks
        //    onto the tenant shape — no confirm_upn, no person-scoped gates, a
        //    null person linkage in the audit — which is the exact bypass this
        //    gate exists to close, on a billing write.
        //
        // So SQL only NARROWS to candidates and never decides: the column
        // filters below are a strict superset of what PHP accepts (anything PHP
        // reads as non-blank is necessarily non-null and non-'' in SQL), and
        // every comparison that can admit or refuse is made in PHP, through the
        // SAME trim() the person resolver uses. One question, one dialect.
        // AND IT NARROWS TO CANDIDATES RATHER THAN HYDRATING THE CLIENT. Three
        // verify seats flagged that the previous cut filtered only on client_id
        // + non-blank columns and then ->get(), so every completely-mapped
        // Person of the client came back on every tenant-shape licence call.
        // LIKE '%needle%' is a NECESSARY condition for the PHP comparison to
        // succeed — a stored value that trims to the needle necessarily contains it — so
        // this cannot exclude a true match, and the exact decision still happens
        // in PHP. The needle is escaped because '%' and '_' are both ordinary in
        // a UPN local part; unescaped, a perfectly valid address becomes a
        // wildcard, which is the same hazard that put licenseTargetKey() on a
        // hash.
        // AND THE CASE FOLD IS PHP'S TOO — the LIKE narrowing that used to sit
        // here is GONE, deliberately, because it reintroduced this same defect
        // a third time in the same method. It read LOWER(cipp_upn) LIKE ?
        // against an mb_strtolower()'d needle: SQL LOWER() is ASCII-only on
        // sqlite and collation-dependent on MariaDB, while mb_strtolower folds
        // Unicode. A UPN whose case differs outside ASCII would be folded by
        // PHP and not by SQL, the LIKE would exclude the row, and a fully
        // PSA-mapped person would walk onto the tenant shape — the exact bypass
        // this gate exists to close. A narrowing predicate that can EXCLUDE a
        // true match is not an optimisation, it is the defect wearing an
        // index's clothes.
        //
        // So SQL narrows on client_id and non-blank columns only — conditions
        // that cannot disagree with PHP in the excluding direction — and PHP
        // decides everything, with the same trim() and the same mb_strtolower()
        // the person resolver uses. THE HYDRATION COST THREE SEATS FLAGGED IS
        // PAID FOR WITH A PROJECTION rather than with a predicate that can be
        // wrong: three columns, not whole models. Correctness first, and the
        // cost bounded by the client's own mapped-person count.
        $person = Person::query()
            ->select(['id', 'is_active', 'cipp_upn', 'cipp_user_id'])
            ->where('client_id', $clientId)
            ->where('cipp_upn', '<>', '')
            ->where('cipp_user_id', '<>', '')
            ->get()
            ->first(static function (Person $candidate) use ($upn, $userId): bool {
                // ACTIVE mappings only, for the same reason as COMPLETE ones and
                // decided in the same place: a person resolveActiveCippPerson()
                // would refuse is one this gate must not refuse either, or the
                // seat is unassignable by every shape (see the docblock). Decided
                // in PHP through the model's boolean cast rather than narrowed in
                // SQL, so this test cannot disagree with the person path's — the
                // one-question-one-dialect rule the rest of this method follows.
                if (! $candidate->is_active) {
                    return false;
                }

                $candidateUpn = mb_strtolower(trim((string) $candidate->cipp_upn));
                $candidateUserId = mb_strtolower(trim((string) $candidate->cipp_user_id));

                // COMPLETE mappings only — a person the resolver would refuse
                // for want of a mapping is one this gate must not refuse either.
                if ($candidateUpn === '' || $candidateUserId === '') {
                    return false;
                }

                return ($upn !== '' && $candidateUpn === $upn)
                    || ($userId !== '' && $candidateUserId === $userId);
            });

        if ($person) {
            throw new CippWriteScopeException('That target_upn is mapped to PSA person #'.$person->id.', so the tenant-scoped shape does not apply to it: that shape is for a tenant user with no PSA person record. Assign this licence with person_id + license_type_id + confirm_upn instead, so the person-scoped rails apply. Nothing was written.');
        }
    }

    /**
     * Resolve a person who RECEIVES access through a CIPP write (e.g. the
     * OneDrive handover successor). Everything resolveCippPerson() enforces,
     * plus the person must be active in the PSA: deactivated people routinely
     * keep their CIPP mapping, and granting company data to a former employee
     * is exactly the mistake this gate refuses (psa-zjpd deep re-review,
     * architecture/product finding). The offboarded OWNER of an action stays
     * on the looser resolver deliberately — being inactive mid-offboarding is
     * expected for them.
     */
    public function resolveActiveCippPerson(int $clientId, mixed $personIdValue, string $roleLabel = 'person'): ResolvedCippPerson
    {
        $resolved = $this->resolveCippPerson($clientId, $personIdValue);

        if (! $resolved->person->is_active) {
            throw new CippWriteScopeException(ucfirst($roleLabel).' is inactive in the PSA; access can only be granted to an active person. Choose an active '.$roleLabel.' (or reactivate this person first) and re-stage.');
        }

        return $resolved;
    }

    public function resolveCippLicense(int $clientId, mixed $licenseTypeIdValue): ResolvedCippLicense
    {
        $licenseTypeId = $this->positiveInteger($licenseTypeIdValue);
        if ($licenseTypeId === null) {
            throw new CippWriteScopeException('license_type_id is required');
        }

        $licenseType = LicenseType::query()
            ->whereKey($licenseTypeId)
            ->where('vendor', 'cipp_m365')
            ->where('is_active', true)
            ->first();

        if (! $licenseType) {
            throw new CippWriteScopeException('CIPP M365 license type not found');
        }

        $license = License::query()
            ->where('client_id', $clientId)
            ->where('license_type_id', $licenseType->id)
            ->where('status', 'active')
            ->first();

        if (! $license) {
            throw new CippWriteScopeException('Client has no active local license row for this CIPP M365 SKU');
        }

        $skuId = trim((string) ($license->vendor_ref ?: $licenseType->vendor_sku_id));
        if ($skuId === '') {
            throw new CippWriteScopeException('CIPP M365 license SKU is not mapped locally');
        }

        return new ResolvedCippLicense($licenseType, $license, $skuId);
    }

    /**
     * Resolve a licence from the UPSTREAM SKU id (the M365 GUID an operator can
     * actually read off cipp_list_licenses) rather than the local
     * license_types.id, which no read tool on the MCP surface emits.
     *
     * Same server-derives-the-identity property as resolveCippLicense(): the
     * caller supplies a claim, the server matches it against synced licence
     * rows and answers with the local objects. The client-entitlement gate is
     * deliberately unchanged — a SKU this client has no active local licence
     * row for is still refused, because that row is the PSA's assertion that
     * the client is billed for the seat.
     *
     * The three failures are reported distinctly on purpose: "SKU not
     * recognised" tells the caller to fix the argument, "no active local
     * licence row" tells them to go and look at the client's licences, and
     * "not mapped locally" is a data gap in the licence type itself. Collapsing
     * them is how a fixable argument reads as an entitlement problem.
     */
    public function resolveCippLicenseBySku(int $clientId, mixed $skuIdValue): ResolvedCippLicense
    {
        // Normalised ONCE, here, in the form every comparison below uses. The
        // previous cut lowered only inside the SQL predicate, so moving the
        // decision into PHP would have silently made the match case-SENSITIVE
        // for a caller typing a mixed-case SKU — a regression introduced by the
        // fix, which is exactly how this branch has produced most of its
        // defects. Caught by reading the assignment rather than by a test,
        // because no existing case types a mixed-case sku_id.
        $sku = is_scalar($skuIdValue) ? mb_strtolower(trim((string) $skuIdValue)) : '';
        if ($sku === '') {
            throw new CippWriteScopeException('sku_id is required');
        }

        // vendor_sku_id ONLY — it is the synced UPSTREAM M365 SKU id this
        // method's contract names. license_types.sku_id is an integer FK to the
        // internal billing `skus` table (the M365 string lives in
        // skus.sku_code), so matching a caller's upstream claim against it
        // compares two unrelated namespaces: a garbled or truncated numeric
        // sku_id would resolve a licence type the caller never named — possibly
        // a costlier one — and assign it on the direct path with no approver in
        // the loop. An unrecognised claim must refuse, not resolve sideways.
        // SAME DIALECT RULE AS assertNoPsaPersonMapping(), and I found this one
        // by sweeping for it rather than waiting for a panel to name it — the
        // point of that rule being that naming a pattern is not the same as
        // having swept it. The caller's claim is trimmed by licenseTargetParams()
        // and again above; vendor_sku_id is stored RAW by the licence sync, so a
        // value carrying trailing whitespace never equals the trimmed claim and
        // an operator holding the correct SKU is told no licence type matches
        // it. The failure direction is a false REFUSAL rather than a false
        // accept, which is why it is a usability defect and not a bypass — but
        // it is the same root, so it gets the same shape of fix: SQL narrows to
        // candidates (LIKE is a necessary condition for the PHP comparison, and
        // the needle is escaped because '%' and '_' occur in real SKU strings),
        // PHP decides with the same trim() everything else here uses.
        $licenseTypes = LicenseType::query()
            ->where('vendor', 'cipp_m365')
            ->where('is_active', true)
            // Narrowed on the ACTIVE/vendor columns only, for the same reason
            // the person gate above dropped its LIKE: LOWER() in SQL and
            // mb_strtolower() in PHP are different functions outside ASCII, so
            // a case-folding narrow can EXCLUDE a row PHP would accept. Here
            // that direction is a false refusal rather than a bypass, but it is
            // the same defect and it gets the same answer — SQL narrows on what
            // it cannot get wrong, PHP decides. The set is active cipp_m365
            // licence types, which is small by construction.
            ->get()
            ->filter(static fn (LicenseType $type): bool => mb_strtolower(trim((string) $type->vendor_sku_id)) === $sku)
            ->values();

        if ($licenseTypes->isEmpty()) {
            throw new CippWriteScopeException('No active CIPP M365 license type matches that sku_id');
        }
        if ($licenseTypes->count() > 1) {
            throw new CippWriteScopeException('That sku_id matches more than one active CIPP M365 license type; resolve the ambiguity locally before assigning');
        }

        /** @var LicenseType $licenseType */
        $licenseType = $licenseTypes->first();

        // AMBIGUITY REFUSES — the same rule the licence-type match above
        // applies one level up, and this level needs it just as much. The CIPP
        // sync upserts on (license_type_id, client_id, vendor_ref), so a
        // re-sync that changes vendor_ref leaves a SECOND active row for the
        // same client and licence type, and an unordered first() over those
        // rows picks a storage-engine-ordered winner: two identical direct
        // calls for two different contractors can send DIFFERENT — possibly
        // costlier — SKUs upstream, both reported as success, with no approver
        // in the loop. The identity dedup key hashes the RESOLVED SKU rather
        // than the caller's claim, so it can at least tell two such writes
        // apart — but nothing downstream can choose between two equally active
        // rows. There is no safe way to pick one, so refuse and name the fix.
        $licenses = License::query()
            ->where('client_id', $clientId)
            ->where('license_type_id', $licenseType->id)
            ->where('status', 'active')
            ->get();

        if ($licenses->isEmpty()) {
            throw new CippWriteScopeException('That SKU is known, but this client has no active local license row for it; the PSA has no record that the client is entitled to a seat');
        }
        if ($licenses->count() > 1) {
            throw new CippWriteScopeException('That sku_id matches more than one active local license row for this client, and those rows can map to different upstream SKUs; resolve the duplicate license rows locally before assigning');
        }

        /** @var License $license */
        $license = $licenses->first();

        $resolvedSkuId = trim((string) ($license->vendor_ref ?: $licenseType->vendor_sku_id));
        if ($resolvedSkuId === '') {
            throw new CippWriteScopeException('CIPP M365 license SKU is not mapped locally');
        }

        return new ResolvedCippLicense($licenseType, $license, $resolvedSkuId);
    }

    /**
     * Resolve one PSA asset into its server-derived Intune device identity for
     * a device-destructive CIPP write. Fail-closed on every gap: the asset must
     * exist in the caller's client, be active, carry a well-formed Intune
     * (M365) managedDevice GUID from the CIPP device sync, and have a hostname
     * (the human-verifiable device name the typed confirmation checks against).
     * The device GUID is canonicalized to lowercase so casing can never fork
     * the idempotency hash or the executed-dedup guard.
     */
    public function resolveIntuneAsset(int $clientId, mixed $assetIdValue): ResolvedIntuneDevice
    {
        $assetId = $this->positiveInteger($assetIdValue);
        if ($assetId === null) {
            throw new CippWriteScopeException('asset_id is required');
        }

        $asset = Asset::query()
            ->whereKey($assetId)
            ->where('client_id', $clientId)
            ->first();

        if (! $asset) {
            throw new CippWriteScopeException('Asset not found or belongs to a different client');
        }

        if (! $asset->is_active) {
            throw new CippWriteScopeException('Asset is not active; device actions are refused for inactive assets');
        }

        $deviceId = mb_strtolower(trim((string) $asset->m365_device_id));
        if ($deviceId === '') {
            throw new CippWriteScopeException('Asset has no Intune (M365) device mapping');
        }
        if (preg_match(self::INTUNE_DEVICE_ID_PATTERN, $deviceId) !== 1) {
            throw new CippWriteScopeException('Asset Intune (M365) device id is malformed; refresh the CIPP device sync before staging a device action');
        }

        $hostname = trim((string) $asset->hostname);
        if ($hostname === '') {
            throw new CippWriteScopeException('Asset has no hostname; set one before staging a device action so the typed confirmation can be verified');
        }

        return new ResolvedIntuneDevice($asset, $deviceId, $hostname);
    }

    /**
     * PROVE the resolved device belongs to the resolved person before a
     * device-destructive CIPP write (psa-zjpd deep-review, security finding):
     * without this, a staged request could pair person A's identity with
     * person B's same-client device and the approval readout would name the
     * wrong human over an irreversible wipe. Accepted proofs — either one
     * suffices, checked at staging and re-proven fresh at approval:
     *
     *   - an explicit asset↔person link (asset_person pivot, manual or auto);
     *   - the asset's RMM last logged-on user UNIQUELY identifying the same
     *     person (see rmmLastUserUniquelyIdentifies() for the strict rule).
     *
     * m365_device_owner_type carries no identity (company/personal only), so
     * it can never bind. Fails closed on any gap.
     */
    public function assertIntuneAssetBelongsToPerson(ResolvedIntuneDevice $device, ResolvedCippPerson $person): void
    {
        $personId = (int) $person->person->id;

        if ($device->asset->users()->where('person_id', $personId)->exists()) {
            return;
        }

        if ($this->rmmLastUserUniquelyIdentifies($device->asset, $person->person)) {
            return;
        }

        throw new CippWriteScopeException('This asset is not linked to this person in the PSA (no asset-user link, and the RMM last logged-on user does not uniquely identify them). Link the person to the asset — or correct the target — and re-stage the device action.');
    }

    /**
     * Whether the asset's RMM-reported last logged-on user IDENTIFIES this
     * person strictly enough to authorize a device-destructive write. The
     * loose UI helper (Asset::resolveLastUserPerson()) prefix-matches the
     * short username and takes the first hit — fine for a display suggestion,
     * not for a wipe proof: with duplicate local parts across domains
     * (alex@alpha…, alex@bravo…) first-match can "prove" the wrong person
     * (psa-zjpd deep re-review, security finding). Here the rule is
     * deterministic and fail-closed:
     *
     *   - DOMAIN\user prefixes are stripped (an AzureAD-joined device may
     *     report AZUREAD\user@tenant, which keeps its address form);
     *   - an address-form value (contains @) must EXACTLY equal a person's
     *     cipp_upn or email, case-insensitively;
     *   - a bare username must equal the local part of a person's cipp_upn
     *     or email, case-insensitively;
     *   - display names carry no account identity and prove nothing;
     *   - the match must be UNIQUE across the client: if any other person
     *     also matches, the signal is ambiguous and proves nothing — the
     *     operator must link the asset to the person explicitly instead.
     *
     * The candidate pool deliberately includes inactive people: the person
     * being offboarded is routinely already deactivated, and a duplicate
     * that was deactivated yesterday still makes the signal ambiguous.
     */
    private function rmmLastUserUniquelyIdentifies(Asset $asset, Person $person): bool
    {
        $lastUser = trim((string) $asset->last_user);
        if ($lastUser === '' || ! $asset->client_id) {
            return false;
        }

        if (str_contains($lastUser, '\\')) {
            $lastUser = trim(substr($lastUser, strrpos($lastUser, '\\') + 1));
            if ($lastUser === '') {
                return false;
            }
        }

        $needle = mb_strtolower($lastUser);

        $matchedIds = Person::query()
            ->where('client_id', $asset->client_id)
            ->where(fn ($query) => $query->whereNotNull('cipp_upn')->orWhereNotNull('email'))
            ->get(['id', 'cipp_upn', 'email'])
            ->filter(function (Person $candidate) use ($needle): bool {
                foreach ([$candidate->cipp_upn, $candidate->email] as $address) {
                    $address = mb_strtolower(trim((string) $address));
                    if ($address === '') {
                        continue;
                    }

                    $matched = str_contains($needle, '@')
                        ? $address === $needle
                        : str_starts_with($address, $needle.'@');
                    if ($matched) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('id')
            ->unique();

        return $matchedIds->count() === 1 && (int) $matchedIds->first() === (int) $person->id;
    }

    public function resolveTicketForHeldAction(int $clientId, mixed $ticketIdValue): Ticket
    {
        $ticketId = $this->positiveInteger($ticketIdValue);
        if ($ticketId === null) {
            throw new CippWriteScopeException('ticket_id is required for staged CIPP write actions');
        }

        $ticket = Ticket::find($ticketId);
        if (! $ticket || (int) $ticket->client_id !== $clientId) {
            throw new CippWriteScopeException('Ticket not found or belongs to a different client');
        }

        return $ticket;
    }

    public function resolveOptionalTicket(int $clientId, mixed $ticketIdValue): ?Ticket
    {
        if ($ticketIdValue === null || $ticketIdValue === '') {
            return null;
        }

        return $this->resolveTicketForHeldAction($clientId, $ticketIdValue);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
