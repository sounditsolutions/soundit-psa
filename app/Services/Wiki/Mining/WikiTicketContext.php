<?php

namespace App\Services\Wiki\Mining;

use App\Models\Ticket;

class WikiTicketContext
{
    // Mirrors triage's ContextBuilder bounds (spec §5.2 gather).
    private const MAX_BODY = 5_000;

    private const MAX_NOTES = 10;

    private const MAX_NOTE_LENGTH = 1_500;

    private const MAX_TRANSCRIPT_SUMMARY = 2_000;

    public function __construct(private readonly WikiRedactor $redactor) {}

    /** Bounded, pre-redacted mining context for one closed ticket. */
    public function build(Ticket $ticket): string
    {
        $parts = [];
        $parts[] = 'TICKET #'.$ticket->id.': '.$ticket->subject;
        $parts[] = "DESCRIPTION:\n".$this->clip((string) $ticket->description, self::MAX_BODY);
        $parts[] = "RESOLUTION:\n".$this->clip((string) $ticket->resolution, self::MAX_BODY);

        $notes = $ticket->notes()
            ->whereIn('note_type', ['reply', 'ai_triage'])
            ->orderByDesc('noted_at')
            ->limit(self::MAX_NOTES)
            ->get();
        foreach ($notes->reverse() as $note) {
            $parts[] = 'NOTE ('.$note->note_type->value.'):'."\n".$this->clip((string) $note->body, self::MAX_NOTE_LENGTH);
        }

        $call = $ticket->phoneCalls()
            ->where('transcription_status', 'completed')
            ->whereNotNull('call_summary')
            ->latest('transcribed_at')
            ->first();
        if ($call) {
            $parts[] = "CALL SUMMARY:\n".$this->clip((string) $call->call_summary, self::MAX_TRANSCRIPT_SUMMARY);
        }

        $triage = $ticket->latestTriageRun;
        if ($triage && is_array($triage->stage_results)) {
            $technical = $triage->stage_results['technical_triage'] ?? null;
            if (is_array($technical) || is_string($technical)) {
                // Security review M1: flatten arrays to a readable decoded string, NOT json_encode —
                // JSON escaping (\/, @ for @) would slip connection-strings/PEM past the redact()
                // patterns, which assume literal '/' and '@'. flattenValues() yields plain text.
                $technicalText = is_string($technical) ? $technical : $this->flattenValues($technical);
                $parts[] = "TRIAGE ANALYSIS:\n".$this->clip($technicalText, self::MAX_NOTE_LENGTH);
            }
        }

        // Redact the whole assembled context — the AI never sees raw secrets (spec §5.2 layer 1).
        return $this->redactor->redact(implode("\n\n", $parts));
    }

    /** Recursively flatten a nested array's scalar values into newline-joined plain text. */
    private function flattenValues(array $data): string
    {
        $out = [];
        array_walk_recursive($data, function ($value) use (&$out) {
            if (is_scalar($value)) {
                $out[] = (string) $value;
            }
        });

        return implode("\n", $out);
    }

    private function clip(string $text, int $max): string
    {
        return strlen($text) > $max ? substr($text, 0, $max).' …[truncated]' : $text;
    }
}
