<?php

namespace App\Services\Mesh;

/**
 * A write Mesh REFUSED at the application layer (HTTP 400) — the server
 * validated the request and declined it. Distinct from MeshClientException
 * (transport / auth / 5xx) because the vendor's own validation text is the
 * useful refusal reason and is passed through to the caller verbatim rather
 * than masked behind a generic failure (#1018 criterion 9).
 *
 * Two validators are measured (2026-09-01 enforcement test, prod tenant):
 *   - `sender`  → {"detail":"No Allow/Block Rules added","errors":["Invalid sender: …special-use or reserved…"]}
 *   - `comment` → {"comment":["String invalid"]}
 * The exact accepted charset for `comment` was NOT narrowed; the wrapper
 * therefore generates the comment itself rather than relying on knowing it.
 */
class MeshWriteRejectedException extends MeshClientException
{
    /** @param array<string, mixed> $vendorBody */
    public function __construct(
        string $message,
        private readonly array $vendorBody = [],
    ) {
        parent::__construct($message, 400);
    }

    /** @return array<string, mixed> */
    public function vendorBody(): array
    {
        return $this->vendorBody;
    }
}
