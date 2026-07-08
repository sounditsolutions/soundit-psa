@props(['client' => null, 'size' => 20, 'link' => true, 'popover' => true, 'fallback' => '—'])

@if($client)
    @php
        $popoverHtml = $popover ? '<strong>' . e($client->name) . '</strong>'
            . ($client->phone_display ? '<br><small class="text-muted">Phone:</small> ' . e($client->phone_display) : '')
            . ($client->email ? '<br><small class="text-muted">Email:</small> ' . e($client->email) : '')
            : '';
    @endphp
    <div class="d-inline-flex align-items-center gap-1"
        @if($popover && $popoverHtml)
            data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-html="true"
            data-bs-placement="auto" data-bs-delay='{"show":300,"hide":200}'
            data-bs-content="{{ $popoverHtml }}"
        @endif
    >
        <x-avatar :avatarUrl="$client->logo_url" :name="$client->name" :size="$size" />
        @if($link)
            <a href="{{ route('clients.show', $client) }}" class="text-decoration-none text-truncate" style="max-width: 200px" {{ $attributes }}>{{ $client->name }}</a>
        @else
            <span class="text-truncate" style="max-width: 200px" {{ $attributes }}>{{ $client->name }}</span>
        @endif
    </div>
@else
    <span class="text-muted">{{ $fallback }}</span>
@endif
