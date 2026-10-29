{{-- Source link-state badge: colour always paired with text (D-15). --}}
@switch($state)
    @case('linked')<span class="badge bg-success" data-state-badge="linked">LINKED</span>@break
    @case('off')<span class="badge bg-secondary" data-state-badge="off" title="Switched off in Settings">OFF</span>@break
    @case('not_configured')<span class="badge border text-secondary bg-white" data-state-badge="not_configured">NOT CONFIGURED</span>@break
    @default<span class="badge border text-secondary bg-white" data-state-badge="not_linked">NOT LINKED</span>
@endswitch
