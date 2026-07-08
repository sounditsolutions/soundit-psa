@props(['person' => null, 'size' => 20, 'link' => true, 'popover' => true, 'fallback' => '—'])

@if($person)
    @php
        $extraEmails = $person->relationLoaded('additionalEmailAddresses')
            ? $person->additionalEmailAddresses->pluck('email')->implode(', ')
            : '';
        $popoverHtml = $popover ? '<strong>' . e($person->fullName) . '</strong>'
            . ($person->relationLoaded('client') && $person->client ? '<br><small class="text-muted">Client:</small> ' . e($person->client->name) : '')
            . ($person->email ? '<br><small class="text-muted">Email:</small> ' . e($person->email) : '')
            . ($extraEmails ? '<br><small class="text-muted">Also:</small> ' . e($extraEmails) : '')
            . ($person->mobile_display ? '<br><small class="text-muted">Mobile:</small> ' . e($person->mobile_display) : ($person->phone_display ? '<br><small class="text-muted">Phone:</small> ' . e($person->phone_display) : ''))
            . ($person->job_title ? '<br><small class="text-muted">Title:</small> ' . e($person->job_title) : '')
            : '';
    @endphp
    <div class="d-inline-flex align-items-center gap-1"
        @if($popover && $popoverHtml)
            data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-html="true"
            data-bs-placement="auto" data-bs-delay='{"show":300,"hide":200}'
            data-bs-content="{{ $popoverHtml }}"
        @endif
    >
        <x-avatar :avatarUrl="$person->avatar_url" :name="$person->fullName" :size="$size" />
        @if($link)
            <a href="{{ route('people.show', $person) }}" class="text-decoration-none text-truncate" style="max-width: 200px" {{ $attributes }}>{{ $person->fullName }}</a>
        @else
            <span class="text-truncate" style="max-width: 200px" {{ $attributes }}>{{ $person->fullName }}</span>
        @endif
    </div>
@else
    <span class="text-muted">{{ $fallback }}</span>
@endif
