<?php

namespace App\Services\AppRiver;

class AppRiverClientException extends \RuntimeException
{
    /**
     * OAuth2 `error` code from the response body when this exception was
     * thrown by a /auth/token failure (e.g. "invalid_grant", "invalid_client").
     * Null for non-OAuth errors.
     */
    public ?string $oauthError = null;
}
