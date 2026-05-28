<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Support\AiConfig;
use Illuminate\Console\Command;

class BackfillTicketKeywords extends Command
{
    protected $signature = 'tickets:backfill-keywords
                            {--limit= : Cap the number of tickets to process}
                            {--dry-run : Print keywords without saving}
                            {--force : Re-run even on tickets that already have keywords}';

    protected $description = 'Generate AI search keywords for open tickets and tickets closed in the last 30 days';

    public function handle(AiClient $ai): int
    {
        if (! AiConfig::isConfigured()) {
            $this->error('AI is not configured.');
            return self::FAILURE;
        }

        $openStatuses = ['new', 'in_progress', 'pending_client', 'pending_vendor'];
        $cutoff = now()->subDays(30);

        $query = Ticket::query()
            ->where(function ($q) use ($openStatuses, $cutoff) {
                $q->whereIn('status', $openStatuses)
                    ->orWhere(function ($r) use ($cutoff) {
                        $r->whereIn('status', ['resolved', 'closed'])
                            ->where(function ($d) use ($cutoff) {
                                $d->where('closed_at', '>=', $cutoff)
                                    ->orWhere('resolved_at', '>=', $cutoff);
                            });
                    });
            });

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('search_keywords')->orWhere('search_keywords', '');
            });
        }

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $tickets = $query->orderByDesc('id')->get();
        $total = $tickets->count();

        if ($total === 0) {
            $this->info('No tickets to process.');
            return self::SUCCESS;
        }

        $this->info("Backfilling keywords on {$total} tickets" . ($this->option('dry-run') ? ' (dry-run)' : ''));

        $bar = $this->output->createProgressBar($total);
        $ok = 0; $skip = 0; $fail = 0;

        foreach ($tickets as $ticket) {
            $userMsg = $this->buildContext($ticket);

            try {
                $result = $ai->completeJson(self::SYSTEM_PROMPT, $userMsg, 512);
                $keywords = $result['keywords'] ?? [];
                $normalized = $this->normalize($keywords);

                if (empty($normalized)) {
                    $skip++;
                    $bar->advance();
                    continue;
                }

                if ($this->option('dry-run')) {
                    $bar->clear();
                    $this->line("T-{$ticket->id}: " . implode(', ', $normalized));
                    $bar->display();
                } else {
                    $ticket->update(['search_keywords' => implode(' ', $normalized)]);
                }
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                $bar->clear();
                $this->warn("T-{$ticket->id}: " . $e->getMessage());
                $bar->display();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. ok={$ok} skipped={$skip} failed={$fail}");

        return self::SUCCESS;
    }

    private const SYSTEM_PROMPT = <<<'TXT'
You generate search keywords for a PSA helpdesk ticket so similar past
tickets can be retrieved later. Pick 4-10 distinctive keywords that
capture the essence of the issue.

Good keywords:
- Vendor names (Lexmark, Outlook, Cisco, Microsoft, Adobe)
- Product/model strings (MS823DN, M365, Office365)
- Error codes (0x80004005)
- Key nouns (printer, vpn, mailbox, password, license)
- Symptoms (offline, crash, slow, timeout, locked)
- Concrete categories (backup, network, hardware, security)

Avoid:
- Stopwords (the, and, with, etc.)
- Generic words (issue, problem, help, request, ticket)
- Full sentences
- Personal names

Return JSON only, in this exact shape:
{"keywords": ["keyword1", "keyword2", ...]}
All keywords must be lowercase, no punctuation, no spaces inside a token.
TXT;

    private function buildContext(Ticket $ticket): string
    {
        $ticket->loadMissing('assets:id,hostname,name');

        $parts = [];
        $parts[] = "Subject: " . ($ticket->subject ?? '');

        if ($ticket->category) {
            $parts[] = "Category: {$ticket->category}" . ($ticket->subcategory ? " / {$ticket->subcategory}" : '');
        }

        $description = trim(strip_tags($ticket->description ?? ''));
        if ($description !== '') {
            $parts[] = "Description:\n" . mb_substr($description, 0, 2000);
        }

        $resolution = trim(strip_tags($ticket->resolution ?? ''));
        if ($resolution !== '') {
            $parts[] = "Resolution:\n" . mb_substr($resolution, 0, 1000);
        }

        $assetLabels = $ticket->assets->map(fn ($a) => $a->hostname ?: $a->name)->filter()->take(10)->values()->all();
        if (! empty($assetLabels)) {
            $parts[] = "Linked assets: " . implode(', ', $assetLabels);
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param  array<int, mixed>  $keywords
     * @return list<string>
     */
    private function normalize(array $keywords): array
    {
        $out = [];
        foreach ($keywords as $kw) {
            if (! is_string($kw)) continue;
            $clean = mb_strtolower(trim($kw), 'UTF-8');
            $clean = preg_replace('/[^\p{L}\p{N}\s_-]+/u', '', $clean) ?? '';
            $clean = trim((string) $clean);
            if ($clean === '' || mb_strlen($clean) < 2) continue;
            $out[$clean] = true;
        }

        return array_keys($out);
    }
}
