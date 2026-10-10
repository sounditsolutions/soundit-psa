<?php

namespace App\Services\ControlD;

/**
 * A POST was explicitly rejected by the vendor envelope (HTTP 4xx), not an unknown write.
 * The exception message is PSA-written and never carries vendor text. $vendorMessage is the
 * envelope's own `error.message`, already sanitized by ControlDClient::vendorMessage()
 * (string only, control characters stripped, whitespace collapsed, capped, and null when it
 * echoes a request secret or contact value); null when absent or dropped.
 */
class ControlDWriteRejectedException extends ControlDClientException
{
    public function __construct(string $message, public readonly ?int $reasonCode = null, public readonly ?int $httpStatus = null, public readonly ?string $vendorMessage = null)
    {
        parent::__construct($message);
    }

    public function isReadOnlyKey(): bool
    {
        return $this->httpStatus === 403 && $this->reasonCode === 40301;
    }
}
