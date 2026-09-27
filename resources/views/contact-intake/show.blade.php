@extends('layouts.app')
@section('title', 'Review contact inquiry')
@section('content')
<h1 class="section-title">Review contact inquiry</h1>
<p><a href="{{ route('contact-intake.index') }}">Contact intake queue</a></p>
<p>State: <strong>{{ $row->state }}</strong>. Receipt: {{ $row->receipt }}. Reason: {{ $row->exception_reason ?? 'None' }}.</p>
<p>All visitor fields are claimed, not verified. Releasing quarantine or matching a client does not verify identity.</p>
@if($row->ticket_id)<p><a href="{{ route('tickets.show', $row->ticket_id) }}">Ticket #{{ $row->ticket_id }}</a></p>@endif
@if($related)<p>Related open tickets (not merged): @foreach($related as $id)<a href="{{ route('tickets.show', $id) }}">#{{ $id }}</a> @endforeach</p>@endif
<dl>@foreach($payload as $key => $value)<dt>{{ $key }}</dt><dd class="text-break" style="white-space: pre-wrap">{{ $value }}</dd>@endforeach</dl>
<form method="POST" action="{{ route('contact-intake.act', $row->id) }}">@csrf
<label for="action" class="form-label">Staff action</label>
<select id="action" name="action" class="form-select" required>
<option value="replay">Replay (does not verify)</option><option value="quarantine">Quarantine pending item</option><option value="resolve_client">Resolve exception to selected client</option><option value="approve_prospect">Approve prospect for exception</option><option value="verify">Mark this submission verified (audited)</option>
</select>
<label for="client_id" class="form-label">Client ID (resolve only)</label><input id="client_id" name="client_id" type="number" min="1" class="form-control">
<label for="person_id" class="form-label">Person ID (optional; must belong to selected client)</label><input id="person_id" name="person_id" type="number" min="1" class="form-control">
<label for="reason" class="form-label">Audit reason / verification evidence</label><textarea id="reason" name="reason" maxlength="1000" required class="form-control"></textarea>
<button class="btn btn-primary mt-2" type="submit">Record staff action</button>
</form>
@if($row->state === 'processed')<form class="mt-3" method="POST" action="{{ route('contact-intake.draft', $row->id) }}">@csrf<button class="btn btn-outline-primary">Request a draft explicitly</button><p>No automatic draft, sending or verification. Draft content remains untrusted.</p></form>@endif
<h2 class="section-title mt-4">Audit</h2>
<ul>@foreach($audits as $audit)<li>{{ $audit->action }} — staff #{{ $audit->user_id }} — {{ \Illuminate\Support\Carbon::parse($audit->created_at)->toAppTz()->format('Y-m-d H:i T') }} — {{ $audit->reason }}</li>@endforeach</ul>
@endsection
