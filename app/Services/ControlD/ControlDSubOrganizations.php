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
 * with required `PK` (string), `updated` (integer) and `name` (string). The schema does
 * NOT mark the field `nullable`, so its only documented "unset" shape is an ABSENT key.
 * Observed, not documented: on 2026-10-09 a read-only production read of this endpoint
 * (card XULQ2iix) returned the key PRESENT with JSON null for a sub-organization that has
 * no Global Profile. A present null is therefore read as unset, exactly like an absent
 * key (XULQ2iix ruling (a)). Also observed, not documented: on 2026-10-09, after a PUT of
 * parent_profile, the same read returned the field as a BARE non-empty string holding the
 * profile PK (the request body's shape, not the response schema's object). That string is
 * read as the PK (XULQ2iix ruling of 2026-10-09 18:3xZ, run-732 diagnosis). Any other shape
 * (an empty string, a number, an array, false, an object without a non-empty string PK)
 * is not a documented or observed shape and is refused as malformed (C-56), never read as
 * "no global profile".
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
        $matches = [];
        foreach ($this->rows() as $listed) {
            if ($listed->PK === $orgPk) {
                $matches[] = $listed;
            }
        }
        if (count($matches) !== 1) {
            throw new ControlDClientException('Control D organization is not uniquely listed in the parent inventory.');
        }

        return $matches[0];
    }

    /**
     * The parent's COMPLETE sub-organization list (one GET). Throws ControlDClientException,
     * never returns a partial list, when the request fails or is unconfirmed (requestParent()),
     * the list is missing or not an array, or ANY row is not an object with a string PK and a
     * string name. An empty array returned here is therefore a complete, well-formed empty list.
     *
     * @return array<int, stdClass>
     */
    public function rows(): array
    {
        $response = $this->vendor->requestParent('GET', 'organizations/sub_organizations');
        $rows = $response['body']->sub_organizations ?? null;
        if (! is_array($rows)) {
            throw new ControlDClientException('Control D parent inventory is malformed.');
        }
        foreach ($rows as $listed) {
            if (! $listed instanceof stdClass || ! is_string($listed->PK ?? null) || ! is_string($listed->name ?? null)) {
                throw new ControlDClientException('Control D parent inventory is malformed.');
            }
        }

        return array_values($rows);
    }

    /**
     * The row's Global Profile PK, or null when it is unset: the key ABSENT (the only
     * shape the vendor's OpenAPI documents, which does not mark the field nullable) or
     * PRESENT with JSON null (the shape a 2026-10-09 production read observed for an org
     * with no Global Profile). Only null itself is unset. The PK is either a bare non-empty
     * string (observed after a PUT on 2026-10-09) or the documented object's non-empty
     * string PK. Any other scalar (including '', 0 and false), an array, or an object
     * without a non-empty string PK throws. Nothing else is checked (not the object's
     * `updated` or `name`, nor the PK's characters).
     */
    public static function parentProfile(stdClass $row): ?string
    {
        if (! property_exists($row, 'parent_profile') || $row->parent_profile === null) {
            return null;
        }
        $profile = $row->parent_profile;
        if (is_string($profile) && $profile !== '') {
            return $profile;
        }
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
