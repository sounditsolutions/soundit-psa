<?php

namespace App\Support;

/**
 * so-ssoj / psa-6vfw1 (#5): flags secret-bearing FILES by name so a tracked
 * `.env.bak-*`, backup, or key file can never reach a push again.
 *
 * This is the filename half of the recurrence guard — the exact gap that let
 * `.env.bak-pre-msgraph-20260708T195933Z` get tracked and pushed to the public
 * repo. Content / token-shape scanning stays in scripts/gc-verify.sh's diff
 * guard; the two are complementary. Pure + unit-tested; git enumeration and the
 * exit-code wiring live in App\Console\Commands\SecretScan.
 */
class SecretScanner
{
    /**
     * A human reason if $path is a secret-bearing file that must never be
     * committed, or null if it is safe.
     */
    public static function dangerousReason(string $path): ?string
    {
        $base = basename($path);

        // Placeholder / example files ship config SHAPE without values and are
        // the intended, safe pattern — checked FIRST so `.env.example` and
        // friends are never caught by the environment rule below.
        foreach (['.example', '.dist', '.sample', '.template'] as $placeholder) {
            if (str_ends_with($base, $placeholder)) {
                return null;
            }
        }

        // Environment files — the root cause. `.env`, `.env.production`,
        // `.env.bak-<ts>`, `deploy.env`, etc. (`.example` already excluded).
        if ($base === '.env' || str_starts_with($base, '.env.') || str_ends_with($base, '.env')) {
            return 'environment file (may contain secrets)';
        }

        // Backup files — `.env.bak-<ts>` dodged the `.env` rule and leaked; the
        // belt catches any `*.bak`, `*.bak-old`, `*.bak.1`.
        if (preg_match('/\.bak([-.]|$)/i', $base) === 1) {
            return 'backup file (may contain secrets)';
        }

        // Private keys, certificates, keystores.
        if (preg_match('/\.(key|pem|p12|pfx|keystore|jks|ppk)$/i', $base) === 1) {
            return 'key/certificate file';
        }

        // SSH private keys (the `.pub` counterpart is public and safe).
        if (preg_match('/^id_(rsa|dsa|ecdsa|ed25519)$/', $base) === 1) {
            return 'private SSH key';
        }

        return null;
    }

    /**
     * Scan repo-relative paths; return [path => reason] for offenders only,
     * preserving input order.
     *
     * @param  iterable<string>  $paths
     * @return array<string, string>
     */
    public static function scan(iterable $paths): array
    {
        $offenders = [];
        foreach ($paths as $path) {
            $reason = self::dangerousReason($path);
            if ($reason !== null) {
                $offenders[$path] = $reason;
            }
        }

        return $offenders;
    }
}
