{{-- Reusable alert list partial
     Variables:
       $alerts — collection of Alert models (with asset, client, ticket loaded)
       $showBulkActions — bool (default true) — show checkboxes and bulk action bar
       $showFilters — bool (default false) — show filter row
       $showPagination — bool (default true) — show pagination links
       $compact — bool (default false) — hide some columns for embedding
--}}
@php
    $showBulkActions = $showBulkActions ?? true;
    $showFilters = $showFilters ?? false;
    $showPagination = $showPagination ?? true;
    $compact = $compact ?? false;
@endphp

@if($alerts->isEmpty())
    <div class="text-center py-4 text-muted">
        <i class="bi bi-bell-slash" style="font-size: 2rem;"></i>
        <p class="mt-2 mb-0 small">No alerts found.</p>
    </div>
@else
    {{-- Desktop: the full table (md+). Below md it is replaced by the stacked
         rows beneath this block so the client, status, and response actions stay
         visible without a horizontal scroll (psa-dxie). --}}
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover mb-0">
            <thead class="thead-brand">
                <tr>
                    @if($showBulkActions)
                        <th style="width: 30px;"><input type="checkbox" class="form-check-input" id="selectAll"></th>
                    @endif
                    <th style="width: 90px;">Severity</th>
                    @if(!$compact)
                        <th style="width: 110px;">Source</th>
                    @endif
                    <th>Device / Title</th>
                    @if(!$compact)
                        <th>Client</th>
                    @endif
                    <th style="width: 120px;">Fired</th>
                    <th style="width: 110px;">Status</th>
                    <th style="width: 160px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($alerts as $alert)
                    @php
                        $severityBorder = match($alert->severity) {
                            \App\Enums\AlertSeverity::Critical => '#dc3545',
                            \App\Enums\AlertSeverity::Error => '#fd7e14',
                            \App\Enums\AlertSeverity::Warning => '#ffc107',
                            \App\Enums\AlertSeverity::Info => '#0dcaf0',
                        };
                    @endphp
                    <tr style="border-left: 3px solid {{ $severityBorder }};">
                        @if($showBulkActions)
                            <td onclick="event.stopPropagation()">
                                <input type="checkbox" class="form-check-input alert-checkbox" value="{{ $alert->id }}">
                            </td>
                        @endif
                        <td>
                            <span class="badge {{ $alert->severity->badgeClass() }}">{{ $alert->severity->label() }}</span>
                        </td>
                        @if(!$compact)
                            <td class="small">
                                @if($alert->sourceUrl())
                                    <a href="{{ $alert->sourceUrl() }}" target="_blank" rel="noopener" class="text-decoration-none" title="View in {{ $alert->source->label() }}">
                                        <i class="bi {{ $alert->source->icon() }} me-1"></i>{{ $alert->source->label() }}
                                        <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 0.7em;"></i>
                                    </a>
                                @else
                                    <i class="bi {{ $alert->source->icon() }} me-1"></i>{{ $alert->source->label() }}
                                @endif
                            </td>
                        @endif
                        <td>
                            @if($alert->asset)
                                <a href="{{ route('assets.show', $alert->asset) }}" class="text-decoration-none fw-semibold me-1">
                                    {{ $alert->hostname ?? $alert->asset->hostname ?? '-' }}
                                </a>
                            @elseif($alert->hostname)
                                <span class="fw-semibold me-1 text-muted">{{ $alert->hostname }}</span>
                            @endif
                            <br>
                            <a href="#" class="small text-muted text-decoration-none alert-detail-link"
                               data-alert-title="{{ e($alert->title) }}"
                               data-alert-message="{{ e($alert->message) }}"
                               data-alert-severity="{{ $alert->severity->label() }}"
                               data-alert-source="{{ $alert->source->label() }}"
                               data-alert-hostname="{{ $alert->hostname ?? $alert->asset?->hostname ?? '-' }}"
                               data-alert-client="{{ $alert->client?->name ?? '-' }}"
                               data-alert-status="{{ $alert->status->label() }}"
                               data-alert-fired="{{ $alert->fired_at?->toAppTz()->format('M j, Y g:ia T') ?? '-' }}"
                               data-alert-refired="{{ $alert->refired_count }}"
                               data-alert-acknowledged="{{ $alert->acknowledged_at?->toAppTz()->format('M j, Y g:ia T') ?? '' }}"
                               data-alert-acknowledged-by="{{ $alert->acknowledgedByUser?->name ?? '' }}"
                               data-alert-resolved="{{ $alert->resolved_at?->toAppTz()->format('M j, Y g:ia T') ?? '' }}"
                               data-alert-source-url="{{ $alert->sourceUrl() ?? '' }}"
                               data-alert-metadata="{{ e(json_encode($alert->metadata)) }}"
                               title="Click for details">{{ Str::limit($alert->title, 70) }}</a>
                            @if($alert->refired_count > 0)
                                <span class="badge bg-secondary ms-1" title="Re-fired {{ $alert->refired_count }} time(s)">
                                    +{{ $alert->refired_count }}
                                </span>
                            @endif
                        </td>
                        @if(!$compact)
                            <td class="small">
                                @if($alert->client)
                                    <x-client-badge :client="$alert->client" fallback="-" />
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        @endif
                        <td class="small" title="{{ $alert->fired_at?->toAppTz()->format('Y-m-d H:i T') }}">
                            {{ $alert->fired_at?->diffForHumans() ?? '-' }}
                        </td>
                        <td>
                            <span class="badge {{ $alert->status->badgeClass() }}">{{ $alert->status->label() }}</span>
                            @if($alert->ticket)
                                <a href="{{ route('tickets.show', $alert->ticket) }}" class="ms-1 small text-decoration-none" title="Linked ticket">
                                    <i class="bi bi-ticket-perforated"></i>#{{ $alert->ticket->display_id ?? $alert->ticket->id }}
                                </a>
                            @endif
                        </td>
                        <td onclick="event.stopPropagation()" class="text-nowrap">
                            @if($alert->status === \App\Enums\AlertStatus::Active)
                                <form method="POST" action="{{ route('alerts.acknowledge', $alert) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-secondary btn-sm" title="Acknowledge">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                            @endif
                            @if(!$alert->ticket_id)
                                <form method="POST" action="{{ route('alerts.create-ticket', $alert) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-primary btn-sm" title="Create New Ticket">
                                        <i class="bi bi-ticket-perforated"></i>
                                    </button>
                                </form>
                                <div class="d-inline-block dropdown">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="dropdown" title="Attach to Existing Ticket">
                                        <i class="bi bi-link-45deg"></i>
                                    </button>
                                    <div class="dropdown-menu p-2" style="min-width: 200px;" onclick="event.stopPropagation()">
                                        <form method="POST" action="{{ route('alerts.attach-ticket', $alert) }}" class="d-flex gap-1">
                                            @csrf
                                            <input type="number" name="ticket_id" class="form-control form-control-sm" placeholder="Ticket #" required style="width: 100px;">
                                            <button type="submit" class="btn btn-primary btn-sm">Attach</button>
                                        </form>
                                    </div>
                                </div>
                            @endif
                            @if($alert->status !== \App\Enums\AlertStatus::Resolved)
                                <form method="POST" action="{{ route('alerts.resolve', $alert) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-success btn-sm" title="Resolve"
                                            onclick="return confirm('Resolve this alert?')">
                                        <i class="bi bi-check-circle"></i>
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Mobile: stacked rows below md. Surfaces the operational signal — client,
         status, fired, and the response controls — that the desktop table pushes
         off-screen on a phone (psa-dxie). Severity and status read as badges, not
         the color-only row stripe (DESIGN.md §5: state is never color alone). --}}
    <div class="d-md-none">
        @foreach($alerts as $alert)
            <div class="data-row">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                    <span class="badge {{ $alert->severity->badgeClass() }}">{{ $alert->severity->label() }}</span>
                    <span class="badge {{ $alert->status->badgeClass() }}">{{ $alert->status->label() }}</span>
                </div>
                <div class="mb-1">
                    @if($alert->asset)
                        <a href="{{ route('assets.show', $alert->asset) }}" class="fw-semibold text-decoration-none me-1">
                            {{ $alert->hostname ?? $alert->asset->hostname ?? '-' }}
                        </a>
                    @elseif($alert->hostname)
                        <span class="fw-semibold me-1 text-muted">{{ $alert->hostname }}</span>
                    @endif
                    <a href="#" class="d-block small text-decoration-none alert-detail-link"
                       data-alert-title="{{ e($alert->title) }}"
                       data-alert-message="{{ e($alert->message) }}"
                       data-alert-severity="{{ $alert->severity->label() }}"
                       data-alert-source="{{ $alert->source->label() }}"
                       data-alert-hostname="{{ $alert->hostname ?? $alert->asset?->hostname ?? '-' }}"
                       data-alert-client="{{ $alert->client?->name ?? '-' }}"
                       data-alert-status="{{ $alert->status->label() }}"
                       data-alert-fired="{{ $alert->fired_at?->toAppTz()->format('M j, Y g:ia T') ?? '-' }}"
                       data-alert-refired="{{ $alert->refired_count }}"
                       data-alert-acknowledged="{{ $alert->acknowledged_at?->toAppTz()->format('M j, Y g:ia T') ?? '' }}"
                       data-alert-acknowledged-by="{{ $alert->acknowledgedByUser?->name ?? '' }}"
                       data-alert-resolved="{{ $alert->resolved_at?->toAppTz()->format('M j, Y g:ia T') ?? '' }}"
                       data-alert-source-url="{{ $alert->sourceUrl() ?? '' }}"
                       data-alert-metadata="{{ e(json_encode($alert->metadata)) }}"
                       title="Click for details">{{ Str::limit($alert->title, 80) }}</a>
                    @if($alert->refired_count > 0)
                        <span class="badge bg-secondary" title="Re-fired {{ $alert->refired_count }} time(s)">+{{ $alert->refired_count }}</span>
                    @endif
                </div>
                <div class="d-flex justify-content-between gap-3 small py-1">
                    <span class="data-label">Source</span>
                    <span class="text-end">
                        @if($alert->sourceUrl())
                            <a href="{{ $alert->sourceUrl() }}" target="_blank" rel="noopener" class="text-decoration-none" title="View in {{ $alert->source->label() }}">
                                <i class="bi {{ $alert->source->icon() }} me-1"></i>{{ $alert->source->label() }}
                                <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 0.7em;"></i>
                            </a>
                        @else
                            <i class="bi {{ $alert->source->icon() }} me-1"></i>{{ $alert->source->label() }}
                        @endif
                    </span>
                </div>
                <div class="d-flex justify-content-between gap-3 small py-1">
                    <span class="data-label">Client</span>
                    <span class="text-end">
                        @if($alert->client)
                            <x-client-badge :client="$alert->client" fallback="-" />
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </span>
                </div>
                <div class="d-flex justify-content-between gap-3 small py-1">
                    <span class="data-label">Fired</span>
                    <span class="text-end" title="{{ $alert->fired_at?->toAppTz()->format('Y-m-d H:i T') }}">
                        {{ $alert->fired_at?->diffForHumans() ?? '-' }}
                    </span>
                </div>
                @if($alert->ticket)
                    <div class="d-flex justify-content-between gap-3 small py-1">
                        <span class="data-label">Ticket</span>
                        <a href="{{ route('tickets.show', $alert->ticket) }}" class="text-decoration-none text-end" title="Linked ticket">
                            <i class="bi bi-ticket-perforated me-1"></i>#{{ $alert->ticket->display_id ?? $alert->ticket->id }}
                        </a>
                    </div>
                @endif
                <div class="d-flex flex-wrap gap-1 pt-2">
                    @if($alert->status === \App\Enums\AlertStatus::Active)
                        <form method="POST" action="{{ route('alerts.acknowledge', $alert) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary btn-sm" title="Acknowledge">
                                <i class="bi bi-check-lg me-1"></i>Acknowledge
                            </button>
                        </form>
                    @endif
                    @if(!$alert->ticket_id)
                        <form method="POST" action="{{ route('alerts.create-ticket', $alert) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-outline-primary btn-sm" title="Create New Ticket">
                                <i class="bi bi-ticket-perforated me-1"></i>Ticket
                            </button>
                        </form>
                        <div class="d-inline-block dropdown">
                            <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="dropdown" title="Attach to Existing Ticket">
                                <i class="bi bi-link-45deg me-1"></i>Attach
                            </button>
                            <div class="dropdown-menu p-2" style="min-width: 200px;">
                                <form method="POST" action="{{ route('alerts.attach-ticket', $alert) }}" class="d-flex gap-1">
                                    @csrf
                                    <input type="number" name="ticket_id" class="form-control form-control-sm" placeholder="Ticket #" required style="width: 100px;">
                                    <button type="submit" class="btn btn-primary btn-sm">Attach</button>
                                </form>
                            </div>
                        </div>
                    @endif
                    @if($alert->status !== \App\Enums\AlertStatus::Resolved)
                        <form method="POST" action="{{ route('alerts.resolve', $alert) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-outline-success btn-sm" title="Resolve"
                                    onclick="return confirm('Resolve this alert?')">
                                <i class="bi bi-check-circle me-1"></i>Resolve
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @if($showPagination && method_exists($alerts, 'links'))
        <div class="mt-3">
            {{ $alerts->links() }}
        </div>
    @endif

    @include('alerts._detail_modal')
@endif
