<?php

namespace App\Services\Triage;

use App\Models\Ticket;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;

/**
 * Stage 1: Triage Classification.
 * Uses AI to classify a ticket's contract/billing situation.
 */
class TriageClassifier
{
    /**
     * Classify a ticket's contract/billing situation.
     */
    public static function classify(Ticket $ticket, AiClient $ai): TriageClassification
    {
        $context = ContextBuilder::buildForTicket($ticket);

        $system = Prompts::TRIAGE_SYSTEM_PROMPT."\n\n".$context;

        $userMessage = 'Analyze this ticket\'s client, contracts, and prepaid balances. '
            .'Respond with ONLY the JSON classification object.';

        Log::info('[Triage] Running triage classification', ['ticket_id' => $ticket->id]);

        $data = $ai->completeJson($system, $userMessage);

        // Add route default — always "technical" for now
        if (! isset($data['route'])) {
            $data['route'] = 'technical';
        }

        $classification = TriageClassification::fromArray($data);

        Log::info('[Triage] Classification result', [
            'ticket_id' => $ticket->id,
            'client_type' => $classification->clientType,
            'has_active_contract' => $classification->hasActiveContract,
            'work_covered' => $classification->workCoveredByManaged,
            'prepaid_balance' => $classification->prepaidBalance,
        ]);

        return $classification;
    }
}
