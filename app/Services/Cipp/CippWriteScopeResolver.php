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
