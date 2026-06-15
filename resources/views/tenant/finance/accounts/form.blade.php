@php $editing = isset($account); @endphp
@extends('layouts.app')

@section('title', $editing ? 'Edit Account' : 'Create Account')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $editing ? 'Edit Account' : 'New Account' }}</h4>
                <h6>Chart of Accounts</h6>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ $editing ? url('/finance/accounts/' . $account->id) : url('/finance/accounts') }}">
                    @csrf
                    @if($editing) @method('PUT') @endif

                    @if($errors->any())
                        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
                    @endif

                    @if($editing && $account->is_system)
                        <div class="alert alert-info">This is a <strong>system account</strong> from the default chart. You can edit its name, parent, and status, but it cannot be deleted.</div>
                    @endif

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label required" for="code">Code</label>
                            <input type="text" id="code" name="code" class="form-control @error('code') is-invalid @enderror"
                                value="{{ old('code', $account->code ?? '') }}" required>
                            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-8 mb-3">
                            <label class="form-label required" for="name">Name</label>
                            <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                                value="{{ old('name', $account->name ?? '') }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label required" for="type">Type</label>
                            <select id="type" name="type" class="form-select" required>
                                @foreach(['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expense'] as $v => $l)
                                    <option value="{{ $v }}" {{ old('type', $account->type ?? 'asset') === $v ? 'selected' : '' }}>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label required" for="normal_balance">Normal Balance</label>
                            <select id="normal_balance" name="normal_balance" class="form-select" required>
                                @foreach(['debit' => 'Debit', 'credit' => 'Credit'] as $v => $l)
                                    <option value="{{ $v }}" {{ old('normal_balance', $account->normal_balance ?? 'debit') === $v ? 'selected' : '' }}>{{ $l }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">Assets &amp; expenses are usually debit; liability, equity &amp; income are credit.</small>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label" for="sort_order">Sort Order</label>
                            <input type="number" id="sort_order" name="sort_order" class="form-control" min="0"
                                value="{{ old('sort_order', $account->sort_order ?? 0) }}">
                        </div>

                        <div class="col-md-8 mb-3">
                            <label class="form-label" for="parent_id">Parent Account</label>
                            <select id="parent_id" name="parent_id" class="form-select">
                                <option value="">— None (top level) —</option>
                                @foreach($parents as $p)
                                    <option value="{{ $p->id }}" {{ (int) old('parent_id', $account->parent_id ?? 0) === $p->id ? 'selected' : '' }}>
                                        {{ $p->code }} — {{ $p->name }} ({{ ucfirst($p->type) }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label" for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" rows="2">{{ old('description', $account->description ?? '') }}</textarea>
                        </div>

                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                                    {{ old('is_active', $account->is_active ?? true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">{{ $editing ? 'Update' : 'Create' }}</button>
                        <a href="{{ url('/finance/accounts') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
@endsection
