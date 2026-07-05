@php
    $route = $route ?? null;
    $eventTypeGroups = $eventTypeGroups ?? [];
    $routeDestinations = $routeDestinations ?? collect();
    $routeTypes = $route->event_filter['types'] ?? [];
    $existingSteps = ($route->steps ?? collect())->values();
    $stepSlotCount = max(3, $existingSteps->count());
@endphp

<div class="mb-3">
    <label for="route_label" class="form-label">Label</label>
    <input type="text" id="route_label" name="label" value="{{ old('label', $route->label ?? '') }}" class="form-control @error('label') is-invalid @enderror" required>
    @error('label')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label class="form-label">Events</label>
    @error('event_filter.types')
        <div class="text-danger small mb-2">{{ $message }}</div>
    @enderror
    <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="event_all" name="event_filter[types][]" value="all" @checked(old('event_filter.types', $routeTypes) === 'all')>
        <label class="form-check-label" for="event_all">All routable events</label>
    </div>
    @foreach($eventTypeGroups as $group => $types)
        <fieldset class="border rounded p-2 mb-2">
            <legend class="float-none w-auto px-1 fs-6 mb-1">{{ $group }}</legend>
            @foreach($types as $type)
                @php
                    $id = 'event_'.$loop->parent->index.'_'.$loop->index;
                    $selectedTypes = old('event_filter.types', $routeTypes);
                    $checked = $selectedTypes === 'all' || in_array($type['key'], (array) $selectedTypes, true);
                @endphp
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="{{ $id }}" name="event_filter[types][]" value="{{ $type['key'] }}" @checked($checked)>
                    <label class="form-check-label" for="{{ $id }}">
                        <code>{{ $type['key'] }}</code>
                        <span class="text-muted small d-block">{{ $type['label'] }}</span>
                    </label>
                </div>
            @endforeach
        </fieldset>
    @endforeach
</div>

<div class="row g-2">
    <div class="col-md-6">
        <label for="route_categories" class="form-label">Categories</label>
        <input type="text" id="route_categories" name="event_filter[categories]" value="{{ old('event_filter.categories', implode(', ', (array) ($route->event_filter['categories'] ?? []))) }}" class="form-control">
    </div>
    <div class="col-md-6">
        <label for="route_clients" class="form-label">Client IDs</label>
        <input type="text" id="route_clients" name="event_filter[client_ids]" value="{{ old('event_filter.client_ids', implode(', ', (array) ($route->event_filter['client_ids'] ?? []))) }}" class="form-control">
    </div>
    <div class="col-md-6">
        <label for="route_priority" class="form-label">Minimum Priority</label>
        <select id="route_priority" name="event_filter[min_priority]" class="form-select">
            <option value="">Any</option>
            @foreach([1, 2, 3, 4, 5] as $priority)
                <option value="{{ $priority }}" @selected((string) old('event_filter.min_priority', $route->event_filter['min_priority'] ?? '') === (string) $priority)>P{{ $priority }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label for="route_cooldown" class="form-label">Cooldown Seconds</label>
        <input type="number" id="route_cooldown" name="cooldown_seconds" value="{{ old('cooldown_seconds', $route->cooldown_seconds ?? 300) }}" class="form-control" min="0">
    </div>
</div>

<div class="mt-3">
    <label class="form-label">Steps</label>
    @error('steps')
        <div class="text-danger small mb-2">{{ $message }}</div>
    @enderror
    @for($i = 0; $i < $stepSlotCount; $i++)
        @php
            $existingStep = $existingSteps[$i] ?? null;
            $prevStep = $existingSteps[$i - 1] ?? null;
            $simultaneousDefault = $existingStep && $prevStep && $existingStep->step_order === $prevStep->step_order;
        @endphp
        <div class="border rounded p-2 mb-2">
            <div class="row g-2">
                <div class="col-md-12">
                    <select name="steps[{{ $i }}][destination_id]" class="form-select @error('steps.'.$i.'.destination_id') is-invalid @enderror">
                        <option value="">Destination</option>
                        @foreach($routeDestinations as $destination)
                            <option value="{{ $destination->id }}" @selected((string) old('steps.'.$i.'.destination_id', $existingStep?->destination_id) === (string) $destination->id)>{{ $destination->label }}</option>
                        @endforeach
                    </select>
                    @error('steps.'.$i.'.destination_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-6">
                    <input type="number" name="steps[{{ $i }}][wait_for_ack_seconds]" value="{{ old('steps.'.$i.'.wait_for_ack_seconds', $existingStep?->wait_for_ack_seconds) }}" class="form-control" min="0" placeholder="Ack seconds">
                </div>
                <div class="col-md-6">
                    <input type="number" name="steps[{{ $i }}][resolve_within_seconds]" value="{{ old('steps.'.$i.'.resolve_within_seconds', $existingStep?->resolve_within_seconds) }}" class="form-control" min="0" placeholder="Resolve seconds">
                </div>
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="step_{{ $i }}_simultaneous" name="steps[{{ $i }}][simultaneous]" value="1" @checked(old('steps.'.$i.'.simultaneous', $simultaneousDefault))>
                        <label class="form-check-label" for="step_{{ $i }}_simultaneous">Simultaneous</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="step_{{ $i }}_non_suppressible" name="steps[{{ $i }}][non_suppressible]" value="1" @checked(old('steps.'.$i.'.non_suppressible', $existingStep?->non_suppressible ?? false))>
                        <label class="form-check-label" for="step_{{ $i }}_non_suppressible">Non-suppressible</label>
                    </div>
                </div>
            </div>
        </div>
    @endfor
</div>
