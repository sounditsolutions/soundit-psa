@extends('layouts.app')
@section('title', 'Agreement templates')
@section('content')
<h1>Agreement templates</h1>
<a class="btn btn-primary mb-3" href="{{ route('settings.agreement-templates.create') }}">Create template</a>
<table class="table"><thead><tr><th>Name</th><th>Kind</th><th>Contract type</th><th>Steps</th><th>Countersign</th><th>Status</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
@foreach($templates as $template)
<tr>
<td>{{ $template->name }}</td><td>{{ ucfirst($template->kind) }}</td>
<td>{{ \App\Enums\ContractType::from($template->contract_type)->label() }}</td>
<td>{{ $template->signing_steps }}</td><td>{{ $template->requires_countersign ? 'Yes' : 'No' }}</td>
<td><span class="badge {{ $template->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $template->is_active ? 'Active' : 'Inactive' }}</span></td>
<td>{{ $template->updated_at->toAppTz()->format('Y-m-d H:i') }}</td>
<td><a href="{{ route('settings.agreement-templates.edit', $template) }}">Edit</a>
<form class="d-inline" method="post" action="{{ route('settings.agreement-templates.'.($template->is_active ? 'deactivate' : 'activate'), $template) }}">@csrf
<button class="btn btn-outline-primary">{{ $template->is_active ? 'Deactivate' : 'Activate' }}</button></form></td>
</tr>
@endforeach
</tbody></table>
@endsection
