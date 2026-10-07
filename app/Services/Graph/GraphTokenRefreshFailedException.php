<?php

namespace App\Services\Graph;

/**
 * Graph answered HTTP 401 and the token refresh that followed failed (#5398).
 *
 * getHttpStatus() is Graph's 401, because Graph did answer 401. What is known on this arm is
 * only that: Graph answered 401, and the refresh then failed, so no request was retried with a
 * fresh token. The class lets a consumer tell this arm from a 401 that a fresh token did not
 * cure. It does not establish why Graph answered 401: an expired token and a permission the
 * request lacked are both still possible, because no fresh token was ever tried (#5459).
 *
 * C-56: the message carries the method only. It carries no endpoint (a users/ endpoint holds
 * the mailbox) and no Guzzle or identity-provider text, and there is no response body.
 *
 * $tokenFailure is the exception getToken() threw, and it is also chained as getPrevious()
 * (#5461) so a reporter that walks the chain keeps the cause. getToken() has three failure
 * arms and its message is status-only on each (#5397, #5511): the HTTP status when the token
 * endpoint answered, the Guzzle exception class when it did not, a fixed string when a 2xx had
 * no access_token. It never carries the token URL, the tenant or the identity provider's body.
 * Tests\Unit\Graph\GraphTokenRefreshFailedExceptionTest renders the chain as Laravel's log
 * formatter does, messages and stack traces of both links (#5513), and finds no vendor text.
 * A reporter that prints frame arguments (zend.exception_ignore_args=Off) shows the users/
 * endpoint, which holds the mailbox, in the trace; that frame is already in this exception's
 * own trace, and the chained link adds nothing the outer one lacks. A getToken() change that
 * puts vendor text in its message must not chain.
 */
class GraphTokenRefreshFailedException extends GraphClientException
{
    public function __construct(string $method, public readonly GraphClientException $tokenFailure)
    {
        parent::__construct("Graph API error: {$method} returned 401 and the token refresh failed", 401, null, $tokenFailure);
    }
}
