<?php

namespace App\Services\Tactical\Actions;

use App\Services\Tactical\TacticalClient;

class ServiceStartTypeAction implements TacticalAction
{
    /** @var array<int, string> */
    public const ALLOWED_START_TYPES = ['auto', 'autodelay', 'manual', 'disabled'];

    public function key(): string
    {
        return 'tactical.service_start_type';
    }

    public function isDestructive(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{service_name: string, start_type: string}
     */
    public function validateParams(array $params): array
    {
        $serviceName = isset($params['service_name']) && is_scalar($params['service_name'])
            ? trim((string) $params['service_name'])
            : '';
        if ($serviceName === '') {
            throw new InvalidActionParams('service_name is required.');
        }

        $startType = self::normalizeStartType($params['start_type'] ?? null);
        if ($startType === null) {
            throw new InvalidActionParams('start_type must be one of: '.implode(', ', self::ALLOWED_START_TYPES).'.');
        }

        return [
            'service_name' => $serviceName,
            'start_type' => $startType,
        ];
    }

    public static function normalizeStartType(mixed $value): ?string
    {
        $startType = is_scalar($value) ? mb_strtolower(trim((string) $value)) : '';

        return in_array($startType, self::ALLOWED_START_TYPES, true) ? $startType : null;
    }

    /** @param array<string, mixed> $params */
    public function summary(array $params): string
    {
        return 'Set service '.(string) ($params['service_name'] ?? 'service').' start type to '.(string) ($params['start_type'] ?? 'unknown');
    }

    /** @param array<string, mixed> $params */
    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
    {
        $result = $client->setServiceStartType($agentId, (string) $params['service_name'], (string) $params['start_type']);

        return TacticalActionResult::ok(is_scalar($result) ? (string) $result : 'Service start type updated.');
    }
}
