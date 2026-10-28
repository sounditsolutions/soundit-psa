@extends('layouts.app')
@section('title', 'Agreement template')
@section('content')
<h1>{{ $template->exists ? 'Edit template' : 'Create template' }}</h1>
<form method="post" action="{{ $template->exists ? route('settings.agreement-templates.update', $template) : route('settings.agreement-templates.store') }}">
@csrf
@if($template->exists) @method('patch') @endif
<label class="form-label" for="template-name">Name</label>
<input class="form-control" id="template-name" name="template_name" maxlength="255" required value="{{ old('template_name', $template->name) }}">
@error('template_name')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
<fieldset class="mt-3"><legend>Kind</legend>
@foreach(['agreement' => 'Agreement', 'proposal' => 'Proposal'] as $value => $label)
<label><input type="radio" name="template_kind" value="{{ $value }}" @checked(old('template_kind', $template->kind) === $value)> {{ $label }}</label>
@endforeach
</fieldset>
@error('template_kind')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
<label class="form-label" for="template-type">Contract type</label>
<select class="form-select" id="template-type" name="template_type">
@foreach(\App\Enums\ContractType::cases() as $type)
<option value="{{ $type->value }}" @selected(old('template_type', $template->contract_type ?? 'breakfix') === $type->value)>{{ $type->label() }}</option>
@endforeach
</select>
@error('template_type')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
<div id="agreement-options">
<label class="form-label" for="template-steps">Signing steps</label>
<select class="form-select" id="template-steps" name="template_steps">
@foreach([1, 2] as $steps)<option value="{{ $steps }}" @selected((int) old('template_steps', $template->signing_steps) === $steps)>{{ $steps }}</option>@endforeach
</select>
@error('template_steps')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
<p>Managed Services is two-step: proposal acceptance, then the agreement</p>
<input type="hidden" name="template_countersign" value="0">
<label><input type="checkbox" id="template-countersign" name="template_countersign" value="1" @checked(old('template_countersign', $template->requires_countersign))> Requires countersign</label>
@error('template_countersign')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
</div>
<label class="form-label d-block mt-3" for="template-body">Body</label>
<textarea class="form-control" id="template-body" name="template_body" rows="16" required>{{ old('template_body', $template->body) }}</textarea>
@error('template_body')<div class="text-danger" role="alert">{{ $message }}</div>@enderror
<p>Merge fields: @verbatim<code>{{client.name}}</code>, <code>{{contract.name}}</code>, <code>{{contract.type}}</code>, <code>{{today}}</code>, <code>{{signer.name}}</code>, <code>{{lines_table}}</code>@endverbatim</p>
<dl><dt>Apply billing on sign</dt><dd>Held: staff apply billing by hand</dd><dt>On complete</dt><dd>None</dd></dl>
<button class="btn btn-primary">Save template</button>
<a class="btn btn-outline-primary" href="{{ route('settings.agreement-templates.index') }}">Back to templates</a>
</form>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const type = document.getElementById('template-type');
    const steps = document.getElementById('template-steps');
    const countersign = document.getElementById('template-countersign');
    const proposal = () => document.querySelector('input[name="template_kind"]:checked').value === 'proposal';
    const updateKind = () => {
        document.getElementById('agreement-options').hidden = proposal();
        if (proposal()) { steps.value = '1'; countersign.checked = false; }
    };
    type.addEventListener('change', () => {
        if (type.value === 'managed' && !proposal()) { steps.value = '2'; countersign.checked = true; }
    });
    document.querySelectorAll('input[name="template_kind"]').forEach(input => input.addEventListener('change', updateKind));
    updateKind();
});
</script>
@endsection
