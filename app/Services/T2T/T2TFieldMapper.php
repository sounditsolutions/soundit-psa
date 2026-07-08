<?php

namespace App\Services\T2T;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Ticket;

class T2TFieldMapper
{
    // ── Boards ──

    public static function boards(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Service Desk',
                'locationId' => 1,
                'businessUnitId' => 1,
                'inactiveFlag' => false,
                'projectFlag' => false,
                'location' => ['id' => 1, 'name' => 'Default'],
                'businessUnit' => ['id' => 1, 'name' => 'Default'],
                '_info' => ['lastUpdated' => '2026-01-01T00:00:00Z'],
            ],
        ];
    }

    // ── Board Types → TicketType ──

    private const TYPE_MAP = [
        1 => 'incident',
        2 => 'service_request',
        3 => 'change',
        4 => 'problem',
    ];

    public static function boardTypes(int $boardId): array
    {
        return [
            ['id' => 1, 'name' => 'Incident', 'board' => ['id' => $boardId], 'inactiveFlag' => false, 'defaultFlag' => true],
            ['id' => 2, 'name' => 'Service Request', 'board' => ['id' => $boardId], 'inactiveFlag' => false, 'defaultFlag' => false],
            ['id' => 3, 'name' => 'Change', 'board' => ['id' => $boardId], 'inactiveFlag' => false, 'defaultFlag' => false],
            ['id' => 4, 'name' => 'Problem', 'board' => ['id' => $boardId], 'inactiveFlag' => false, 'defaultFlag' => false],
        ];
    }

    public static function cwTypeToTicketType(?int $cwTypeId): TicketType
    {
        $value = self::TYPE_MAP[$cwTypeId] ?? 'incident';

        return TicketType::from($value);
    }

    public static function ticketTypeToCwId(TicketType $type): int
    {
        return match ($type) {
            TicketType::Incident => 1,
            TicketType::ServiceRequest => 2,
            TicketType::Change => 3,
            TicketType::Problem => 4,
        };
    }

    // ── Board Statuses → TicketStatus ──

    private const STATUS_MAP = [
        1 => 'new',
        2 => 'in_progress',
        3 => 'pending_client',
        4 => 'pending_third_party',
        5 => 'resolved',
        6 => 'closed',
    ];

    public static function boardStatuses(int $boardId): array
    {
        return [
            ['id' => 1, 'name' => 'New', 'board' => ['id' => $boardId], 'sortOrder' => 1, 'displayOnBoard' => true, 'inactiveFlag' => false, 'closedStatus' => false, 'defaultFlag' => true],
            ['id' => 2, 'name' => 'In Progress', 'board' => ['id' => $boardId], 'sortOrder' => 2, 'displayOnBoard' => true, 'inactiveFlag' => false, 'closedStatus' => false, 'defaultFlag' => false],
            ['id' => 3, 'name' => 'Pending Client', 'board' => ['id' => $boardId], 'sortOrder' => 3, 'displayOnBoard' => true, 'inactiveFlag' => false, 'closedStatus' => false, 'defaultFlag' => false],
            ['id' => 4, 'name' => 'Pending Third Party', 'board' => ['id' => $boardId], 'sortOrder' => 4, 'displayOnBoard' => true, 'inactiveFlag' => false, 'closedStatus' => false, 'defaultFlag' => false],
            ['id' => 5, 'name' => 'Resolved', 'board' => ['id' => $boardId], 'sortOrder' => 5, 'displayOnBoard' => true, 'inactiveFlag' => false, 'closedStatus' => true, 'defaultFlag' => false],
            ['id' => 6, 'name' => 'Closed', 'board' => ['id' => $boardId], 'sortOrder' => 6, 'displayOnBoard' => false, 'inactiveFlag' => false, 'closedStatus' => true, 'defaultFlag' => false],
        ];
    }

    public static function cwStatusToTicketStatus(int $cwStatusId): TicketStatus
    {
        $value = self::STATUS_MAP[$cwStatusId] ?? 'new';

        return TicketStatus::from($value);
    }

    public static function ticketStatusToCwId(TicketStatus $status): int
    {
        return match ($status) {
            TicketStatus::New => 1,
            TicketStatus::InProgress => 2,
            TicketStatus::PendingClient => 3,
            TicketStatus::PendingThirdParty => 4,
            TicketStatus::Resolved => 5,
            TicketStatus::Closed => 6,
        };
    }

    // ── Priorities → TicketPriority ──

    private const PRIORITY_MAP = [
        1 => 'p1',
        2 => 'p2',
        3 => 'p3',
        4 => 'p4',
    ];

    public static function priorities(): array
    {
        return [
            ['id' => 1, 'name' => 'P1 - Critical', 'sortOrder' => 1, 'defaultFlag' => false, 'color' => 'Red'],
            ['id' => 2, 'name' => 'P2 - High', 'sortOrder' => 2, 'defaultFlag' => false, 'color' => 'Orange'],
            ['id' => 3, 'name' => 'P3 - Medium', 'sortOrder' => 3, 'defaultFlag' => true, 'color' => 'Yellow'],
            ['id' => 4, 'name' => 'P4 - Low', 'sortOrder' => 4, 'defaultFlag' => false, 'color' => 'White'],
        ];
    }

    public static function cwPriorityToTicketPriority(?int $cwPriorityId): TicketPriority
    {
        $value = self::PRIORITY_MAP[$cwPriorityId] ?? 'p3';

        return TicketPriority::from($value);
    }

    public static function ticketPriorityToCwId(TicketPriority $priority): int
    {
        return match ($priority) {
            TicketPriority::P1 => 1,
            TicketPriority::P2 => 2,
            TicketPriority::P3 => 3,
            TicketPriority::P4 => 4,
        };
    }

    // ── Sources ──

    public static function sources(): array
    {
        return [
            ['id' => 1, 'name' => 'Helpdesk Button', 'defaultFlag' => true, 'enteredByFlag' => false],
        ];
    }

    public static function boardTeams(int $boardId): array
    {
        return [
            ['id' => 1, 'name' => 'Service Desk', 'board' => ['id' => $boardId], 'defaultFlag' => true],
        ];
    }

    public static function boardSubTypes(int $boardId): array
    {
        return [];
    }

    // ── Severities (Urgency) & Impacts — CW Manage fields HDB validates ──

    public static function severities(): array
    {
        return [
            ['id' => 1, 'name' => 'High', 'defaultFlag' => false],
            ['id' => 2, 'name' => 'Medium', 'defaultFlag' => true],
            ['id' => 3, 'name' => 'Low', 'defaultFlag' => false],
        ];
    }

    public static function impacts(): array
    {
        return [
            ['id' => 1, 'name' => 'High', 'defaultFlag' => false],
            ['id' => 2, 'name' => 'Medium', 'defaultFlag' => true],
            ['id' => 3, 'name' => 'Low', 'defaultFlag' => false],
        ];
    }

    public static function systemInfo(): array
    {
        return [
            'version' => 'v4.6.0',
            'isCloud' => true,
            'serverTimeZone' => 'UTC',
        ];
    }

    // ── Entity → CW Format Converters ──

    /**
     * Build the CW-format API base URL for _info href fields.
     */
    private static function apiBaseUrl(): string
    {
        return url('/api/tier2tickets/v4_6_release/apis/3.0');
    }

    /**
     * Build a CW-format company object with _info.company_href.
     */
    public static function companyRef(int $id, string $identifier, string $name): array
    {
        return [
            'id' => $id,
            'identifier' => $identifier,
            'name' => $name,
            '_info' => [
                'company_href' => self::apiBaseUrl().'/company/companies/'.$id,
            ],
        ];
    }

    /**
     * Convert a Client to a full CW-format company record.
     */
    public static function clientToCwCompany(Client $client): array
    {
        return [
            'id' => $client->id,
            'identifier' => $client->name,
            'name' => $client->name,
            'status' => ['id' => 1, 'name' => $client->is_active ? 'Active' : 'Inactive'],
            'phoneNumber' => $client->phone,
            'website' => $client->website,
            'deletedFlag' => false,
            '_info' => [
                'lastUpdated' => $client->updated_at?->toIso8601String(),
            ],
        ];
    }

    public static function ticketToCwFormat(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'summary' => $ticket->subject,
            'recordType' => 'ServiceTicket',
            'board' => ['id' => 1, 'name' => 'Service Desk'],
            'status' => [
                'id' => self::ticketStatusToCwId($ticket->status),
                'name' => $ticket->status->label(),
            ],
            'priority' => [
                'id' => self::ticketPriorityToCwId($ticket->priority),
                'name' => $ticket->priority->label(),
            ],
            'type' => $ticket->type ? [
                'id' => self::ticketTypeToCwId($ticket->type),
                'name' => $ticket->type->label(),
            ] : null,
            'company' => $ticket->client
                ? self::companyRef($ticket->client_id, $ticket->client->name, $ticket->client->name)
                : null,
            'contact' => $ticket->contact ? [
                'id' => $ticket->contact_id,
                'name' => $ticket->contact->full_name,
            ] : null,
            'initialDescription' => $ticket->description,
            'source' => ['id' => 1, 'name' => 'Helpdesk Button'],
            'dateEntered' => $ticket->created_at?->toIso8601String(),
            '_info' => [
                'lastUpdated' => $ticket->updated_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Build the flat (non-nested) Entity format required by T2T callbacks.
     * All field names are PascalCase, all values are plain text (not objects).
     */
    public static function ticketToCallbackEntity(Ticket $ticket, ?string $actor = null): array
    {
        $isClosed = in_array($ticket->status, [
            \App\Enums\TicketStatus::Closed,
            \App\Enums\TicketStatus::Resolved,
        ]);

        $actor = $actor ?? $ticket->assignee?->name ?? 'admin1';

        return [
            'Id' => $ticket->id,
            'ClosedFlag' => $isClosed,
            'Summary' => $ticket->subject,
            'StatusId' => self::ticketStatusToCwId($ticket->status),
            'StatusName' => $ticket->status->label(),
            'PriorityId' => self::ticketPriorityToCwId($ticket->priority),
            'PriorityName' => $ticket->priority->label(),
            'CompanyName' => $ticket->client?->name ?? '',
            'CompanyId' => $ticket->client_id,
            'ContactName' => $ticket->contact?->full_name ?? '',
            'ContactEmailAddress' => $ticket->contact?->email ?? '',
            'ContactPhoneNumber' => $ticket->contact?->phone ?? '',
            'UpdatedBy' => $actor,
            'Resources' => $ticket->assignee?->name ?? $actor,
            'AddressLine1' => $ticket->client?->address_line1 ?? '',
            'AddressLine2' => $ticket->client?->address_line2 ?? '',
            'City' => $ticket->client?->city ?? '',
            'StateIdentifier' => $ticket->client?->state ?? '',
            'Zip' => $ticket->client?->zip ?? '',
            'Country' => $ticket->client?->country ?? '',
            'InitialDescription' => $ticket->description ?? '',
            'RecordType' => 'ServiceTicket',
            'DateEntered' => $ticket->created_at?->toIso8601String(),
        ];
    }

    public static function syntheticUnregisteredContact(int $id): array
    {
        return [
            'id' => $id,
            'firstName' => 'Unregistered',
            'lastName' => 'User',
            'company' => self::companyRef(0, 'Unregistered', 'Unregistered'),
            'communicationItems' => [
                [
                    'id' => 1,
                    'type' => ['id' => 1, 'name' => 'Email'],
                    'value' => 'unregistered@helpdeskbuttons.com',
                    'communicationType' => 'Email',
                    'defaultFlag' => true,
                ],
            ],
            'inactiveFlag' => false,
        ];
    }

    public static function personToCwContact(Person $person): array
    {
        $communicationItems = [];

        if ($person->email) {
            $communicationItems[] = [
                'id' => 1,
                'type' => ['id' => 1, 'name' => 'Email'],
                'value' => $person->email,
                'communicationType' => 'Email',
                'defaultFlag' => true,
            ];
        }

        if ($person->phone) {
            $communicationItems[] = [
                'id' => 2,
                'type' => ['id' => 2, 'name' => 'Phone'],
                'value' => $person->phone_display ?? $person->phone,
                'communicationType' => 'Phone',
                'defaultFlag' => false,
            ];
        }

        return [
            'id' => $person->id,
            'firstName' => $person->first_name,
            'lastName' => $person->last_name,
            'company' => $person->client
                ? self::companyRef($person->client_id, $person->client->name, $person->client->name)
                : null,
            'communicationItems' => $communicationItems,
            'inactiveFlag' => ! $person->is_active,
        ];
    }

    public static function assetToCwConfiguration(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'name' => $asset->hostname ?? $asset->name,
            'deviceIdentifier' => $asset->hostname,
            'type' => ['id' => 1, 'name' => $asset->asset_type ?? 'Workstation'],
            'status' => ['id' => 1, 'name' => $asset->is_active ? 'Active' : 'Inactive'],
            'company' => $asset->client
                ? self::companyRef($asset->client_id, $asset->client->name, $asset->client->name)
                : null,
            'serialNumber' => $asset->serial_number,
            'ipAddress' => $asset->ip_address,
            'osType' => $asset->os,
        ];
    }
}
