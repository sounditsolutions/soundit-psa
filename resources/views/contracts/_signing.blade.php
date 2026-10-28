@if(config('signing.enabled') && auth()->user()->isAdmin())
<section class="card mb-3">
    <div class="card-header">Signing</div>
    <div class="card-body">
        @if(session('signing_error'))
            <div class="alert alert-danger" role="alert">{{ session('signing_error') }}</div>
        @endif
        <p>Signatures only. No billing changes are applied.</p>
        <form method="post" action="{{ route('signing.generate', $contract) }}">
            @csrf
            <label>Template <select name="template_id" required>
                @foreach(\App\Models\AgreementTemplate::where('is_active', true)->where('contract_type', $contract->type->value)->get() as $template)
                    <option value="{{ $template->id }}">{{ ucfirst($template->kind) }} · {{ $template->name }}{{ $template->kind === 'agreement' && $template->signing_steps === 2 ? ' (two-step)' : '' }}</option>
                @endforeach
            </select></label>
            <label>Recipient name <input name="recipient_name" required maxlength="255"></label>
            @error('recipient_name')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
            <label>Recipient email <input name="recipient_email" type="email" required maxlength="255"></label>
            @error('recipient_email')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
            <button class="btn btn-outline-primary">Generate</button>
        </form>
        <table class="table"><thead><tr><th>Kind</th><th>Version</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        @foreach(\App\Models\SignableDocument::where('contract_id', $contract->id)->orderBy('kind')->orderByDesc('version')->get() as $document)
            <tr><td>{{ ucfirst($document->kind) }}</td><td>{{ $document->version }}
                @if($document->accepted_snapshot_id)
                    @php($acceptedSnapshot = \App\Models\SigningSnapshot::find($document->accepted_snapshot_id))
                    <small class="d-block">From proposal v{{ \App\Models\SignableDocument::find($acceptedSnapshot->signable_document_id)->version }}</small>
                @endif
            </td><td>{{ $document->status }}</td><td>
                @if($document->isFinal())
                    <a href="{{ route('signing.download', [$contract, $document]) }}">{{ $document->kind === 'proposal' ? 'Download accepted proposal' : 'Download signed PDF' }}</a>
                @endif
                @foreach(['send' => $document->status === 'draft', 'countersign' => $document->kind === 'agreement' && $document->status === 'signed' && $document->requires_countersign, 'void' => $document->isVoidable()] as $action => $available)
                    @if($available)
                        <form class="d-inline" method="post" action="{{ route('signing.action', [$contract, $document, $action]) }}">@csrf<button class="btn btn-outline-primary">{{ ucfirst($action) }}</button></form>
                    @endif
                @endforeach
            </td></tr>
        @endforeach
        </tbody></table>
    </div>
</section>
@endif
