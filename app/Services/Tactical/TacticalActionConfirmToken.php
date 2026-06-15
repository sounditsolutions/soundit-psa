<?php

namespace App\Services\Tactical;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * A short-lived, signed confirmation token for a DESTRUCTIVE Tactical action
 * (spec §11.3 / amendments M8, m3).
 *
 * The token is HMAC-bound to the tuple {action_key, agent_id, actor_id,
 * payloadHash, expires_at} so a confirmation cannot be blind-replayed against a
 * different endpoint, a different action, by a different actor, or — once P3's
 * ad-hoc `cmd` arrives — with a different command (payloadHash = sha256 of the
 * canonical resolved command; null for Reboot, which has no free-text payload).
 *
 * Signing: hash_hmac('sha256', json_encode($tuple), APP_KEY). The tuple is
 * json_encoded (NOT raw-concatenated) to avoid field-confusion, and expires_at
 * lives INSIDE the signed payload so it can't be extended without re-signing.
 * Verification recomputes the expected signature from the SUPPLIED tuple + the
 * token's own claimed expires_at and hash_equals() it against the token's
 * signature — one constant-time comparison that simultaneously validates the
 * signature and that every bound field matches.
 */
class TacticalActionConfirmToken
{
    /** Time-to-live in seconds (~10 min — amendment m3). */
    public const TTL_SECONDS = 600;

    /**
     * Issue an opaque token bound to the tuple, valid for TTL_SECONDS.
     */
    public static function issue(
        string $actionKey,
        string $agentId,
        ?int $actorId,
        ?string $payloadHash = null,
    ): string {
        $expiresAt = Carbon::now()->getTimestamp() + self::TTL_SECONDS;

        $payload = self::payload($actionKey, $agentId, $actorId, $payloadHash, $expiresAt);
        $signature = self::sign($payload);

        $envelope = json_encode([
            'p' => $payload,
            's' => $signature,
        ]);

        return rtrim(strtr(base64_encode($envelope), '+/', '-_'), '=');
    }

    /**
     * True iff $token is a well-formed, untampered token bound to exactly this
     * tuple and not yet expired. Any decode/shape failure returns false (never
     * throws).
     */
    public static function verify(
        string $token,
        string $actionKey,
        string $agentId,
        ?int $actorId,
        ?string $payloadHash = null,
    ): bool {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false || $decoded === '') {
            return false;
        }

        $envelope = json_decode($decoded, true);
        if (! is_array($envelope) || ! isset($envelope['p'], $envelope['s']) || ! is_array($envelope['p'])) {
            return false;
        }

        $claimedExpiry = $envelope['p']['e'] ?? null;
        if (! is_int($claimedExpiry)) {
            return false;
        }

        // Recompute the expected signature from the SUPPLIED tuple, reusing the
        // token's own expiry. If any bound field differs, the signature won't
        // match — one constant-time check covers signature + every field.
        $expectedPayload = self::payload($actionKey, $agentId, $actorId, $payloadHash, $claimedExpiry);
        $expectedSignature = self::sign($expectedPayload);

        $providedSignature = is_string($envelope['s']) ? $envelope['s'] : '';
        if (! hash_equals($expectedSignature, $providedSignature)) {
            return false;
        }

        // Signature good ⇒ expiry is authentic ⇒ enforce the TTL.
        return Carbon::now()->getTimestamp() <= $claimedExpiry;
    }

    /**
     * The canonical, order-fixed signed payload. Keys are short + explicit; the
     * structure (not a flat concat) is what prevents tuple-confusion.
     *
     * @return array{a: string, g: string, u: int|null, h: string|null, e: int}
     */
    private static function payload(
        string $actionKey,
        string $agentId,
        ?int $actorId,
        ?string $payloadHash,
        int $expiresAt,
    ): array {
        return [
            'a' => $actionKey,
            'g' => $agentId,
            'u' => $actorId,
            'h' => $payloadHash,
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

        // Laravel stores APP_KEY as "base64:...."; use the decoded raw bytes so
        // the HMAC key is the actual application secret.
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }
}
