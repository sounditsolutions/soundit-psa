<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Person;
use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Services\Triage\ContextBuilder;
use App\Support\AiConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReplyDraftService
{
    private const MAX_TOKENS = 2000;

    /**
     * Generate an AI draft reply for a ticket.
     *
     * @return array{draft: string, to: string|null, cc: string[], status: string|null, input_tokens: int, output_tokens: int}
     *
     * @throws \RuntimeException if AI is not configured
     */
    public function generateDraft(Ticket $ticket, ?string $instructions = null, ?string $techName = null): array
    {
        if (! AiConfig::isConfigured()) {
            throw new \RuntimeException('AI is not configured. Set it up in Settings > Integrations.');
        }

        // Rate limit: one draft per ticket per 10 seconds
        $cacheKey = "reply_draft:{$ticket->id}";
        if (! Cache::add($cacheKey, true, 10)) {
            throw new RateLimitException('Please wait a few seconds before generating another draft.');
        }

        // Build context: ticket/client/contract/asset info (without notes to avoid duplication)
        $context = ContextBuilder::buildForTicket($ticket, skipNotes: true);

        // Build conversation context: all notes (including private) so the AI has full technical context
        $conversation = ContextBuilder::buildConversationContext($ticket, publicOnly: false);

        // Build user message
        $userMessage = $this->buildUserMessage($ticket, $context, $conversation, $instructions, $techName);

        $aiClient = new AiClient;
        $result = $aiClient->completeJson(ReplyDraftPrompts::SYSTEM_PROMPT, $userMessage, self::MAX_TOKENS);

        $draft = $this->cleanDraft($result['draft'] ?? '');
        $to = $this->sanitizeEmail($result['to'] ?? null, $ticket);
        $cc = $this->sanitizeCcEmails($result['cc'] ?? [], $ticket);
        $status = $this->sanitizeStatus($result['status'] ?? null, $ticket);

        Log::info('[ReplyDraft] Draft generated', [
            'ticket_id' => $ticket->id,
            'has_instructions' => $instructions !== null,
            'to' => $to,
            'cc' => $cc,
            'suggested_status' => $status,
            'input_tokens' => $aiClient->cumulativeInputTokens(),
            'output_tokens' => $aiClient->cumulativeOutputTokens(),
        ]);

        return [
            'draft' => $draft,
            'to' => $to,
            'cc' => $cc,
            'status' => $status,
            'input_tokens' => $aiClient->cumulativeInputTokens(),
            'output_tokens' => $aiClient->cumulativeOutputTokens(),
        ];
    }

    private function buildUserMessage(
        Ticket $ticket,
        string $context,
        string $conversation,
        ?string $instructions,
        ?string $techName = null,
    ): string {
        $contactName = $ticket->contact?->first_name ?? $ticket->contact?->full_name ?? 'there';

        $parts = [
            'Generate a client-facing reply for this ticket.',
            "CONTACT FIRST NAME: {$contactName}",
        ];

        if ($techName) {
            $parts[] = "YOU ARE: {$techName} (the technician writing this reply)";
        }

        // Available contacts for recipient selection
        $parts[] = $this->buildContactList($ticket);

        // Inject MSP communication guidelines from Settings (if configured)
        $guidelines = AiConfig::replyGuidelines();
        if ($guidelines) {
            $parts[] = "COMMUNICATION GUIDELINES:\n{$guidelines}";
        }

        // Allowed status transitions for this ticket (so AI only suggests valid ones)
        $parts[] = $this->buildAllowedTransitions($ticket);

        if ($instructions) {
            $parts[] = "TECHNICIAN INSTRUCTIONS: {$instructions}";
        }

        $parts[] = $context;
        $parts[] = $conversation;

        return implode("\n\n", $parts);
    }

    /**
     * Build a list of available client contacts with emails for recipient selection.
     */
    private function buildContactList(Ticket $ticket): string
    {
        $contacts = collect();

        if ($ticket->client_id) {
            $contacts = $ticket->client->people()
                ->with('emailAddresses')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->where('is_active', true)
                ->get();
        }

        if ($contacts->isEmpty() && $ticket->contact?->email) {
            return "AVAILABLE CONTACTS:\n- {$ticket->contact->full_name} <{$ticket->contact->email}> (ticket contact)";
        }

        if ($contacts->isEmpty()) {
            return "AVAILABLE CONTACTS:\nNo contacts with email addresses found.";
        }

        $ticketContactEmail = $ticket->contact?->email;
        $lines = ['AVAILABLE CONTACTS:'];
        foreach ($contacts as $person) {
            $label = trim("{$person->first_name} {$person->last_name}");
            $marker = ($person->email === $ticketContactEmail) ? ' (ticket contact)' : '';
            $allEmails = $person->allEmailAddresses();
            $primary = $person->email;
            $extras = array_filter($allEmails, fn ($e) => $e !== mb_strtolower($primary));
            $extraStr = $extras ? ' (also: '.implode(', ', $extras).')' : '';
            $lines[] = "- {$label} <{$person->email}>{$marker}{$extraStr}";
        }

        return implode("\n", $lines);
    }

    /**
     * Build the allowed status transitions for the AI prompt.
     */
    private function buildAllowedTransitions(Ticket $ticket): string
    {
        $transitions = $ticket->status->allowedTransitions();
        $lines = ['ALLOWED STATUS TRANSITIONS:'];

        foreach ($transitions as $status) {
            // Exclude 'closed' — that's an administrative action, not an AI suggestion
            if ($status === TicketStatus::Closed) {
                continue;
            }
            $lines[] = "- {$status->value} ({$status->label()})";
        }

        if (count($lines) === 1) {
            $lines[] = '- none (no transitions available)';
        }

        return implode("\n", $lines);
    }

    /**
     * Validate the AI-suggested status against allowed transitions.
     * Returns the enum value string if valid, null otherwise.
     */
    private function sanitizeStatus(mixed $status, Ticket $ticket): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $statusEnum = TicketStatus::tryFrom($status);
        if (! $statusEnum) {
            return null;
        }

        // Never suggest 'closed' even if allowed
        if ($statusEnum === TicketStatus::Closed) {
            return null;
        }

        if (! in_array($statusEnum, $ticket->status->allowedTransitions(), true)) {
            return null;
        }

        return $statusEnum->value;
    }

    /**
     * Validate that the AI-suggested TO email belongs to an actual client contact.
     * Falls back to the ticket's contact email if invalid.
     */
    private function sanitizeEmail(?string $email, Ticket $ticket): ?string
    {
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $ticket->contact?->email;
        }

        // Verify it belongs to a real contact on this client
        if ($ticket->client_id) {
            $exists = Person::where('client_id', $ticket->client_id)
                ->whereEmailMatch($email)
                ->exists();
            if ($exists) {
                return $email;
            }
        }

        return $ticket->contact?->email;
    }

    /**
     * Filter CC emails to only include valid client contacts. Remove the TO address.
     */
    private function sanitizeCcEmails(array $emails, Ticket $ticket): array
    {
        if (empty($emails) || ! $ticket->client_id) {
            return [];
        }

        // Check each CC address against client contacts (including additional emails)
        $normalizedEmails = array_map(fn ($e) => mb_strtolower(trim($e)), $emails);

        $validEmails = [];
        foreach ($normalizedEmails as $email) {
            if (Person::where('client_id', $ticket->client_id)->whereEmailMatch($email)->exists()) {
                $validEmails[] = $email;
            }
        }

        return $validEmails;
    }

    /**
     * Clean up the AI draft: strip accidental email headers, refusal preambles, etc.
     */
    private function cleanDraft(string $text): string
    {
        $text = trim($text);

        // Strip email header lines that the AI might prepend despite instructions
        $text = preg_replace('/^(Subject|To|From|Cc|Bcc):\s*.+\n/im', '', $text);

        // Strip common AI preambles
        $text = preg_replace('/^(Here\'s a draft[:\s]*|Draft reply[:\s]*)/i', '', $text);

        return trim($text);
    }
}
