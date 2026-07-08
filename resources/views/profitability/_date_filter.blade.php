<form method="GET" action="" class="mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="form-label small">From</label>
            <input type="date" name="from" class="form-control form-control-sm" value="{{ $from }}">
        </div>
        <div class="col-auto">
            <label class="form-label small">To</label>
            <input type="date" name="to" class="form-control form-control-sm" value="{{ $to }}">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            @if($from || $to)
                <a href="{{ url()->current() }}" class="btn btn-outline-secondary btn-sm">Clear</a>
            @endif
        </div>
    </div>
</form>
