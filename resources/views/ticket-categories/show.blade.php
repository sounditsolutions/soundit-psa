@extends('layouts.app')

@section('title', $node->name)

@section('content')
@php
    // On a validation failure the page reloads — reopen the editor the user
    // was in (each in-place form identifies itself via a hidden form_key).
    $openEditor = $errors->any() ? old('form_key') : null;
@endphp

<div class="row mb-3">
    <div class="col">
        <a href="{{ route('ticket-categories.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Ticket Categories
        </a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row mb-4">
    <div class="col d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            @if($node->ancestors()->isNotEmpty())
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1">
                        @foreach($node->ancestors() as $ancestor)
                            <li class="breadcrumb-item">
                                <a href="{{ route('ticket-categories.show', $ancestor) }}" class="text-decoration-none">{{ $ancestor->name }}</a>
                            </li>
                        @endforeach
                        <li class="breadcrumb-item active" aria-current="page">{{ $node->name }}</li>
                    </ol>
                </nav>
            @endif

            <div id="display-name" class="{{ $openEditor === 'name' ? 'd-none' : '' }}">
                <h4 class="section-title mb-1 d-inline-block">{{ $node->name }}</h4>
                <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-muted align-baseline"
                        onclick="tcToggle('name')" title="Rename">
                    <i class="bi bi-pencil"></i>
                </button>
            </div>
            <form id="editor-name" method="POST" action="{{ route('ticket-categories.update', $node) }}"
                  class="{{ $openEditor === 'name' ? '' : 'd-none' }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="form_key" value="name">
                <div class="input-group" style="max-width: 420px;">
                    <input type="text" name="name" class="form-control" maxlength="150" required
                           value="{{ old('name', $node->name) }}">
                    <button type="submit" class="btn btn-success" title="Save"><i class="bi bi-check"></i></button>
                    <button type="button" class="btn btn-outline-secondary" onclick="tcToggle('name')" title="Cancel"><i class="bi bi-x"></i></button>
                </div>
            </form>

            <div class="mt-1">
                <span class="badge {{ $node->sop_status->badgeClass() }}">{{ $node->sop_status->label() }}</span>
                @if($node->record_type_hint)
                    <span class="badge {{ $node->record_type_hint->badgeClass() }}">{{ $node->record_type_hint->label() }}</span>
                @endif
                @unless($node->is_active)
                    <span class="badge bg-secondary">Retired</span>
                @endunless
            </div>
        </div>
        <div class="d-flex gap-2">
            @if($canHaveChildren)
                <a href="{{ route('ticket-categories.create', ['parent' => $node->id]) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>Add Child
                </a>
            @endif
            <form method="POST" action="{{ route('ticket-categories.update', $node) }}"
                  @if($node->is_active) onsubmit="return confirm('Retire this category? It stays on existing tickets but is hidden from active lists.')" @endif>
                @csrf
                @method('PATCH')
                <input type="hidden" name="form_key" value="is_active">
                <input type="hidden" name="is_active" value="{{ $node->is_active ? 0 : 1 }}">
                @if($node->is_active)
                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-archive me-1"></i>Retire</button>
                @else
                    <button type="submit" class="btn btn-outline-success btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Reactivate</button>
                @endif
            </form>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        {{-- Description --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Description</span>
                <button type="button" class="btn btn-link btn-sm p-0 text-muted" onclick="tcToggle('description')" title="Edit description">
                    <i class="bi bi-pencil"></i>
                </button>
            </div>
            <div class="card-body">
                <div id="display-description" class="{{ $openEditor === 'description' ? 'd-none' : '' }}">
                    @if($descriptionHtml)
                        <div class="markdown-body">{!! $descriptionHtml !!}</div>
                    @else
                        <span class="text-muted">No description. <a href="#" onclick="tcToggle('description'); return false;">Add one</a>.</span>
                    @endif
                </div>
                <form id="editor-description" method="POST" action="{{ route('ticket-categories.update', $node) }}"
                      class="{{ $openEditor === 'description' ? '' : 'd-none' }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="form_key" value="description">
                    <textarea name="description" rows="4" class="form-control font-monospace mb-2"
                              placeholder="What belongs under this category? (Markdown)">{{ old('description', $node->description) }}</textarea>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="tcToggle('description')">Cancel</button>
                </form>
            </div>
        </div>

        {{-- SOP --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-semibold">Standard Operating Procedure</span>
                <div class="d-flex align-items-center gap-2">
                    <form method="POST" action="{{ route('ticket-categories.update', $node) }}" class="d-flex align-items-center gap-1">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="form_key" value="sop_status">
                        <label class="small text-muted mb-0" for="sop_status_select">Status</label>
                        <select id="sop_status_select" name="sop_status" class="form-select form-select-sm" style="width: auto;"
                                onchange="this.form.submit()">
                            @foreach(\App\Enums\SopStatus::cases() as $status)
                                <option value="{{ $status->value }}" {{ $node->sop_status === $status ? 'selected' : '' }}>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                    </form>
                    <button type="button" class="btn btn-link btn-sm p-0 text-muted" onclick="tcToggle('sop')" title="Edit SOP">
                        <i class="bi bi-pencil"></i>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div id="display-sop" class="{{ $openEditor === 'sop' ? 'd-none' : '' }}">
                    @if($sopHtml)
                        <div class="markdown-body">{!! $sopHtml !!}</div>
                    @else
                        <div class="text-center py-4">
                            <p class="text-muted mb-2">No SOP written yet — this is a coverage gap.</p>
                            <button type="button" class="btn btn-primary btn-sm" onclick="tcToggle('sop')">
                                <i class="bi bi-pencil me-1"></i>Write the SOP
                            </button>
                        </div>
                    @endif
                </div>
                <form id="editor-sop" method="POST" action="{{ route('ticket-categories.update', $node) }}"
                      class="{{ $openEditor === 'sop' ? '' : 'd-none' }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="form_key" value="sop">
                    <textarea name="sop_text" rows="16" class="form-control font-monospace mb-2"
                              placeholder="Step-by-step procedure for tickets in this category… (Markdown)">{{ old('sop_text', $node->sop_text) }}</textarea>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="tcToggle('sop')">Cancel</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        {{-- Details --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Details</span>
                <button type="button" class="btn btn-link btn-sm p-0 text-muted" onclick="tcToggle('details')" title="Edit details">
                    <i class="bi bi-pencil"></i>
                </button>
            </div>
            <div class="card-body">
                <div id="display-details" class="{{ $openEditor === 'details' ? 'd-none' : '' }}">
                    <dl class="row mb-0 small">
                        <dt class="col-5">Parent</dt>
                        <dd class="col-7">
                            @if($node->parent)
                                <a href="{{ route('ticket-categories.show', $node->parent) }}" class="text-decoration-none">{{ $node->parent->name }}</a>
                            @else
                                <span class="text-muted">Root category</span>
                            @endif
                        </dd>
                        <dt class="col-5">Type hint</dt>
                        <dd class="col-7">{{ $node->record_type_hint?->label() ?? '—' }}</dd>
                        <dt class="col-5">Sort order</dt>
                        <dd class="col-7">{{ $node->sort_order }}</dd>
                        <dt class="col-5">Source runbook</dt>
                        <dd class="col-7">{{ $node->source_runbook_slug ?? '—' }}</dd>
                        <dt class="col-5">Tickets</dt>
                        <dd class="col-7">{{ number_format($node->tickets_count) }}</dd>
                        <dt class="col-5">Updated</dt>
                        <dd class="col-7">
                            <span title="{{ $node->updated_at->toAppTz()->format('M j, Y g:i A') }}">{{ $node->updated_at->diffForHumans() }}</span>
                            @if($node->editor)
                                by {{ $node->editor->name }}
                            @endif
                        </dd>
                    </dl>
                </div>
                <form id="editor-details" method="POST" action="{{ route('ticket-categories.update', $node) }}"
                      class="{{ $openEditor === 'details' ? '' : 'd-none' }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="form_key" value="details">
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="parent_id">Parent</label>
                        <select id="parent_id" name="parent_id" class="form-select form-select-sm">
                            <option value="">— Root category —</option>
                            @foreach($parentOptions as $opt)
                                <option value="{{ $opt['node']->id }}"
                                    {{ (int) old('parent_id', $node->parent_id) === $opt['node']->id ? 'selected' : '' }}>
                                    {{ $opt['label'] }}{{ $opt['node']->is_active ? '' : ' (retired)' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="record_type_hint">Type hint</label>
                        <select id="record_type_hint" name="record_type_hint" class="form-select form-select-sm">
                            <option value="">— None —</option>
                            @foreach(\App\Enums\RecordTypeHint::cases() as $hint)
                                <option value="{{ $hint->value }}"
                                    {{ old('record_type_hint', $node->record_type_hint?->value) === $hint->value ? 'selected' : '' }}>
                                    {{ $hint->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="sort_order">Sort order</label>
                        <input id="sort_order" name="sort_order" type="number" class="form-control form-control-sm"
                               min="0" max="10000" value="{{ old('sort_order', $node->sort_order) }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small mb-1" for="source_runbook_slug">Source runbook slug</label>
                        <input id="source_runbook_slug" name="source_runbook_slug" class="form-control form-control-sm"
                               maxlength="255" value="{{ old('source_runbook_slug', $node->source_runbook_slug) }}"
                               placeholder="Provenance of a migrated wiki runbook">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="tcToggle('details')">Cancel</button>
                </form>
            </div>
        </div>

        {{-- Children --}}
        @if($canHaveChildren || $node->children->isNotEmpty())
            <div class="card shadow-sm mb-3">
                <div class="card-header fw-semibold">Children</div>
                <div class="card-body p-0">
                    @if($node->children->isEmpty())
                        <p class="text-muted small px-3 py-2 mb-0">No child categories.</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach($node->children as $child)
                                <li class="list-group-item d-flex justify-content-between align-items-center {{ $child->is_active ? '' : 'opacity-50' }}">
                                    <a href="{{ route('ticket-categories.show', $child) }}" class="text-decoration-none">{{ $child->name }}</a>
                                    <span>
                                        @unless($child->is_active)
                                            <span class="badge bg-secondary">Retired</span>
                                        @endunless
                                        <span class="badge {{ $child->sop_status->badgeClass() }}">{{ $child->sop_status->label() }}</span>
                                        @if($child->tickets_count > 0)
                                            <span class="badge bg-light text-dark border">{{ number_format($child->tickets_count) }} <i class="bi bi-ticket-perforated"></i></span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                @if($canHaveChildren)
                    <div class="card-footer">
                        <form method="POST" action="{{ route('ticket-categories.store') }}">
                            @csrf
                            <input type="hidden" name="parent_id" value="{{ $node->id }}">
                            <div class="input-group input-group-sm">
                                <input type="text" name="name" class="form-control" maxlength="150" required
                                       placeholder="Quick-add child…">
                                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </form>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
function tcToggle(key) {
    document.getElementById('display-' + key).classList.toggle('d-none');
    document.getElementById('editor-' + key).classList.toggle('d-none');
}
</script>
@endpush
