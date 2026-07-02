<?php

namespace App\Services\Signals;

class SignalEventTypes
{
    public static function all(): array
    {
        return [
            'ticket.created' => [
                'label' => 'Ticket created',
                'core' => true,
                'routable' => true,
            ],
            'ticket.client_replied' => [
                'label' => 'Client replied',
                'core' => true,
                'routable' => true,
            ],
            'ticket.sla_breached' => [
                'label' => 'Ticket SLA breached',
                'core' => false,
                'routable' => true,
            ],
            'ticket.sla_approaching' => [
                'label' => 'Ticket SLA approaching',
                'core' => false,
                'routable' => true,
            ],
            'intake.email_received' => [
                'label' => 'Intake email received',
                'core' => false,
                'routable' => true,
            ],
            'intake.call_received' => [
                'label' => 'Intake call received',
                'core' => false,
                'routable' => true,
            ],
            'agent.flag_attention' => [
                'label' => 'Agent flagged for attention',
                'core' => true,
                'routable' => true,
            ],
            'operator.message' => [
                'label' => 'Operator message',
                'core' => false,
                'routable' => true,
            ],
            'agent.proposal_held' => [
                'label' => 'Agent proposal held',
                'core' => false,
                'routable' => true,
            ],
            'agent.proposal_auto_closed' => [
                'label' => 'Agent proposal auto-closed',
                'core' => false,
                'routable' => true,
            ],
            'agent.run_failed' => [
                'label' => 'Agent run failed',
                'core' => false,
                'routable' => true,
            ],
            'integration.sync_failed' => [
                'label' => 'Integration sync failed',
                'core' => false,
                'routable' => true,
            ],
            'tactical.alert_created' => [
                'label' => 'Tactical alert created',
                'core' => false,
                'routable' => true,
            ],
            'signal.delivery_failed' => [
                'label' => 'Signal delivery failed',
                'core' => true,
                'routable' => true,
            ],
            'digest.daily' => [
                'label' => 'Daily digest',
                'core' => false,
                'routable' => true,
            ],
            'system.test' => [
                'label' => 'System test',
                'core' => true,
                'routable' => false,
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function routable(string $key): bool
    {
        return self::all()[$key]['routable'] ?? false;
    }
}
