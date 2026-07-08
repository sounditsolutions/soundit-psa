<?php

namespace App\Services\Qbo;

use RuntimeException;

class QboClientException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly ?array $responseBody = null,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }
}
