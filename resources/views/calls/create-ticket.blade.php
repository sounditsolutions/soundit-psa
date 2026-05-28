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
                {{-- Client --}}
                <div class="col-md-6">
                    <label for="client_id" class="form-label">Client <span class="text-danger">*</span></label>
                    <select name="client_id" id="client_id" class="form-select @error('client_id') is-invalid @enderror" required>
                        <option value="">Select client...</option>
                        @foreach($clients as $c)
                            <option value="{{ $c->id }}" {{ old('client_id', $call->client_id) == $c->id ? 'selected' : '' }}>
                                {{ $c->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('client_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
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
    const clientSelect = document.getElementById('client_id');
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

    clientSelect.addEventListener('change', function() {
        loadClientData(this.value, null, null);
    });

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

    // Initial load: if client is pre-selected, load contacts/assets with preselects
    if (clientSelect.value) {
        loadClientData(clientSelect.value, preselectedContactId, preselectedAssetId);
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
