<?php

namespace App\Services\Technician;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * A short-lived, signed approval grant for a non-AUTO Technician action
 * (spec §4.5/§7). Copied from TacticalActionConfirmToken: HMAC-bound to the
 * tuple {action_type, ticket_id, content_hash, approver_user_id, expires_at} so
 * it cannot be replayed against a different action, ticket, content, or approver.
 *
 * Phase 0 only VERIFIES grants at the gate (no human-approval UI yet); issuance
 * exists so the verify path is testable and the full approval round-trip
 * (cockpit / Teams) can extend it in Phase 1.
 */
class TechnicianApprovalGrant
{
    /** Time-to-live in seconds (~10 min). */
    public const TTL_SECONDS = 600;

    public static function issue(
        string $actionType,
        int $ticketId,
        string $contentHash,
        ?int $approverUserId,
    ): string {
        $expiresAt = Carbon::now()->getTimestamp() + self::TTL_SECONDS;

        $payload = self::payload($actionType, $ticketId, $contentHash, $approverUserId, $expiresAt);
        $signature = self::sign($payload);

        $envelope = json_encode(['p' => $payload, 's' => $signature]);

        return rtrim(strtr(base64_encode($envelope), '+/', '-_'), '=');
    }

    public static function verify(
        string $token,
        string $actionType,
        int $ticketId,
        string $contentHash,
        ?int $approverUserId,
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

        $expectedPayload = self::payload($actionType, $ticketId, $contentHash, $approverUserId, $claimedExpiry);
        $expectedSignature = self::sign($expectedPayload);

        $providedSignature = is_string($envelope['s']) ? $envelope['s'] : '';
        if (! hash_equals($expectedSignature, $providedSignature)) {
            return false;
        }

        return Carbon::now()->getTimestamp() <= $claimedExpiry;
    }

    /**
     * @return array{a: string, t: int, h: string, u: int|null, e: int}
     */
    private static function payload(
        string $actionType,
        int $ticketId,
        string $contentHash,
        ?int $approverUserId,
        int $expiresAt,
    ): array {
        return [
            'a' => $actionType,
            't' => $ticketId,
            'h' => $contentHash,
            'u' => $approverUserId,
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
