@extends('layouts.app')

@section('title', 'UniFi Site Mapping')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="section-title mb-0">UniFi Site Mapping</h2>
            <div class="d-flex gap-2">
                <a href="{{ route('settings.unifi-sites.auto-match') }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-magic me-1"></i>Auto-Match by Name
                </a>
                <a href="{{ route('settings.integrations') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to Integrations
                </a>
            </div>
        </div>

        <p class="text-muted mb-3">
            Map UniFi sites to local clients. This scopes network telemetry (WAN/ISP health, devices) to the right client.
            Saving a mapping stores both the site ID and its console (host) ID &mdash; the console is resolved automatically
            from the UniFi site listing and is required for device reads.
        </p>

        {{-- success/error flashes are rendered globally by the layout; info is not --}}
        @if(session('info'))
            <div class="alert alert-info alert-dismissible fade show">
                {{ session('info') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <form method="POST" action="{{ route('settings.unifi-sites.update') }}">
            @csrf

            <div class="card card-static shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>UniFi Site</th>
                                <th class="d-none d-md-table-cell">Console (Host ID)</th>
                                <th class="text-end d-none d-md-table-cell">Devices</th>
                                <th class="d-none d-lg-table-cell">ISP</th>
                                <th style="min-width: 220px;">Mapped Client</th>
                                <th class="text-center" style="width: 80px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($sites as $site)
                            @php
                                $mapped = $mappedClients->get($site['site_id']);
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $site['label'] }}</strong>
                                    @if($site['internal_name'] && $site['internal_name'] !== $site['label'])
                                        <span class="text-muted small">({{ $site['internal_name'] }})</span>
                                    @endif
                                    <br><small class="text-muted font-monospace">Site ID: {{ $site['site_id'] }}</small>
                                </td>
                                <td class="d-none d-md-table-cell" style="max-width: 260px;">
                                    @if($site['host_id'])
                                        <code class="small" style="word-break: break-all;">{{ $site['host_id'] }}</code>
                                    @else
                                        <span class="text-muted small">&mdash;</span>
                                    @endif
                                </td>
                                <td class="text-end d-none d-md-table-cell fw-semibold">{{ $site['device_count'] ?? '—' }}</td>
                                <td class="d-none d-lg-table-cell">{{ $site['isp_name'] ?? '—' }}</td>
                                <td>
                                    <select name="mappings[{{ $site['site_id'] }}]" class="form-select form-select-sm client-select" data-selected="{{ $mapped?->id }}">
                                        <option value="">&mdash; Not mapped &mdash;</option>
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
                                <td colspan="6" class="text-center text-muted py-4">
                                    No UniFi sites are visible to this API key. Check that the key belongs to the account that administers your clients' consoles.
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
