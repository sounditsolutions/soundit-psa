<?php

namespace App\Http\Controllers\Web;

use App\Enums\DocumentSummaryStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SummarizeContractDocument;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\ContractDocument;
use App\Services\ContractDocumentService;
use App\Support\AiConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ContractDocumentController extends Controller
{
    public function __construct(
        private readonly ContractDocumentService $documentService,
    ) {}

    public function store(Request $request, Contract $contract): RedirectResponse
    {
        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:20480'],
        ]);

        $document = $this->documentService->upload(
            $contract,
            $request->file('document'),
            auth()->id(),
        );

        // Log activity
        ContractActivity::create([
            'contract_id' => $contract->id,
            'user_id' => auth()->id(),
            'action' => 'document_uploaded',
            'changes' => ['filename' => $document->original_filename],
        ]);

        // Dispatch summary job if text was extracted
        if ($document->summary_status === DocumentSummaryStatus::Pending) {
            SummarizeContractDocument::dispatch($document->id)->afterResponse();
        }

        $message = 'Document uploaded.';
        if ($document->summary_status === DocumentSummaryStatus::Pending) {
            $message .= ' AI summary is being generated.';
        } elseif ($document->summary_status === DocumentSummaryStatus::Skipped) {
            $message .= ' No extractable text found in this PDF (it may be a scanned image).';
        }

        return redirect()->route('contracts.show', $contract)->with('success', $message);
    }

    public function download(Contract $contract, ContractDocument $document)
    {
        abort_if($document->contract_id !== $contract->id, 404);

        return Storage::disk('local')->download(
            $document->disk_path,
            $document->original_filename,
        );
    }

    public function destroy(Contract $contract, ContractDocument $document): RedirectResponse
    {
        abort_if($document->contract_id !== $contract->id, 404);

        $filename = $document->original_filename;

        // Log activity before deletion
        ContractActivity::create([
            'contract_id' => $contract->id,
            'user_id' => auth()->id(),
            'action' => 'document_deleted',
            'changes' => ['filename' => $filename],
        ]);

        $this->documentService->delete($document);

        return redirect()->route('contracts.show', $contract)
            ->with('success', "Document \"{$filename}\" deleted.");
    }

    public function resummarize(Contract $contract, ContractDocument $document): RedirectResponse
    {
        abort_if($document->contract_id !== $contract->id, 404);

        if (! $document->extracted_text) {
            return redirect()->back()->with('error', 'No text was extracted from this document.');
        }

        if (! AiConfig::isConfigured()) {
            return redirect()->back()
                ->with('error', 'AI is not configured. Add an API key in Settings > Integrations.');
        }

        if ($document->summary_status === DocumentSummaryStatus::Processing) {
            return redirect()->back()->with('info', 'Summary is already being generated.');
        }

        $document->update(['summary_status' => DocumentSummaryStatus::Pending]);
        SummarizeContractDocument::dispatch($document->id)->afterResponse();

        return redirect()->route('contracts.show', $contract)
            ->with('success', 'Re-summarization started.');
    }

    public function status(Contract $contract, ContractDocument $document): JsonResponse
    {
        abort_if($document->contract_id !== $contract->id, 404);

        return response()->json([
            'status' => $document->summary_status->value,
            'label' => $document->summary_status->label(),
            'summary' => $document->ai_summary,
            'summarized_at' => $document->summarized_at?->diffForHumans(),
        ]);
    }
}
