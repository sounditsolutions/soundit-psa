@extends('layouts.app')

@section('title', 'Create Ticket from Call')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('calls.show', $call) }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Call
        </a>
    </div>
</div>

<div class="row mb-3">
    <div class="col">
        <h4 class="section-title">Create Ticket from Call</h4>
    </div>
</div>

{{-- Call context card --}}
<div class="card card-static shadow-sm mb-4 border-start border-3 border-primary">
    <div class="card-body">
        <div class="row">
            <div class="col-auto">
                <i class="bi bi-telephone fs-4 text-primary"></i>
            </div>
            <div class="col">
                <strong>{{ \App\Support\PhoneNumber::format($call->from_number) }}</strong>
                <small class="d-block text-muted">{{ $call->started_at?->toAppTz()->format('d M Y H:i T') }}</small>
                @if($call->client)
                    <div class="mt-1">{{ $call->client->name }}</div>
                    @if($call->person)
                        <small class="text-muted">{{ $call->person->fullName }}</small>
                    @endif
                @else
                    <div class="mt-1 text-muted">Unknown caller</div>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm card-static">
    <div class="card-body">
        <form method="POST" action="{{ route('calls.store-ticket', $call) }}">
            @csrf

            <div class="row g-3">
                {{-- Client — search-first control --}}
                <div class="col-md-6">
                    <label for="client_search_input" class="form-label">Client <span class="text-danger">*</span></label>
                    {{-- Hidden field that carries the real client_id on submit --}}
                    <input type="hidden" name="client_id" id="client_id"
                           value="{{ old('client_id', $call->client_id) }}">
                    <div class="position-relative">
                        <input type="text"
                               id="client_search_input"
                               class="form-control @error('client_id') is-invalid @enderror"
                               placeholder="Search clients by name, phone, or email…"
                               autocomplete="off"
                               value="{{ old('client_id', $call->client_id) ? ($call->client?->name ?? '') : '' }}">
                        <div id="ticket-client-results" class="list-group position-absolute w-100 shadow-sm"
                             style="display:none; z-index: 1050;"></div>
                    </div>
                    @error('client_id')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    @if($call->client_id && $call->client)
                        <small class="text-muted">{{ $call->client->name }}</small>
                    @endif
                </div>

                {{-- Contact (AJAX) --}}
                <div class="col-md-6" id="contactGroup">
                    <label for="contact_id" class="form-label">Contact</label>
                    <select name="contact_id" id="contact_id" class="form-select" disabled>
                        <option value="">Select client first...</option>
                    </select>
                </div>

                {{-- Subject --}}
                <div class="col-12">
                    <label for="subject" class="form-label">Subject <span class="text-danger">*</span></label>
                    <input type="text" name="subject" id="subject"
                           class="form-control @error('subject') is-invalid @enderror"
                           value="{{ old('subject', $defaultSubject) }}" required autofocus>
                    @error('subject')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Description --}}
                <div class="col-12">
                    <label for="description" class="form-label">Description</label>
                    <x-markdown-editor name="description" id="description" rows="6" :value="$defaultDescription" />
                </div>

                {{-- Type --}}
                <div class="col-md-3">
                    <label for="type" class="form-label">Type</label>
                    <select name="type" id="type" class="form-select">
                        @foreach($types as $t)
                            <option value="{{ $t->value }}" {{ old('type', $defaultType ?? 'incident') === $t->value ? 'selected' : '' }}>
                                {{ $t->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Priority --}}
                <div class="col-md-3">
                    <label for="priority" class="form-label">Priority</label>
                    <select name="priority" id="priority" class="form-select">
                        @foreach($priorities as $p)
                            <option value="{{ $p->value }}"
                                    data-sla-hours="{{ $p->defaultSlaHours() }}"
                                    {{ old('priority', $defaultPriority ?? 'p3') === $p->value ? 'selected' : '' }}>
                                {{ $p->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Asset (AJAX) --}}
                <div class="col-md-3" id="assetGroup">
                    <label for="asset_id" class="form-label">Asset</label>
                    <select name="asset_id" id="asset_id" class="form-select" disabled>
                        <option value="">Select client first...</option>
                    </select>
                </div>

                {{-- Assignee --}}
                <div class="col-md-3">
                    <label for="assignee_id" class="form-label">Assignee</label>
                    <select name="assignee_id" id="assignee_id" class="form-select">
                        <option value="">Unassigned</option>
                        @foreach($users as $u)
                            @php $defaultAssignee = old('assignee_id', $call->answered_by ?? auth()->id()); @endphp
                            <option value="{{ $u->id }}" {{ $defaultAssignee == $u->id ? 'selected' : '' }}>
                                {{ $u->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Category --}}
                <div class="col-md-4">
                    <label for="category" class="form-label">Category</label>
                    <select name="category" id="category" class="form-select">
                        <option value="">-- None --</option>
                        @foreach(array_keys($categories) as $cat)
                            <option value="{{ $cat }}" {{ old('category', $defaultCategory ?? '') === $cat ? 'selected' : '' }}>
                                {{ $cat }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Subcategory --}}
                <div class="col-md-4">
                    <label for="subcategory" class="form-label">Subcategory</label>
                    <select name="subcategory" id="subcategory" class="form-select" disabled
                            data-default="{{ old('subcategory', $defaultSubcategory ?? '') }}">
                        <option value="">Select category first...</option>
                    </select>
                </div>

                {{-- Due date --}}
                <div class="col-md-4">
                    <label for="due_at" class="form-label">Due Date</label>
                    <input type="datetime-local" name="due_at" id="due_at"
                           class="form-control" value="{{ old('due_at') }}">
                    <small class="form-text text-muted">Auto-set from priority SLA</small>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-accent">
                    <i class="bi bi-ticket-perforated me-1"></i>Create Ticket & Link Call
                </button>
                <a href="{{ route('calls.show', $call) }}" class="btn btn-outline-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const categories = @json($categories);

    // Client search control — populates hidden #client_id and triggers contacts/assets load
    const clientSearchInput = document.getElementById('client_search_input');
    const clientIdHidden = document.getElementById('client_id');
    const clientResultsDiv = document.getElementById('ticket-client-results');

    if (clientSearchInput) {
        let searchTimer;

        clientSearchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            clientIdHidden.value = '';  // clear selection when typing
            const q = this.value.trim();
            if (q.length < 2) {
                clientResultsDiv.style.display = 'none';
                clientResultsDiv.innerHTML = '';
                return;
            }
            searchTimer = setTimeout(function() {
                fetch('/api/clients/search-all?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' }
                })
                .then(r => r.json())
                .then(function(clients) {
                    clientResultsDiv.innerHTML = '';
                    if (clients.length === 0) {
                        clientResultsDiv.style.display = 'none';
                        return;
                    }
                    clients.forEach(function(c) {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'list-group-item list-group-item-action py-1 px-2 small';
                        btn.textContent = c.name + (c.stage === 'prospect' ? ' (prospect)' : '');
                        btn.addEventListener('click', function() {
                            clientIdHidden.value = c.id;
                            clientSearchInput.value = c.name + (c.stage === 'prospect' ? ' (prospect)' : '');
                            clientResultsDiv.style.display = 'none';
                            clientResultsDiv.innerHTML = '';
                            loadClientData(c.id, null, null);
                        });
                        clientResultsDiv.appendChild(btn);
                    });
                    clientResultsDiv.style.display = '';
                })
                .catch(function() { clientResultsDiv.style.display = 'none'; });
            }, 250);
        });

        document.addEventListener('click', function(e) {
            if (!clientResultsDiv.contains(e.target) && e.target !== clientSearchInput) {
                clientResultsDiv.style.display = 'none';
            }
        });
    }

    const contactSelect = document.getElementById('contact_id');
    const assetSelect = document.getElementById('asset_id');
    const categorySelect = document.getElementById('category');
    const subcategorySelect = document.getElementById('subcategory');
    const prioritySelect = document.getElementById('priority');
    const dueAtInput = document.getElementById('due_at');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    // Pre-selected contact and asset from call record / AI suggestion
    const preselectedContactId = '{{ old('contact_id', $call->person_id) }}';
    const preselectedAssetId = '{{ old('asset_id', $defaultAssetId ?? '') }}';

    // Client change → load contacts and assets
    function loadClientData(clientId, preselectContact, preselectAsset) {
        contactSelect.innerHTML = '<option value="">-- None --</option>';
        assetSelect.innerHTML = '<option value="">-- None --</option>';

        if (!clientId) {
            contactSelect.disabled = true;
            assetSelect.disabled = true;
            return;
        }

        // Load contacts
        fetch('/api/clients/' + clientId + '/contacts', {
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(contacts => {
            contactSelect.disabled = false;
            contacts.forEach(function(c) {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name + (c.email ? ' (' + c.email + ')' : '');
                if (preselectContact && String(c.id) === String(preselectContact)) {
                    opt.selected = true;
                }
                contactSelect.appendChild(opt);
            });
        })
        .catch(function() {
            contactSelect.disabled = true;
        });

        // Load assets
        fetch('/api/clients/' + clientId + '/assets', {
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(assets => {
            assetSelect.disabled = false;
            assets.forEach(function(a) {
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = a.name;
                if (preselectAsset && String(a.id) === String(preselectAsset)) {
                    opt.selected = true;
                }
                assetSelect.appendChild(opt);
            });
        })
        .catch(function() {
            assetSelect.disabled = true;
        });
    }

    // Category → subcategory cascade
    function populateSubcategories(cat, preselect) {
        subcategorySelect.innerHTML = '<option value="">-- None --</option>';
        if (cat && categories[cat]) {
            subcategorySelect.disabled = false;
            categories[cat].forEach(function(sub) {
                const opt = document.createElement('option');
                opt.value = sub;
                opt.textContent = sub;
                if (preselect && sub === preselect) {
                    opt.selected = true;
                }
                subcategorySelect.appendChild(opt);
            });
        } else {
            subcategorySelect.disabled = true;
        }
    }
    categorySelect.addEventListener('change', function() {
        populateSubcategories(this.value, null);
    });

    // Priority change → auto-set due date.
    // NOTE: new Date() uses the browser's local timezone, not the app timezone. For a
    // single-location MSP where browser tz == app tz this is correct. Full fix deferred.
    function updateDueDate() {
        const selected = prioritySelect.options[prioritySelect.selectedIndex];
        const slaHours = parseInt(selected.dataset.slaHours || 24);
        const due = new Date();
        due.setHours(due.getHours() + slaHours);
        const pad = n => String(n).padStart(2, '0');
        dueAtInput.value = due.getFullYear() + '-' + pad(due.getMonth() + 1) + '-' +
                           pad(due.getDate()) + 'T' + pad(due.getHours()) + ':' + pad(due.getMinutes());
    }

    prioritySelect.addEventListener('change', updateDueDate);

    // Initial load: if client is pre-selected (call already has a client), load contacts/assets
    if (clientIdHidden && clientIdHidden.value) {
        loadClientData(clientIdHidden.value, preselectedContactId, preselectedAssetId);
    }

    // Initial subcategory population from server-side default
    if (categorySelect.value) {
        populateSubcategories(categorySelect.value, subcategorySelect.dataset.default || null);
    }

    // Set initial due date on page load
    if (!dueAtInput.value) {
        updateDueDate();
    }
});
</script>
@endpush
