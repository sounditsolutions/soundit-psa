<?php

namespace App\Services\ControlD;

use App\Models\Client;
use App\Models\User;
use App\Support\ControlDConfig;
use Illuminate\Support\Facades\Log;

/** B2 only. No caller, adoption, remap, retry, deletion or B1 code creation. */
class ControlDOnboardingOrganization
{
    public function __construct(private readonly ControlDClient $vendor) {}

    /**
     * All four producer-required fields are explicit caller choices: no contact,
     * MFA or analytics-region default is inferred from a client or parent account.
     * The User must be server-resolved, never reconstructed from external input.
     */
    public function create(User $actor, int $clientId, #[\SensitiveParameter] string $name, #[\SensitiveParameter] string $contactEmail, int $requireMfa, string $statsEndpoint): void
    {
        $connection = (new Client)->getConnection();
        if ($connection->transactionLevel() > 0) {
            throw new ControlDClientException('Organization onboarding requires an independent transaction.');
        }
        $this->authorize($actor);
        // Do not trust stale/modified model attributes, even before the vendor call.
        $this->authorize(User::query()->find($actor->getKey()));
        $this->unmapped(Client::query()->find($clientId));
        if (! ControlDConfig::isEnabled()) {
            throw new ControlDClientException('Control D is disabled.');
        }
        // Pre-flight the transport credential: a missing key sends nothing, so it is a
        // definite local refusal, never an uncertain outcome that needs reconciliation.
        if (! $this->vendor->isConfigured()) {
            throw new ControlDClientException('Control D parent transport is unconfigured.');
        }
        if (trim($name) === '' || trim($name) !== $name || mb_strlen($name) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $name)
            || ! filter_var($contactEmail, FILTER_VALIDATE_EMAIL)
            || ! in_array($requireMfa, [0, 1], true)
            || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $statsEndpoint)) {
            throw new ControlDClientException('Explicit organization name, contact email, MFA choice and analytics region are required.');
        }
        // The sub-organization is created with the configured Global Profile enforced
        // (vendor POST /organizations/suborg optional `parent_profile`). No setting, no POST.
        $globalProfile = ControlDConfig::defaultProfileId();
        if ($globalProfile === null || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $globalProfile)) {
            throw new ControlDClientException(ControlDConfig::DEFAULT_PROFILE_SETTING.' is required to create an organization.');
        }
        $pk = null;
        $phase = 'post';
        try {
            // Producer OpenAPI: POST body.organization; GET body.sub_organizations[].
            $response = $this->vendor->requestParent('POST', 'organizations/suborg', [
                'name' => $name, 'contact_email' => $contactEmail,
                'twofa_req' => $requireMfa, 'stats_endpoint' => $statsEndpoint,
                'parent_profile' => $globalProfile,
            ]);
            $row = $response['body']->organization ?? null;
            if (! $row instanceof \stdClass || ! is_string($row->PK ?? null)
                || ! preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $row->PK)) {
                throw new ControlDClientException('Control D organization identity is unconfirmed.');
            }
            $pk = $row->PK;
            $phase = 'readback';
            $readback = $this->vendor->requestParent('GET', 'organizations/sub_organizations');
            $rows = $readback['body']->sub_organizations ?? null;
            if (! is_array($rows)) {
                throw new ControlDClientException('Control D parent inventory is malformed.');
            }
            $matches = 0;
            foreach ($rows as $listed) {
                if (! $listed instanceof \stdClass || ! is_string($listed->PK ?? null) || ! is_string($listed->name ?? null)) {
                    throw new ControlDClientException('Control D parent inventory is malformed.');
                }
                if ($listed->PK === $pk) {
                    if ($listed->name !== $name) {
                        throw new ControlDClientException('Control D organization name does not match this attempt.');
                    }
                    if (ControlDSubOrganizations::parentProfile($listed) !== $globalProfile) {
                        throw new ControlDClientException('Control D organization global profile does not match this attempt.');
                    }
                    $matches++;
                }
            }
            if ($matches !== 1) {
                throw new ControlDClientException('Control D organization is not uniquely confirmed in the parent inventory.');
            }
            $phase = 'local-persistence';
            $connection->transaction(function () use ($actor, $clientId, $pk): void {
                $client = Client::query()->lockForUpdate()->find($clientId);
                $this->unmapped($client);
                $this->authorize(User::query()->lockForUpdate()->find($actor->getKey()));
                if (Client::withTrashed()->where('controld_org_id', $pk)->exists()) {
                    throw new ControlDClientException('Control D organization is already mapped.');
                }
                $client->controld_org_id = $pk;
                if (! $client->save()) {
                    throw new ControlDClientException('Control D organization persistence was refused.');
                }
            }, 1);
        } catch (ControlDWriteRejectedException $e) {
            if ($phase === 'post') {
                throw $e;
            }
            throw new ControlDOrganizationUncertainException($pk, $phase);
        } catch (\Throwable) {
            // No upstream/SQL exception chaining: bodies and bindings can contain PII.
            throw new ControlDOrganizationUncertainException($pk, $phase);
        }
        Log::info('[ControlDOnboardingOrganization] Organization mapped', [
            'actor_id' => $actor->getKey(), 'client_id' => $clientId, 'org_pk' => $pk,
        ]);
    }

    private function authorize(?User $actor): void
    {
        if ($actor === null || ! $actor->exists || ! $actor->is_active || ! $actor->isAdmin()) {
            throw new ControlDClientException('Organization onboarding requires an active Admin user.');
        }
    }

    private function unmapped(?Client $client): void
    {
        if ($client === null || $client->controld_org_id !== null) {
            throw new ControlDClientException('Organization onboarding requires an existing, non-deleted, unmapped client.');
        }
    }
}
