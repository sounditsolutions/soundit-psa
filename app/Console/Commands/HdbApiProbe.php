<?php

namespace App\Console\Commands;

use App\Models\TicketNote;
use App\Services\Hdb\HdbApiReportClient;
use App\Support\HdbApiConfig;
use Illuminate\Console\Command;

class HdbApiProbe extends Command
{
    protected $signature = 'hdb:api-probe {--notes=10}';

    protected $description = 'Measure Reports API mapping; prints booleans, counts and status codes, never ids or content.';

    public function handle(HdbApiReportClient $client): int
    {
        if (! HdbApiConfig::isConfigured()) {
            $this->line('configured=false');

            return self::FAILURE;
        }
        $count = filter_var($this->option('notes'), FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > 100) {
            $this->line('notes_valid=false');

            return self::FAILURE;
        }
        $totals = ['rows' => 0, 'h1_hit' => 0, 'h2_hit' => 0, 'h3_match_count' => 0, 'http_404' => 0, 'list_incomplete' => 0];
        $notes = TicketNote::whereNotNull('hdb_press_id')->orderByDesc('created_at')->orderByDesc('id')->limit($count)->get();
        foreach ($notes as $index => $note) {
            $read = $client->detail($note->hdb_press_id);
            $data = $read['data'] ?? [];
            $h1 = ($read['http_status'] ?? null) === 200 && (($data['report_id'] ?? null) === $note->hdb_press_id || $this->urlMatches($data['report_url'] ?? null, $note->hdb_press_id));
            $list = $client->listReports([
                'group' => 'ALL',
                'since' => $note->created_at->copy()->subMinutes(15)->toIso8601String(),
                'before' => $note->created_at->copy()->addMinutes(15)->toIso8601String(),
            ]);
            $h2 = false;
            $h3 = 0;
            foreach ($list['items'] as $item) {
                if ($this->urlMatches($item['report_url'] ?? null, $note->hdb_press_id)) {
                    $h2 = true;
                }
                if ($client->matchesTicket($item['ticket_id'] ?? null, $note->ticket_id) || $client->matchesTicket($item['ticket_number'] ?? null, $note->ticket_id)) {
                    $h3++;
                }
            }
            $status = in_array($data['status'] ?? null, ['complete', 'pending', 'requested', 'pressed'], true) ? $data['status'] : 'unknown';
            $sprite = is_string($data['instant_replay']['sprite'] ?? null) && $data['instant_replay']['sprite'] !== '';
            $bucket = 'none';
            if ($h1 && $sprite) {
                $client->report($note->hdb_press_id, $note->ticket_id);
                $bucket = $client->spriteSizeBucket();
            }
            $row = [
                'row' => $index + 1,
                'h1_hit' => $h1, 'h2_hit' => $h2, 'h3_match_count' => $h3,
                'binding_ticket_id_eq' => $client->matchesTicket($data['ticket_id'] ?? null, $note->ticket_id),
                'binding_ticket_number_eq' => $client->matchesTicket($data['ticket_number'] ?? null, $note->ticket_id),
                'sprite_advertised' => $sprite,
                'redaction_flags_present' => is_bool($data['diagnostics_redacted'] ?? null) && is_bool($data['instant_replay']['redacted'] ?? null),
                'status' => $status, 'sprite_size' => $bucket,
            ];
            if (isset($read['failure'])) {
                $row['http_status'] = $read['http_status'] ?? 0;
                $row['error_code'] = $read['code'] ?? $read['failure']->reason;
            }
            if (isset($list['failure'])) {
                $row['list_http_status'] = $list['http_status'] ?? 0;
                $row['list_error_code'] = $list['code'] ?? $list['failure']->reason;
            }
            $this->line(json_encode($row, JSON_UNESCAPED_SLASHES));
            $totals['rows']++;
            $totals['h1_hit'] += (int) $h1;
            $totals['h2_hit'] += (int) $h2;
            $totals['h3_match_count'] += $h3;
            $totals['http_404'] += (int) (($read['http_status'] ?? null) === 404);
            $totals['list_incomplete'] += (int) ! $list['exhausted'];
        }
        $this->line(json_encode($totals));

        return self::SUCCESS;
    }

    private function urlMatches(mixed $url, string $press): bool
    {
        if (! is_string($url)) {
            return false;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ($query['pressID'] ?? null) === $press;
    }
}
