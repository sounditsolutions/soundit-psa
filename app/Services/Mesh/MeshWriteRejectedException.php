<?php

namespace App\Services\Mesh;

/**
 * A write Mesh REFUSED at the application layer (HTTP 400) — the server
 * validated the request and declined it. Distinct from MeshClientException
 * (transport / auth / 5xx) because a 400 is a DETERMINATE refusal: Mesh
 * validated the request and did not act, so nothing needs reconciling.
 *
 * #6106: the message is written by the PSA and carries no vendor text: the
 * status, and at most the names of request fields Mesh's answer was keyed
 * on, from a fixed allowlist (MeshWriteClient::refusalFields()). The
 * vendor's validation text (it can echo the sender mailbox) is neither in
 * the message nor kept on the exception. Callers report a refusal through
 * statusPhrase() (C-56, card FLzMLDxF), as before.
 *
 * Two validators are measured (2026-09-01 enforcement test, prod tenant):
 *   - `sender`  → {"detail":"No Allow/Block Rules added","errors":["Invalid sender: …special-use or reserved…"]}
 *   - `comment` → {"comment":["String invalid"]}
 * The exact accepted charset for `comment` was NOT narrowed; the wrapper
 * therefore generates the comment itself rather than relying on knowing it.
 */
class MeshWriteRejectedException extends MeshClientException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 400);
    }
}
