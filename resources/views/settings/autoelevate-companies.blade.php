@extends('layouts.app')

@section('title', 'AutoElevate Company Mapping')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="section-title mb-0">AutoElevate Company Mapping</h2>
            <div class="d-flex gap-2">
                {{-- Auto-match writes mappings and spends a vendor read: POST with a CSRF token,
                     never a link a prefetcher could follow. --}}
                <form method="POST" action="{{ route('settings.autoelevate-companies.auto-match') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-magic me-1"></i>Auto-Match by Name
                    </button>
                </form>
                <a href="{{ route('settings.integrations') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to Integrations
                </a>
            </div>
        </div>

        <p class="text-muted mb-3">
            Map AutoElevate companies to local clients. This lets a client's page show its AutoElevate computers. Read-only against AutoElevate.
        </p>

        @if(session('info'))
            <div class="alert alert-info alert-dismissible fade show">
                {{ session('info') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('settings.autoelevate-companies.update') }}">
            @csrf

            <div class="card card-static shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>AutoElevate Company</th>
                                <th style="min-width: 220px;">Mapped Client</th>
                                <th class="text-center" style="width: 80px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($companies as $company)
                            @php
                                $companyId = $company['id'];
                                $mapped = $mappedClients->get($companyId);
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $company['name'] }}</strong>
                                    <br><small class="text-muted">ID: {{ $companyId }}</small>
                                </td>
                                <td>
                                    <select name="mappings[{{ $companyId }}]" class="form-select form-select-sm client-select" data-selected="{{ $mapped?->id }}">
                                        <option value="">— Not mapped —</option>
                                    </select>
                                </td>
                                <td class="text-center">
                                    @if($mapped)
                                        <span class="badge bg-success">Mapped</span>
                                    @else
                                        <span class="badge bg-secondary">-</span>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="3" class="text-muted">
                                    AutoElevate returned zero companies for this key.
                                    @if($mappedClients->isNotEmpty())
                                        <br><span class="text-danger">{{ $mappedClients->count() }} client(s) still hold a mapping. They are kept — saving is disabled while the list is empty so an empty screen cannot clear them.</span>
                                    @endif
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Save is withheld when the table lists nothing: a submission with no company
                 keys is indistinguishable from "unmap everything", so the controller refuses
                 it. The button would have no inputs to submit and could only destroy or fail. --}}
            @if(count($companies) > 0)
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Save Mappings</button>
            </div>
            @endif
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const clients = @json($allClients->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]));

    document.querySelectorAll('.client-select').forEach(select => {
        const selected = select.dataset.selected;
        clients.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name;
            if (String(c.id) === selected) opt.selected = true;
            select.appendChild(opt);
        });
    });
</script>
@endpush
