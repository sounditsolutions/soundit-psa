<?php

namespace App\Services\Huntress;

/**
 * A refusal on the Huntress staged-write path (scope, shape, or state gate).
 * Every message is operator-readable and every gate that throws it leaves an
 * audit row; nothing was sent upstream when it is thrown.
 */
class HuntressWriteScopeException extends \RuntimeException {}
