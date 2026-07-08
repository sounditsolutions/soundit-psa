@php
    $stateMap = [
        'draft' => ['Draft · inactive', 'secondary', 'dash-circle'],
        'active' => ['Active', 'success', 'check-circle-fill'],
        'paused' => ['Paused', 'warning', 'pause-circle'],
        'revoked' => ['Revoked', 'danger', 'x-circle'],
    ];
    [$stateLabel, $stateVariant, $stateIcon] = $stateMap[$state] ?? $stateMap['draft'];
@endphp
<span class="badge rounded-pill bg-{{ $stateVariant }}-subtle text-{{ $stateVariant }}-emphasis border border-{{ $stateVariant }}-subtle">
    <i class="bi bi-{{ $stateIcon }} me-1"></i>{{ $stateLabel }}
</span>
