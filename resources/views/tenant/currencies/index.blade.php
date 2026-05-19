@extends('layouts.app')

@section('title', 'Currencies')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-1">Currencies &amp; Denominations</h1>
        <p class="fw-medium">Configure accepted currencies and cash denominations for counting.</p>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif

<div class="row g-4">
    {{-- Add Currency --}}
    @can('tenant.currencies.store')
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><h5 class="mb-0">Add Currency</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ url('/currencies') }}" class="row g-3" novalidate>
                    @csrf

                    <div class="col-md-4">
                        <label for="currency-code" class="form-label required">ISO Code</label>
                        <input type="text" id="currency-code" name="code"
                            value="{{ old('code') }}" class="form-control"
                            maxlength="3" required placeholder="PKR" style="text-transform:uppercase">
                    </div>

                    <div class="col-md-8">
                        <label for="currency-name" class="form-label required">Name</label>
                        <input type="text" id="currency-name" name="name"
                            value="{{ old('name') }}" class="form-control"
                            required maxlength="190" placeholder="Pakistani Rupee">
                    </div>

                    <div class="col-md-4">
                        <label for="currency-symbol" class="form-label required">Symbol</label>
                        <input type="text" id="currency-symbol" name="symbol"
                            value="{{ old('symbol') }}" class="form-control"
                            required maxlength="10" placeholder="Rs">
                    </div>

                    <div class="col-md-4">
                        <label for="decimal-places" class="form-label required">Decimals</label>
                        <input type="number" id="decimal-places" name="decimal_places"
                            value="{{ old('decimal_places', 2) }}" class="form-control"
                            required min="0" max="4">
                    </div>

                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch"
                                id="is-default" name="is_default" value="1"
                                @checked(old('is_default'))>
                            <label class="form-check-label" for="is-default">Set as Default</label>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="ti ti-plus me-1" aria-hidden="true"></i>Add Currency
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endcan

    {{-- Currency list --}}
    <div class="col-lg-7">
        @forelse($currencies as $currency)
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div>
                        <strong>{{ $currency->code }}</strong> — {{ $currency->name }}
                        <span class="text-muted ms-2">{{ $currency->symbol }}</span>
                        @if($currency->is_default)
                            <span class="badge bg-success ms-2">Default</span>
                        @endif
                        @if(!$currency->is_active)
                            <span class="badge bg-secondary ms-1">Inactive</span>
                        @endif
                    </div>
                    @if(!$currency->is_default)
                        @can('tenant.currencies.default')
                            <form method="POST" action="{{ url('/currencies/' . $currency->id . '/default') }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-success">Set Default</button>
                            </form>
                        @endcan
                    @endif
                </div>

                <div class="card-body">
                    {{-- Denominations table --}}
                    @if($currency->denominations->count())
                        <table class="table table-sm mb-3">
                            <caption class="visually-hidden">Denominations for {{ $currency->name }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Value</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Active</th>
                                    <th scope="col" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($currency->denominations->sortByDesc('denomination_value') as $denom)
                                <tr>
                                    <td>{{ $currency->symbol }} {{ number_format($denom->denomination_value, 2) }}</td>
                                    <td>{{ ucfirst($denom->denomination_type) }}</td>
                                    <td>{{ $denom->is_active ? 'Yes' : 'No' }}</td>
                                    <td class="text-end">
                                        @can('tenant.currency-denominations.destroy')
                                            <form method="POST"
                                                  action="{{ url('/currency-denominations/' . $denom->id) }}"
                                                  class="d-inline"
                                                  onsubmit="return confirm('Delete this denomination?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="text-muted small mb-3">No denominations added yet.</p>
                    @endif

                    {{-- Add denomination --}}
                    @can('tenant.currency-denominations.store')
                        <form method="POST"
                              action="{{ url('/currencies/' . $currency->id . '/denominations') }}"
                              class="row g-2 align-items-end"
                              novalidate>
                            @csrf
                            <div class="col-auto">
                                <label for="denom-value-{{ $currency->id }}" class="form-label required visually-hidden">
                                    Denomination value
                                </label>
                                <input type="number" id="denom-value-{{ $currency->id }}"
                                    name="denomination_value" class="form-control form-control-sm"
                                    min="0.01" step="0.01" required placeholder="Value"
                                    aria-label="Denomination value for {{ $currency->name }}">
                            </div>
                            <div class="col-auto">
                                <label for="denom-type-{{ $currency->id }}" class="form-label required visually-hidden">Type</label>
                                <select id="denom-type-{{ $currency->id }}" name="denomination_type"
                                    class="form-select form-select-sm" required
                                    aria-label="Denomination type for {{ $currency->name }}">
                                    <option value="note">Note</option>
                                    <option value="coin">Coin</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-dark">Add</button>
                            </div>
                        </form>
                    @endcan
                </div>
            </div>
        @empty
            <div class="alert alert-info" role="status">
                No currencies configured. Add your first currency.
            </div>
        @endforelse
    </div>
</div>
@endsection
