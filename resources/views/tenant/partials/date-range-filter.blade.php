{{-- Shared date-range filter: From / To + Today / Yesterday quick buttons.
     Dates are the branch's local calendar days (Asia/Karachi for Khatri). --}}
<form method="GET" action="{{ $action }}" class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-end gap-2">
        <div>
            <label for="date_from" class="form-label small mb-1">From</label>
            <input type="date" id="date_from" name="date_from" value="{{ $dateFrom ?? '' }}" class="form-control form-control-sm">
        </div>
        <div>
            <label for="date_to" class="form-label small mb-1">To</label>
            <input type="date" id="date_to" name="date_to" value="{{ $dateTo ?? '' }}" class="form-control form-control-sm">
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
        <button type="submit" name="range" value="today" class="btn btn-sm btn-outline-secondary">Today</button>
        <button type="submit" name="range" value="yesterday" class="btn btn-sm btn-outline-secondary">Yesterday</button>
        <a href="{{ $action }}" class="btn btn-sm btn-light">Clear</a>
    </div>
</form>
