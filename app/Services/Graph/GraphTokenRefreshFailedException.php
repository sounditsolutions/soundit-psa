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
 * (#5461) so a reporter that walks the chain keeps the cause. getToken() throws a
 * GraphClientException on four arms (#5397, #5511), and what its message carries differs by
 * arm (#5724):
 * - the HTTP status of the response Guzzle threw on, the only arm whose message has a status.
 *   That is 400 or more, or a 3xx when Guzzle refuses a redirect (more than five, or a Location
 *   whose scheme is not http/https; #5674), and after a followed redirect it is the status of
 *   the last response, not the first;
 * - the Guzzle exception class when no response arrived (no status);
 * - a fixed string, with no status, when the response Guzzle returned (one it did not follow,
 *   such as a 2xx or a 3xx without Location; #5620) had no access_token;
 * - a fixed string, with no status, naming the field when access_token is not a string or
 *   expires_in is not numeric (#5659). That response is not cached, and its record names the
 *   field and the PHP type it decoded as, never the value.
 * None of the four carries the token URL, the tenant or the identity provider's body.
 * Tests\Unit\Graph\GraphTokenRefreshFailedExceptionTest reports
 * the chain through Laravel's exception handler and renders it with Laravel's log formatter,
 * messages and stack traces of both links (#5513, #5613), and finds no vendor text. A reporter
 * that prints frame arguments (zend.exception_ignore_args=Off) shows the users/ endpoint, which
 * holds the mailbox, in the trace; that frame is already in this exception's own trace. The
 * test compares the quoted string arguments of the two traces' frames, include/require frames
 * excluded, as its stringArguments() splits them (an argument containing a quote is split),
 * and finds none that only the chained link prints (#5614, #5675). A getToken() change that
 * puts vendor text in its message must not chain.
 */
class GraphTokenRefreshFailedException extends GraphClientException
{
    public function __construct(string $method, public readonly GraphClientException $tokenFailure)
    {
        parent::__construct("Graph API error: {$method} returned 401 and the token refresh failed", 401, null, $tokenFailure);
    }
}
