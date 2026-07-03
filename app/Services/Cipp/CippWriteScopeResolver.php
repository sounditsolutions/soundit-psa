<?php

namespace App\Services\Cipp;

use App\Models\Client;
use App\Models\License;
use App\Models\LicenseType;
use App\Models\Person;
use App\Models\Ticket;

class CippWriteScopeResolver
{
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
