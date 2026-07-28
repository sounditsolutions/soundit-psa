<?php

namespace App\Services\Graph;

/**
 * A Microsoft Graph success payload whose SHAPE could not be trusted — a malformed OData
 * envelope, a per-mailbox free/busy error, a missing/duplicate requested mailbox, a truncated
 * paged result, or a malformed event resource (psa-abl0i.2 architecture review).
 *
 * It is a GraphClientException so existing catchers still handle it, but distinguishable so a
 * caller can tell "the read is untrustworthy" apart from a transport/HTTP failure. The whole
 * point is the CLAUDE.md hard vendor rule: a degraded read must SCREAM, never collapse to a
 * clean empty/all-clear — for a free/busy grid, a swallowed error reads as "this person is FREE".
 */
class GraphShapeDriftException extends GraphClientException {}
