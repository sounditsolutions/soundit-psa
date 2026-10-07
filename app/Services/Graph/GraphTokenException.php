<?php

namespace App\Services\Graph;

/**
 * GraphClient::getToken() failed: the OAuth2 token request to the identity provider, not a
 * Graph request (#5728).
 *
 * getHttpStatus() is 0 on every arm, because no Graph request was answered. The class is what
 * tells this failure apart from a Graph request that received no response, which also has
 * status 0 but is a plain GraphClientException: a record that carries only the status and the
 * exception class (GraphWebhookManager::failure(), GraphWebhookController) then still names
 * the token leg. getToken()'s own record, where it writes one, carries the token endpoint's
 * status. Callers that catch GraphClientException catch this too.
 */
class GraphTokenException extends GraphClientException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
