{{-- Shared report filter bar. Pass $showOrderType, $showCashier, $showTerminal as needed. --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" action="" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1" for="filter-date-from">From</label>
                <input type="date" id="filter-date-from" name="date_from" class="form-control form-control-sm"
                    value="{{ $filters['date_from'] ?? today()->format('Y-m-d') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1" for="filter-date-to">To</label>
                <input type="date" id="filter-date-to" name="date_to" class="form-control form-control-sm"
                    value="{{ $filters['date_to'] ?? today()->format('Y-m-d') }}">
            </div>
            @if($branchMulti ?? false)
                {{-- Multi-select branch (reports only). Submits branch_ids[]. --}}
                @include('tenant.partials.branch-multiselect', [
                    'branches'          => $branches ?? [],
                    'selectedBranchIds' => $selectedBranchIds ?? [],
                    'colClass'          => 'col-md-3',
                ])
            @else
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="filter-branch">Branch</label>
                    <select id="filter-branch" name="branch_id" class="form-select form-select-sm">
                        <option value="">All Branches</option>
                        @foreach($branches ?? [] as $b)
                            <option value="{{ $b->id }}" {{ ($filters['branch_id'] ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if($showTerminal ?? true)
            <div class="col-md-2">
                <label class="form-label small mb-1" for="filter-terminal">Terminal</label>
                <select id="filter-terminal" name="terminal_id" class="form-select form-select-sm">
                    <option value="">All Terminals</option>
                    @foreach($terminals ?? [] as $t)
                        <option value="{{ $t->id }}" {{ ($filters['terminal_id'] ?? '') == $t->id ? 'selected' : '' }}>{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            @if($showOrderType ?? false)
            <div class="col-md-2">
                <label class="form-label small mb-1" for="filter-order-type">Order Type</label>
                <select id="filter-order-type" name="order_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    @foreach($orderTypes ?? [] as $ot)
                        <option value="{{ $ot }}" {{ ($filters['order_type'] ?? '') === $ot ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $ot)) }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                <a href="{{ url()->current() }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                @if($showCsvExport ?? false)
                <button type="submit" name="export_csv" value="1" class="btn btn-outline-success btn-sm">
                    <i class="ti ti-download me-1"></i>CSV
                </button>
                @endif
            </div>
        </form>
    </div>
</div>
