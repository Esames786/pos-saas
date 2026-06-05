@extends('layouts.app')

@section('title', 'Promotions')

@section('content')
        <div class="page-header">
            <div class="page-title">
                <h4>Promotions</h4>
                <h6>Manage discount codes and automatic promotions</h6>
            </div>
            @can('tenant.promotions.create')
            <div class="page-btn">
                <a href="{{ url('/promotions/create') }}" class="btn btn-added">
                    <i class="ti ti-plus me-1"></i>Add Promotion
                </a>
            </div>
            @endcan
        </div>

        @if(session('status'))
            <div class="alert alert-success alert-dismissible fade show">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        @endif

        <div class="card table-list-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table datanew">
                        <caption class="visually-hidden">Promotions list</caption>
                        <thead class="thead-light">
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Code</th>
                                <th scope="col">Type</th>
                                <th scope="col">Discount</th>
                                <th scope="col">Order Types</th>
                                <th scope="col">Used</th>
                                <th scope="col">Status</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($promotions as $promo)
                            <tr>
                                <td>{{ $promo->name }}</td>
                                <td>@if($promo->code)<code>{{ $promo->code }}</code>@else<span class="text-muted">Auto</span>@endif</td>
                                <td>{{ ucfirst($promo->promotion_type) }}</td>
                                <td>
                                    @if($promo->discount_type === 'percent')
                                        {{ $promo->discount_value }}%
                                    @else
                                        {{ number_format($promo->discount_value, 2) }}
                                    @endif
                                </td>
                                <td>
                                    @if($promo->order_types)
                                        {{ implode(', ', $promo->order_types) }}
                                    @else
                                        <span class="text-muted">All</span>
                                    @endif
                                </td>
                                <td>{{ $promo->used_count }}@if($promo->usage_limit) / {{ $promo->usage_limit }}@endif</td>
                                <td>
                                    <span class="badge bg-{{ $promo->status === 'active' ? 'success' : 'secondary' }}">
                                        {{ ucfirst($promo->status) }}
                                    </span>
                                </td>
                                <td>
                                    @can('tenant.promotions.edit')
                                    <a href="{{ url('/promotions/' . $promo->id . '/edit') }}" class="btn btn-sm btn-outline-secondary me-1">
                                        <i class="ti ti-pencil"></i>
                                    </a>
                                    @endcan
                                    @can('tenant.promotions.destroy')
                                    <form method="POST" action="{{ url('/promotions/' . $promo->id) }}" class="d-inline" onsubmit="return confirm('Delete this promotion?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
                                    </form>
                                    @endcan
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">No promotions found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $promotions->links() }}</div>
            </div>
        </div>
@endsection
