{{-- Manufacturing reports filter bar (read-only). Submits GET to the reports index. --}}
<div class="card">
    <div class="card-body">
        <form method="GET" action="{{ url('/manufacturing/reports') }}" class="row g-2 align-items-end">
            <div class="col-sm-6 col-md-2">
                <label class="form-label mb-1">From</label>
                <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
            </div>
            <div class="col-sm-6 col-md-2">
                <label class="form-label mb-1">To</label>
                <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
            </div>

            @include('tenant.partials.branch-multiselect', [
                'branches'          => $branches,
                'selectedBranchIds' => $selectedBranchIds ?? [],
                'colClass'          => 'col-sm-6 col-md-3',
                'label'             => 'Branch',
            ])

            <div class="col-sm-6 col-md-2">
                <label class="form-label mb-1">Status (orders)</label>
                <select name="status" class="select form-select">
                    <option value="">All</option>
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" {{ ($filters['status'] ?? '') === $s ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-sm-6 col-md-3">
                <label class="form-label mb-1">Production Order</label>
                <select name="production_order_id"
                        class="ajax-select2 form-select" data-ajax-url="{{ url('/ajax/production-orders') }}"
                        data-placeholder="All orders…" data-min-input="1" data-allow-clear="1">
                    <option value=""></option>
                    @if($selectedOrder ?? null)<option value="{{ $selectedOrder['id'] }}" selected>{{ $selectedOrder['text'] }}</option>@endif
                </select>
            </div>

            <div class="col-sm-6 col-md-3">
                <label class="form-label mb-1">Manufacturing Customer</label>
                <select name="manufacturing_customer_id"
                        class="ajax-select2 form-select" data-ajax-url="{{ url('/ajax/manufacturing-customers') }}"
                        data-placeholder="All customers…" data-min-input="1" data-allow-clear="1">
                    <option value=""></option>
                    @if($selectedCustomer ?? null)<option value="{{ $selectedCustomer['id'] }}" selected>{{ $selectedCustomer['text'] }}</option>@endif
                </select>
            </div>

            <div class="col-sm-6 col-md-3">
                <label class="form-label mb-1">Product</label>
                <select name="product_id"
                        class="ajax-select2 form-select" data-ajax-url="{{ url('/ajax/products') }}"
                        data-placeholder="All products…" data-min-input="1" data-allow-clear="1">
                    <option value=""></option>
                    @if($selectedProduct ?? null)<option value="{{ $selectedProduct['id'] }}" selected>{{ $selectedProduct['text'] }}</option>@endif
                </select>
                <div class="form-text">Applies to Orders / WIP / Finished Goods.</div>
            </div>

            <div class="col-sm-6 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Apply</button>
                <a href="{{ url('/manufacturing/reports') }}" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

@include('tenant.partials.ajax-select2-scripts')
