{{-- Shared form partial for Production Order create/edit --}}
@php
    $statusLabels = [
        'draft'       => 'Draft',
        'planned'     => 'Planned',
        'released'    => 'Released',
        'in_progress' => 'In Progress',
        'on_hold'     => 'On Hold',
        'completed'   => 'Completed',
        'cancelled'   => 'Cancelled',
    ];
    $priorityLabels = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
@endphp

<form method="POST"
      action="{{ $order ? url('/manufacturing/production-orders/' . $order->id) : url('/manufacturing/production-orders') }}"
      novalidate>
    @csrf
    @if($order) @method('PUT') @endif

    @if($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        <div class="card-header"><h6 class="mb-0">Order Details</h6></div>
        <div class="card-body row g-3">

            <div class="col-md-4">
                <label for="order_no" class="form-label required">Order No</label>
                <input id="order_no" name="order_no"
                       class="form-control @error('order_no') is-invalid @enderror"
                       value="{{ old('order_no', $order?->order_no ?? $nextNo) }}"
                       placeholder="{{ $nextNo ?? 'PROD-000001' }}">
                <div class="form-text">Auto-generated if left blank.</div>
                @error('order_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="order_date" class="form-label required">Order Date</label>
                <input id="order_date" name="order_date" type="date" required
                       class="form-control @error('order_date') is-invalid @enderror"
                       value="{{ old('order_date', $order?->order_date?->toDateString() ?? now()->toDateString()) }}">
                @error('order_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="due_date" class="form-label">Due Date</label>
                <input id="due_date" name="due_date" type="date"
                       class="form-control @error('due_date') is-invalid @enderror"
                       value="{{ old('due_date', $order?->due_date?->toDateString()) }}">
                @error('due_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label for="product_id" class="form-label required">Finished Product</label>
                <select id="product_id" name="product_id" required
                        class="ajax-select2 form-select @error('product_id') is-invalid @enderror"
                        data-ajax-url="{{ url('/ajax/products') }}"
                        data-context="production_order"
                        data-placeholder="Search product…" data-min-input="1">
                    @if($selectedProduct ?? null)
                        <option value="{{ $selectedProduct['id'] }}" selected>{{ $selectedProduct['text'] }}</option>
                    @endif
                </select>
                <div class="form-text">Type to search by SKU or name.</div>
                @error('product_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="planned_quantity" class="form-label required">Planned Qty</label>
                <input id="planned_quantity" name="planned_quantity" type="number"
                       step="0.0001" min="0.0001" required
                       class="form-control @error('planned_quantity') is-invalid @enderror"
                       value="{{ old('planned_quantity', $order?->planned_quantity) }}">
                @error('planned_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-3">
                <label for="produced_quantity" class="form-label">Produced Qty</label>
                <input id="produced_quantity" name="produced_quantity" type="number"
                       step="0.0001" min="0"
                       class="form-control @error('produced_quantity') is-invalid @enderror"
                       value="{{ old('produced_quantity', $order?->produced_quantity ?? 0) }}">
                <div class="form-text">Cannot exceed planned qty.</div>
                @error('produced_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header"><h6 class="mb-0">Assignment</h6></div>
        <div class="card-body row g-3">

            <div class="col-md-6">
                <label for="manufacturing_customer_id" class="form-label">Manufacturing Customer</label>
                <select id="manufacturing_customer_id" name="manufacturing_customer_id"
                        class="ajax-select2 form-select @error('manufacturing_customer_id') is-invalid @enderror"
                        data-ajax-url="{{ url('/ajax/manufacturing-customers') }}"
                        data-placeholder="Search customer (optional)…" data-min-input="1" data-allow-clear="1">
                    <option value=""></option>
                    @if($selectedCustomer ?? null)
                        <option value="{{ $selectedCustomer['id'] }}" selected>{{ $selectedCustomer['text'] }}</option>
                    @endif
                </select>
                <div class="form-text">Optional. <strong>Not linked to POS/Sales customers.</strong></div>
                @error('manufacturing_customer_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label for="branch_id" class="form-label required">Branch / Production Unit</label>
                <select id="branch_id" name="branch_id" required
                        class="select form-select @error('branch_id') is-invalid @enderror">
                    <option value="">— Select branch —</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected(old('branch_id', $order?->branch_id) == $b->id)>
                            {{ $b->name }}
                        </option>
                    @endforeach
                </select>
                <div class="form-text">A production order belongs to one branch / production unit.</div>
                @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header"><h6 class="mb-0">Status & Priority</h6></div>
        <div class="card-body row g-3">

            <div class="col-md-4">
                <label for="status" class="form-label required">Status</label>
                <select id="status" name="status" required
                        class="select form-select @error('status') is-invalid @enderror">
                    @foreach($statuses as $s)
                        <option value="{{ $s }}" @selected(old('status', $order?->status ?? 'draft') === $s)>
                            {{ $statusLabels[$s] ?? ucfirst($s) }}
                        </option>
                    @endforeach
                </select>
                @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-4">
                <label for="priority" class="form-label">Priority</label>
                <select id="priority" name="priority"
                        class="select form-select @error('priority') is-invalid @enderror">
                    <option value="">— Not set —</option>
                    @foreach($priorities as $p)
                        <option value="{{ $p }}" @selected(old('priority', $order?->priority) === $p)>
                            {{ $priorityLabels[$p] ?? ucfirst($p) }}
                        </option>
                    @endforeach
                </select>
                @error('priority') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-12">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="2"
                          class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $order?->notes) }}</textarea>
                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

        </div>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary">
            {{ $order ? 'Update Order' : 'Create Order' }}
        </button>
        <a href="{{ url('/manufacturing/production-orders' . ($order ? '/' . $order->id : '')) }}"
           class="btn btn-light ms-2">Cancel</a>
    </div>
</form>

@include('tenant.partials.ajax-select2-scripts')
