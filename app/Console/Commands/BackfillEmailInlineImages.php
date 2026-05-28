<?php

namespace App\Console\Commands;

use App\Helpers\HtmlSanitizer;
use App\Models\Email;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Services\AttachmentService;
use App\Services\Graph\GraphClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-download inline images for tickets/notes whose source email landed
 * before the has_attachments-gate bug was fixed (commit dce0b49).
 *
 * Bug pattern: when has_attachments was false on the Graph message, the
 * download was skipped entirely → no Attachment rows created → no CID
 * replacement → no body_html was stored at all (it was null), so plaintext
 * showed up but the screenshots referenced in the email's HTML were
 * silently dropped.
 *
 * Detection: find Emails whose body_html still contains "cid:" AND whose
 * destination (ticket description or related ticket note) has no
 * attachments linked.
 */
class BackfillEmailInlineImages extends Command
{
    protected $signature = 'attachments:backfill-email-inline-images {--dry-run : Report what would be processed without making changes} {--limit= : Cap processing at N candidates (for incremental runs)}';

    protected $description = 'Restore inline images on tickets/notes whose source email landed before the has_attachments-gate bug was fixed.';

    public function handle(AttachmentService $attachmentService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if ($dryRun) {
            $this->info('[DRY RUN] No changes will be made.');
        }

        $mailbox = Setting::getValue('graph_mailbox');
        if (! $mailbox) {
            $this->error('No graph_mailbox configured; cannot fetch attachments.');

            return self::FAILURE;
        }

        $graph = app(GraphClient::class);

        $candidates = Email::query()
            ->whereNotNull('graph_id')
            ->whereNotNull('ticket_id')
            ->where('body_html', 'like', '%cid:%')
            ->orderBy('id')
            ->get();

        $this->info("Candidate emails (cid: refs + ticket-linked + has graph_id): {$candidates->count()}");

        $report = [
            'processed' => 0,
            'no_destination' => 0,
            'already_fixed' => 0,
            'graph_no_attachments' => 0,
            'fixed_ticket_desc' => 0,
            'fixed_note_body' => 0,
            'errors' => 0,
        ];

        $processedThisRun = 0;

        foreach ($candidates as $email) {
            if ($limit !== null && $processedThisRun >= $limit) {
                $this->line("--- limit ({$limit}) reached, stopping ---");
                break;
            }

            try {
                // Destination resolution:
                // - Reply path: a TicketNote exists with email_id = this email
                // - New-ticket path: no note for this email, but email.ticket_id is set
                $note = TicketNote::where('email_id', $email->id)->first();
                $ticket = Ticket::find($email->ticket_id);

                if (! $ticket) {
                    $report['no_destination']++;
                    continue;
                }

                // Skip if destination already has attachments (manually fixed or
                // re-imported). For reply notes, check the note. For new-ticket
                // emails without a note, check the ticket's own attachments.
                $destination = $note ?: $ticket;
                $destAttachableType = $note ? TicketNote::class : Ticket::class;
                $destAttachableId = $note ? $note->id : $ticket->id;

                $existingAttachmentCount = \App\Models\Attachment::where('attachable_type', $destAttachableType)
                    ->where('attachable_id', $destAttachableId)
                    ->count();

                if ($existingAttachmentCount > 0) {
                    $report['already_fixed']++;
                    continue;
                }

                $processedThisRun++;
                $report['processed']++;

                if ($dryRun) {
                    $this->line(sprintf(
                        '  email #%d → %s #%d (subject: %s)',
                        $email->id,
                        $note ? 'note' : 'ticket',
                        $destAttachableId,
                        mb_substr($email->subject ?? '(no subject)', 0, 60),
                    ));
                    continue;
                }

                // Re-fetch attachments from Graph
                $attachments = $attachmentService->downloadEmailAttachments($email, $graph, $mailbox);
                if (empty($attachments)) {
                    $this->line("  email #{$email->id}: Graph returned no attachments — skipped (purged?)");
                    $report['graph_no_attachments']++;
                    continue;
                }

                // Replace cid: in the email's body_html and save to destination
                $newHtml = $attachmentService->replaceCidReferences($email->body_html, $attachments);
                $newHtml = HtmlSanitizer::sanitize($newHtml);

                if ($note) {
                    $note->update(['body_html' => $newHtml]);
                    foreach ($attachments as $a) {
                        $attachmentService->linkTo($a, TicketNote::class, $note->id);
                    }
                    $this->line("  email #{$email->id} → note #{$note->id}: rewrote body_html + linked ".count($attachments).' attachment(s)');
                    $report['fixed_note_body']++;
                } else {
                    $ticket->update(['description_html' => $newHtml]);
                    foreach ($attachments as $a) {
                        $attachmentService->linkTo($a, Ticket::class, $ticket->id);
                    }
                    $this->line("  email #{$email->id} → ticket #{$ticket->id}: rewrote description_html + linked ".count($attachments).' attachment(s)');
                    $report['fixed_ticket_desc']++;
                }
            } catch (\Throwable $e) {
                Log::warning("[BackfillInlineImages] email {$email->id} failed: {$e->getMessage()}");
                $this->warn("  email #{$email->id}: ERROR — {$e->getMessage()}");
                $report['errors']++;
            }
        }

        $this->newLine();
        $this->info('Done.');
        $this->table(
            ['Metric', 'Count'],
            collect($report)->map(fn ($v, $k) => [$k, $v])->values()->toArray(),
        );

        return $report['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
