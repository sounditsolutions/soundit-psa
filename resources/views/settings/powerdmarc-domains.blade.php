@extends('layouts.app')

@section('title', 'PowerDMARC Domain Mapping')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="section-title mb-0">PowerDMARC Domain Mapping</h2>
            <div class="d-flex gap-2">
                {{-- Auto-Match writes mappings, so it posts with a CSRF token --}}
                <form method="POST" action="{{ route('settings.powerdmarc-domains.auto-match') }}" class="d-inline">
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

        <p class="text-muted mb-2">
            Map PowerDMARC domains to local clients. This scopes email-authentication reads (domain status,
            aggregate reports, DNS timeline) to the right client. Saving a mapping stores both the domain ID and its
            name &mdash; the name is resolved automatically from the PowerDMARC domain listing.
        </p>
        <p class="text-muted mb-3">
            <i class="bi bi-info-circle me-1"></i><strong>A client with several mail domains can map to several PowerDMARC domains</strong>
            &mdash; just choose the same client on each of its domain rows.
        </p>

        {{-- success/error flashes are rendered globally by the layout; info is not --}}
        @if(session('info'))
            <div class="alert alert-info alert-dismissible fade show">
                {{ session('info') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <form method="POST" action="{{ route('settings.powerdmarc-domains.update') }}">
            @csrf

            <div class="card card-static shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>PowerDMARC Domain</th>
                                <th class="text-center d-none d-md-table-cell">DMARC Record</th>
                                <th class="text-center d-none d-md-table-cell">Setup</th>
                                <th style="min-width: 220px;">Mapped Client</th>
                                <th class="text-center" style="width: 80px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($domains as $domain)
                            @php
                                $mapped = $mappedClients->get($domain['domain_id']);
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $domain['name'] }}</strong>
                                    <br><small class="text-muted font-monospace">Domain ID: {{ $domain['domain_id'] }}</small>
                                </td>
                                <td class="text-center d-none d-md-table-cell">
                                    @if($domain['is_dmarc_record_correct'] === true)
                                        <span class="badge bg-success">Correct</span>
                                    @elseif($domain['is_dmarc_record_correct'] === false)
                                        <span class="badge bg-danger">Incorrect</span>
                                    @else
                                        <span class="text-muted small">&mdash;</span>
                                    @endif
                                </td>
                                <td class="text-center d-none d-md-table-cell">
                                    @if($domain['is_setup_completed'] === true)
                                        <span class="badge bg-success">Completed</span>
                                    @elseif($domain['is_setup_completed'] === false)
                                        <span class="badge bg-warning text-dark">Incomplete</span>
                                    @else
                                        <span class="text-muted small">&mdash;</span>
                                    @endif
                                </td>
                                <td>
                                    <select name="mappings[{{ $domain['domain_id'] }}]" class="form-select form-select-sm client-select" data-selected="{{ $mapped?->id }}" aria-label="Mapped client for {{ $domain['name'] }}">
                                        <option value="">&mdash; Not mapped &mdash;</option>
                                        @foreach($allClients as $client)
                                            <option value="{{ $client->id }}" @selected($mapped?->id === $client->id)>{{ $client->name }}</option>
                                        @endforeach
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
                                <td colspan="5" class="text-center text-muted py-4">
                                    No PowerDMARC domains are visible to this API key. Check that the key belongs to the account that manages your clients' domains.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Save Mappings</button>
            </div>
        </form>
    </div>
</div>
@endsection
