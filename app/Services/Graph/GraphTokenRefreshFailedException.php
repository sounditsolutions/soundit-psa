<?php

namespace App\Services\Graph;

/**
 * Graph answered HTTP 401 and the token refresh that followed failed (#5398).
 *
 * getHttpStatus() is Graph's 401, because Graph did answer 401. The class exists so a consumer
 * can tell this arm from a 401 that a fresh token did not cure: here no fresh token was ever
 * obtained, so the cause is the token request, not a permission the request lacked.
 *
 * C-56: the message carries the method only. It carries no endpoint (a users/ endpoint holds
 * the mailbox) and no Guzzle or identity-provider text, and there is no response body.
 * $tokenFailure is the exception getToken() threw.
 */
class GraphTokenRefreshFailedException extends GraphClientException
{
    public function __construct(string $method, public readonly GraphClientException $tokenFailure)
    {
        parent::__construct("Graph API error: {$method} returned 401 and the token refresh failed", 401);
    }
}
