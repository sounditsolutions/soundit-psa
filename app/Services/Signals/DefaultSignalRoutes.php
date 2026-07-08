<?php

namespace App\Services\Signals;

use App\Models\SignalConfigLog;
use App\Models\SignalDestination;
use App\Models\SignalRoute;
use App\Models\SignalRouteStep;
use App\Support\TechnicianConfig;
use Illuminate\Support\Facades\DB;

class DefaultSignalRoutes
{
    private const LEGACY_OPERATOR_LABEL = 'Legacy operator webhook (migration)';

    public function ensureLegacyOperatorWebhookRoute(?int $userId = null): ?SignalRoute
    {
        $webhookUrl = TechnicianConfig::teamsWebhookUrl();
        if ($webhookUrl === null) {
            return null;
        }

        return DB::transaction(function () use ($userId, $webhookUrl): SignalRoute {
            [$destination, $destinationCreated] = $this->destination($webhookUrl);
            if ($destinationCreated) {
                SignalConfigLog::record($userId, 'created', $destination, [
                    'label' => $destination->label,
                    'type' => $destination->type,
                    'enabled' => false,
                    'source_setting' => 'technician_teams_webhook_url',
                ]);
            }

            [$route, $routeCreated] = $this->route();
            $stepCreated = $this->step($route, $destination);

            if ($routeCreated) {
                SignalConfigLog::record($userId, 'created', $route, [
                    'label' => $route->label,
                    'event_filter' => $route->event_filter,
                    'enabled' => false,
                    'steps' => [
                        [
                            'step_order' => 1,
                            'destination_id' => $destination->id,
                        ],
                    ],
                ]);
            } elseif ($stepCreated) {
                SignalConfigLog::record($userId, 'updated', $route, [
                    'steps' => [
                        [
                            'step_order' => 1,
                            'destination_id' => $destination->id,
                        ],
                    ],
                ]);
            }

            return $route->fresh('steps') ?? $route;
        });
    }

    /** @return array{0:SignalDestination, 1:bool} */
    private function destination(string $webhookUrl): array
    {
        $destination = SignalDestination::query()
            ->where('label', self::LEGACY_OPERATOR_LABEL)
            ->where('type', 'webhook')
            ->first();

        if ($destination !== null) {
            return [$destination, false];
        }

        return [
            SignalDestination::create([
                'label' => self::LEGACY_OPERATOR_LABEL,
                'type' => 'webhook',
                'address' => $webhookUrl,
                'enabled' => false,
            ]),
            true,
        ];
    }

    /** @return array{0:SignalRoute, 1:bool} */
    private function route(): array
    {
        $route = SignalRoute::query()
            ->where('label', self::LEGACY_OPERATOR_LABEL)
            ->first();

        if ($route !== null) {
            return [$route, false];
        }

        return [
            SignalRoute::create([
                'label' => self::LEGACY_OPERATOR_LABEL,
                'event_filter' => ['types' => ['agent.flag_attention']],
                'enabled' => false,
                'cooldown_seconds' => 300,
            ]),
            true,
        ];
    }

    private function step(SignalRoute $route, SignalDestination $destination): bool
    {
        $step = SignalRouteStep::query()
            ->where('route_id', $route->id)
            ->where('step_order', 1)
            ->where('destination_id', $destination->id)
            ->first();

        if ($step !== null) {
            return false;
        }

        SignalRouteStep::create([
            'route_id' => $route->id,
            'step_order' => 1,
            'destination_id' => $destination->id,
        ]);

        return true;
    }
}
