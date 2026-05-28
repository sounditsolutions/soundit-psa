<div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
        <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th class="d-none d-md-table-cell">Filter</th>
                <th class="d-none d-md-table-cell">Last Evaluated</th>
                <th class="text-center">Active</th>
                <th style="width: 60px;"></th>
            </tr>
        </thead>
        <tbody>
            @foreach($rules as $rule)
                <tr data-rule-id="{{ $rule->id }}">
                    <td>{{ $rule->name }}</td>
                    <td><span class="badge bg-light text-dark">{{ $rule->rule_type->label() }}</span></td>
                    <td class="d-none d-md-table-cell small text-muted">
                        {{ $rule->filter_values ? implode(', ', $rule->filter_values) : '-' }}
                    </td>
                    <td class="d-none d-md-table-cell small">
                        {{ $rule->last_evaluated_at?->diffForHumans() ?? 'Never' }}
                    </td>
                    <td class="text-center">
                        @if($rule->is_active)
                            <i class="bi bi-check-circle text-success"></i>
                        @else
                            <i class="bi bi-x-circle text-muted"></i>
                        @endif
                    </td>
                    <td>
                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1 delete-rule-btn"
                                data-url="{{ route('rules.destroy', $rule) }}" title="Delete rule">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
