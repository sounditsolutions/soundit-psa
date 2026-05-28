<?php

namespace App\Services\Triage;

use App\Enums\NoteType;
use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Support\AiConfig;
use App\Support\TriageConfig;
use Illuminate\Support\Facades\Log;

/**
 * Stage 3: Technical Triage.
 * Performs deep technical analysis via an agentic tool loop with Claude.
 * Writes a detailed private note and sets ticket priority.
 */
class TechnicalTriager
{
    /**
     * Run technical triage analysis on a ticket.
     *
     * @return array Result summary for the triage run record
     */
    public static function analyze(
        Ticket $ticket,
        AiClient $ai,
        ?TriageClassification $classification = null,
        \App\Services\TicketService $ticketService = null,
    ): array {
        $ticket->loadMissing(['client', 'contact', 'assets', 'attachments', 'notes.attachments']);

        $context = ContextBuilder::buildForTicket($ticket);

        // Add classification context if available
        if ($classification) {
            $context .= "\n\n## Triage Classification\n";
            $context .= 'Client type: ' . $classification->clientType . "\n";
            $context .= 'Active contract: ' . ($classification->hasActiveContract ? 'Yes' : 'No') . "\n";
            $context .= 'Work covered: ' . ($classification->workCoveredByManaged ? 'Yes' : 'No') . "\n";
            if ($classification->hasPrepaidTime) {
                $context .= 'Prepaid balance: ' . $classification->prepaidBalance . "\n";
            }
            $context .= 'Reasoning: ' . $classification->reasoning . "\n";
        }

        $system = Prompts::TECHNICAL_TRIAGE_SYSTEM_PROMPT . "\n\n" . $context;

        // Check if ticket has images for multimodal context
        $hasImages = $ticket->attachments->contains(fn ($a) => $a->isImage())
            || $ticket->notes->flatMap(fn ($n) => $n->attachments)->contains(fn ($a) => $a->isImage());

        $userPrompt = 'Analyze this ticket and provide your technical assessment. '
            . 'Search for similar past tickets, check device health if relevant, '
            . 'and set the appropriate priority.';

        if ($hasImages && AiConfig::provider() === 'anthropic') {
            $multimodalBlocks = ContextBuilder::buildMultimodalContent($ticket);
            // Prepend the instruction text, then append the image blocks
            $userContent = array_merge(
                [['type' => 'text', 'text' => $userPrompt]],
                $multimodalBlocks,
            );

            Log::info('[Triage] Using multimodal content for technical triage', [
                'ticket_id' => $ticket->id,
                'content_blocks' => count($userContent),
            ]);
        } else {
            $userContent = $userPrompt;
        }

        $tools = TriageToolDefinitions::getTools();
        $executor = new TriageToolExecutor($ticket);

        $maxTokens = TriageConfig::maxTokensPerRun();

        Log::info('[Triage] Starting technical triage', [
            'ticket_id' => $ticket->id,
            'tools_available' => count($tools),
            'multimodal' => is_array($userContent),
        ]);

        $response = $ai->runToolLoop(
            system: $system,
            userMessage: $userContent,
            tools: $tools,
            executor: fn (string $name, array $input) => $executor->execute($name, $input),
            maxRounds: 10,
            maxTokenBudget: $maxTokens,
            wallClockSeconds: 240,
        );

        $analysisText = $response->text;

        if (! $analysisText) {
            Log::warning('[Triage] Technical triage produced no output', ['ticket_id' => $ticket->id]);

            return [
                'status' => 'no_output',
                'tokens' => $response->totalTokens(),
            ];
        }

        // Write the analysis as a private AI triage note
        $systemUserId = TriageConfig::systemUserId();
        if ($systemUserId && $ticketService) {
            $ticketService->addNote(
                $ticket,
                $analysisText,
                NoteType::AiTriage,
                true, // private
                $systemUserId,
            );

            Log::info('[Triage] Technical triage note written', [
                'ticket_id' => $ticket->id,
                'note_length' => strlen($analysisText),
            ]);
        }

        // Auto-assign ticket if unassigned
        self::autoAssignIfNeeded($ticket, $systemUserId, $ticketService);

        return [
            'status' => 'completed',
            'note_length' => strlen($analysisText),
            'tokens' => $response->totalTokens(),
            'summary' => mb_substr($analysisText, 0, 200),
        ];
    }

    /**
     * Auto-assign the ticket to the appropriate technician.
     */
    private static function autoAssignIfNeeded(
        Ticket $ticket,
        ?int $systemUserId,
        ?\App\Services\TicketService $ticketService,
    ): void {
        // Only assign if currently unassigned
        if ($ticket->assignee_id || ! $systemUserId || ! $ticketService) {
            return;
        }

        $assigneeId = $ticket->client?->primary_tech_id ?? TriageConfig::defaultAssigneeId();

        if ($assigneeId) {
            $ticketService->assignTicket($ticket, $assigneeId, $systemUserId);

            Log::info('[Triage] Ticket auto-assigned', [
                'ticket_id' => $ticket->id,
                'assignee_id' => $assigneeId,
                'source' => $ticket->client?->primary_tech_id ? 'primary_tech' : 'default',
            ]);
        } else {
            Log::warning('[Triage] No assignee found — client has no primary_tech_id and no default assignee configured', [
                'ticket_id' => $ticket->id,
                'client_id' => $ticket->client_id,
            ]);
        }
    }
}
