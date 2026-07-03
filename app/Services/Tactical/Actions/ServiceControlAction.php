<?php

namespace App\Services\Tactical\Actions;

use App\Services\Tactical\TacticalClient;

class ServiceControlAction implements TacticalAction
{
    private const OPERATIONS = ['start', 'stop', 'restart'];

    public function __construct(private readonly string $operation)
    {
        if (! in_array($this->operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException('Unsupported service operation.');
        }
    }

    public function key(): string
    {
        return 'tactical.service_'.$this->operation;
    }

    public function isDestructive(): bool
    {
        return in_array($this->operation, ['stop', 'restart'], true);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{service_name: string}
     */
    public function validateParams(array $params): array
    {
        $serviceName = isset($params['service_name']) && is_scalar($params['service_name'])
            ? trim((string) $params['service_name'])
            : '';

        if ($serviceName === '') {
            throw new InvalidActionParams('service_name is required.');
        }

        return ['service_name' => $serviceName];
    }

    /** @param array<string, mixed> $params */
    public function summary(array $params): string
    {
        $serviceName = (string) ($params['service_name'] ?? 'service');

        return ucfirst($this->operation).' service '.$serviceName;
    }

    /** @param array<string, mixed> $params */
    public function execute(TacticalClient $client, string $agentId, array $params): TacticalActionResult
    {
        $result = $client->controlService($agentId, (string) $params['service_name'], $this->operation);

        return TacticalActionResult::ok(is_scalar($result) ? (string) $result : 'Service action sent.');
    }

    /** @param array<string, mixed> $params */
    public function payloadHash(array $params): string
    {
        return hash('sha256', json_encode([
            'operation' => $this->operation,
            'service_name' => (string) ($params['service_name'] ?? ''),
        ], JSON_THROW_ON_ERROR));
    }
}
