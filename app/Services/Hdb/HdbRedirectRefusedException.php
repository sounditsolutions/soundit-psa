<?php

namespace App\Services\Hdb;

/**
 * A redirect hop left the configured portal origin.
 *
 * Guzzle's redirect middleware re-issues a credential POST AS a POST under
 * `strict` mode, so every hop is another send of the decrypted service
 * subaccount password. Refusing one means throwing out of the `on_redirect`
 * callback; this type exists so {@see HdbAuthClient} can tell that refusal apart
 * from an ordinary transport failure and report it as its own reason.
 *
 * It deliberately carries no message: the URL it refused is exactly the text
 * that must not escape this package.
 */
final class HdbRedirectRefusedException extends \RuntimeException {}
