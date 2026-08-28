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

        {{-- Page-level validation summary (the #751 lesson): a bounced key save
             must be visible, not look like Save did nothing. --}}
        @if ($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>Not saved.</strong>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

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

        @if($domainsError !== null)
            {{-- The account-level listing failed (on an MSSP account /domains can
                 403 under the account key). Shown inline so the per-client key
                 section below — the remedy — stays reachable. --}}
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>Could not list PowerDMARC domains with the account-level key:</strong>
                {{ $domainsError }}
                <div class="small mt-1">
                    Existing mappings are untouched. If this account is an MSSP/partner account, per-domain reads
                    may need per-client API keys &mdash; you can still manage those below.
                </div>
            </div>
        @else
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
        @endif

        {{-- ── Per-client API keys (ops 440/442) ──────────────────────────── --}}
        <div class="d-flex align-items-center justify-content-between mt-5 mb-2">
            <h4 class="mb-0">Per-Client API Keys</h4>
        </div>
        <p class="text-muted mb-3">
            The account-level key on the Integrations page stays in charge of the domain listing above. But an
            MSSP/partner account key is refused (403) on PowerDMARC's per-domain report routes &mdash; those reads
            need a <strong>client-portal token</strong>, minted per client via <em>Login as client</em> in the
            PowerDMARC MSSP portal. A client with a key below uses it for its domain status, aggregate-report and
            DNS-timeline reads; a client without one falls back to the account-level key.
            <strong>Test</strong> runs a real per-domain read with the stored key, which is the decisive check.
        </p>

        <form method="POST" action="{{ route('settings.powerdmarc-domains.keys.update') }}">
            @csrf

            <div class="card card-static shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="min-width: 180px;">Client</th>
                                <th>API Key (client-portal token)</th>
                                <th class="text-center" style="width: 140px;">Status</th>
                                <th class="text-center" style="width: 70px;">Clear</th>
                                <th class="text-center" style="width: 90px;">Test</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($keyClients as $client)
                            @php
                                $keyRow = $clientKeys->get($client->id);
                            @endphp
                            <tr>
                                <td><strong>{{ $client->name }}</strong></td>
                                <td>
                                    <input type="password"
                                           class="form-control form-control-sm font-monospace {{ $errors->has('keys.'.$client->id) ? 'is-invalid' : '' }}"
                                           name="keys[{{ $client->id }}]"
                                           value=""
                                           autocomplete="off"
                                           placeholder="{{ $keyRow ? '••••••••' : 'No key — uses the account-level key' }}"
                                           aria-label="PowerDMARC API key for {{ $client->name }}">
                                    @if($errors->has('keys.'.$client->id))
                                        <div class="invalid-feedback d-block">{{ $errors->first('keys.'.$client->id) }}</div>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($keyRow?->verified_at)
                                        <span class="badge bg-success" title="Last verified {{ $keyRow->verified_at->format('Y-m-d H:i') }} UTC">Verified</span>
                                    @elseif($keyRow)
                                        <span class="badge bg-warning text-dark">Set &mdash; untested</span>
                                    @else
                                        <span class="badge bg-secondary">Not set</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($keyRow)
                                        <input type="checkbox" class="form-check-input" name="clear[{{ $client->id }}]" value="1" aria-label="Clear the stored key for {{ $client->name }}">
                                    @else
                                        <span class="text-muted small">&mdash;</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($keyRow)
                                        <button type="button" class="btn btn-outline-secondary btn-sm pdmarc-test-key" data-url="{{ route('settings.powerdmarc-domains.keys.test', $client) }}">
                                            <i class="bi bi-plug"></i>
                                        </button>
                                    @else
                                        <span class="text-muted small">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No active clients.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="pdmarc-key-test-result" class="alert mt-3" style="display:none;"></div>

            <div class="mt-3 d-flex align-items-center gap-3">
                <button type="submit" class="btn btn-primary">Save Per-Client Keys</button>
                <span class="text-muted small">Blank fields keep the stored key. Removal is the Clear checkbox only. Saving or testing a key requires administrator access.</span>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.pdmarc-test-key').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var result = document.getElementById('pdmarc-key-test-result');
        btn.disabled = true;
        result.style.display = 'block';
        result.className = 'alert alert-info mt-3';
        result.textContent = 'Testing…';

        fetch(btn.dataset.url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
            },
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                result.className = 'alert mt-3 ' + (data.success ? 'alert-success' : 'alert-danger');
                result.textContent = data.message;
            })
            .catch(function () {
                result.className = 'alert alert-danger mt-3';
                result.textContent = 'Test request failed.';
            })
            .finally(function () { btn.disabled = false; });
    });
});
</script>
@endsection
