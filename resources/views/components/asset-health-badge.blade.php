@props(['asset', 'showLabel' => false])
{{-- Compact cached-health-score pill. Score is null for assets with no
     monitoring signals we can read (grades Unknown → "N/A"). --}}
@php
    $grade = $asset->health_grade instanceof \App\Enums\AssetHealthGrade
        ? $asset->health_grade
        : \App\Enums\AssetHealthGrade::fromScore($asset->health_score);
    $score = $asset->health_score;
@endphp
@if($score !== null)
    <span {{ $attributes->merge(['class' => 'badge '.$grade->badgeClass()]) }}
          title="Health score: {{ $score }}/100 ({{ $grade->label() }})">
        <i class="bi {{ $grade->icon() }} me-1"></i>{{ $score }}@if($showLabel) · {{ $grade->label() }}@endif
    </span>
@else
    <span {{ $attributes->merge(['class' => 'badge bg-secondary']) }}
          title="Not enough monitoring data to score this device">
        <i class="bi bi-question-circle-fill me-1"></i>N/A
    </span>
@endif
