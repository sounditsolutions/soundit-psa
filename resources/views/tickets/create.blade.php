@extends('layouts.app')

@section('title', 'New Ticket')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('tickets.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Tickets
        </a>
    </div>
</div>

<div class="row mb-3">
    <div class="col">
        <h4 class="section-title">New Ticket</h4>
    </div>
</div>

<div class="card shadow-sm card-static">
    <div class="card-body">
        <form method="POST" action="{{ route('tickets.store') }}">
            @csrf

            <div class="row g-3">
                {{-- Client --}}
                <div class="col-md-6">
                    <label for="client_id" class="form-label">Client <span class="text-danger">*</span></label>
                    <select name="client_id" id="client_id" class="form-select @error('client_id') is-invalid @enderror" required>
                        <option value="">Select client...</option>
                        @foreach($clients as $c)
                            <option value="{{ $c->id }}" {{ old('client_id') == $c->id ? 'selected' : '' }}>
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
                           value="{{ old('subject') }}" required autofocus>
                    @error('subject')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Description --}}
                <div class="col-12">
                    <label for="description" class="form-label">Description</label>
                    <x-markdown-editor name="description" id="description" rows="4" />
                </div>

                {{-- Type --}}
                <div class="col-md-3">
                    <label for="type" class="form-label">Type</label>
                    <select name="type" id="type" class="form-select">
                        @foreach($types as $t)
                            <option value="{{ $t->value }}" {{ old('type', 'incident') === $t->value ? 'selected' : '' }}>
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
                                    {{ old('priority', 'p3') === $p->value ? 'selected' : '' }}>
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
                            <option value="{{ $u->id }}" {{ old('assignee_id', auth()->id()) == $u->id ? 'selected' : '' }}>
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
                            <option value="{{ $cat }}" {{ old('category') === $cat ? 'selected' : '' }}>
                                {{ $cat }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Subcategory --}}
                <div class="col-md-4">
                    <label for="subcategory" class="form-label">Subcategory</label>
                    <select name="subcategory" id="subcategory" class="form-select" disabled>
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

            {{-- Change management (ITIL) — shown only when Type is Change --}}
            <div class="row g-3 mt-1 {{ old('type', 'incident') === 'change' ? '' : 'd-none' }}" id="changeMgmtFields">
                <div class="col-12">
                    <hr class="mb-1">
                    <div class="form-text mb-0"><i class="bi bi-arrow-repeat me-1"></i>Change Management</div>
                </div>
                {{-- Change type --}}
                <div class="col-md-4">
                    <label for="change_type" class="form-label">Change Type</label>
                    <select name="change_type" id="change_type" class="form-select">
                        <option value="">-- None --</option>
                        @foreach($changeTypes as $ct)
                            <option value="{{ $ct->value }}" {{ old('change_type') === $ct->value ? 'selected' : '' }}>
                                {{ $ct->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                {{-- Risk level --}}
                <div class="col-md-4">
                    <label for="risk_level" class="form-label">Risk Level</label>
                    <select name="risk_level" id="risk_level" class="form-select">
                        <option value="">-- None --</option>
                        @foreach($riskLevels as $rl)
                            <option value="{{ $rl->value }}" {{ old('risk_level') === $rl->value ? 'selected' : '' }}>
                                {{ $rl->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                {{-- CAB approval --}}
                <div class="col-md-4">
                    <label for="cab_approval" class="form-label">CAB Approval</label>
                    <select name="cab_approval" id="cab_approval" class="form-select">
                        <option value="">-- None --</option>
                        @foreach($cabApprovals as $ca)
                            <option value="{{ $ca->value }}" {{ old('cab_approval') === $ca->value ? 'selected' : '' }}>
                                {{ $ca->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Create Ticket
                </button>
                <a href="{{ route('tickets.index') }}" class="btn btn-outline-secondary ms-2">Cancel</a>
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
    const typeSelect = document.getElementById('type');
    const changeMgmtFields = document.getElementById('changeMgmtFields');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    // Type change → reveal ITIL change management fields only for Change tickets
    if (typeSelect && changeMgmtFields) {
        typeSelect.addEventListener('change', function() {
            changeMgmtFields.classList.toggle('d-none', this.value !== 'change');
        });
    }

    // Client change → load contacts and assets
    clientSelect.addEventListener('change', function() {
        const clientId = this.value;
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
                contactSelect.appendChild(opt);
            });
        })
        .catch(function() {
            contactSelect.disabled = true;
            document.getElementById('contactGroup').classList.add('d-none');
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
                assetSelect.appendChild(opt);
            });
        })
        .catch(function() {
            assetSelect.disabled = true;
            document.getElementById('assetGroup').classList.add('d-none');
        });
    });

    // Category → subcategory cascade
    categorySelect.addEventListener('change', function() {
        const cat = this.value;
        subcategorySelect.innerHTML = '<option value="">-- None --</option>';

        if (cat && categories[cat]) {
            subcategorySelect.disabled = false;
            categories[cat].forEach(function(sub) {
                const opt = document.createElement('option');
                opt.value = sub;
                opt.textContent = sub;
                subcategorySelect.appendChild(opt);
            });
        } else {
            subcategorySelect.disabled = true;
        }
    });

    // Priority change → auto-set due date.
    // NOTE: new Date() uses the browser's local timezone, not the app timezone. For a
    // single-location MSP where browser tz == app tz this is correct. Full fix deferred.
    function updateDueDate() {
        const selected = prioritySelect.options[prioritySelect.selectedIndex];
        const slaHours = parseInt(selected.dataset.slaHours || 24);
        const due = new Date();
        due.setHours(due.getHours() + slaHours);
        // Format as datetime-local value
        const pad = n => String(n).padStart(2, '0');
        dueAtInput.value = due.getFullYear() + '-' + pad(due.getMonth() + 1) + '-' +
                           pad(due.getDate()) + 'T' + pad(due.getHours()) + ':' + pad(due.getMinutes());
    }

    prioritySelect.addEventListener('change', updateDueDate);

    // Set initial due date on page load
    if (!dueAtInput.value) {
        updateDueDate();
    }
});
</script>
@endpush
