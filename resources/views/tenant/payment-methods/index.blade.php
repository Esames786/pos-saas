@extends('layouts.app')

@section('title', 'Payment Methods')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Payment Methods</h1>
        <p class="fw-medium">Configure accepted payment methods for sales.</p>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success" role="alert" aria-live="polite">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="row g-4">
    {{-- Left: existing methods list --}}
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><strong>Configured Methods</strong></div>
            <div class="card-body table-responsive p-0">
                <table class="table table-nowrap align-middle mb-0">
                    <caption class="visually-hidden">Payment method list</caption>
                    <thead>
                    <tr>
                        <th scope="col">Code</th>
                        <th scope="col">Name</th>
                        <th scope="col">Type</th>
                        <th scope="col">Cash/Bank</th>
                        <th scope="col">Cash Drawer</th>
                        <th scope="col">Active</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($methods as $method)
                        <tr>
                            <td><code>{{ $method->code }}</code></td>
                            <td>{{ $method->name }}</td>
                            <td>{{ str_replace('_', ' ', ucfirst($method->method_type)) }}</td>
                            <td class="text-muted">{{ $method->cashBankAccount?->code ?? '—' }}</td>
                            <td>{{ $method->is_cash_drawer ? 'Yes' : 'No' }}</td>
                            <td>
                                <span class="badge bg-{{ $method->is_active ? 'success' : 'secondary' }}">
                                    {{ $method->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="text-end">
                                @can('tenant.payment-methods.update')
                                    <button type="button" class="btn btn-sm btn-light"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editModal{{ $method->id }}">
                                        Edit
                                    </button>
                                @endcan
                                @can('tenant.payment-methods.destroy')
                                    <form method="POST" action="{{ url('/payment-methods/' . $method->id) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('Delete this payment method?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No payment methods configured.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Right: Add new method form --}}
    @can('tenant.payment-methods.store')
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><strong>Add Payment Method</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ url('/payment-methods') }}" novalidate>
                    @csrf
                    <div class="mb-3">
                        <label for="code" class="form-label required">Code</label>
                        <input id="code" name="code" type="text" required
                               class="form-control @error('code') is-invalid @enderror"
                               value="{{ old('code') }}" placeholder="e.g. CASH">
                        @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-3">
                        <label for="name" class="form-label required">Name</label>
                        <input id="name" name="name" type="text" required
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name') }}" placeholder="e.g. Cash">
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-3">
                        <label for="method_type" class="form-label required">Type</label>
                        <select id="method_type" name="method_type" required
                                class="form-select @error('method_type') is-invalid @enderror">
                            <option value="cash"          @selected(old('method_type') === 'cash')>Cash</option>
                            <option value="card"          @selected(old('method_type') === 'card')>Card</option>
                            <option value="bank_transfer" @selected(old('method_type') === 'bank_transfer')>Bank Transfer</option>
                            <option value="cheque"        @selected(old('method_type') === 'cheque')>Cheque</option>
                            <option value="wallet"        @selected(old('method_type') === 'wallet')>Wallet</option>
                            <option value="other"         @selected(old('method_type') === 'other')>Other</option>
                        </select>
                        @error('method_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-3">
                        <label for="cash_bank_account_id" class="form-label">Linked Cash/Bank Account</label>
                        <select id="cash_bank_account_id" name="cash_bank_account_id"
                                class="form-select @error('cash_bank_account_id') is-invalid @enderror">
                            <option value="">— None —</option>
                            @foreach($cashBankAccounts as $cba)
                                <option value="{{ $cba->id }}" @selected(old('cash_bank_account_id') == $cba->id)>{{ $cba->code }} — {{ $cba->name }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">Where sales paid with this method are recorded in the ledger.</small>
                        @error('cash_bank_account_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-2 form-check">
                        <input class="form-check-input" type="checkbox" id="requires_reference"
                               name="requires_reference" value="1"
                               {{ old('requires_reference') ? 'checked' : '' }}>
                        <label class="form-check-label" for="requires_reference">Requires Reference</label>
                    </div>
                    <div class="mb-2 form-check">
                        <input class="form-check-input" type="checkbox" id="is_cash_drawer"
                               name="is_cash_drawer" value="1"
                               {{ old('is_cash_drawer') ? 'checked' : '' }}>
                        <label class="form-check-label" for="is_cash_drawer">Opens Cash Drawer</label>
                    </div>
                    <div class="mb-3 form-check">
                        <input class="form-check-input" type="checkbox" id="is_active"
                               name="is_active" value="1" checked
                               {{ old('is_active', '1') ? 'checked' : '' }}>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Add Method</button>
                </form>
            </div>
        </div>
    </div>
    @endcan
</div>

{{-- Edit Modals --}}
@can('tenant.payment-methods.update')
    @foreach($methods as $method)
    <div class="modal fade" id="editModal{{ $method->id }}" tabindex="-1"
         aria-labelledby="editModalLabel{{ $method->id }}" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ url('/payment-methods/' . $method->id) }}" novalidate>
                    @csrf @method('PUT')
                    <div class="modal-header">
                        <h5 class="modal-title" id="editModalLabel{{ $method->id }}">Edit: {{ $method->name }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body row g-3">
                        <div class="col-6">
                            <label for="edit-code-{{ $method->id }}" class="form-label required">Code</label>
                            <input id="edit-code-{{ $method->id }}" name="code" type="text" required
                                   class="form-control" value="{{ $method->code }}">
                        </div>
                        <div class="col-6">
                            <label for="edit-name-{{ $method->id }}" class="form-label required">Name</label>
                            <input id="edit-name-{{ $method->id }}" name="name" type="text" required
                                   class="form-control" value="{{ $method->name }}">
                        </div>
                        <div class="col-12">
                            <label for="edit-type-{{ $method->id }}" class="form-label required">Type</label>
                            <select id="edit-type-{{ $method->id }}" name="method_type" required class="form-select">
                                <option value="cash"          @selected($method->method_type === 'cash')>Cash</option>
                                <option value="card"          @selected($method->method_type === 'card')>Card</option>
                                <option value="bank_transfer" @selected($method->method_type === 'bank_transfer')>Bank Transfer</option>
                                <option value="cheque"        @selected($method->method_type === 'cheque')>Cheque</option>
                                <option value="wallet"        @selected($method->method_type === 'wallet')>Wallet</option>
                                <option value="other"         @selected($method->method_type === 'other')>Other</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="edit-cba-{{ $method->id }}" class="form-label">Linked Cash/Bank Account</label>
                            <select id="edit-cba-{{ $method->id }}" name="cash_bank_account_id" class="form-select">
                                <option value="">— None —</option>
                                @foreach($cashBankAccounts as $cba)
                                    <option value="{{ $cba->id }}" @selected($method->cash_bank_account_id == $cba->id)>{{ $cba->code }} — {{ $cba->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="edit-ref-{{ $method->id }}"
                                       name="requires_reference" value="1"
                                       {{ $method->requires_reference ? 'checked' : '' }}>
                                <label class="form-check-label" for="edit-ref-{{ $method->id }}">Requires Reference</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="edit-drawer-{{ $method->id }}"
                                       name="is_cash_drawer" value="1"
                                       {{ $method->is_cash_drawer ? 'checked' : '' }}>
                                <label class="form-check-label" for="edit-drawer-{{ $method->id }}">Opens Cash Drawer</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="edit-active-{{ $method->id }}"
                                       name="is_active" value="1"
                                       {{ $method->is_active ? 'checked' : '' }}>
                                <label class="form-check-label" for="edit-active-{{ $method->id }}">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endforeach
@endcan
@endsection
