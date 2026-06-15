@php $editing = isset($expenseCategory); $cat = $expenseCategory ?? null; @endphp
@extends('layouts.app')

@section('title', $editing ? 'Edit Expense Category' : 'Create Expense Category')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $editing ? 'Edit Expense Category' : 'New Expense Category' }}</h4>
                <h6>Finance — Expense Categories</h6>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ $editing ? url('/finance/expense-categories/' . $cat->id) : url('/finance/expense-categories') }}">
                    @csrf
                    @if($editing) @method('PUT') @endif

                    @if($errors->any())
                        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
                    @endif

                    @if($editing && $cat->is_system)
                        <div class="alert alert-info">This is a <strong>system category</strong>. You can edit its details and linkage, but it cannot be deleted.</div>
                    @endif

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label required" for="code">Code</label>
                            <input type="text" id="code" name="code" class="form-control @error('code') is-invalid @enderror"
                                value="{{ old('code', $cat->code ?? '') }}" required>
                            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-8 mb-3">
                            <label class="form-label required" for="name">Name</label>
                            <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                                value="{{ old('name', $cat->name ?? '') }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="account_id">Linked Chart of Account (expense)</label>
                            <select id="account_id" name="account_id" class="form-select">
                                <option value="">— None —</option>
                                @foreach($expenseAccounts as $a)
                                    <option value="{{ $a->id }}" {{ (int) old('account_id', $cat->account_id ?? 0) === $a->id ? 'selected' : '' }}>{{ $a->code }} — {{ $a->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="sort_order">Sort Order</label>
                            <input type="number" id="sort_order" name="sort_order" class="form-control" min="0" value="{{ old('sort_order', $cat->sort_order ?? 0) }}">
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label" for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="2">{{ old('description', $cat->description ?? '') }}</textarea>
                        </div>

                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                                    {{ old('is_active', $cat->is_active ?? true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">{{ $editing ? 'Update' : 'Create' }}</button>
                        <a href="{{ url('/finance/expense-categories') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
@endsection
