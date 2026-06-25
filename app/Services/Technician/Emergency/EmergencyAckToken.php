<?php

namespace App\Services\Technician\Emergency;

use App\Support\TechnicianConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * A short-lived, signed one-tap emergency-acknowledgement grant (Phase 2).
 *
 * Mirrors TechnicianApprovalGrant's sign/verify shape exactly so this stays
 * consistent with the existing approval-grant security: HMAC-SHA256 over a
 * single-char-keyed payload {em, u, e} with the app key, wrapped in a base64url
 * {p, s} envelope, verified with hash_equals (constant-time).
 *
 * This token is an UNAUTHENTICATED BEARER CREDENTIAL: the route carries only the
 * token and no session, so the token IS the auth — it binds the acking user id.
 *
 * CO-5(c): a long-lived token is the vulnerability (a leaked/forwarded link
 * could silence the backstop). TTL is therefore pinned to ~the escalation
 * timeout — not hours — clamped to a sane 15–30 minute window. The away operator
 * has roughly one escalation cycle to tap; after that the link is dead and the
 * deterministic sweep keeps watching.
 *
 * Stateless. Single-use is enforced downstream by the emergency-row CAS in the
 * controller (state=open ⇒ acknowledged), not by the token.
 */
class EmergencyAckToken
{
    /** TTL clamp floor/ceiling in minutes (CO-5(c)): keep the bearer link short. */
    public const TTL_FLOOR_MINUTES = 15;

    public const TTL_CEILING_MINUTES = 30;

    public static function issue(int $emergencyId, int $userId): string
    {
        $expiresAt = Carbon::now()->getTimestamp() + (self::ttlMinutes() * 60);

        $payload = self::payload($emergencyId, $userId, $expiresAt);
        $signature = self::sign($payload);

        $envelope = json_encode(['p' => $payload, 's' => $signature]);

        return rtrim(strtr(base64_encode($envelope), '+/', '-_'), '=');
    }

    public static function verify(string $token, int $emergencyId, int $userId): bool
    {
        $envelope = self::decode($token);
        if ($envelope === null) {
            return false;
        }

        $claimedExpiry = $envelope['p']['e'] ?? null;
        if (! is_int($claimedExpiry)) {
            return false;
        }

        $expectedPayload = self::payload($emergencyId, $userId, $claimedExpiry);
        $expectedSignature = self::sign($expectedPayload);

        $providedSignature = is_string($envelope['s']) ? $envelope['s'] : '';
        if (! hash_equals($expectedSignature, $providedSignature)) {
            return false;
        }

        return Carbon::now()->getTimestamp() <= $claimedExpiry;
    }

    /**
     * Public, NON-verifying decode of the claimed payload (CO-16). The route only
     * carries {token}; the controller needs the claimed {em, u} to then call
     * verify($token, $em, $u). This base64url-decodes the envelope WITHOUT
     * checking the HMAC — callers MUST still verify() before trusting the claims.
     *
     * @return array{em: int, u: int, e: int}|null
     */
    public static function claims(string $token): ?array
    {
        $envelope = self::decode($token);
        if ($envelope === null) {
            return null;
        }

        $p = $envelope['p'];
        if (! isset($p['em'], $p['u'], $p['e']) || ! is_int($p['em']) || ! is_int($p['u']) || ! is_int($p['e'])) {
            return null;
        }

        return ['em' => $p['em'], 'u' => $p['u'], 'e' => $p['e']];
    }

    /**
     * Decode and shape-check the {p, s} envelope. Returns null on any malformed
     * input. Does NOT verify the HMAC.
     *
     * @return array{p: array<string, mixed>, s: mixed}|null
     */
    private static function decode(string $token): ?array
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        $envelope = json_decode($decoded, true);
        if (! is_array($envelope) || ! isset($envelope['p'], $envelope['s']) || ! is_array($envelope['p'])) {
            return null;
        }

        return $envelope;
    }

    /** Escalation-timeout-pinned TTL, clamped to the short bearer-link window. */
    private static function ttlMinutes(): int
    {
        return max(self::TTL_FLOOR_MINUTES, min(self::TTL_CEILING_MINUTES, TechnicianConfig::escalationTimeoutMinutes()));
    }

    /**
     * @return array{em: int, u: int, e: int}
     */
    private static function payload(int $emergencyId, int $userId, int $expiresAt): array
    {
        return [
            'em' => $emergencyId,
            'u' => $userId,
            'e' => $expiresAt,
        ];
    }

    private static function sign(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload), self::key());
    }

    private static function key(): string
    {
        $key = (string) Config::get('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }
}
