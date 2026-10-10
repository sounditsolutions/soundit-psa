<?php

namespace App\Services\ControlD;

use App\Support\ControlDConfig;
use stdClass;

/**
 * Provisioning A: transport and verified vendor operations, not the onboarding writer.
 * No route, persistence, automatic retry, Tactical fan-out or authorization grant.
 * Producer contract/fixture provenance: tests/Fixtures/ControlD/README.md.
 */
class ControlDProvisioning
{
    public function __construct(private readonly ControlDClient $client) {}

    /**
     * $fields contains final wire values (not unchecked settings or arithmetic).
     * PIN is a separate canonical LOCAL string; null means Prevent Deactivation OFF.
     * Result contains secrets: callers must not log/serialize it to a public surface.
     * A failure after POST is uncertain, not permission to retry or auto-delete.
     * The PIN is a sensitive parameter, and validate() copies it into the request body, so
     * every frame that then carries that body — preflight(), postForOrg(), requestForOrg() —
     * annotates its own array parameter as well. PHP therefore redacts the PIN from THESE
     * frames' trace arguments even where zend.exception_ignore_args is Off. That is a
     * statement about this path only, not about a caller that copies the body elsewhere.
     * $admitBeforePost lets a caller commit its own durable one-shot admission at the
     * last moment before the write, so its pre-write refusals stay definite refusals.
     */
    public function create(string $orgPk, array $fields, #[\SensitiveParameter] ?string $pin = null, ?\Closure $recordPostedPk = null, ?\Closure $admitBeforePost = null): array
    {
        $this->available();
        $this->identifier($orgPk);
        $body = $this->validate($fields, $pin);
        $this->preflight($orgPk, $body);
        // Everything above is local validation or a read-only GET. A caller admission
        // runs here, outside the try below, so its refusal propagates definitely and is
        // never converted into an uncertain write.
        if ($admitBeforePost !== null) {
            $admitBeforePost();
        }
        $pk = null;
        $phase = 'post';
        try {
            $response = $this->client->postForOrg('provision', $orgPk, $body);
            $phase = 'create-response';
            $created = $this->body($response);
            if (! ($created->provision ?? null) instanceof stdClass) {
                $this->refuse('Create response has no provisioning row; reconcile before retrying.');
            }
            $candidate = $created->provision->PK ?? null;
            $this->identifier($candidate);
            $pk = $candidate;
            // B3 may durably retain the own-POST handle before read-back. No code/PIN
            // crosses this callback. A failed checkpoint is uncertain, never retried.
            if ($recordPostedPk !== null) {
                $recordPostedPk($pk);
            }
            $phase = 'read-back';

            return $this->confirmRow($this->readBack($orgPk, $pk), $pk, $body, $pin);
        } catch (ControlDWriteRejectedException $e) {
            if ($phase === 'post') {
                throw $e;
            }
            throw new ControlDWriteUncertainException($orgPk, $pk, $phase);
        } catch (\Throwable) {
            throw new ControlDWriteUncertainException($orgPk, $pk, $phase);
        }
    }

    /**
     * Read-only reconcile of an EXISTING row against the fields a create would have sent:
     * the same validation and the same read-back checks create() applies after its POST
     * (confirmRow()). Returns the same ['PK', 'code', 'deactivation_pin'] create() returns;
     * throws ControlDClientException on any difference. Makes no request itself.
     */
    public function confirmExisting(stdClass $row, string $pk, array $fields, #[\SensitiveParameter] ?string $pin = null): array
    {
        $this->identifier($pk);
        if (($row->PK ?? null) !== $pk) {
            $this->refuse('Provisioning row does not carry the expected PK.');
        }

        return $this->confirmRow($row, $pk, $this->validate($fields, $pin), $pin);
    }

    /** The create() read-back checks, shared with confirmExisting(). */
    private function confirmRow(stdClass $row, string $pk, #[\SensitiveParameter] array $body, #[\SensitiveParameter] ?string $pin): array
    {
        foreach (['profile_id', 'max', 'ts_exp', 'stats', 'intercept_mode', 'icon'] as $key) {
            if (! property_exists($row, $key) || $body[$key] !== $row->$key) {
                $this->refuse('Provisioning read-back differs from requested fields.');
            }
        }
        if (isset($body['name_prefix'])) {
            if (($row->name_prefix ?? null) !== $body['name_prefix']) {
                $this->refuse('Provisioning prefix read-back differs.');
            }
        } elseif (property_exists($row, 'name_prefix') && $row->name_prefix !== '') {
            $this->refuse('Provisioning read-back has an unexpected prefix.');
        }
        if ($pin !== null) {
            if (! is_int($row->deactivation_pin ?? null) || (string) $row->deactivation_pin !== $pin) {
                $this->refuse('Provisioning PIN read-back is missing or differs.');
            }
        } elseif (property_exists($row, 'deactivation_pin')) {
            $this->refuse('Provisioning read-back has an unexpected PIN.');
        }
        if (! is_string($row->code ?? null) || strlen($row->code) !== 32
            || ($row->status ?? null) !== 1 || ($row->expired ?? null) !== 0) {
            $this->refuse('Provisioning read-back is not an active usable code.');
        }

        return ['PK' => $pk, 'code' => $row->code, 'deactivation_pin' => $pin];
    }

    public function invalidate(string $orgPk, string $pk): void
    {
        $this->available();
        $this->identifier($orgPk);
        $this->identifier($pk);
        $this->client->putForOrg('provision/'.$pk.'/invalidate', $orgPk);
        if (($this->readBack($orgPk, $pk)->status ?? null) !== -1) {
            $this->refuse('Invalidation was not confirmed by read-back.');
        }
    }

    /** Vendor acknowledgment only; absence/read-back proof is not claimed here. */
    public function delete(string $orgPk, string $pk): void
    {
        $this->available();
        $this->identifier($orgPk);
        $this->identifier($pk);
        $this->client->deleteForOrg('provision/'.$pk, $orgPk);
    }

    private function available(): void
    {
        if (! ControlDConfig::isEnabled() || ! ControlDConfig::isConfigured()) {
            $this->refuse('Control D is disabled or unconfigured.');
        }
    }

    private function identifier(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/\A[A-Za-z0-9_-]+\z/', $value)) {
            $this->refuse('Control D identifier is missing or invalid.');
        }
    }

    private function validate(array $fields, #[\SensitiveParameter] ?string $pin): array
    {
        $required = ['icon', 'profile_id', 'max', 'ts_exp', 'stats', 'intercept_mode'];
        if (array_diff($required, array_keys($fields))
            || array_diff(array_keys($fields), [...$required, 'name_prefix'])) {
            $this->refuse('Provisioning fields are missing or unsupported.');
        }
        $this->identifier($fields['profile_id']);
        $this->identifier($fields['icon']);
        if (! is_int($fields['max']) || $fields['max'] < 1 || $fields['max'] > 10000
            || ! is_int($fields['ts_exp']) || ($fields['ts_exp'] !== 0 && $fields['ts_exp'] <= time())
            || ! in_array($fields['stats'], [0, 1, 2], true)
            || ! in_array($fields['intercept_mode'], ['standard', 'intercept-dns'], true)) {
            $this->refuse('Provisioning limit, expiry, analytics or intercept mode is invalid.');
        }
        if (array_key_exists('name_prefix', $fields)) {
            if (! is_string($fields['name_prefix'])) {
                $this->refuse('Provisioning prefix must be a string.');
            }
            if ($fields['name_prefix'] === '') {
                unset($fields['name_prefix']);
            }
        }
        if ($pin !== null) {
            if (! preg_match('/\A[1-9][0-9]{0,9}\z/', $pin) || (string) (int) $pin !== $pin) {
                $this->refuse('PIN must contain 1–10 decimal digits, starting with 1–9.');
            }
            $fields['deactivation_pin'] = (int) $pin;
        }

        return $fields;
    }

    private function preflight(string $orgPk, #[\SensitiveParameter] array $body): void
    {
        // Live GET /devices/types shape recorded by the producer-contract probe.
        $types = $this->body($this->client->requestForOrg('GET', 'devices/types', $orgPk));
        $icons = $types->types->os->icons ?? null;
        if (! $icons instanceof stdClass || ! property_exists($icons, $body['icon'])
            || $body['icon'] === 'mobile-ios') {
            $this->refuse('Provisioning device type is unavailable or unsupported.');
        }
        // Dashboard getProfiles consumes body.profiles; do not invent fallback lists.
        $profiles = $this->body($this->client->requestForOrg('GET', 'profiles', $orgPk));
        if (! is_array($profiles->profiles ?? null)) {
            $this->refuse('Profile inventory response is malformed.');
        }
        $matches = 0;
        foreach ($profiles->profiles as $profile) {
            if (! $profile instanceof stdClass || ! is_string($profile->PK ?? null)) {
                $this->refuse('Profile inventory row is malformed.');
            }
            $matches += $profile->PK === $body['profile_id'] ? 1 : 0;
        }
        // Two accepted arms (XULQ2iix ruling (c)): (ii) the profile is one of the
        // sub-organization's OWN profiles exactly once; or (i) it is the sub-organization's
        // Global Profile (parent_profile), which a read-only production GET reported on card
        // XULQ2iix (2026-10-08) as absent from that sub-organization's GET profiles. An own
        // inventory listing the PK more than once is ambiguous and a definite pre-write
        // refusal whatever arm (i) would say; arm (i) is consulted only when the PK is not
        // listed at all, read live from the parent's GET sub_organizations with the org
        // listed exactly once, and an unreadable or malformed parent inventory refuses.
        if ($matches > 1) {
            $this->refuse('Enforced profile is listed more than once in this organization\'s own profiles (ambiguous).');
        }
        if ($matches === 0) {
            try {
                $global = (new ControlDSubOrganizations($this->client))->parentProfileOf($orgPk);
            } catch (ControlDClientException) {
                $this->refuse('Control D global profile of this organization could not be confirmed.');
            }
            if ($global !== $body['profile_id']) {
                $this->refuse('Enforced profile is neither this organization\'s global profile nor exactly one of its own profiles.');
            }
        }
        if ($body['stats'] !== 0) {
            $data = $this->body($this->client->requestForOrg('GET', 'organizations/organization', $orgPk));
            $org = $data->organization ?? null;
            if (! $org instanceof stdClass || ($org->PK ?? null) !== $orgPk
                || ! is_string($org->stats_endpoint ?? null) || trim($org->stats_endpoint) === '') {
                $this->refuse('Analytics requires a verified region for this organization.');
            }
        }
    }

    /**
     * Read-only: the organization's provisioning list as ONE GET provision returns it (under
     * X-Force-Org-Id; producer notes in tests/Fixtures/ControlD/README.md: GET returns
     * `body.provisions`). Callers treat it as the complete list: no paging, cursor, total or
     * truncation marker is checked (none appears in the recorded fixtures). Throws
     * ControlDClientException, never returns an empty stand-in, when the request fails, the
     * response is not a confirmed success, the list is missing or not an array, or ANY row is
     * not an object with a string PK belonging to this organization. So an empty array
     * returned here is a well-formed empty response. Rows carry secrets (code, PIN): callers
     * must not log or serialize them.
     *
     * @return array<int, stdClass>
     */
    public function provisions(string $orgPk): array
    {
        $this->available();
        $this->identifier($orgPk);
        $body = $this->body($this->client->requestForOrg('GET', 'provision', $orgPk));
        if (! is_array($body->provisions ?? null)) {
            $this->refuse('Provisioning inventory response is malformed.');
        }
        foreach ($body->provisions as $row) {
            if (! $row instanceof stdClass || ! is_string($row->PK ?? null) || ($row->org ?? null) !== $orgPk) {
                $this->refuse('Provisioning inventory row is malformed or outside this organization.');
            }
        }

        return array_values($body->provisions);
    }

    private function readBack(string $orgPk, string $pk): stdClass
    {
        $matches = [];
        foreach ($this->provisions($orgPk) as $row) {
            if ($row->PK === $pk) {
                $matches[] = $row;
            }
        }
        if (count($matches) !== 1) {
            $this->refuse('Provisioning read-back is missing or ambiguous.');
        }

        return $matches[0];
    }

    private function body(array $response): stdClass
    {
        if (($response['success'] ?? null) !== true || ! ($response['body'] ?? null) instanceof stdClass) {
            $this->refuse('Control D response body is malformed.');
        }

        return $response['body'];
    }

    private function refuse(string $message): never
    {
        throw new ControlDClientException($message);
    }
}
