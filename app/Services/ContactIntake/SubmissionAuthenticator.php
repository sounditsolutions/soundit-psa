<?php

namespace App\Services\ContactIntake;

use App\Support\ContactIntakeConfig;

/** Website authentication is not verification of the visitor's claimed identity. */
final class SubmissionAuthenticator
{
    public const PATH = '/api/intake/contact-submissions';

    public const MAX_BODY_BYTES = 32768;

    public function accepts(string $method, string $path, string $body, string $keyId, string $timestamp, string $signature): bool
    {
        if (! ContactIntakeConfig::enabled() || $method !== 'POST' || $path !== self::PATH
            || strlen($body) > self::MAX_BODY_BYTES || $body === ''
            || ! preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $keyId)
            || ! preg_match('/\A[0-9]{10}\z/', $timestamp)
            || abs(now()->timestamp - (int) $timestamp) > 300
            || ! preg_match('/\A[a-f0-9]{64}\z/', $signature)) {
            return false;
        }
        $key = ContactIntakeConfig::signingKey($keyId);
        if ($key === null) {
            return false;
        }
        $canonical = implode("\n", [$method, $path, $keyId, $timestamp, hash('sha256', $body)]);

        return hash_equals(hash_hmac('sha256', $canonical, $key), $signature);
    }
}
