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
<label for="reason" class="form-label">Audit reason / verification evidence</label><textarea id="reason" name="reason" maxlength="1000" required class="form-control" aria-describedby="reason-help"></textarea>
<div id="reason-help" class="form-text">Say what you checked, for example "Called the client back on the number in their record". This is saved in the audit log.</div>
<button class="btn btn-primary mt-2" type="submit">Record staff action</button>
</form>
{{-- Card JyBDeHPz; mockup design/mockups/contact-intake/review.html --}}
<div class="card card-static shadow-sm mt-3" data-intake-actions>
<div class="card-header">What each action does</div>
<div class="card-body"><dl class="mb-0">
<dt>Mark this submission verified</dt><dd>Use when you have confirmed the sender is genuine, for example by contacting them through details already on file. Once every web form inquiry on the ticket is verified, the ticket becomes visible to AI agents, automation and the client portal, and its contract is assigned (or you're asked to choose one). Processed inquiries only.</dd>
<dt>Resolve exception to selected client</dt><dd>The sender belongs to the client whose ID you enter. The inquiry is processed again under that client. This does not verify it.</dd>
<dt>Approve prospect for exception</dt><dd>The sender is a new organization. A prospect is created when the inquiry is processed again. This does not verify it.</dd>
<dt>Quarantine pending item</dt><dd>Holds a pending inquiry so nothing is created until you resolve or approve it.</dd>
<dt>Replay</dt><dd>Processes the inquiry again, for example after a failure. This does not verify it.</dd>
</dl></div>
</div>
@if($row->state === 'processed')<form class="mt-3" method="POST" action="{{ route('contact-intake.draft', $row->id) }}">@csrf<button class="btn btn-outline-primary">Request a draft explicitly</button><p>No automatic draft, sending or verification. Draft content remains untrusted.</p></form>@endif
<h2 class="section-title mt-4">Audit</h2>
<ul>@foreach($audits as $audit)<li>{{ $audit->action }} — staff #{{ $audit->user_id }} — {{ \Illuminate\Support\Carbon::parse($audit->created_at)->toAppTz()->format('Y-m-d H:i T') }} — {{ $audit->reason }}</li>@endforeach</ul>
@endsection
