<?php

namespace App\Services\Cipp;

/**
 * A CIPP write that was refused before its request left this process: the
 * write method's own input checks, or send()'s endpointUrl(),
 * safeRequestOptions() or getToken().
 *
 * This type is the only evidence a caller may read as "nothing was sent".
 * A plain CippClientException proves nothing about whether the write left,
 * so callers must hedge on it (#3709).
 */
final class CippWriteNotSentException extends CippClientException {}
