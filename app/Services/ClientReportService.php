<?php

namespace App\Services;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\License;
use App\Models\Person;
use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Support\AiConfig;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Builds a weekly, QBR-ready service report for a single client: closed-ticket
 * volume and SLA/response metrics, recurring issue themes, contract usage
 * (prepaid-time burn and license assignment), and AI-generated recommendations.
 *
 * The report is produced as a Markdown document — suitable for pasting into a
 * QBR deck or emailing directly to the client's primary contact.
 *
 * All queries are strictly client-scoped. AI and email are optional: the
 * report degrades gracefully when AI is not configured, and emailing is a
 * separate, explicit action.
 */
class ClientReportService
{
    private const RECOMMENDATIONS_PROMPT = <<<'PROMPT'
You are an MSP service-delivery analyst preparing talking points for a Quarterly
Business Review (QBR) with a managed-services client. You are given a one-week
snapshot of the client's support activity and account usage.

Write 3–5 concise, specific recommendations for the account and service team.
Focus on: recurring issue patterns worth addressing at the root cause, SLA or
responsiveness concerns, prepaid-time burn and runway, and license utilization
(waste or shortfalls). Be practical and client-appropriate — the wording may be
shared with the client. Do not invent data that is not present in the snapshot;
if the week was quiet, say so briefly.

Output GitHub-flavored Markdown as a bulleted list only — no headings, no
preamble, no closing summary.
PROMPT;

    /**
     * Build the complete weekly report for a client and week window.
     *
     * @param  CarbonInterface|null  $weekStart  Any day within the target week; defaults to the current week. The window is normalised to the enclosing week (Mon–Sun).
     * @return array{week_start: Carbon, week_end: Carbon, data: array<string, mixed>, recommendations: ?string, markdown: string}
     */
    public function weeklyReport(Client $client, ?CarbonInterface $weekStart = null): array
    {
        $start = ($weekStart ? Carbon::parse($weekStart) : Carbon::now())->startOfWeek();
        $end = $start->copy()->endOfWeek();

        $data = $this->gatherData($client, $start, $end);
        $recommendations = $this->generateRecommendations($client, $data);
        $markdown = $this->buildMarkdown($client, $start, $end, $data, $recommendations);

        return [
            'week_start' => $start,
            'week_end' => $end,
            'data' => $data,
            'recommendations' => $recommendations,
            'markdown' => $markdown,
        ];
    }

    /**
     * Gather all client-scoped metrics for the given week window.
     *
     * @return array<string, mixed>
     */
    public function gatherData(Client $client, CarbonInterface $start, CarbonInterface $end): array
    {
        // Tickets resolved/closed during the week. resolved_at is the reliable
        // "done" marker — it is back-filled when a ticket is closed without a
        // prior resolve, so it covers both Resolved and Closed terminal states.
        $closed = Ticket::forClient($client->id)
            ->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed])
            ->whereBetween('resolved_at', [$start, $end])
            ->with('categoryNode.parent.parent')
            ->orderBy('resolved_at')
            ->get();

        $openedCount = Ticket::forClient($client->id)
            ->whereBetween('opened_at', [$start, $end])
            ->count();

        $currentlyOpen = Ticket::forClient($client->id)->open()->count();

        $tickets = $closed->map(function (Ticket $t): array {
            $responseMins = ($t->opened_at && $t->responded_at)
                ? (int) round(abs($t->opened_at->diffInMinutes($t->responded_at)))
                : null;
            $resolutionMins = ($t->opened_at && $t->resolved_at)
                ? (int) round(abs($t->opened_at->diffInMinutes($t->resolved_at)))
                : null;
            // SLA is only "tracked" for a ticket that carried a resolution
            // deadline. Met = resolved on or before the deadline.
            $slaMet = ($t->due_at && $t->resolved_at)
                ? $t->resolved_at->lessThanOrEqualTo($t->due_at)
                : null;

            return [
                'id' => $t->id,
                'subject' => $t->subject,
                'priority' => $t->priority,
                // The ITIL taxonomy path (psa-717bn) — NOT the legacy free-text
                // $t->category string. Null-safe: uncategorised → null → grouped
                // under "Uncategorized" / rendered as "—" downstream.
                'category' => $t->categoryNode?->pathString(),
                'response_mins' => $responseMins,
                'resolution_mins' => $resolutionMins,
                'sla_met' => $slaMet,
            ];
        })->all();

        $responseValues = collect($tickets)->pluck('response_mins')->filter(fn ($v) => $v !== null);
        $resolutionValues = collect($tickets)->pluck('resolution_mins')->filter(fn ($v) => $v !== null);
        $slaTracked = collect($tickets)->filter(fn ($t) => $t['sla_met'] !== null);

        // Recurring themes: closed tickets grouped by category, most common first.
        $themes = collect($tickets)
            ->groupBy(fn ($t) => $t['category'] ?: 'Uncategorized')
            ->map->count()
            ->sortDesc()
            ->all();

        [$prepay, $totalBurnHours] = $this->gatherPrepay($client, $start, $end);
        $licenses = $this->gatherLicenses($client);

        return [
            'closed_count' => $closed->count(),
            'opened_count' => $openedCount,
            'currently_open' => $currentlyOpen,
            'avg_response_mins' => $responseValues->isNotEmpty() ? (int) round($responseValues->avg()) : null,
            'avg_resolution_mins' => $resolutionValues->isNotEmpty() ? (int) round($resolutionValues->avg()) : null,
            'sla_tracked' => $slaTracked->count(),
            'sla_met' => $slaTracked->filter(fn ($t) => $t['sla_met'] === true)->count(),
            'tickets' => $tickets,
            'themes' => $themes,
            'prepay' => $prepay,
            'total_burn_hours' => round($totalBurnHours, 2),
            'licenses' => $licenses,
        ];
    }

    /**
     * Per-contract prepaid-time burn for the week plus remaining balance.
     *
     * Burn is aggregated from the prepay ledger's consumption rows (ticket
     * time, phone-call time, manual debits) — stored as negative values — over
     * the week's economic `date`, and taken as an absolute quantity. The
     * cumulative `prepay_used` column is deliberately not used: it has no date
     * dimension.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: float}
     */
    private function gatherPrepay(Client $client, CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = [];
        $totalBurnHours = 0.0;

        $contracts = Contract::forClient($client->id)->active()->get();

        foreach ($contracts as $contract) {
            // Only contracts that actually track prepaid time.
            if ($contract->prepay_balance === null) {
                continue;
            }

            $field = $contract->prepay_as_amount ? 'amount' : 'hours';

            $burn = abs((float) $contract->prepayTransactions()
                ->consumption()
                ->whereBetween('date', [$start, $end])
                ->sum($field));

            if (! $contract->prepay_as_amount) {
                $totalBurnHours += $burn;
            }

            $rows[] = [
                'contract' => $contract->name,
                'as_amount' => (bool) $contract->prepay_as_amount,
                'burn' => round($burn, 2),
                'balance' => (float) $contract->prepay_balance,
                'balance_formatted' => $contract->prepay_balance_formatted,
            ];
        }

        return [$rows, $totalBurnHours];
    }

    /**
     * Active licenses for the client with vendor-agnostic utilization context.
     *
     * @return array<int, array<string, mixed>>
     */
    private function gatherLicenses(Client $client): array
    {
        return License::forClient($client->id)
            ->active()
            ->with('licenseType')
            ->get()
            ->sortBy(fn (License $l) => $l->licenseType?->name ?? '')
            ->map(fn (License $l): array => [
                'name' => $l->licenseType?->name ?? 'Unknown',
                'vendor' => $l->licenseType?->vendor,
                'quantity' => $l->quantity,
                'assigned' => $l->assigned_quantity,
                'unassigned' => $l->unassigned_quantity,
                'utilization' => $l->utilization_percent,
                'status' => $l->utilization_status,
            ])
            ->values()
            ->all();
    }

    /**
     * Generate AI recommendations from the gathered metrics. Returns null when
     * AI is not configured or the call fails — the report is still complete
     * without it.
     *
     * @param  array<string, mixed>  $data
     */
    public function generateRecommendations(Client $client, array $data): ?string
    {
        if (! AiConfig::isConfigured()) {
            return null;
        }

        try {
            $response = (new AiClient)->complete(
                self::RECOMMENDATIONS_PROMPT,
                $this->buildAiContext($client, $data),
                1024,
            );

            $text = trim($response->text);

            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            Log::warning('[ClientReport] AI recommendations failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Email the rendered report to the client's active primary contact.
     *
     * @return array{sent: bool, to?: string, name?: string, reason?: string}
     */
    public function emailReport(Client $client, string $markdown, CarbonInterface $start, CarbonInterface $end): array
    {
        $primary = Person::where('client_id', $client->id)
            ->where('is_primary', true)
            ->where('is_active', true)
            ->first();

        if (! $primary || ! $primary->email) {
            return ['sent' => false, 'reason' => 'No active primary contact with an email address is set for this client.'];
        }

        $subject = 'Weekly Service Report — '.$start->format('M j').'–'.$end->format('M j, Y');

        try {
            app(EmailService::class)->sendNew($primary->email, $subject, $markdown, $primary->full_name);
        } catch (\Throwable $e) {
            Log::error('[ClientReport] Email send failed', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'reason' => 'Email delivery failed: '.$e->getMessage()];
        }

        return ['sent' => true, 'to' => $primary->email, 'name' => $primary->full_name];
    }

    /**
     * Assemble the Markdown report document from gathered data.
     *
     * @param  array<string, mixed>  $data
     */
    public function buildMarkdown(Client $client, CarbonInterface $start, CarbonInterface $end, array $data, ?string $recommendations): string
    {
        $range = $start->format('M j').' – '.$end->format('M j, Y');

        $lines = [];
        $lines[] = "# Weekly Service Report — {$client->name}";
        $lines[] = "**Week of {$range}**";
        $lines[] = '';

        // ── Summary ──
        $lines[] = '## Summary';
        $lines[] = "- **Tickets resolved this week:** {$data['closed_count']}";
        $lines[] = "- **Tickets opened this week:** {$data['opened_count']}";
        $lines[] = "- **Currently open tickets:** {$data['currently_open']}";
        $lines[] = '- **Average first response:** '.self::humanizeMinutes($data['avg_response_mins']);
        $lines[] = '- **Average resolution time:** '.self::humanizeMinutes($data['avg_resolution_mins']);
        if ($data['sla_tracked'] > 0) {
            $pct = (int) round(($data['sla_met'] / $data['sla_tracked']) * 100);
            $lines[] = "- **Resolution SLA met:** {$data['sla_met']} of {$data['sla_tracked']} ({$pct}%)";
        }
        $lines[] = '';

        // ── Recurring themes ──
        if (! empty($data['themes'])) {
            $lines[] = '## Recurring Themes';
            foreach ($data['themes'] as $theme => $count) {
                $noun = $count === 1 ? 'ticket' : 'tickets';
                $lines[] = "- **{$theme}:** {$count} {$noun}";
            }
            $lines[] = '';
        }

        // ── Resolved tickets ──
        if (! empty($data['tickets'])) {
            $lines[] = '## Tickets Resolved';
            $lines[] = '';
            $lines[] = '| Subject | Priority | Category | Response | Resolution |';
            $lines[] = '| --- | --- | --- | --- | --- |';
            foreach ($data['tickets'] as $t) {
                $priority = $t['priority'] instanceof TicketPriority ? $t['priority']->label() : (string) $t['priority'];
                $category = $t['category'] ?: '—';
                $lines[] = '| '.implode(' | ', [
                    $this->mdCell($t['subject']),
                    $this->mdCell($priority),
                    $this->mdCell($category),
                    self::humanizeMinutes($t['response_mins']),
                    self::humanizeMinutes($t['resolution_mins']),
                ]).' |';
            }
            $lines[] = '';
        }

        // ── Contract usage ──
        $lines[] = '## Contract Usage';
        $lines[] = '';
        $lines[] = '### Prepaid Time';
        if (! empty($data['prepay'])) {
            foreach ($data['prepay'] as $p) {
                $burn = $p['as_amount']
                    ? '$'.number_format($p['burn'], 2)
                    : number_format($p['burn'], 2).' hrs';
                $lines[] = "- **{$p['contract']}:** {$burn} used this week · {$p['balance_formatted']} remaining";
            }
        } else {
            $lines[] = '_No prepaid-time tracking on active contracts._';
        }
        $lines[] = '';

        $lines[] = '### License Assignment';
        $lines[] = '';
        if (! empty($data['licenses'])) {
            $lines[] = '| License | Vendor | Assigned | Total | Utilization |';
            $lines[] = '| --- | --- | --- | --- | --- |';
            foreach ($data['licenses'] as $l) {
                $assigned = $l['assigned'] ?? '—';
                $util = $l['utilization'] !== null ? $l['utilization'].'%' : '—';
                $lines[] = '| '.implode(' | ', [
                    $this->mdCell($l['name']),
                    $this->mdCell($l['vendor'] ?: '—'),
                    (string) $assigned,
                    (string) $l['quantity'],
                    $util,
                ]).' |';
            }
        } else {
            $lines[] = '_No active licenses on record._';
        }
        $lines[] = '';

        // ── Recommendations ──
        $lines[] = '## Recommendations';
        $lines[] = '';
        $lines[] = $recommendations ?: '_AI recommendations are unavailable (AI is not configured for this instance)._';
        $lines[] = '';

        $lines[] = '---';
        $lines[] = '_Generated '.Carbon::now()->toAppTz()->format('M j, Y g:i A').' by '.config('app.name').'._';

        return implode("\n", $lines);
    }

    /**
     * Compact plain-text summary of the metrics, fed to the AI model.
     *
     * @param  array<string, mixed>  $data
     */
    private function buildAiContext(Client $client, array $data): string
    {
        $lines = [];
        $lines[] = "Client: {$client->name}";
        $lines[] = "Tickets resolved this week: {$data['closed_count']}";
        $lines[] = "Tickets opened this week: {$data['opened_count']}";
        $lines[] = "Currently open tickets: {$data['currently_open']}";
        $lines[] = 'Average first response: '.self::humanizeMinutes($data['avg_response_mins']);
        $lines[] = 'Average resolution time: '.self::humanizeMinutes($data['avg_resolution_mins']);
        if ($data['sla_tracked'] > 0) {
            $lines[] = "Resolution SLA met: {$data['sla_met']} of {$data['sla_tracked']}";
        }

        if (! empty($data['themes'])) {
            $themeStr = collect($data['themes'])
                ->map(fn ($count, $theme) => "{$theme} ({$count})")
                ->implode(', ');
            $lines[] = "Ticket categories this week: {$themeStr}";
        }

        if (! empty($data['prepay'])) {
            foreach ($data['prepay'] as $p) {
                $unit = $p['as_amount'] ? '' : ' hrs';
                $lines[] = "Prepaid contract '{$p['contract']}': {$p['burn']}{$unit} burned this week, {$p['balance_formatted']} remaining.";
            }
        } else {
            $lines[] = 'No prepaid-time tracking on active contracts.';
        }

        if (! empty($data['licenses'])) {
            foreach ($data['licenses'] as $l) {
                if ($l['utilization'] !== null) {
                    $lines[] = "License '{$l['name']}': {$l['assigned']}/{$l['quantity']} seats assigned ({$l['utilization']}% utilization).";
                } else {
                    $lines[] = "License '{$l['name']}': {$l['quantity']} seats (assignment data unavailable).";
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Humanize a minute count as a compact duration (e.g. "3h 12m", "2d 4h").
     */
    public static function humanizeMinutes(?int $mins): string
    {
        if ($mins === null) {
            return '—';
        }
        if ($mins < 60) {
            return "{$mins}m";
        }

        $hours = intdiv($mins, 60);
        if ($hours < 24) {
            $rem = $mins % 60;

            return $rem ? "{$hours}h {$rem}m" : "{$hours}h";
        }

        $days = intdiv($hours, 24);
        $h = $hours % 24;

        return $h ? "{$days}d {$h}h" : "{$days}d";
    }

    /**
     * Escape a value for safe inclusion in a Markdown table cell.
     */
    private function mdCell(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace('|', '\\|', $text)));
    }
}
