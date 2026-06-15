<?php

namespace App\Services\Tactical;

use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

/**
 * Raised for any failed Tactical API call. Carries the STRUCTURED signal the
 * action bus (P2 §11/M2) classifies on:
 *
 *   - A transport failure — a Guzzle ConnectException / timeout with no HTTP
 *     response (code 0) — is offline-classifiable (the agent / NATS is
 *     unreachable). isTransportFailure() === true; statusCode() === null.
 *   - An HTTP response error (401/403/404/5xx) carries its status + body.
 *     isTransportFailure() === false. These must NEVER be collapsed to
 *     "offline" — a 403 is an auth failure / possible key compromise.
 */
class TacticalClientException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?int $statusCode = null,
        private readonly ?string $responseBody = null,
        private readonly bool $transportFailure = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Build from a caught Guzzle exception, deriving the structured signal.
     *
     * A RequestException with a response => an HTTP error (carries status/body,
     * not a transport failure). Anything else (ConnectException, timeouts,
     * TooManyRedirectsException — none carry a usable response) => transport
     * failure.
     */
    public static function fromGuzzle(string $message, Throwable $e): self
    {
        $status = null;
        $body = null;
        $transport = true;

        if ($e instanceof RequestException && $e->hasResponse()) {
            $response = $e->getResponse();
            $status = $response?->getStatusCode();
            $body = $response !== null ? (string) $response->getBody() : null;
            $transport = false;
        }

        return new self($message, $e->getCode(), $e, $status, $body, $transport);
    }

    /**
     * True when the failure is a transport / connectivity problem (no HTTP
     * response) — the bus may classify this as `offline`.
     */
    public function isTransportFailure(): bool
    {
        return $this->transportFailure;
    }

    /**
     * The HTTP status code when the failure carried a response, else null.
     */
    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * The raw HTTP response body when present, else null.
     */
    public function responseBody(): ?string
    {
        return $this->responseBody;
    }
}
