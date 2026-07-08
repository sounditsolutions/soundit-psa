@php($enabled = $enabled ?? false)
<span class="badge rounded-pill bg-{{ $enabled ? 'success' : 'secondary' }}-subtle text-{{ $enabled ? 'success' : 'secondary' }}-emphasis">{{ $enabled ? 'Enabled' : 'Disabled' }}</span>
