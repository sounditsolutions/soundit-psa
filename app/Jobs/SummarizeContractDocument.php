<?php

namespace App\Jobs;

use App\Enums\DocumentSummaryStatus;
use App\Models\ContractDocument;
use App\Services\ContractDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SummarizeContractDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(
        private readonly int $documentId,
    ) {}

    public function handle(ContractDocumentService $service): void
    {
        // Use pessimistic locking to prevent duplicate summarizations
        $document = DB::transaction(function () {
            $doc = ContractDocument::where('id', $this->documentId)
                ->lockForUpdate()->first();

            if (! $doc) {
                Log::warning('[ContractDocument] Document not found for summary', [
                    'document_id' => $this->documentId,
                ]);

                return null;
            }

            // Bail if already processing or completed
            if (in_array($doc->summary_status, [
                DocumentSummaryStatus::Processing,
                DocumentSummaryStatus::Completed,
            ])) {
                Log::debug('[ContractDocument] Skipping — already ' . $doc->summary_status->value, [
                    'document_id' => $this->documentId,
                ]);

                return null;
            }

            return $doc;
        });

        if (! $document) {
            return;
        }

        try {
            $service->summarize($document);
        } catch (\Throwable $e) {
            Log::error('[ContractDocument] Summary job failed', [
                'document_id' => $this->documentId,
                'error' => $e->getMessage(),
            ]);

            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }
}
