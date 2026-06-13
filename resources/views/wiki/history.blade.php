@extends('layouts.app')

@section('content')
<div class="container-fluid" style="max-width: 980px;">
    <h1 class="h3 mb-3">History: {{ $page->title }}</h1>

    @foreach ($revisions as $revision)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <span>
                    <strong>{{ $revision->change_summary }}</strong>
                    <span class="text-muted small ms-2">
                        {{ $revision->author_type->value }}{{ $revision->author ? ' · '.$revision->author->name : '' }}
                    </span>
                </span>
                <span class="text-muted small">{{ $revision->created_at->format('Y-m-d H:i') }}</span>
            </div>
            <div class="card-body p-0">
                <pre class="mb-0 small" style="max-height: 320px; overflow:auto;">@foreach ($diffs[$revision->id] as $row)@if ($row['type'] === 'add')<div class="bg-success-subtle">+ {{ $row['line'] }}</div>@elseif ($row['type'] === 'del')<div class="bg-danger-subtle">− {{ $row['line'] }}</div>@else<div class="text-muted">  {{ $row['line'] }}</div>@endif @endforeach</pre>
            </div>
        </div>
    @endforeach
</div>
@endsection
