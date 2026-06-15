@php $editing = isset($cashBankAccount); $acc = $cashBankAccount ?? null; @endphp
@extends('layouts.app')

@section('title', $editing ? 'Edit Cash/Bank Account' : 'Create Cash/Bank Account')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>{{ $editing ? 'Edit Cash/Bank Account' : 'New Cash/Bank Account' }}</h4>
                <h6>Finance — Cash &amp; Bank Accounts</h6>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ $editing ? url('/finance/cash-bank-accounts/' . $acc->id) : url('/finance/cash-bank-accounts') }}">
                    @csrf
                    @if($editing) @method('PUT') @endif

                    @if($errors->any())
                        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
                    @endif

                    @if($editing && $acc->is_system)
                        <div class="alert alert-info">This is a <strong>system account</strong> from the default setup. You can edit its details and linkage, but it cannot be deleted.</div>
                    @endif

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label required" for="code">Code</label>
                            <input type="text" id="code" name="code" class="form-control @error('code') is-invalid @enderror"
                                value="{{ old('code', $acc->code ?? '') }}" required>
                            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-5 mb-3">
                            <label class="form-label required" for="name">Name</label>
                            <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror"
                                value="{{ old('name', $acc->name ?? '') }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label required" for="account_type">Type</label>
                            <select id="account_type" name="account_type" class="form-select" required>
                                @foreach(['cash' => 'Cash', 'bank' => 'Bank', 'wallet' => 'Wallet', 'card' => 'Card', 'other' => 'Other'] as $v => $l)
                                    <option value="{{ $v }}" {{ old('account_type', $acc->account_type ?? 'cash') === $v ? 'selected' : '' }}>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="account_id">Linked Chart of Account (asset)</label>
                            <select id="account_id" name="account_id" class="form-select">
                                <option value="">— None —</option>
                                @foreach($coaAccounts as $c)
                                    <option value="{{ $c->id }}" {{ (int) old('account_id', $acc->account_id ?? 0) === $c->id ? 'selected' : '' }}>{{ $c->code }} — {{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="branch_id">Branch</label>
                            <select id="branch_id" name="branch_id" class="form-select">
                                <option value="">— Shared —</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" {{ (int) old('branch_id', $acc->branch_id ?? 0) === $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label" for="currency_id">Currency</label>
                            <select id="currency_id" name="currency_id" class="form-select">
                                <option value="">— Default —</option>
                                @foreach($currencies as $cur)
                                    <option value="{{ $cur->id }}" {{ (int) old('currency_id', $acc->currency_id ?? 0) === $cur->id ? 'selected' : '' }}>{{ $cur->code }} — {{ $cur->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label" for="bank_name">Bank Name</label>
                            <input type="text" id="bank_name" name="bank_name" class="form-control" value="{{ old('bank_name', $acc->bank_name ?? '') }}">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label" for="account_number">Account Number</label>
                            <input type="text" id="account_number" name="account_number" class="form-control" value="{{ old('account_number', $acc->account_number ?? '') }}">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label" for="iban">IBAN</label>
                            <input type="text" id="iban" name="iban" class="form-control" value="{{ old('iban', $acc->iban ?? '') }}">
                        </div>

                        @if($editing)
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Current Balance</label>
                                <input type="text" class="form-control" value="{{ number_format((float) $acc->current_balance, 2) }}" readonly>
                                <small class="text-muted">Balance changes via opening balance and (later) journal posting.</small>
                            </div>
                        @else
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="opening_balance">Opening Balance</label>
                                <input type="number" step="0.0001" id="opening_balance" name="opening_balance" class="form-control" value="{{ old('opening_balance', 0) }}">
                                <small class="text-muted">Set once at creation. Records an opening-balance entry.</small>
                            </div>
                        @endif

                        <div class="col-12 mb-3">
                            <label class="form-label" for="notes">Notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="2">{{ old('notes', $acc->notes ?? '') }}</textarea>
                        </div>

                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_default" id="is_default" value="1"
                                    {{ old('is_default', $acc->is_default ?? false) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_default">Default account
                                    <small class="text-muted d-block">Only one account can be the tenant default.</small>
                                </label>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                                    {{ old('is_active', $acc->is_active ?? true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">{{ $editing ? 'Update' : 'Create' }}</button>
                        <a href="{{ url('/finance/cash-bank-accounts') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
@endsection
