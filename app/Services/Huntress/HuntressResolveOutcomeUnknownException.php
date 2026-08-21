<?php

namespace App\Services\Huntress;

/**
 * The resolution POST was SENT and its outcome could NOT be established — the
 * reply was lost (a read timeout after the request was flushed is
 * indistinguishable from a connection that never opened; Guzzle raises the same
 * ConnectException for both) or the server answered 5xx after it may already
 * have acted. The escalation MAY be resolved, and if it is, its
 * `resolution_method` was never seen, so the executor's post-condition never
 * ran.
 *
 * Typed apart from its parent precisely because the ordinary
 * HuntressClientException means the request was REFUSED (an answered 4xx):
 * nothing changed upstream and the approval may safely reopen for a retry. This
 * one may never be reported as "nothing was resolved" and may never reopen a
 * run for one-tap re-approval — that is the second POST, and the replay
 * converges on the live-read/409 branch as a clean `executed`, laundering a
 * rules-were-created fault into a green success.
 */
class HuntressResolveOutcomeUnknownException extends HuntressClientException {}
