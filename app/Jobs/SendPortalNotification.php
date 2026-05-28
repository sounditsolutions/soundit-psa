<?php

namespace App\Jobs;

use App\Models\Person;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\EmailService;
use App\Services\Graph\GraphClientException;
use App\Support\PortalConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Send email notifications to portal-enabled contacts about ticket updates.
 */
class SendPortalNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 30;
    public array $backoff = [10, 60];

    public function __construct(
        private readonly int $personId,
        private readonly int $ticketId,
        private readonly string $eventType,
        private readonly ?string $extraContext = null,
    ) {}

    public function handle(EmailService $emailService): void
    {
        $person = Person::find($this->personId);
        if (! $person || ! $person->portal_enabled || ! $person->email) {
            return;
        }

        $mailbox = Setting::getValue('graph_mailbox');
        if (! $mailbox) {
            return;
        }

        $ticket = Ticket::find($this->ticketId);
        if (! $ticket) {
            return;
        }

        $companyName = PortalConfig::companyName();
        $portalUrl = url("/portal/tickets/{$ticket->id}");

        $subject = $this->buildSubject($ticket, $companyName);
        $body = $this->buildBody($ticket, $portalUrl);

        try {
            $emailService->sendNew(
                $person->email,
                $subject,
                $body,
                $person->full_name,
            );

            Log::info('[PortalNotification] Sent', [
                'event' => $this->eventType,
                'ticket' => $this->ticketId,
                'person' => $this->personId,
            ]);
        } catch (GraphClientException $e) {
            Log::error('[PortalNotification] Failed to send', [
                'event' => $this->eventType,
                'ticket' => $this->ticketId,
                'person' => $this->personId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function buildSubject(Ticket $ticket, string $companyName): string
    {
        $ref = "#{$ticket->id}";
        $ticketSubject = Str::limit($ticket->subject, 60);

        return match ($this->eventType) {
            'staff_reply' => "[{$ref}] New reply on: {$ticketSubject}",
            'status_resolved' => "[{$ref}] Resolved: {$ticketSubject}",
            'status_pending_client' => "[{$ref}] Action needed: {$ticketSubject}",
            default => "[{$ref}] Update on: {$ticketSubject}",
        };
    }

    private function buildBody(Ticket $ticket, string $portalUrl): string
    {
        $lines = [];

        $lines[] = match ($this->eventType) {
            'staff_reply' => 'A technician has replied to your support ticket.',
            'status_resolved' => 'Your support ticket has been marked as resolved.',
            'status_pending_client' => 'Your support ticket needs your attention.',
            default => 'There is an update on your support ticket.',
        };

        $lines[] = '';
        $lines[] = "Ticket: #{$ticket->id} — {$ticket->subject}";

        if ($this->extraContext) {
            $lines[] = '';
            $lines[] = Str::limit($this->extraContext, 500);
        }

        $lines[] = '';

        if ($this->eventType === 'status_resolved') {
            $lines[] = 'If the issue is fixed, you can confirm it resolved in the portal.';
            $lines[] = "If the issue persists, you can reopen the ticket from the portal.";
        } elseif ($this->eventType === 'status_pending_client') {
            $lines[] = 'Please reply via the portal to continue.';
        }

        $lines[] = '';
        $lines[] = 'View ticket:';
        $lines[] = $portalUrl;

        return implode("\n", $lines);
    }
}
