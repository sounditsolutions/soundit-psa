<?php

namespace App\Services\Tactical\Actions;

use App\Services\Tactical\TacticalClient;

class PatchAction implements TacticalAction
{
    /** @var array<int, string> */
    public const ALLOWED_ACTIONS = ['approve', 'ignore', 'nothing', 'inherit'];

    public function key(): string
    {
        return 'tactical.patch_action';
    }

    public function isDestructive(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{patch_id: int, action: string}
     */
    public function validateParams(array $params): array
    {
        $patchId = $this->positiveInteger($params['patch_id'] ?? null);
        if ($patchId === null) {
            throw new InvalidActionParams('patch_id is required.');
        }

        $action = self::normalizeAction($params['action'] ?? null);
        if ($action === null) {
            throw new InvalidActionParams('action must be one of: '.implode(', ', self::ALLOWED_ACTIONS).'.');
        }

        return [
            'patch_id' => $patchId,
            'action' => $action,
        ];
    }

    public static function normalizeAction(mixed $value): ?string
    {
        $action = is_scalar($value) ? mb_strtolower(trim((string) $value)) : '';

        return in_array($action, self::ALLOWED_ACTIONS, true) ? $action : null;
    }

    /** @param array<string, mixed> $params */
    public function summary(array $params): string
    {
        return 'Set Windows update #'.(string) ($params['patch_id'] ?? 'unknown').' action to '.(string) ($params['action'] ?? 'unknown');
    }

    /** @param array<string, mixed> $params */
    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
    {
        $result = $client->setPatchAction((int) $params['patch_id'], (string) $params['action']);

        return TacticalActionResult::ok(is_scalar($result) ? (string) $result : 'Windows update action changed.');
    }

    /** @param array<string, mixed> $params */
    public function payloadHash(array $params): string
    {
        return hash('sha256', json_encode([
            'patch_id' => (int) ($params['patch_id'] ?? 0),
            'action' => (string) ($params['action'] ?? ''),
        ], JSON_THROW_ON_ERROR));
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
