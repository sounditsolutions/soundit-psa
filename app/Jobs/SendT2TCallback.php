<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\T2T\T2TFieldMapper;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendT2TCallback implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 30;
    public array $backoff = [10, 60];

    public function __construct(
        private readonly int $ticketId,
        private readonly string $callbackUrl,
    ) {}

    public function handle(): void
    {
        $ticket = Ticket::with(['client', 'contact', 'assignee'])->find($this->ticketId);

        if (! $ticket) {
            Log::warning('[T2T] Callback: ticket not found', ['ticket_id' => $this->ticketId]);

            return;
        }

        $updatedBy = $ticket->assignee?->name ?? 'admin1';
        $entity = T2TFieldMapper::ticketToCallbackEntity($ticket, $updatedBy);

        $payload = [
            'Action' => 'updated',
            'ID' => $ticket->id,
            'Type' => 'ticket',
            'MemberId' => $updatedBy,
            'Entity' => json_encode($entity),
        ];

        // T2T expects query string parameters alongside the JSON body
        $queryParams = [
            (string) $ticket->id => '',
            'action' => 'updated',
            'isInternalAnalysis' => 'False',
            'isProblemDescription' => 'False',
            'isResolution' => 'False',
            'memberId' => $updatedBy,
            'notes' => '',
        ];

        $url = $this->callbackUrl . '?' . http_build_query($queryParams);

        try {
            $client = new GuzzleClient(['timeout' => 15]);

            $client->post($url, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            Log::info('[T2T] Callback sent', [
                'ticket_id' => $ticket->id,
                'url' => $this->callbackUrl,
                'status' => $ticket->status->value,
            ]);
        } catch (\Throwable $e) {
            Log::error('[T2T] Callback failed', [
                'ticket_id' => $ticket->id,
                'url' => $this->callbackUrl,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Rethrow for retry
        }
    }
}
