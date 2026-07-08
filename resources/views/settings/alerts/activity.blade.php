<div class="row g-4">
    <div class="col-xl-8">
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-list-check me-2"></i>Recent Deliveries
            </div>

            @if($recentDeliveries->isEmpty())
                <div class="card-body">
                    <div class="text-muted">No deliveries yet.</div>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="thead-brand">
                            <tr>
                                <th>When</th>
                                <th>Destination</th>
                                <th>Event</th>
                                <th>Status</th>
                                <th>Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentDeliveries as $delivery)
                                <tr>
                                    <td class="small text-muted">{{ $delivery->created_at?->format('Y-m-d H:i') }}</td>
                                    <td>{{ $delivery->destination?->label ?? 'Destination #'.$delivery->destination_id }}</td>
                                    <td><code>{{ $delivery->event?->type_key ?? 'event #'.$delivery->event_id }}</code></td>
                                    <td><span class="badge bg-light text-dark">{{ $delivery->status }}</span></td>
                                    <td class="small text-danger">{{ $delivery->error }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-clock-history me-2"></i>Config Changes
            </div>

            @if($recentConfigLogs->isEmpty())
                <div class="card-body">
                    <div class="text-muted">No config changes yet.</div>
                </div>
            @else
                <div class="list-group list-group-flush">
                    @foreach($recentConfigLogs as $log)
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="fw-semibold">{{ $log->action }}</span>
                                <span class="text-muted small">{{ $log->created_at?->format('Y-m-d H:i') }}</span>
                            </div>
                            <div class="text-muted small">
                                {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}
                            </div>
                            <code class="small d-block text-wrap">{{ json_encode($log->changes, JSON_UNESCAPED_SLASHES) }}</code>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
