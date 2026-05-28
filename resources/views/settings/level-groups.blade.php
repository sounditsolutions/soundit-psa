@extends('layouts.app')

@section('title', 'Level RMM Group Mapping')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="section-title mb-0">Level RMM Group Mapping</h2>
            <a href="{{ route('settings.integrations') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to Integrations
            </a>
        </div>

        <p class="text-muted mb-3">
            Map Level RMM groups to local clients. This enables device sync for each mapped client.
            Uses <code>ancestor_group_id</code> filtering, so devices in subgroups are included automatically.
        </p>

        <form method="POST" action="{{ route('settings.level-groups.update') }}">
            @csrf

            <div class="card card-static shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Level Group</th>
                                <th>Mapped Client</th>
                                <th class="text-center" style="width: 100px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($groups as $group)
                            @php
                                $groupId = $group['id'];
                                $mapped = $mappedClients->get($groupId);
                                $deviceCount = $group['device_count'] ?? 0;
                                $descendentCount = $group['descendent_device_count'] ?? 0;
                                $totalDevices = $deviceCount + $descendentCount;
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $group['name'] ?? 'Unknown' }}</strong>
                                    <br><small class="text-muted">
                                        {{ $totalDevices }} device{{ $totalDevices !== 1 ? 's' : '' }}
                                        @if($descendentCount > 0)
                                            ({{ $deviceCount }} direct + {{ $descendentCount }} in subgroups)
                                        @endif
                                    </small>
                                </td>
                                <td>
                                    <div class="client-search-wrapper" data-group-id="{{ $groupId }}">
                                        <input type="hidden"
                                               name="mappings[{{ $groupId }}]"
                                               value="{{ $mapped?->id }}"
                                               class="client-id-input">
                                        <input type="text"
                                               class="form-control form-control-sm client-search"
                                               value="{{ $mapped?->name }}"
                                               placeholder="Search client..."
                                               autocomplete="off">
                                        <div class="client-search-results dropdown-menu" style="display:none; position:absolute; z-index:1000;"></div>
                                        @if($mapped)
                                        <button type="button" class="btn btn-link btn-sm text-danger p-0 mt-1 clear-mapping">
                                            <i class="bi bi-x-circle"></i> Clear
                                        </button>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-center">
                                    @if($mapped)
                                        <span class="badge bg-success">Mapped</span>
                                    @else
                                        <span class="badge bg-secondary">Unmapped</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Save Mappings
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.client-search').forEach(input => {
    const wrapper = input.closest('.client-search-wrapper');
    const hiddenInput = wrapper.querySelector('.client-id-input');
    const resultsDiv = wrapper.querySelector('.client-search-results');
    let debounce = null;

    input.addEventListener('input', function() {
        clearTimeout(debounce);
        const q = this.value.trim();
        if (q.length < 2) {
            resultsDiv.style.display = 'none';
            return;
        }
        debounce = setTimeout(() => {
            fetch('{{ route("api.clients.search") }}?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(clients => {
                    if (clients.length === 0) {
                        resultsDiv.innerHTML = '<div class="dropdown-item text-muted">No results</div>';
                    } else {
                        resultsDiv.innerHTML = clients.map(c =>
                            '<button type="button" class="dropdown-item" data-id="' + c.id + '">' + c.name + '</button>'
                        ).join('');
                    }
                    resultsDiv.style.display = 'block';
                });
        }, 300);
    });

    resultsDiv.addEventListener('click', function(e) {
        const item = e.target.closest('[data-id]');
        if (item) {
            hiddenInput.value = item.dataset.id;
            input.value = item.textContent;
            resultsDiv.style.display = 'none';
        }
    });

    input.addEventListener('blur', function() {
        setTimeout(() => { resultsDiv.style.display = 'none'; }, 200);
    });

    const clearBtn = wrapper.querySelector('.clear-mapping');
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            hiddenInput.value = '';
            input.value = '';
            this.remove();
        });
    }
});
</script>
@endpush
