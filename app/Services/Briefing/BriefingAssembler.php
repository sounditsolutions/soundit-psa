<?php

namespace App\Services\Briefing;

use App\Enums\CallStatus;
use App\Models\Alert;
use App\Models\Client;
use App\Models\PhoneCall;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Support\AiConfig;
use App\Support\AppTimezone;
use App\Support\BriefingConfig;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Assembles one technician's daily briefing from local DB reads (no external
 * API calls beyond the optional AI suggestion), mirroring the pure-read
 * {@see \App\Services\Technician\Notify\DigestBuilder}.
 *
 * Sections (per the psa-ebw spec):
 *   (a) open tickets the technician owns, with age + priority;
 *   (b) alerts on their clients that broke overnight;
 *   (c) voicemails awaiting callback;
 *   (d) tickets at risk of SLA breach today;
 *   plus one or two AI-suggested next actions.
 *
 * "Their clients" is resolved from {@see Client::$primary_tech_id} (the only
 * staff↔client ownership signal in the schema). Alerts and voicemails are scoped
 * to those clients so the briefing stays personal; a deployment that does not set
 * primary techs simply gets the ticket-based sections.
 */
class BriefingAssembler
{
    public function assemble(User $technician): BriefingContent
    {
        $now = now();
        $tz = AppTimezone::get();
        $localNow = $now->copy()->setTimezone($tz);
        // DB stores UTC and the query builder binds Carbon values without timezone
        // conversion, so the local day boundary must be converted back to UTC
        // before it is compared against the stored *_at columns.
        $endOfLocalDayUtc = $localNow->copy()->endOfDay()->setTimezone('UTC');

        $openTickets = Ticket::open()
            ->assignedTo($technician->id)
            ->with('client')
            ->orderBy('priority_order')
            ->get();

        $clientIds = Client::where('primary_tech_id', $technician->id)
            ->where('is_active', true)
            ->pluck('id');

        $alerts = $this->overnightAlerts($clientIds, $now);
        $voicemails = $this->awaitingVoicemails($clientIds);
        $slaRisk = $this->slaRiskToday($technician->id, $endOfLocalDayUtc);

        $isEmpty = $openTickets->isEmpty()
            && $alerts->isEmpty()
            && $voicemails->isEmpty()
            && $slaRisk->isEmpty();

        // Only spend an AI call when there is actually a workload to reason about.
        $suggestions = $isEmpty
            ? null
            : $this->generateSuggestions($technician, $openTickets, $slaRisk, $alerts, $voicemails);

        $body = $this->renderBody($technician, $localNow, $openTickets, $slaRisk, $alerts, $voicemails, $suggestions);
        $subject = $this->buildSubject($localNow, $openTickets->count(), $slaRisk->count());

        return new BriefingContent(
            subject: $subject,
            body: $body,
            openTicketCount: $openTickets->count(),
            alertCount: $alerts->count(),
            voicemailCount: $voicemails->count(),
            slaRiskCount: $slaRisk->count(),
            aiSuggestionsIncluded: $suggestions !== null,
            isEmpty: $isEmpty,
        );
    }

    /** Active alerts on the technician's clients fired within the overnight window. */
    private function overnightAlerts(Collection $clientIds, Carbon $now): Collection
    {
        if ($clientIds->isEmpty()) {
            return collect();
        }

        $since = $now->copy()->subHours(BriefingConfig::overnightHours());

        return Alert::active()
            ->whereIn('client_id', $clientIds)
            ->where(function ($q) use ($since) {
                // fired_at is nullable on some ingestion paths — fall back to created_at.
                $q->where('fired_at', '>=', $since)
                    ->orWhere(function ($q2) use ($since) {
                        $q2->whereNull('fired_at')->where('created_at', '>=', $since);
                    });
            })
            ->with('client')
            ->orderByDesc('fired_at')
            ->get();
    }

    /** Voicemails on the technician's clients that still need a callback. */
    private function awaitingVoicemails(Collection $clientIds): Collection
    {
        if ($clientIds->isEmpty()) {
            return collect();
        }

        return PhoneCall::where('status', CallStatus::Voicemail)
            ->whereNull('followed_up_at')
            ->whereIn('client_id', $clientIds)
            ->with(['client', 'person'])
            ->orderByDesc('started_at')
            ->get();
    }

    /**
     * Open tickets the technician owns whose resolution or first-response SLA
     * deadline falls at or before end-of-day (i.e. will breach today — or already
     * has). Reuses the same overdue semantics as {@see Ticket::scopeOverdue()}.
     */
    private function slaRiskToday(int $technicianId, Carbon $endOfLocalDayUtc): Collection
    {
        return Ticket::open()
            ->assignedTo($technicianId)
            ->whereNull('resolved_at')
            ->where(function ($q) use ($endOfLocalDayUtc) {
                $q->where(function ($q2) use ($endOfLocalDayUtc) {
                    $q2->whereNotNull('due_at')->where('due_at', '<=', $endOfLocalDayUtc);
                })->orWhere(function ($q2) use ($endOfLocalDayUtc) {
                    $q2->whereNotNull('response_due_at')
                        ->whereNull('responded_at')
                        ->where('response_due_at', '<=', $endOfLocalDayUtc);
                });
            })
            ->with('client')
            ->orderBy('priority_order')
            ->get();
    }

    private function buildSubject(Carbon $localNow, int $openCount, int $slaCount): string
    {
        $summary = trim(sprintf(
            '%s%s',
            $openCount === 1 ? '1 open ticket' : "{$openCount} open tickets",
            $slaCount > 0 ? ", {$slaCount} SLA-risk" : '',
        ));

        return "Your daily briefing — {$localNow->format('D, M j')} ({$summary})";
    }

    private function renderBody(
        User $technician,
        Carbon $localNow,
        Collection $openTickets,
        Collection $slaRisk,
        Collection $alerts,
        Collection $voicemails,
        ?string $suggestions,
    ): string {
        $firstName = trim(explode(' ', trim($technician->name))[0] ?? $technician->name);
        $now = now();

        $lines = [];
        $lines[] = "## Good morning, {$firstName}";
        $lines[] = '';
        $lines[] = "Here's your briefing for {$localNow->format('l, F j')}.";
        $lines[] = '';
        $lines[] = sprintf(
            '**At a glance:** %d open · %d SLA-risk today · %d overnight alert%s · %d voicemail%s to return',
            $openTickets->count(),
            $slaRisk->count(),
            $alerts->count(),
            $alerts->count() === 1 ? '' : 's',
            $voicemails->count(),
            $voicemails->count() === 1 ? '' : 's',
        );

        if ($slaRisk->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "### ⚠️ SLA risk today ({$slaRisk->count()})";
            $lines[] = '';
            foreach ($slaRisk as $ticket) {
                $lines[] = sprintf(
                    '- **[%s] %s** — %s · %s · %s ([view](%s))',
                    $ticket->display_id,
                    $ticket->subject,
                    $ticket->client?->name ?? 'No client',
                    $ticket->priority->label(),
                    $this->slaPhrase($ticket, $now),
                    route('tickets.show', $ticket),
                );
            }
        }

        if ($openTickets->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "### Your open tickets ({$openTickets->count()})";
            $lines[] = '';
            $limit = BriefingConfig::maxTicketsListed();
            foreach ($openTickets->take($limit) as $ticket) {
                $opened = $ticket->opened_at ?? $ticket->created_at;
                $lines[] = sprintf(
                    '- **[%s] %s** — %s · %s · opened %s ([view](%s))',
                    $ticket->display_id,
                    $ticket->subject,
                    $ticket->client?->name ?? 'No client',
                    $ticket->priority->label(),
                    $opened ? $opened->diffForHumans() : 'unknown',
                    route('tickets.show', $ticket),
                );
            }
            if ($openTickets->count() > $limit) {
                $remaining = $openTickets->count() - $limit;
                $lines[] = "- …and {$remaining} more.";
            }
        }

        if ($alerts->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "### Overnight alerts ({$alerts->count()})";
            $lines[] = '';
            foreach ($alerts as $alert) {
                $fired = $alert->fired_at ?? $alert->created_at;
                $details = array_filter([
                    $alert->client?->name,
                    $alert->hostname,
                    $alert->title,
                ]);
                $lines[] = sprintf(
                    '- **%s** — %s (fired %s)',
                    ucfirst($alert->severity->value),
                    implode(' · ', $details) ?: 'Alert',
                    $fired ? $fired->diffForHumans() : 'recently',
                );
            }
        }

        if ($voicemails->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "### Voicemails to return ({$voicemails->count()})";
            $lines[] = '';
            foreach ($voicemails as $call) {
                $caller = $call->person?->full_name ?? PhoneNumber::format($call->from_number);
                $left = $call->started_at ?? $call->created_at;
                $duration = $call->recording_duration ? ' · '.$this->formatDuration((int) $call->recording_duration) : '';
                $lines[] = sprintf(
                    '- **%s** (%s)%s · left %s ([listen](%s))',
                    $caller,
                    $call->client?->name ?? 'Unknown client',
                    $duration,
                    $left ? $left->diffForHumans() : 'recently',
                    route('calls.show', $call),
                );
            }
        }

        if ($suggestions !== null) {
            $lines[] = '';
            $lines[] = '### Suggested next actions';
            $lines[] = '';
            $lines[] = $suggestions;
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '[Open your ticket queue]('.route('tickets.index').')';

        return implode("\n", $lines);
    }

    /** Human phrase describing which SLA deadline is at risk and by how much. */
    private function slaPhrase(Ticket $ticket, Carbon $now): string
    {
        $parts = [];

        if ($ticket->response_due_at && ! $ticket->responded_at) {
            $parts[] = $ticket->response_due_at->isBefore($now)
                ? 'response overdue by '.$ticket->response_due_at->diffForHumans($now, true)
                : 'response due in '.$ticket->response_due_at->diffForHumans($now, true);
        }

        if ($ticket->due_at && ! $ticket->resolved_at) {
            $parts[] = $ticket->due_at->isBefore($now)
                ? 'resolution overdue by '.$ticket->due_at->diffForHumans($now, true)
                : 'resolution due in '.$ticket->due_at->diffForHumans($now, true);
        }

        return $parts === [] ? 'at risk today' : implode(' · ', $parts);
    }

    private function formatDuration(int $seconds): string
    {
        $format = $seconds >= 3600 ? 'H:i:s' : 'i:s';

        return gmdate($format, $seconds);
    }

    /**
     * Ask the AI for one or two concrete, prioritized next actions. Degrades
     * gracefully: returns null when AI is disabled/unconfigured or any error
     * occurs, so a single technician's AI failure never breaks their briefing.
     * Uses the cheap Haiku tier — this is a short, low-stakes summary.
     */
    private function generateSuggestions(
        User $technician,
        Collection $openTickets,
        Collection $slaRisk,
        Collection $alerts,
        Collection $voicemails,
    ): ?string {
        if (! BriefingConfig::includeAiSuggestions() || ! AiConfig::isConfigured()) {
            return null;
        }

        try {
            $context = $this->buildAiContext($openTickets, $slaRisk, $alerts, $voicemails);

            $system = 'You are an assistant helping a managed-service-provider technician start their workday. '
                .'Given a summary of their current workload, suggest ONE or TWO concrete, prioritized next actions '
                .'to tackle first. Reference specific ticket IDs and clients. Favour anything at risk of breaching '
                .'its SLA. Respond with ONLY 1-2 short markdown bullet points ("- ..."), no preamble, no headings.';

            $response = (new AiClient(AiConfig::haikuModel()))->complete($system, $context, 400);
            $text = trim($response->text);

            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            Log::warning('[Briefing] AI suggestion generation failed', [
                'user_id' => $technician->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Compact plain-text workload summary fed to the AI for suggestion generation. */
    private function buildAiContext(
        Collection $openTickets,
        Collection $slaRisk,
        Collection $alerts,
        Collection $voicemails,
    ): string {
        $lines = [];

        $lines[] = 'SLA at risk today ('.$slaRisk->count().'):';
        foreach ($slaRisk->take(10) as $ticket) {
            $lines[] = sprintf(
                '- [%s] %s (%s, %s, %s)',
                $ticket->display_id,
                $ticket->subject,
                $ticket->client?->name ?? 'no client',
                $ticket->priority->label(),
                $this->slaPhrase($ticket, now()),
            );
        }

        $lines[] = '';
        $lines[] = 'Open tickets ('.$openTickets->count().'):';
        foreach ($openTickets->take(15) as $ticket) {
            $opened = $ticket->opened_at ?? $ticket->created_at;
            $lines[] = sprintf(
                '- [%s] %s (%s, %s, opened %s)',
                $ticket->display_id,
                $ticket->subject,
                $ticket->client?->name ?? 'no client',
                $ticket->priority->label(),
                $opened ? $opened->diffForHumans() : 'unknown',
            );
        }

        if ($alerts->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Overnight alerts ('.$alerts->count().'):';
            foreach ($alerts->take(10) as $alert) {
                $lines[] = sprintf(
                    '- %s: %s (%s)',
                    ucfirst($alert->severity->value),
                    $alert->title ?? 'alert',
                    $alert->client?->name ?? 'unknown client',
                );
            }
        }

        if ($voicemails->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Voicemails to return ('.$voicemails->count().'):';
            foreach ($voicemails->take(10) as $call) {
                $lines[] = '- '.($call->person?->full_name ?? PhoneNumber::format($call->from_number))
                    .' ('.($call->client?->name ?? 'unknown client').')';
            }
        }

        return implode("\n", $lines);
    }
}
