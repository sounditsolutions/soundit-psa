<?php

namespace App\Services\Level;

use RuntimeException;

class LevelClientException extends RuntimeException
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
