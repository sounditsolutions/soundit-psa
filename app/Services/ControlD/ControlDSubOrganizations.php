<?php

namespace App\Services\ControlD;

use stdClass;

/**
 * The parent's GET organizations/sub_organizations, read for ONE sub-organization's
 * Global Profile (`parent_profile`).
 *
 * Producer: vendor OpenAPI 3.0.1, GET /organizations/sub_organizations
 * (tests/Fixtures/ControlD/organization-schema.json). Each row's `parent_profile` is an
 * OPTIONAL property (absent from the row's `required` list) whose type is an object
 * with required `PK` (string), `updated` (integer) and `name` (string). So the only
 * documented "unset" shape is an ABSENT key. Any other shape — null, a scalar, an
 * object without a string PK — is not a documented shape and is refused as malformed
 * (C-56), never read as "no global profile".
 */
final class ControlDSubOrganizations
{
    public function __construct(private readonly ControlDClient $vendor) {}

    /**
     * The sub-organization's row, listed exactly once in the parent inventory. Throws
     * ControlDClientException when the inventory is malformed or the org is not listed
     * exactly once. Read-only: the caller decides what a refusal here means.
     */
    public function row(string $orgPk): stdClass
    {
        $response = $this->vendor->requestParent('GET', 'organizations/sub_organizations');
        $rows = $response['body']->sub_organizations ?? null;
        if (! is_array($rows)) {
            throw new ControlDClientException('Control D parent inventory is malformed.');
        }
        $matches = [];
        foreach ($rows as $listed) {
            if (! $listed instanceof stdClass || ! is_string($listed->PK ?? null) || ! is_string($listed->name ?? null)) {
                throw new ControlDClientException('Control D parent inventory is malformed.');
            }
            if ($listed->PK === $orgPk) {
                $matches[] = $listed;
            }
        }
        if (count($matches) !== 1) {
            throw new ControlDClientException('Control D organization is not uniquely listed in the parent inventory.');
        }

        return $matches[0];
    }

    /** The row's Global Profile PK, or null when the key is absent. Any other shape throws. */
    public static function parentProfile(stdClass $row): ?string
    {
        if (! property_exists($row, 'parent_profile')) {
            return null;
        }
        $profile = $row->parent_profile;
        if (! $profile instanceof stdClass || ! is_string($profile->PK ?? null) || $profile->PK === '') {
            throw new ControlDClientException('Control D global profile field is malformed.');
        }

        return $profile->PK;
    }

    /** Convenience: the listed sub-organization's Global Profile PK (or null when unset). */
    public function parentProfileOf(string $orgPk): ?string
    {
        return self::parentProfile($this->row($orgPk));
    }
}
