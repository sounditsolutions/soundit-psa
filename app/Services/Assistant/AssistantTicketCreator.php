<?php

namespace App\Services\Assistant;

use App\Enums\TicketSource;
use App\Enums\TicketType;
use App\Models\Ticket;
use App\Services\TicketService;
use InvalidArgumentException;

class AssistantTicketCreator
{
    public function __construct(
        private readonly TicketService $tickets,
    ) {}

    /** @return array{client_id: int, subject: string, description: string, priority: string, type: string, source: string} */
    public function payload(?int $clientId, array $input): array
    {
        if (! $clientId) {
            throw new InvalidArgumentException('No client context - cannot create ticket');
        }

        $subject = $this->requiredString($input['subject'] ?? null);
        $description = $this->requiredString($input['description'] ?? null);
        if ($subject === null || $description === null) {
            throw new InvalidArgumentException('subject and description are required');
        }

        $priorityLevel = $input['priority'] ?? 3;
        $priorityMap = [1 => 'p1', 2 => 'p2', 3 => 'p3', 4 => 'p4'];
        $priorityKey = is_numeric($priorityLevel) ? (int) $priorityLevel : 3;
        $priority = $priorityMap[$priorityKey] ?? 'p3';

        return [
            'client_id' => $clientId,
            'subject' => $subject,
            'description' => $description,
            'priority' => $priority,
            'type' => TicketType::ServiceRequest->value,
            'source' => TicketSource::Assistant->value,
        ];
    }

    public function create(?int $clientId, array $input, ?int $createdByUserId): Ticket
    {
        return $this->createFromPayload($this->payload($clientId, $input), $createdByUserId);
    }

    /** @param  array{client_id: int, subject: string, description: string, priority: string, type: string, source: string}  $payload */
    public function createFromPayload(array $payload, ?int $createdByUserId): Ticket
    {
        return $this->tickets->createTicket($payload, $createdByUserId);
    }

    /** @param  array{client_id: int, subject: string, description: string}  $payload */
    public function contentHashFromPayload(array $payload): string
    {
        return hash('sha256', implode(':', [
            'create_ticket',
            $payload['client_id'],
            $this->normalizedHashText($payload['subject']),
            $this->normalizedHashText($payload['description']),
        ]));
    }

    private function requiredString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizedHashText(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/', ' ', trim($value)));
    }
}
