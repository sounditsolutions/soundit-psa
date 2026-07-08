@props(['user' => null, 'size' => 20, 'popover' => true, 'fallback' => '—'])

@if($user)
    @php
        $popoverHtml = $popover ? '<strong>' . e($user->name) . '</strong>'
            . ($user->email ? '<br><small class="text-muted">Email:</small> ' . e($user->email) : '')
            : '';
    @endphp
    <div class="d-inline-flex align-items-center gap-1" tabindex="0"
        @if($popover && $popoverHtml)
            data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-html="true"
            data-bs-placement="auto" data-bs-delay='{"show":300,"hide":200}'
            data-bs-content="{{ $popoverHtml }}"
        @endif
    >
        <x-avatar :user="$user" :size="$size" />
        <span class="text-truncate" style="max-width: 200px" {{ $attributes }}>{{ $user->name }}</span>
    </div>
@else
    <span class="text-muted">{{ $fallback }}</span>
@endif
