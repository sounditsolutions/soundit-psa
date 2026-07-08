@props(['user' => null, 'name' => null, 'avatarUrl' => null, 'size' => 36, 'class' => ''])

@php
    $displayName = $user?->name ?? $name ?? '?';
    $avatarUrl = $avatarUrl ?? $user?->avatar_url;
    $initials = collect(explode(' ', $displayName))
        ->map(fn($w) => strtoupper(substr($w, 0, 1)))
        ->take(2)
        ->implode('');
    // Deterministic color from name hash
    $colors = ['#1a365d', '#2563eb', '#7c3aed', '#db2777', '#dc2626', '#ea580c', '#16a34a', '#0d9488'];
    $bgColor = $colors[abs(crc32($displayName)) % count($colors)];
    $initialsId = 'av-' . uniqid();
@endphp

@if($avatarUrl)
    <img src="{{ $avatarUrl }}"
         alt="{{ $displayName }}"
         class="rounded-circle {{ $class }}"
         style="width: {{ $size }}px; height: {{ $size }}px; object-fit: cover;"
         loading="lazy"
         onerror="this.style.display='none';document.getElementById('{{ $initialsId }}').classList.replace('d-none','d-flex');">
    <div id="{{ $initialsId }}"
         class="rounded-circle d-none align-items-center justify-content-center text-white fw-bold {{ $class }}"
         style="width: {{ $size }}px; height: {{ $size }}px; background: {{ $bgColor }}; font-size: {{ round($size * 0.35) }}px;">
        {{ $initials }}
    </div>
@else
    <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold {{ $class }}"
         style="width: {{ $size }}px; height: {{ $size }}px; background: {{ $bgColor }}; font-size: {{ round($size * 0.35) }}px;">
        {{ $initials }}
    </div>
@endif
