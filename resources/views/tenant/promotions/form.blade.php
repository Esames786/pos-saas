@php $editing = isset($promotion); @endphp
@extends('layouts.app')

@section('title', $editing ? 'Edit Promotion' : 'Create Promotion')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $editing ? 'Edit Promotion' : 'New Promotion' }}</h4>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ $editing ? url('/promotions/' . $promotion->id) : url('/promotions') }}">
                    @csrf
                    @if($editing) @method('PUT') @endif

                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                        </div>
                    @endif

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required" for="name">Name</label>
                            <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                                value="{{ old('name', $promotion->name ?? '') }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="code">Promo Code <small class="text-muted">(leave blank for automatic)</small></label>
                            <input type="text" id="code" name="code" class="form-control @error('code') is-invalid @enderror"
                                value="{{ old('code', $promotion->code ?? '') }}" style="text-transform:uppercase">
                            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label required" for="promotion_type">Promotion Type</label>
                            <select id="promotion_type" name="promotion_type" class="form-select" required>
                                @foreach(['order' => 'Order (whole order)', 'product' => 'Product', 'category' => 'Category'] as $v => $l)
                                    <option value="{{ $v }}" {{ old('promotion_type', $promotion->promotion_type ?? 'order') === $v ? 'selected' : '' }}>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label required" for="discount_type">Discount Type</label>
                            <select id="discount_type" name="discount_type" class="form-select" required>
                                <option value="percent" {{ old('discount_type', $promotion->discount_type ?? '') === 'percent' ? 'selected' : '' }}>Percent (%)</option>
                                <option value="fixed" {{ old('discount_type', $promotion->discount_type ?? '') === 'fixed' ? 'selected' : '' }}>Fixed Amount</option>
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label required" for="discount_value">Discount Value</label>
                            <input type="number" id="discount_value" name="discount_value" class="form-control"
                                value="{{ old('discount_value', $promotion->discount_value ?? '') }}" step="0.01" min="0" required>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label" for="max_discount_amount">Max Discount Amount</label>
                            <input type="number" id="max_discount_amount" name="max_discount_amount" class="form-control"
                                value="{{ old('max_discount_amount', $promotion->max_discount_amount ?? '') }}" step="0.01" min="0">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label" for="min_order_amount">Min Order Amount</label>
                            <input type="number" id="min_order_amount" name="min_order_amount" class="form-control"
                                value="{{ old('min_order_amount', $promotion->min_order_amount ?? 0) }}" step="0.01" min="0">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label" for="usage_limit">Usage Limit</label>
                            <input type="number" id="usage_limit" name="usage_limit" class="form-control"
                                value="{{ old('usage_limit', $promotion->usage_limit ?? '') }}" min="1" placeholder="Unlimited">
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label">Applicable Order Types <small class="text-muted">(leave unchecked for all)</small></label>
                            <div class="d-flex gap-3 flex-wrap">
                                @php $currentTypes = old('order_types', $promotion->order_types ?? []); @endphp
                                @foreach(['quick_sale' => 'Quick Sale', 'takeaway' => 'Takeaway', 'dine_in' => 'Dine-in', 'delivery' => 'Delivery'] as $v => $l)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="order_types[]" value="{{ $v }}" id="ot_{{ $v }}"
                                            {{ in_array($v, $currentTypes ?: []) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="ot_{{ $v }}">{{ $l }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="starts_at">Starts At</label>
                            <input type="datetime-local" id="starts_at" name="starts_at" class="form-control"
                                value="{{ old('starts_at', isset($promotion->starts_at) ? $promotion->starts_at->format('Y-m-d\TH:i') : '') }}">
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="ends_at">Ends At</label>
                            <input type="datetime-local" id="ends_at" name="ends_at" class="form-control"
                                value="{{ old('ends_at', isset($promotion->ends_at) ? $promotion->ends_at->format('Y-m-d\TH:i') : '') }}">
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label required" for="status">Status</label>
                            <select id="status" name="status" class="form-select" required>
                                <option value="active" {{ old('status', $promotion->status ?? 'active') === 'active' ? 'selected' : '' }}>Active</option>
                                <option value="inactive" {{ old('status', $promotion->status ?? '') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                            </select>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="priority">Priority <small class="text-muted">(higher = applied first)</small></label>
                            <input type="number" id="priority" name="priority" class="form-control"
                                value="{{ old('priority', $promotion->priority ?? 0) }}" min="0">
                        </div>

                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="requires_code" id="requires_code" value="1"
                                    {{ old('requires_code', $promotion->requires_code ?? false) ? 'checked' : '' }}>
                                <label class="form-check-label" for="requires_code">Requires promo code to apply</label>
                            </div>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label" for="notes">Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="2">{{ old('notes', $promotion->notes ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">{{ $editing ? 'Update Promotion' : 'Create Promotion' }}</button>
                        <a href="{{ url('/promotions') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
@endsection
