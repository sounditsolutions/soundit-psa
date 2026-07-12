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
