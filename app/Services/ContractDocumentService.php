<?php

namespace App\Services;

use App\Enums\DocumentSummaryStatus;
use App\Models\Contract;
use App\Models\ContractDocument;
use App\Services\Ai\AiClient;
use App\Support\AiConfig;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser as PdfParser;

class ContractDocumentService
{
    private const MAX_EXTRACTED_TEXT = 50_000;
    private const MIN_EXTRACTED_TEXT = 50;

    private const CONTRACT_SUMMARY_PROMPT = <<<'PROMPT'
You are generating an internal quick-reference summary for a contract record in an MSP's PSA system. This will be read by technicians before working on a ticket to understand what the client is paying for and what requires additional billing.

Extract and summarize these SPECIFIC details from the contract documents:

- Contract type (managed services, break/fix, prepaid block, etc.)
- What services/products ARE covered (list specific items: email, servers, workstations, network equipment, backup, security, etc.)
- What is explicitly NOT covered or excluded
- Billing terms: hourly rate, prepaid hours included, overage rate, after-hours rate
- SLA response times (if specified)
- Contract term and renewal terms (auto-renew? notice period?)
- Any caps, limits, or special conditions (e.g. "up to 10 workstations", "excludes hardware over 5 years old")
- Device/seat counts or limits

Multiple documents may be provided (e.g. a proposal and a signed contract). Extract details from ALL of them, including appendices.

IMPORTANT: Contract documents often contain tables of optional services with checkboxes. PDF text extraction cannot capture checkbox states reliably, so do NOT rely on the contract document alone to determine which services are selected. If recurring profile line items are provided, use them as the definitive source of truth — the line items on the recurring profile are exactly what the client is paying for. If no recurring profile is available, use the subtotal/total as a cross-check: add up the prices of services you believe are selected — if the sum does not match the documented subtotal, remove items until it does. The subtotal is always correct.

NOTE: Recurring profile quantities are dynamic — they reflect the CURRENT billing period and change as devices, users, or seats are added or removed. They may not match the quantities in the original signed contract. Use the recurring profile to determine WHICH services are active, but use the contract documents for the per-unit pricing, terms, SLAs, and other static details.

IMPORTANT: This summary describes the contract's TERMS and STRUCTURE — it is not regenerated frequently, so do NOT include point-in-time data that changes over the life of the contract. Specifically:
- Do NOT include current prepaid balance / hours remaining
- Do NOT include current device or seat counts from the recurring profile
- DO include per-unit pricing, included quantities from the signed contract, rate tiers, and any caps or limits defined in the contract terms

If an existing summary is provided, incorporate any manually-entered information from it (e.g. custom notes, annotations, or details not found in the contract documents) into your improved summary.

Be specific with numbers, rates, and covered items — do NOT write generic summaries. If a detail is not in the document, omit it. Use plain text with dashes for bullet points. Do not use markdown formatting or headers.
PROMPT;

    /**
     * Upload and store a PDF document for a contract.
     * Extracts text immediately (fast, no AI needed).
     */
    public function upload(Contract $contract, UploadedFile $file, ?int $userId = null): ContractDocument
    {
        $uuid = Str::uuid();
        $path = "contract-documents/{$contract->id}/{$uuid}.pdf";

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $extractedText = $this->extractText(Storage::disk('local')->path($path));

        $hasText = $extractedText && strlen($extractedText) >= self::MIN_EXTRACTED_TEXT;

        $doc = ContractDocument::create([
            'contract_id' => $contract->id,
            'uploaded_by' => $userId,
            'original_filename' => $file->getClientOriginalName(),
            'disk_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'extracted_text' => $extractedText,
            'summary_status' => $hasText
                ? DocumentSummaryStatus::Pending
                : DocumentSummaryStatus::Skipped,
        ]);

        Log::info('[ContractDocument] Uploaded', [
            'document_id' => $doc->id,
            'contract_id' => $contract->id,
            'filename' => $doc->original_filename,
            'text_length' => strlen($extractedText ?? ''),
            'has_usable_text' => $hasText,
        ]);

        return $doc;
    }

    /**
     * Extract text from a PDF file using smalot/pdfparser.
     */
    public function extractText(string $absolutePath): ?string
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($absolutePath);
            $text = $pdf->getText();

            return trim($text) !== '' ? trim($text) : null;
        } catch (\Throwable $e) {
            Log::warning('[ContractDocument] PDF text extraction failed', [
                'path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate AI summary for a document.
     */
    public function summarize(ContractDocument $document): void
    {
        if (! AiConfig::isConfigured()) {
            $document->update(['summary_status' => DocumentSummaryStatus::Skipped]);
            Log::info('[ContractDocument] AI not configured, skipping summary', [
                'document_id' => $document->id,
            ]);

            return;
        }

        if (! $document->extracted_text || strlen($document->extracted_text) < self::MIN_EXTRACTED_TEXT) {
            $document->update(['summary_status' => DocumentSummaryStatus::Skipped]);

            return;
        }

        $document->update(['summary_status' => DocumentSummaryStatus::Processing]);

        try {
            $contract = $document->contract()->with('profiles.lines')->first();
            $userContent = $this->buildUserContent($document, $contract);

            $aiClient = new AiClient();
            $response = $aiClient->complete(
                self::CONTRACT_SUMMARY_PROMPT,
                $userContent,
                2048,
            );

            $document->update([
                'ai_summary' => $response->text,
                'summary_status' => DocumentSummaryStatus::Completed,
                'summary_tokens_used' => $response->totalTokens(),
                'summarized_at' => now(),
            ]);

            Log::info('[ContractDocument] Summary generated', [
                'document_id' => $document->id,
                'contract_id' => $document->contract_id,
                'tokens' => $response->totalTokens(),
            ]);
        } catch (\Throwable $e) {
            $document->update(['summary_status' => DocumentSummaryStatus::Failed]);
            Log::error('[ContractDocument] Summary generation failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete a document (soft delete — model event handles disk cleanup).
     */
    public function delete(ContractDocument $document): void
    {
        $docId = $document->id;
        $contractId = $document->contract_id;
        $filename = $document->original_filename;

        $document->delete();

        Log::info('[ContractDocument] Deleted', [
            'document_id' => $docId,
            'contract_id' => $contractId,
            'filename' => $filename,
        ]);
    }

    /**
     * Build the user message content for AI summarization.
     */
    private function buildUserContent(ContractDocument $document, Contract $contract): string
    {
        $lines = [
            "Contract: {$contract->name}",
            'Type: ' . $contract->type->label(),
            'Status: ' . $contract->status->label(),
            'Start: ' . $contract->start_date->format('Y-m-d'),
            'End: ' . ($contract->end_date?->format('Y-m-d') ?? 'None'),
        ];

        // Recurring profile line items (definitive source of billed services)
        $profileText = $this->buildRecurringProfileText($contract);
        if ($profileText) {
            $lines[] = '';
            $lines[] = 'Recurring Profile Line Items (definitive source of billed services):';
            $lines[] = $profileText;
        }

        // Contract document text (truncated at last newline boundary)
        $docText = $document->extracted_text;
        if (strlen($docText) > self::MAX_EXTRACTED_TEXT) {
            $cut = substr($docText, 0, self::MAX_EXTRACTED_TEXT);
            $lastNewline = strrpos($cut, "\n");
            if ($lastNewline !== false && $lastNewline > self::MAX_EXTRACTED_TEXT * 0.8) {
                $cut = substr($cut, 0, $lastNewline);
            }
            $docText = $cut . "\n[TRUNCATED — document exceeded " . number_format(self::MAX_EXTRACTED_TEXT) . ' characters]';
        }
        $lines[] = '';
        $lines[] = "<document_text filename=\"{$document->original_filename}\">";
        $lines[] = $docText;
        $lines[] = '</document_text>';

        $content = implode("\n", $lines);

        // For re-summarization, include existing summary
        if ($document->ai_summary) {
            return "The existing summary for this contract may be incomplete or outdated. "
                . "Generate an improved summary using the contract document and recurring profile data below. "
                . "Incorporate any valid information from the existing summary.\n\n"
                . "EXISTING SUMMARY:\n{$document->ai_summary}\n\n"
                . "CONTRACT DATA:\n{$content}";
        }

        return "Generate a concise summary for this contract:\n\n{$content}";
    }

    /**
     * Build text representation of active recurring profile line items.
     */
    private function buildRecurringProfileText(Contract $contract): ?string
    {
        $profiles = $contract->profiles->where('is_active', true);
        if ($profiles->isEmpty()) {
            return null;
        }

        $lines = [];
        foreach ($profiles as $profile) {
            $lines[] = "Profile: {$profile->name}";
            foreach ($profile->lines as $line) {
                $lineInfo = "  - {$line->description}";
                if ($line->fixed_quantity) {
                    $lineInfo .= " x{$line->fixed_quantity}";
                }
                if ($line->unit_price) {
                    $lineInfo .= " @ \${$line->unit_price}";
                }
                $lines[] = $lineInfo;
            }
        }

        return implode("\n", $lines);
    }
}
