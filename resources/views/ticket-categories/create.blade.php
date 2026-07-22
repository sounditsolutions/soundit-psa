@extends('layouts.app')

@section('title', 'New Ticket Category')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('ticket-categories.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Ticket Categories
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col"><h4 class="section-title">New Ticket Category</h4></div>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="POST" action="{{ route('ticket-categories.store') }}">
                    @csrf

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="name">Name <span class="text-danger">*</span></label>
                            <input id="name" name="name" class="form-control" maxlength="150" required
                                   value="{{ old('name') }}" autofocus>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="sort_order">Sort order</label>
                            <input id="sort_order" name="sort_order" type="number" class="form-control"
                                   min="0" max="10000" value="{{ old('sort_order', 0) }}">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label" for="parent_id">Parent</label>
                            <select id="parent_id" name="parent_id" class="form-select">
                                <option value="">— Root category —</option>
                                @foreach($parentOptions as $opt)
                                    <option value="{{ $opt['node']->id }}"
                                        {{ (int) old('parent_id', $preselectedParent) === $opt['node']->id ? 'selected' : '' }}>
                                        {{ $opt['label'] }}{{ $opt['node']->is_active ? '' : ' (retired)' }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Tree is at most 3 levels: Category / Subcategory / Item.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="record_type_hint">Type hint</label>
                            <select id="record_type_hint" name="record_type_hint" class="form-select">
                                <option value="">— None —</option>
                                @foreach(\App\Enums\RecordTypeHint::cases() as $hint)
                                    <option value="{{ $hint->value }}" {{ old('record_type_hint') === $hint->value ? 'selected' : '' }}>
                                        {{ $hint->label() }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">Advisory: does work here tend to be an incident or a request?</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="description">Description <small class="text-muted">(Markdown)</small></label>
                            <textarea id="description" name="description" rows="3" class="form-control font-monospace"
                                      placeholder="What belongs under this category?">{{ old('description') }}</textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="sop_text">SOP <small class="text-muted">(Markdown)</small></label>
                            <textarea id="sop_text" name="sop_text" rows="12" class="form-control font-monospace"
                                      placeholder="Step-by-step procedure for tickets in this category…">{{ old('sop_text') }}</textarea>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="sop_status">SOP status</label>
                            <select id="sop_status" name="sop_status" class="form-select">
                                @foreach(\App\Enums\SopStatus::cases() as $status)
                                    <option value="{{ $status->value }}" {{ old('sop_status', 'none') === $status->value ? 'selected' : '' }}>
                                        {{ $status->label() }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">A soft hint for readers — never blocks the SOP from being shown.</div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Create Category</button>
                        <a href="{{ route('ticket-categories.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
