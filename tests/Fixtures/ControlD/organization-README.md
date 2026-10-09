# Organization fixtures (B2)

Source: vendor-owned OpenAPI 3.0.1, info.version 1.0.1, embedded in
https://docs.controld.com/reference/post_organizations-suborg and
https://docs.controld.com/reference/get_organizations-sub-organizations,
retrieved 2026-09-16. `organization-schema.json` preserves the two path definitions.
`organization.json` is mechanically instantiated from the POST response schema,
with synthetic values; it is NOT a captured live create response. No vendor
request was made with an API credential. The GET fixture uses that schema's common
PK/name fields. The service validates the consumed identity projection, not every
unused quota/contact field. Any missing/malformed inventory or identity fails loud.

POST consumes application/x-www-form-urlencoded with four required inputs: name,
contact_email, twofa_req (0/1), stats_endpoint. B2 requires all four explicitly;
it does not select a contact, MFA policy or region on the operator's behalf.
The caller must supply a supported analytics-region PK; vendor validation failures
remain failures, not automatic defaults. One optional field is emitted (XULQ2iix):
`parent_profile` = the configured Global Profile PK (`controld_default_profile_id`).

`sub-organization.json` is mechanically instantiated from the GET
`sub_organizations` item schema with synthetic values (G-13). In that schema
`parent_profile` is an OPTIONAL property (absent from `required`) of type object with
required `PK`/`updated`/`name`, and it is not marked `nullable`. So ABSENT is the only
documented unset shape. Observed, not documented: on 2026-10-09 a read-only production
read returned the key PRESENT with JSON null for a sub-organization with no Global
Profile, so a present null is read as unset too (XULQ2iix ruling (a)); the pinned
`organization-schema.json` is the vendor's contract and is left unchanged. A scalar, an
array, false or an object without a non-empty string PK is treated as malformed. A read-back that lists
the org without `parent_profile.PK` equal to the setting is uncertain, not success.

PUT `/organizations` ("Modify Organization") is NOT preserved in
`organization-schema.json`. The same vendor OpenAPI document (read 2026-10-08)
declares its requestBody as application/x-www-form-urlencoded with optional
`parent_profile` ("Global Profile ID (PK) to enforce on all created Devices") and a
200 body.organization object. No PUT payload was captured: the tests' PUT response is
synthetic, and whether the vendor honours it under `X-Force-Org-Id` is unproven until
the first approved global-profile step.

POST response: body.organization.PK. GET response: body.sub_organizations[] with
PK and name. Only the exact PK returned by this attempt can be bound. The GET must
contain exactly one matching PK with the byte-exact sent name; no name-based
adoption. This is a positive-membership check, not an assertion that an empty or
partial inventory proves global absence. Unknown/missing membership refuses.

Name length 255 and non-control/edge-whitespace refusal, and identifier syntax
limits, are local safety policies, not claimed vendor maxima. Parent transport
uses fixed errors, no redirects, no retries, and no raw-error legacy GET path.
