<div class="row g-4">
    <div class="col-xl-4">
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-plus-circle me-2"></i>Create Route
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.alerts.routes.store') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="route_label" class="form-label">Label</label>
                        <input type="text" id="route_label" name="label" value="{{ old('label') }}" class="form-control @error('label') is-invalid @enderror" required>
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
                            <input class="form-check-input" type="checkbox" id="event_all" name="event_filter[types][]" value="all">
                            <label class="form-check-label" for="event_all">All routable events</label>
                        </div>
                        @foreach($eventTypeGroups as $group => $types)
                            <fieldset class="border rounded p-2 mb-2">
                                <legend class="float-none w-auto px-1 fs-6 mb-1">{{ $group }}</legend>
                                @foreach($types as $type)
                                    @php $id = 'event_'.$loop->parent->index.'_'.$loop->index; @endphp
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="{{ $id }}" name="event_filter[types][]" value="{{ $type['key'] }}" @checked(in_array($type['key'], old('event_filter.types', []), true))>
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
                            <input type="text" id="route_categories" name="event_filter[categories]" value="{{ old('event_filter.categories') }}" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label for="route_clients" class="form-label">Client IDs</label>
                            <input type="text" id="route_clients" name="event_filter[client_ids]" value="{{ old('event_filter.client_ids') }}" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label for="route_priority" class="form-label">Minimum Priority</label>
                            <select id="route_priority" name="event_filter[min_priority]" class="form-select">
                                <option value="">Any</option>
                                @foreach([1, 2, 3, 4, 5] as $priority)
                                    <option value="{{ $priority }}" @selected((string) old('event_filter.min_priority') === (string) $priority)>P{{ $priority }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="route_cooldown" class="form-label">Cooldown Seconds</label>
                            <input type="number" id="route_cooldown" name="cooldown_seconds" value="{{ old('cooldown_seconds', 300) }}" class="form-control" min="0">
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">Steps</label>
                        @error('steps')
                            <div class="text-danger small mb-2">{{ $message }}</div>
                        @enderror
                        @for($i = 0; $i < 3; $i++)
                            <div class="border rounded p-2 mb-2">
                                <div class="row g-2">
                                    <div class="col-md-12">
                                        <select name="steps[{{ $i }}][destination_id]" class="form-select @error('steps.'.$i.'.destination_id') is-invalid @enderror">
                                            <option value="">Destination</option>
                                            @foreach($routeDestinations as $destination)
                                                <option value="{{ $destination->id }}" @selected((string) old('steps.'.$i.'.destination_id') === (string) $destination->id)>{{ $destination->label }}</option>
                                            @endforeach
                                        </select>
                                        @error('steps.'.$i.'.destination_id')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-6">
                                        <input type="number" name="steps[{{ $i }}][wait_for_ack_seconds]" value="{{ old('steps.'.$i.'.wait_for_ack_seconds') }}" class="form-control" min="0" placeholder="Ack seconds">
                                    </div>
                                    <div class="col-md-6">
                                        <input type="number" name="steps[{{ $i }}][resolve_within_seconds]" value="{{ old('steps.'.$i.'.resolve_within_seconds') }}" class="form-control" min="0" placeholder="Resolve seconds">
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="step_{{ $i }}_simultaneous" name="steps[{{ $i }}][simultaneous]" value="1" @checked(old('steps.'.$i.'.simultaneous'))>
                                            <label class="form-check-label" for="step_{{ $i }}_simultaneous">Simultaneous</label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="step_{{ $i }}_non_suppressible" name="steps[{{ $i }}][non_suppressible]" value="1" @checked(old('steps.'.$i.'.non_suppressible'))>
                                            <label class="form-check-label" for="step_{{ $i }}_non_suppressible">Non-suppressible</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endfor
                    </div>

                    <button type="submit" class="btn btn-primary mt-2">
                        <i class="bi bi-plus-lg me-1"></i>Create
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-diagram-3 me-2"></i>Routes
            </div>

            @if($routes->isEmpty())
                <div class="card-body">
                    <div class="text-muted">No routes yet.</div>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="thead-brand">
                            <tr>
                                <th>Route</th>
                                <th>Filter</th>
                                <th>Steps</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($routes as $route)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $route->label }}</div>
                                        <span class="badge {{ $route->enabled ? 'bg-success' : 'bg-secondary' }}">{{ $route->enabled ? 'Enabled' : 'Disabled' }}</span>
                                        <span class="text-muted small ms-1">{{ $route->cooldown_seconds }}s cooldown</span>
                                    </td>
                                    <td class="small">{{ $route->event_filter_summary }}</td>
                                    <td class="small">{{ $route->steps_summary }}</td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('settings.alerts.routes.toggle', $route) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm {{ $route->enabled ? 'btn-outline-warning' : 'btn-outline-success' }}" title="{{ $route->enabled ? 'Disable' : 'Enable' }}">
                                                <i class="bi {{ $route->enabled ? 'bi-pause' : 'bi-play' }}"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="bg-light-subtle">
                                        <form method="POST" action="{{ route('settings.alerts.routes.update', $route) }}" class="row g-2">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-md-4">
                                                <label class="form-label small" for="edit_route_label_{{ $route->id }}">Label</label>
                                                <input type="text" id="edit_route_label_{{ $route->id }}" name="label" value="{{ $route->label }}" class="form-control form-control-sm" required>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label small" for="edit_route_cooldown_{{ $route->id }}">Cooldown</label>
                                                <input type="number" id="edit_route_cooldown_{{ $route->id }}" name="cooldown_seconds" value="{{ $route->cooldown_seconds }}" class="form-control form-control-sm" min="0">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label small" for="edit_route_priority_{{ $route->id }}">Priority</label>
                                                <select id="edit_route_priority_{{ $route->id }}" name="event_filter[min_priority]" class="form-select form-select-sm">
                                                    <option value="">Any</option>
                                                    @foreach([1, 2, 3, 4, 5] as $priority)
                                                        <option value="{{ $priority }}" @selected(($route->event_filter['min_priority'] ?? null) === $priority)>P{{ $priority }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-3 d-flex align-items-end">
                                                <button type="submit" class="btn btn-outline-primary btn-sm">
                                                    <i class="bi bi-check-lg me-1"></i>Save Route
                                                </button>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small" for="edit_route_categories_{{ $route->id }}">Categories</label>
                                                <input type="text" id="edit_route_categories_{{ $route->id }}" name="event_filter[categories]" value="{{ implode(', ', (array) ($route->event_filter['categories'] ?? [])) }}" class="form-control form-control-sm">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small" for="edit_route_clients_{{ $route->id }}">Client IDs</label>
                                                <input type="text" id="edit_route_clients_{{ $route->id }}" name="event_filter[client_ids]" value="{{ implode(', ', (array) ($route->event_filter['client_ids'] ?? [])) }}" class="form-control form-control-sm">
                                            </div>
                                            <div class="col-12">
                                                <div class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" id="route_{{ $route->id }}_event_all" name="event_filter[types][]" value="all" @checked(($route->event_filter['types'] ?? []) === 'all')>
                                                    <label class="form-check-label" for="route_{{ $route->id }}_event_all">All routable events</label>
                                                </div>
                                                <div class="row g-2">
                                                    @foreach($eventTypeGroups as $group => $types)
                                                        <div class="col-md-4">
                                                            <fieldset class="border rounded p-2 h-100">
                                                                <legend class="float-none w-auto px-1 fs-6 mb-1">{{ $group }}</legend>
                                                                @foreach($types as $type)
                                                                    @php
                                                                        $routeTypes = $route->event_filter['types'] ?? [];
                                                                        $checked = $routeTypes === 'all' || in_array($type['key'], (array) $routeTypes, true);
                                                                        $id = 'route_'.$route->id.'_event_'.$loop->parent->index.'_'.$loop->index;
                                                                    @endphp
                                                                    <div class="form-check">
                                                                        <input class="form-check-input" type="checkbox" id="{{ $id }}" name="event_filter[types][]" value="{{ $type['key'] }}" @checked($checked)>
                                                                        <label class="form-check-label small" for="{{ $id }}">{{ $type['key'] }}</label>
                                                                    </div>
                                                                @endforeach
                                                            </fieldset>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                            @php $editSteps = $route->steps->values(); @endphp
                                            @foreach($editSteps as $index => $step)
                                                <div class="col-md-4">
                                                    <div class="border rounded p-2 h-100">
                                                        <select name="steps[{{ $index }}][destination_id]" class="form-select form-select-sm mb-2">
                                                            @foreach($routeDestinations as $destination)
                                                                <option value="{{ $destination->id }}" @selected($step->destination_id === $destination->id)>{{ $destination->label }}</option>
                                                            @endforeach
                                                        </select>
                                                        <input type="number" name="steps[{{ $index }}][wait_for_ack_seconds]" value="{{ $step->wait_for_ack_seconds }}" class="form-control form-control-sm mb-2" min="0" placeholder="Ack seconds">
                                                        <input type="number" name="steps[{{ $index }}][resolve_within_seconds]" value="{{ $step->resolve_within_seconds }}" class="form-control form-control-sm mb-2" min="0" placeholder="Resolve seconds">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="edit_step_{{ $route->id }}_{{ $index }}_simultaneous" name="steps[{{ $index }}][simultaneous]" value="1" @checked($index > 0 && $step->step_order === $editSteps[$index - 1]->step_order)>
                                                            <label class="form-check-label small" for="edit_step_{{ $route->id }}_{{ $index }}_simultaneous">Simultaneous</label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="edit_step_{{ $route->id }}_{{ $index }}_non_suppressible" name="steps[{{ $index }}][non_suppressible]" value="1" @checked($step->non_suppressible)>
                                                            <label class="form-check-label small" for="edit_step_{{ $route->id }}_{{ $index }}_non_suppressible">Non-suppressible</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
