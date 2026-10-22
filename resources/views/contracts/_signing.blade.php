@if(config('signing.enabled') && auth()->user()->isAdmin())
<section class="card mb-3">
    <div class="card-header">Signing</div>
    <div class="card-body">
        <p>Signatures only. No billing changes are applied.</p>
        <form method="post" action="{{ route('signing.generate', $contract) }}">
            @csrf
            <label>Template <select name="template_id" required>
                @foreach(\App\Models\AgreementTemplate::where('is_active', true)->where('contract_type', $contract->type->value)->get() as $template)
                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                @endforeach
            </select></label>
            <label>Recipient name <input name="name" required maxlength="255"></label>
            <label>Recipient email <input name="email" type="email" required maxlength="255"></label>
            <button class="btn btn-outline-primary">Generate</button>
        </form>
        <table class="table"><thead><tr><th>Version</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        @foreach(\App\Models\SignableDocument::where('contract_id', $contract->id)->orderByDesc('version')->get() as $document)
            <tr><td>{{ $document->version }}</td><td>{{ $document->status }}</td><td>
                @if($document->isFinal())
                    <a href="{{ route('signing.download', [$contract, $document]) }}">Download signed PDF</a>
                @endif
                @foreach(['send' => $document->status === 'draft', 'countersign' => $document->status === 'signed' && $document->requires_countersign, 'void' => $document->status !== 'void'] as $action => $available)
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
