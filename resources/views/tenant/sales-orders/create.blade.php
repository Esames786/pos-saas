@extends('layouts.app')

@section('title', 'Create Sales Order')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Create Sales Order</h1>
        <p class="fw-medium">Manual sale — select branch, products, and payment.</p>
    </div>
    <a href="{{ url('/sales-orders') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert" aria-live="polite">{{ $errors->first() }}</div>
@endif

<form method="POST" action="{{ url('/sales-orders') }}" novalidate id="sale-form">
    @csrf
    <input type="hidden" name="order_source" value="manual">

    <div class="row g-3">
        <div class="col-lg-8">
            {{-- Header --}}
            <div class="card mb-3">
                <div class="card-header"><strong>Sale Header</strong></div>
                <div class="card-body row g-3">
                    <div class="col-md-4">
                        <label for="branch_id" class="form-label required">Branch</label>
                        <select id="branch_id" name="branch_id" required
                                class="form-select @error('branch_id') is-invalid @enderror">
                            <option value="">— Select —</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}"
                                        @selected(old('branch_id') == $branch->id)>
                                    {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label for="terminal_id" class="form-label">Terminal</label>
                        <select id="terminal_id" name="terminal_id"
                                class="form-select @error('terminal_id') is-invalid @enderror">
                            <option value="">— None —</option>
                            @foreach($terminals as $terminal)
                                <option value="{{ $terminal->id }}"
                                        @selected(old('terminal_id') == $terminal->id)>
                                    {{ $terminal->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('terminal_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label for="order_type" class="form-label required">Order Type</label>
                        <select id="order_type" name="order_type" required
                                class="form-select @error('order_type') is-invalid @enderror">
                            <option value="quick_sale"  @selected(old('order_type', 'quick_sale') === 'quick_sale')>Quick Sale</option>
                            <option value="takeaway"    @selected(old('order_type') === 'takeaway')>Takeaway</option>
                            <option value="dine_in"     @selected(old('order_type') === 'dine_in')>Dine In</option>
                            <option value="delivery"    @selected(old('order_type') === 'delivery')>Delivery</option>
                        </select>
                        @error('order_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="customer_id" class="form-label">Customer</label>
                        <select id="customer_id" name="customer_id"
                                class="form-select @error('customer_id') is-invalid @enderror">
                            <option value="">Walk-in</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}"
                                        @selected(old('customer_id') == $customer->id)>
                                    {{ $customer->name }}{{ $customer->phone ? ' — ' . $customer->phone : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('customer_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-6">
                        <label for="customer_name" class="form-label">Customer Name (override)</label>
                        <input id="customer_name" name="customer_name" type="text"
                               class="form-control" value="{{ old('customer_name') }}"
                               placeholder="Optional override">
                    </div>

                    <div class="col-md-4">
                        <label for="customer_phone" class="form-label">Customer Phone</label>
                        <input id="customer_phone" name="customer_phone" type="text"
                               class="form-control" value="{{ old('customer_phone') }}">
                    </div>

                    <div class="col-md-8">
                        <label for="notes" class="form-label">Notes</label>
                        <input id="notes" name="notes" type="text"
                               class="form-control" value="{{ old('notes') }}">
                    </div>
                </div>
            </div>

            {{-- Product lines --}}
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center gap-2">
                    <strong>Sale Lines</strong>
                    <div class="d-flex gap-2 flex-grow-1 justify-content-end">
                        <input type="search" id="barcode-search"
                               class="form-control form-control-sm"
                               style="max-width:200px"
                               placeholder="Barcode / product search"
                               aria-label="Search product by barcode or name">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="add-line">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>Add Line
                        </button>
                    </div>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-nowrap align-middle mb-0">
                        <caption class="visually-hidden">Sale product lines</caption>
                        <thead>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">Variant</th>
                            <th scope="col" style="width:90px">Qty</th>
                            <th scope="col" style="width:110px">Unit Price</th>
                            <th scope="col" style="width:100px">Discount</th>
                            <th scope="col" style="width:100px">Tax</th>
                            <th scope="col" style="width:110px">Line Total</th>
                            <th scope="col" style="width:40px"></th>
                        </tr>
                        </thead>
                        <tbody class="lines-body" id="lines-body">
                        @php $lineCount = max(count(old('lines', [])), 3); @endphp
                        @for($i = 0; $i < $lineCount; $i++)
                        <tr class="line-row">
                            <td>
                                <select name="lines[{{ $i }}][product_id]"
                                        class="form-select form-select-sm product-select"
                                        aria-label="Product for line {{ $i + 1 }}">
                                    <option value="">— Select —</option>
                                    @foreach($products as $product)
                                        @php
                                            $bpMap = $product->branchPrices->whereNull('product_variant_id')
                                                ->pluck('selling_price', 'branch_id')->toArray();
                                            $bcList = $product->barcodes->pluck('barcode')->implode(',');
                                            $variantData = $product->variants->map(fn($v) => [
                                                'id'            => $v->id,
                                                'name'          => $v->name,
                                                'selling_price' => (string)($v->selling_price ?? '0'),
                                                'branch_prices' => $product->branchPrices
                                                    ->where('product_variant_id', $v->id)
                                                    ->pluck('selling_price', 'branch_id')->toArray(),
                                            ]);
                                        @endphp
                                        <option value="{{ $product->id }}"
                                                data-price="{{ $product->default_selling_price ?? 0 }}"
                                                data-taxable="{{ $product->is_taxable ? 1 : 0 }}"
                                                data-tax-rate="{{ $product->tax_rate_percent ?? 0 }}"
                                                data-branch-prices="{{ json_encode($bpMap) }}"
                                                data-barcodes="{{ $bcList }}"
                                                data-variants="{{ $variantData->toJson() }}"
                                                @selected(old("lines.$i.product_id") == $product->id)>
                                            {{ $product->name }}{{ $product->sku ? ' — ' . $product->sku : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <select name="lines[{{ $i }}][product_variant_id]"
                                        class="form-select form-select-sm variant-select"
                                        aria-label="Variant for line {{ $i + 1 }}">
                                    <option value="">—</option>
                                </select>
                            </td>
                            <td>
                                <input type="number" step="0.001" min="0.001"
                                       name="lines[{{ $i }}][quantity]"
                                       class="form-control form-control-sm qty-input"
                                       aria-label="Quantity for line {{ $i + 1 }}"
                                       value="{{ old("lines.$i.quantity", 1) }}">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0"
                                       name="lines[{{ $i }}][unit_price]"
                                       class="form-control form-control-sm price-input"
                                       aria-label="Unit price for line {{ $i + 1 }}"
                                       value="{{ old("lines.$i.unit_price", 0) }}">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0"
                                       name="lines[{{ $i }}][discount_amount]"
                                       class="form-control form-control-sm disc-input"
                                       aria-label="Discount for line {{ $i + 1 }}"
                                       value="{{ old("lines.$i.discount_amount", 0) }}">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0"
                                       name="lines[{{ $i }}][tax_amount]"
                                       class="form-control form-control-sm tax-input"
                                       aria-label="Tax for line {{ $i + 1 }}"
                                       value="{{ old("lines.$i.tax_amount", 0) }}">
                            </td>
                            <td><span class="line-total fw-medium">0.00</span></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-link text-danger remove-line"
                                        aria-label="Remove line {{ $i + 1 }}">
                                    <i class="ti ti-x" aria-hidden="true"></i>
                                </button>
                            </td>
                        </tr>
                        @endfor
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Discount --}}
            <div class="card mb-3">
                <div class="card-body row g-3">
                    <div class="col-md-3">
                        <label for="discount_type" class="form-label">Order Discount</label>
                        <select id="discount_type" name="discount_type" class="form-select">
                            <option value="none"    @selected(old('discount_type', 'none') === 'none')>None</option>
                            <option value="fixed"   @selected(old('discount_type') === 'fixed')>Fixed Amount</option>
                            <option value="percent" @selected(old('discount_type') === 'percent')>Percent (%)</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="discount_value" class="form-label">Value</label>
                        <input id="discount_value" type="number" step="0.01" min="0"
                               name="discount_value" class="form-control"
                               value="{{ old('discount_value', 0) }}">
                    </div>
                </div>
            </div>
        </div>

        {{-- Right: totals + payments --}}
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><strong>Totals</strong></div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-6">Subtotal</dt>
                        <dd class="col-6 text-end" id="summary-subtotal">0.00</dd>
                        <dt class="col-6">Discount</dt>
                        <dd class="col-6 text-end" id="summary-discount">0.00</dd>
                        <dt class="col-6">Tax</dt>
                        <dd class="col-6 text-end" id="summary-tax">0.00</dd>
                        <dt class="col-6 fw-bold fs-5">Grand Total</dt>
                        <dd class="col-6 text-end fw-bold fs-5 text-primary" id="summary-grand">0.00</dd>
                        <dt class="col-6 text-success">Paid</dt>
                        <dd class="col-6 text-end text-success" id="summary-paid">0.00</dd>
                        <dt class="col-6 text-warning">Change</dt>
                        <dd class="col-6 text-end text-warning" id="summary-change">0.00</dd>
                    </dl>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Payments</strong>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="add-payment">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>Add
                    </button>
                </div>
                <div class="card-body p-2">
                    @include('tenant.sales.partials.payment-lines', ['paymentMethods' => $paymentMethods])
                </div>
            </div>

            <div class="d-grid gap-2">
                <button class="btn btn-primary btn-lg" type="submit"
                        onclick="return confirm('Post this sale?')">
                    <i class="ti ti-check me-1" aria-hidden="true"></i>Post Sale
                </button>
                <a href="{{ url('/sales-orders') }}" class="btn btn-light">Cancel</a>
            </div>
        </div>
    </div>
</form>

@include('tenant.sales.partials.sales-js')
@endsection
