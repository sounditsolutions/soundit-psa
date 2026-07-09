<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractActivity;
use App\Models\PandaDocDocument;
use App\Services\PandaDoc\PandaDocService;
use App\Support\PandaDocConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PandaDocDocumentController extends Controller
{
    public function __construct(
        private readonly PandaDocService $service,
    ) {}

    public function store(Request $request, Contract $contract): RedirectResponse
    {
        if (! PandaDocConfig::isConfigured()) {
            return redirect()->back()
                ->with('error', 'PandaDoc is not configured. Add an API key in Settings > Integrations.');
        }

        $validated = $request->validate([
            'template_id' => ['required', 'string', 'max:64'],
            'template_name' => ['nullable', 'string', 'max:255'],
            'recipient_email' => ['required', 'email', 'max:255'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'send' => ['nullable', 'boolean'],
        ]);

        try {
            $document = $this->service->createFromTemplate(
                $contract,
                $validated['template_id'],
                $validated['template_name'] ?? null,
                $validated['recipient_email'],
                $validated['recipient_name'],
                $validated['name'] ?? null,
                auth()->id(),
            );
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'PandaDoc document creation failed: '.$e->getMessage());
        }

        ContractActivity::create([
            'contract_id' => $contract->id,
            'user_id' => auth()->id(),
            'action' => 'pandadoc_created',
            'changes' => ['name' => $document->name, 'recipient' => $document->recipient_email],
        ]);

        $message = 'PandaDoc agreement created.';

        return redirect()->route('contracts.show', $contract)->with('success', $message);
    }

    public function send(Contract $contract, PandaDocDocument $document): RedirectResponse
    {
        abort_if($document->contract_id !== $contract->id, 404);

        try {
            $this->service->send($document);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Failed to send agreement: '.$e->getMessage());
        }

        ContractActivity::create([
            'contract_id' => $contract->id,
            'user_id' => auth()->id(),
            'action' => 'pandadoc_sent',
            'changes' => ['name' => $document->name, 'recipient' => $document->recipient_email],
        ]);

        return redirect()->route('contracts.show', $contract)
            ->with('success', 'Agreement sent for signature.');
    }

    public function sync(Contract $contract, PandaDocDocument $document): RedirectResponse
    {
        abort_if($document->contract_id !== $contract->id, 404);

        try {
            $this->service->syncStatus($document);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Failed to refresh status: '.$e->getMessage());
        }

        return redirect()->route('contracts.show', $contract)
            ->with('success', 'Status refreshed: '.$document->fresh()->status->label().'.');
    }

    public function download(Contract $contract, PandaDocDocument $document)
    {
        abort_if($document->contract_id !== $contract->id, 404);
        abort_unless($document->hasSignedPdf(), 404, 'No signed document is available yet.');

        return Storage::disk('local')->download(
            $document->signed_disk_path,
            $document->name.'.pdf',
        );
    }

    public function destroy(Contract $contract, PandaDocDocument $document): RedirectResponse
    {
        abort_if($document->contract_id !== $contract->id, 404);

        $name = $document->name;

        $this->service->delete($document);

        ContractActivity::create([
            'contract_id' => $contract->id,
            'user_id' => auth()->id(),
            'action' => 'pandadoc_deleted',
            'changes' => ['name' => $name],
        ]);

        return redirect()->route('contracts.show', $contract)
            ->with('success', "Agreement \"{$name}\" removed.");
    }
}
