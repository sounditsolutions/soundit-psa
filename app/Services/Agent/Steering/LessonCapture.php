<?php

namespace App\Services\Agent\Steering;

use App\Enums\ToolingGapClassification;
use App\Enums\ToolingGapSource;
use App\Jobs\ComposeClientOverview;
use App\Models\AssistantConversation;
use App\Models\Ticket;
use App\Models\ToolingGap;
use App\Models\WikiPage;
use App\Services\Triage\ContextBuilder;
use App\Services\Wiki\Mining\WikiFactExtractor;
use App\Services\Wiki\WikiComposerService;
use App\Services\Wiki\WikiFactService;
use App\Services\Wiki\WikiSkeletonService;
use App\Support\WikiConfig;
use Illuminate\Support\Facades\Log;

class LessonCapture
{
    public function __construct(
        private readonly LessonDistiller $distiller,
        private readonly WikiFactService $facts,
        private readonly WikiSkeletonService $skeleton,
        private readonly WikiComposerService $composer,
    ) {}

    public function capture(Ticket $ticket, AssistantConversation $correction): void
    {
        try {
            $client = $ticket->client;
            if ($client === null) {
                return; // a lesson is client-scoped knowledge
            }

            // The operator's correction text = the latest user message of this conversation.
            // reorder() clears the messages() default orderBy('id') ASC so latest() truly wins.
            $text = (string) optional(
                $correction->messages()->where('role', 'user')->reorder()->orderByDesc('id')->first()
            )->content;
            if (trim($text) === '') {
                return;
            }

            $candidate = $this->distiller->distill($text, ContextBuilder::buildForTicket($ticket, true));
            if ($candidate === null || $candidate->type === 'none') {
                return; // nothing durable to learn
            }

            if ($candidate->isTooling()) {
                // RETRIEVE loop (Inc 3): a correction revealing the agent missed retrievable info becomes a
                // DURABLE, VISIBLE tooling-gap backlog row (replacing Inc 2's filtered Log::info seam). The
                // abstract, forwardable capability_gap is stored SEPARATELY from the instance-private evidence
                // (multi-MSP seam). Independent of the wiki — recorded whenever the agent ran (dormancy already
                // gated upstream).
                ToolingGap::record(
                    ticketId: $ticket->id,
                    clientId: $client->id,
                    capabilityGap: $candidate->statement,                         // abstract / forwardable
                    evidence: "Ticket #{$ticket->id}: ".mb_substr($text, 0, 300), // instance-private (the correction)
                    classification: ToolingGapClassification::ToolUnused,          // a correction = the data WAS available, not used
                    source: ToolingGapSource::Correction,
                );

                return;
            }

            // knowledge → write a durable, pinned, Correction-sourced fact (NO approval queue).

            // Gate: no wiki = nowhere to store/inject the lesson → no-op (additive, dormant-safe).
            if (! WikiConfig::isEnabled()) {
                return;
            }

            // Belt-and-suspenders: only ever write to a staff TARGETS page — NEVER the injection-guarded
            // Overview. The distiller already enforces this, but re-check at the write point because 'overview'
            // is a real skeleton page that the "page missing" guard below would NOT catch.
            if (! array_key_exists((string) $candidate->page, WikiFactExtractor::TARGETS)) {
                return;
            }

            $this->skeleton->ensureForClient($client);
            $page = WikiPage::forClient($client->id)->where('slug', $candidate->page)->first();
            if ($page === null) {
                Log::warning('[Steering][LessonCapture] target wiki page missing', [
                    'client_id' => $client->id, 'page' => $candidate->page,
                ]);

                return;
            }

            $this->facts->upsertCorrectionFact(
                $page,
                $candidate->anchor,
                $candidate->subjectKey,
                $candidate->statement,
                [['type' => 'correction', 'conversation_id' => $correction->id]],
            );

            // Make it visible to the agent: recompose the staff section deterministically, then queue the
            // (AI) overview recompose — ContextBuilder injects the composed overview, closing the loop.
            $this->composer->composeSection($page->fresh(), $candidate->anchor);
            ComposeClientOverview::dispatch($client->id);
        } catch (\Throwable $e) {
            // Fail-soft: a capture error must NEVER break the agent run / fail the job (the agent has
            // already acted by the time we're called). Swallow + log.
            Log::warning('[Steering][LessonCapture] capture failed (non-fatal)', [
                'ticket_id' => $ticket->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
