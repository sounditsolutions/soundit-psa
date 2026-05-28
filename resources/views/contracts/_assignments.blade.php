{{-- Assignment summary counts --}}
<div class="d-flex align-items-center gap-3 mb-2">
    <span class="badge bg-light text-dark">
        <i class="bi bi-pc-display me-1"></i>{{ $contract->assets->count() }} Assets
    </span>
    <span class="badge bg-light text-dark">
        <i class="bi bi-people me-1"></i>{{ $contract->people->count() }} People
    </span>
    <span class="badge bg-light text-dark">
        <i class="bi bi-key me-1"></i>{{ $contract->licenses->count() }} Licenses
    </span>
    <span class="badge bg-light text-dark" id="ruleCountBadge">
        <i class="bi bi-gear me-1"></i>{{ $contract->assignmentRules->count() }} Rules
    </span>
</div>

<ul class="nav nav-tabs" id="assignmentTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="assets-tab" data-bs-toggle="tab" data-bs-target="#assets-pane" type="button" role="tab">
            Assets
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="people-tab" data-bs-toggle="tab" data-bs-target="#people-pane" type="button" role="tab">
            People
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="licenses-tab" data-bs-toggle="tab" data-bs-target="#licenses-pane" type="button" role="tab">
            Licenses
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="rules-tab" data-bs-toggle="tab" data-bs-target="#rules-pane" type="button" role="tab">
            Rules
        </button>
    </li>
</ul>

<div class="tab-content mt-3" id="assignmentTabContent">
    {{-- Assets Tab --}}
    <div class="tab-pane fade show active" id="assets-pane" role="tabpanel">
        @if($unassignedAssets->isNotEmpty())
            <form method="POST" action="{{ route('contracts.assign-asset', $contract) }}" class="mb-3">
                @csrf
                <div class="input-group input-group-sm" style="max-width: 400px;">
                    <select name="asset_id" class="form-select form-select-sm" required>
                        <option value="">Assign asset...</option>
                        @foreach($unassignedAssets as $a)
                            <option value="{{ $a->id }}">{{ $a->hostname ?: $a->name }} ({{ $a->asset_type }})</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
            </form>
        @endif

        @if($contract->assets->isEmpty())
            <p class="text-muted small">No assets assigned to this contract.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th class="d-none d-md-table-cell">Type</th>
                            <th class="d-none d-md-table-cell">Source</th>
                            <th style="width: 60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contract->assets as $asset)
                            <tr>
                                <td>
                                    <x-asset-badge :asset="$asset" />
                                </td>
                                <td class="d-none d-md-table-cell small text-muted">{{ $asset->asset_type }}</td>
                                <td class="d-none d-md-table-cell">
                                    <span class="badge {{ $asset->pivot->assignment_source === 'rule' ? 'bg-info' : 'bg-light text-dark' }}">
                                        {{ ucfirst($asset->pivot->assignment_source) }}
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('contracts.unassign-asset', [$contract, $asset]) }}" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Unassign">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- People Tab --}}
    <div class="tab-pane fade" id="people-pane" role="tabpanel">
        @if($unassignedPeople->isNotEmpty())
            <form method="POST" action="{{ route('contracts.assign-person', $contract) }}" class="mb-3">
                @csrf
                <div class="input-group input-group-sm" style="max-width: 400px;">
                    <select name="person_id" class="form-select form-select-sm" required>
                        <option value="">Assign person...</option>
                        @foreach($unassignedPeople as $p)
                            <option value="{{ $p->id }}">{{ $p->full_name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
            </form>
        @endif

        @if($contract->people->isEmpty())
            <p class="text-muted small">No people assigned to this contract.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th class="d-none d-md-table-cell">Email</th>
                            <th class="d-none d-md-table-cell">Source</th>
                            <th style="width: 60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contract->people as $person)
                            <tr>
                                <td><x-person-badge :person="$person" :size="24" /></td>
                                <td class="d-none d-md-table-cell small text-muted">{{ $person->email ?? '-' }}</td>
                                <td class="d-none d-md-table-cell">
                                    <span class="badge {{ $person->pivot->assignment_source === 'rule' ? 'bg-info' : 'bg-light text-dark' }}">
                                        {{ ucfirst($person->pivot->assignment_source) }}
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('contracts.unassign-person', [$contract, $person]) }}" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Unassign">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Licenses Tab --}}
    <div class="tab-pane fade" id="licenses-pane" role="tabpanel">
        @if($unassignedLicenses->isNotEmpty())
            <div class="d-flex align-items-start gap-2 mb-3 flex-wrap">
                <form method="POST" action="{{ route('contracts.assign-license', $contract) }}">
                    @csrf
                    <div class="input-group input-group-sm" style="max-width: 500px;">
                        <select name="license_id" class="form-select form-select-sm" required>
                            <option value="">Assign license...</option>
                            @foreach($unassignedLicenses as $lic)
                                <option value="{{ $lic->id }}">
                                    {{ $lic->licenseType->name }} ({{ $lic->quantity }}x) — {{ $lic->licenseType->vendor }}
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    </div>
                </form>
                <form method="POST" action="{{ route('contracts.assign-all-licenses', $contract) }}"
                      onsubmit="return confirm('Assign {{ $unassignedLicenses->count() }} unassigned license(s) to this contract?')">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-circle me-1"></i>Assign All ({{ $unassignedLicenses->count() }})
                    </button>
                </form>
            </div>
        @endif

        @if($contract->licenses->isEmpty())
            <p class="text-muted small">No licenses assigned. Assign licenses from the client's license pool or <a href="{{ route('licenses.create') }}">create one</a>.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>License Type</th>
                            <th class="d-none d-md-table-cell">Vendor</th>
                            <th class="text-end">Qty</th>
                            <th class="d-none d-md-table-cell">Status</th>
                            <th style="width: 60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contract->licenses as $license)
                            <tr>
                                <td>{{ $license->licenseType->name }}</td>
                                <td class="d-none d-md-table-cell">
                                    <span class="badge bg-light text-dark">{{ $license->licenseType->vendor }}</span>
                                </td>
                                <td class="text-end fw-semibold">{{ $license->quantity }}</td>
                                <td class="d-none d-md-table-cell">
                                    <span class="badge bg-{{ $license->status === 'active' ? 'success' : 'secondary' }}">
                                        {{ ucfirst($license->status) }}
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('contracts.unassign-license', [$contract, $license]) }}" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Unassign">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Rules Tab --}}
    <div class="tab-pane fade" id="rules-pane" role="tabpanel">
        <div id="rulesAlert" class="d-none"></div>

        <div class="d-flex gap-2 mb-3">
            <button type="button" class="btn btn-outline-primary btn-sm" id="evaluateRulesBtn"
                    data-url="{{ route('contracts.evaluate-rules', $contract) }}">
                <i class="bi bi-play-fill me-1"></i>Evaluate Now
            </button>
        </div>

        {{-- Add Rule Form --}}
        <form id="addRuleForm" data-url="{{ route('contracts.store-rule', $contract) }}" class="mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Rule Name <span class="text-muted">(optional)</span></label>
                    <input type="text" name="name" class="form-control form-control-sm" placeholder="Auto-named from type">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Rule Type</label>
                    <select name="rule_type" class="form-select form-select-sm" id="newRuleType" onchange="toggleFilterValues()">
                        @foreach($ruleTypes as $rt)
                            <option value="{{ $rt->value }}" data-needs-filter="{{ $rt->needsFilterValues() ? '1' : '0' }}">
                                {{ $rt->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4" id="filterValuesCol">
                    <label class="form-label small">Asset Types <span class="text-muted">(comma-separated)</span></label>
                    <input type="text" name="filter_values" class="form-control form-control-sm"
                           placeholder="Windows Workstation, Mac">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-plus-lg me-1"></i>Add Rule
                    </button>
                </div>
            </div>
        </form>

        <div id="rulesTableContainer">
            @if($contract->assignmentRules->isEmpty())
                <p class="text-muted small" id="noRulesMsg">No assignment rules. Add one above or assign assets and people manually.</p>
            @else
                @include('contracts._rules_table', ['rules' => $contract->assignmentRules])
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
function toggleFilterValues() {
    const sel = document.getElementById('newRuleType');
    const col = document.getElementById('filterValuesCol');
    const opt = sel.options[sel.selectedIndex];
    col.style.display = opt.dataset.needsFilter === '1' ? '' : 'none';
}
toggleFilterValues();

(function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const alertBox = document.getElementById('rulesAlert');

    function showAlert(msg, type) {
        alertBox.className = `alert alert-${type} alert-dismissible fade show small py-2`;
        alertBox.innerHTML = msg + '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>';
        setTimeout(() => { if (alertBox.classList.contains('show')) alertBox.classList.add('d-none'); }, 4000);
    }

    function updateRuleCount(count) {
        const badge = document.getElementById('ruleCountBadge');
        if (badge) badge.innerHTML = '<i class="bi bi-gear me-1"></i>' + count + ' Rules';
    }

    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function buildRuleRow(rule) {
        const filter = rule.filter_values ? rule.filter_values.join(', ') : '-';
        return `<tr data-rule-id="${rule.id}">
            <td>${escHtml(rule.name)}</td>
            <td><span class="badge bg-light text-dark">${escHtml(rule.type_label)}</span></td>
            <td class="d-none d-md-table-cell small text-muted">${escHtml(filter)}</td>
            <td class="d-none d-md-table-cell small">Never</td>
            <td class="text-center"><i class="bi bi-check-circle text-success"></i></td>
            <td>
                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1 delete-rule-btn"
                        data-url="${rule.destroy_url}" title="Delete rule">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>`;
    }

    function ensureTable() {
        const container = document.getElementById('rulesTableContainer');
        const noMsg = document.getElementById('noRulesMsg');
        if (noMsg) noMsg.remove();
        if (!container.querySelector('table')) {
            container.innerHTML = `<div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr>
                        <th>Name</th><th>Type</th>
                        <th class="d-none d-md-table-cell">Filter</th>
                        <th class="d-none d-md-table-cell">Last Evaluated</th>
                        <th class="text-center">Active</th>
                        <th style="width: 60px;"></th>
                    </tr></thead>
                    <tbody></tbody>
                </table>
            </div>`;
        }
        return container.querySelector('tbody');
    }

    async function ajaxRequest(url, method, body) {
        const opts = {
            method: method,
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
        };
        if (body) opts.body = JSON.stringify(body);
        const resp = await fetch(url, opts);
        const data = await resp.json();
        if (!resp.ok) {
            const msg = data.errors
                ? Object.values(data.errors).flat().join('<br>')
                : (data.message || 'An error occurred.');
            throw new Error(msg);
        }
        return data;
    }

    // Add Rule
    document.getElementById('addRuleForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const form = this;
        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;

        try {
            const formData = new FormData(form);
            const body = Object.fromEntries(formData.entries());
            const data = await ajaxRequest(form.dataset.url, 'POST', body);

            const tbody = ensureTable();
            tbody.insertAdjacentHTML('beforeend', buildRuleRow(data.rule));
            updateRuleCount(data.rule_count);
            showAlert(data.message, 'success');
            form.reset();
            toggleFilterValues();
        } catch (err) {
            showAlert(err.message, 'danger');
        } finally {
            btn.disabled = false;
        }
    });

    // Delete Rule (delegated)
    document.getElementById('rulesTableContainer').addEventListener('click', async function(e) {
        const btn = e.target.closest('.delete-rule-btn');
        if (!btn) return;
        btn.disabled = true;

        try {
            const data = await ajaxRequest(btn.dataset.url, 'DELETE', null);
            const row = btn.closest('tr');
            row.remove();
            updateRuleCount(data.rule_count);
            showAlert(data.message, 'success');

            // If table is now empty, show the empty message
            const tbody = document.querySelector('#rulesTableContainer tbody');
            if (tbody && tbody.children.length === 0) {
                document.getElementById('rulesTableContainer').innerHTML =
                    '<p class="text-muted small" id="noRulesMsg">No assignment rules. Add one above or assign assets and people manually.</p>';
            }
        } catch (err) {
            showAlert(err.message, 'danger');
            btn.disabled = false;
        }
    });

    // Evaluate Rules
    document.getElementById('evaluateRulesBtn').addEventListener('click', async function() {
        const btn = this;
        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Evaluating...';

        try {
            const data = await ajaxRequest(btn.dataset.url, 'POST', null);
            showAlert(data.message, 'success');
            // Reload to reflect assignment changes in the tables
            if (data.assets_added > 0 || data.assets_removed > 0 || data.people_added > 0 || data.people_removed > 0) {
                setTimeout(() => location.reload(), 1000);
            }
        } catch (err) {
            showAlert(err.message, 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    });
})();
</script>
@endpush
