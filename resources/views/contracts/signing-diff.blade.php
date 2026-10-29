@extends('layouts.app')
@section('title', 'Accepted proposal differs')
@section('content')
<h1>Accepted proposal differs</h1>
<p>Proposal v{{ $diff->proposal->version }} accepted by {{ $diff->acceptance->typed_name }} at {{ $diff->acceptance->signed_at->toAppTz()->format('Y-m-d H:i') }}.</p>
<p>Generate a new proposal and obtain fresh acceptance before generating this agreement.</p>
<table class="table"><thead><tr><th>Line</th><th>Field</th><th>Accepted</th><th>Current</th></tr></thead><tbody>
@foreach($diff->rows as $row)
<tr><td>{{ $row['line'] }}</td><td>{{ \App\Services\Signing\AcceptanceDiff::displayField($row['field']) }}</td><td>{{ \App\Services\Signing\AcceptanceDiff::displayValue($row['field'], $row['accepted']) }}</td><td>{{ \App\Services\Signing\AcceptanceDiff::displayValue($row['field'], $row['current']) }}</td></tr>
@endforeach
</tbody></table>
@if($proposalTemplate)
<form method="post" action="{{ route('signing.generate', $contract) }}">@csrf
<input type="hidden" name="template_id" value="{{ $proposalTemplate->id }}">
<input type="hidden" name="recipient_name" value="{{ $recipient['recipient_name'] }}">
<input type="hidden" name="recipient_email" value="{{ $recipient['recipient_email'] }}">
<button class="btn btn-accent">Generate new proposal</button>
</form>
@else
<p>No active proposal template is available for this contract type.</p>
@endif
<a class="btn btn-outline-primary" href="{{ route('contracts.show', $contract) }}">Back to contract</a>
@endsection
