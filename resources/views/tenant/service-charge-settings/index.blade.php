@extends('layouts.app')

@section('title', 'Service Charge Settings')

@section('content')
<div class="page-wrapper">
    <div class="content">
        <div class="page-header">
            <div class="page-title">
                <h4>Service Charge Settings</h4>
                <h6>Configure per-branch service charges</h6>
            </div>
        </div>

        @if(session('status'))
            <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif

        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ url('/service-charge-settings') }}">
                    @csrf
                    @if($errors->any())
                        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
                    @endif

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label required" for="branch_id">Branch</label>
                            <select id="branch_id" name="branch_id" class="form-select" required>
                                <option value="">— Select Branch —</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label required" for="charge_type">Type</label>
                            <select id="charge_type" name="charge_type" class="form-select" required>
                                <option value="percent">Percent (%)</option>
                                <option value="fixed">Fixed Amount</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label required" for="charge_value">Value</label>
                            <input type="number" id="charge_value" name="charge_value" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-2 mb-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Save</button>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label">Applicable Order Types <small class="text-muted">(leave unchecked for all)</small></label>
                            <div class="d-flex gap-3">
                                @foreach(['quick_sale' => 'Quick Sale', 'takeaway' => 'Takeaway', 'dine_in' => 'Dine-in', 'delivery' => 'Delivery'] as $v => $l)
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="order_types[]" value="{{ $v }}" id="sc_{{ $v }}">
                                        <label class="form-check-label" for="sc_{{ $v }}">{{ $l }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_taxable" id="is_taxable" value="1">
                                <label class="form-check-label" for="is_taxable">Service charge is taxable</label>
                            </div>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" checked>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        @if($settings->isNotEmpty())
        <div class="card mt-3">
            <div class="card-header"><h6 class="mb-0">Current Settings</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Branch</th>
                            <th scope="col">Type</th>
                            <th scope="col">Value</th>
                            <th scope="col">Order Types</th>
                            <th scope="col">Taxable</th>
                            <th scope="col">Active</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($settings as $s)
                        <tr>
                            <td>{{ $s->branch?->name }}</td>
                            <td>{{ ucfirst($s->charge_type) }}</td>
                            <td>{{ $s->charge_type === 'percent' ? $s->charge_value . '%' : number_format($s->charge_value, 2) }}</td>
                            <td>{{ $s->order_types ? implode(', ', $s->order_types) : 'All' }}</td>
                            <td>{{ $s->is_taxable ? 'Yes' : 'No' }}</td>
                            <td><span class="badge bg-{{ $s->is_active ? 'success' : 'secondary' }}">{{ $s->is_active ? 'Yes' : 'No' }}</span></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
