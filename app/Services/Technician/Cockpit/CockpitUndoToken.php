<?php

namespace App\Services\Technician\Cockpit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CockpitUndoToken
{
    public const WINDOW_MINUTES = 5;

    /** @return array{action: string, url: string} */
    public function issue(string $targetType, int $targetId, string $action, int $userId, array $extra = []): array
    {
        $token = Str::random(40);
        $payload = array_merge([
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'user_id' => $userId,
        ], $extra);

        Cache::put($this->tokenKey($token), $payload, now()->addMinutes(self::WINDOW_MINUTES));
        Cache::put($this->currentKey($targetType, $targetId, $action), $token, now()->addMinutes(self::WINDOW_MINUTES));

        return [
            'action' => $action,
            'url' => URL::temporarySignedRoute(
                'cockpit.undo',
                now()->addMinutes(self::WINDOW_MINUTES),
                [
                    'token' => $token,
                    'user_id' => $userId,
                ],
                false,
            ),
        ];
    }

    public function isValidRequest(Request $request, int $userId): bool
    {
        return $request->hasValidRelativeSignature()
            && (int) $request->query('user_id') === $userId
            && is_string($request->query('token'));
    }

    /** @return array<string, mixed>|null */
    public function consume(string $token): ?array
    {
        $payload = Cache::pull($this->tokenKey($token));

        if (! is_array($payload)) {
            return null;
        }

        $targetType = (string) ($payload['target_type'] ?? '');
        $targetId = (int) ($payload['target_id'] ?? 0);
        $action = (string) ($payload['action'] ?? '');

        if (Cache::get($this->currentKey($targetType, $targetId, $action)) !== $token) {
            return null;
        }

        Cache::forget($this->currentKey($targetType, $targetId, $action));

        return $payload;
    }

    private function tokenKey(string $token): string
    {
        return "cockpit_undo:token:{$token}";
    }

    private function currentKey(string $targetType, int $targetId, string $action): string
    {
        return "cockpit_undo:current:{$targetType}:{$targetId}:{$action}";
    }
}
