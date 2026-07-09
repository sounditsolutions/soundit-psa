{{-- Reusable people list partial.
     Expects: $people, $clients, $search, $clientId, $personType
     Optional: $listRoute (string, default 'people.index'), $prefilter (array, default [])
               $columns (array, default null = all columns), $showFilters (bool, default true),
               $showBulkActions (bool, default true)
     Column keys: checkbox, name, email, phone, mobile, client
--}}
@php
    $listRoute = $listRoute ?? 'people.index';
    $prefilter = $prefilter ?? [];
    $showFilters = $showFilters ?? true;
    $showBulkActions = $showBulkActions ?? true;
    $columns = $columns ?? null; // null = show all columns
@endphp

@if($showFilters)
<form method="GET" action="{{ route($listRoute, $prefilter) }}" class="mb-3">
    <div class="row g-2">
        <div class="col">
            <div class="input-group">
                <input type="text" name="search" class="form-control" placeholder="Search by name, email, or phone..."
                       value="{{ $search }}" autofocus>
                <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                @if($search || $clientId || $personType)
                    <a href="{{ route($listRoute, $prefilter) }}" class="btn btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
                @endif
            </div>
        </div>
        @unless(isset($prefilter['client_id']))
        <div class="col-auto" style="min-width: 200px;">
            <select name="client_id" class="form-select" onchange="this.form.submit()">
                <option value="">All clients</option>
                @foreach($clients as $c)
                    <option value="{{ $c->id }}" {{ $clientId == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        @endunless
        <div class="col-auto" style="min-width: 160px;">
            <select name="person_type" class="form-select" onchange="this.form.submit()">
                <option value="">All types</option>
                @foreach(\App\Enums\PersonType::cases() as $type)
                    <option value="{{ $type->value }}" {{ $personType === $type->value ? 'selected' : '' }}>{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>
    </div>
</form>
@endif

@if($people->isEmpty())
    <div class="alert alert-info">
        @if($search || $clientId || $personType)
            No contacts match your filters.
        @else
            No contacts found.
        @endif
    </div>
@else
    @if($showBulkActions)
    <form method="POST" action="{{ route('people.bulk-type') }}" id="bulkTypeForm">
        @csrf
    @endif
        <div class="card shadow-sm card-static">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-brand">
                        <tr>
                            @if($showBulkActions && (!$columns || in_array('checkbox', $columns)))
                            <th style="width: 36px;">
                                <input class="form-check-input" type="checkbox" id="selectAll" title="Select all">
                            </th>
                            @endif
                            @if(!$columns || in_array('name', $columns))
                            <th>Name</th>
                            @endif
                            @if(!$columns || in_array('email', $columns))
                            <th>Email</th>
                            @endif
                            @if(!$columns || in_array('phone', $columns))
                            <th>Phone</th>
                            @endif
                            @if(!$columns || in_array('mobile', $columns))
                            <th>Mobile</th>
                            @endif
                            @if((!$columns || in_array('client', $columns)) && !isset($prefilter['client_id']))
                            <th>Client</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($people as $person)
                            <tr>
                                @if($showBulkActions && (!$columns || in_array('checkbox', $columns)))
                                <td>
                                    <input class="form-check-input bulk-check" type="checkbox" name="person_ids[]" value="{{ $person->id }}">
                                </td>
                                @endif
                                @if(!$columns || in_array('name', $columns))
                                <td>
                                    <x-person-badge :person="$person" :size="24" />
                                    @if($person->is_primary)
                                        <span class="badge bg-warning text-dark ms-1">Primary</span>
                                    @endif
                                    @if($person->person_type !== \App\Enums\PersonType::User)
                                        <span class="badge bg-secondary ms-1" title="{{ $person->person_type->label() }}">
                                            <i class="{{ $person->person_type->icon() }} me-1"></i>{{ $person->person_type->label() }}
                                        </span>
                                    @endif
                                </td>
                                @endif
                                @if(!$columns || in_array('email', $columns))
                                <td>
                                    @if($person->email)
                                        <a href="mailto:{{ $person->email }}">{{ $person->email }}</a>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                @endif
                                @if(!$columns || in_array('phone', $columns))
                                <td>
                                    @if($person->phone_display)
                                        <a href="#" data-phone="{{ $person->phone }}" class="text-decoration-none dial-link">
                                            {{ $person->phone_display }}
                                        </a>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                @endif
                                @if(!$columns || in_array('mobile', $columns))
                                <td>
                                    @if($person->mobile_display)
                                        <a href="#" data-phone="{{ $person->mobile }}" class="text-decoration-none dial-link">
                                            {{ $person->mobile_display }}
                                        </a>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                @endif
                                @if((!$columns || in_array('client', $columns)) && !isset($prefilter['client_id']))
                                <td><x-client-badge :client="$person->client" fallback="-" /></td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if($showBulkActions)
        {{-- Bulk action bar --}}
        <div class="card shadow-sm mt-2 d-none" id="bulkBar">
            <div class="card-body py-2 d-flex align-items-center gap-2">
                <span class="text-muted small"><span id="selectedCount">0</span> selected</span>
                <select name="person_type" class="form-select form-select-sm" style="width: auto;" id="bulkTypeSelect">
                    @foreach(\App\Enums\PersonType::cases() as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-check-lg me-1"></i>Set Type
                </button>
            </div>
        </div>
    </form>
    @endif

    <div class="mt-3">
        {{ $people->links() }}
    </div>
@endif

<script>
@if($showBulkActions)
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAll');
    const checks = document.querySelectorAll('.bulk-check');
    const bulkBar = document.getElementById('bulkBar');
    const countEl = document.getElementById('selectedCount');

    function updateBar() {
        const checked = document.querySelectorAll('.bulk-check:checked').length;
        countEl.textContent = checked;
        bulkBar.classList.toggle('d-none', checked === 0);
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checks.forEach(c => c.checked = this.checked);
            updateBar();
        });
    }

    checks.forEach(c => c.addEventListener('change', updateBar));
});
@endif
</script>
