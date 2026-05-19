@extends('layouts.app')

@section('title', $recipe->name)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-0">{{ $recipe->name }}</h1>
        <div class="text-muted">{{ $recipe->product?->name }}</div>
    </div>
    <div class="d-flex gap-2">
        @can('tenant.recipes.edit')
            <a href="{{ url('/recipes/' . $recipe->id . '/edit') }}" class="btn btn-light">Edit</a>
        @endcan
        <a href="{{ url('/recipes') }}" class="btn btn-light">Back</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<div class="row g-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <dl class="mb-0">
                    <dt>Product</dt>
                    <dd>{{ $recipe->product?->name }}</dd>
                    <dt>Yield</dt>
                    <dd>{{ $recipe->yield_quantity }} {{ $recipe->yieldUnit?->name ?? $recipe->yieldUnit?->code }}</dd>
                    <dt>Status</dt>
                    <dd>
                        @if($recipe->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </dd>
                    <dt>Estimated Cost</dt>
                    <dd>{{ number_format($estimatedCost, 4) }}</dd>
                    @if($recipe->notes)
                    <dt>Notes</dt>
                    <dd>{{ $recipe->notes }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><strong>Ingredients</strong></div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Ingredient</th>
                            <th>Quantity</th>
                            <th>Unit</th>
                            <th>Cost Override</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recipe->ingredients as $ing)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td>
                                {{ $ing->product?->name }}
                                @if($ing->variant) <small class="text-muted">({{ $ing->variant->name }})</small> @endif
                            </td>
                            <td>{{ $ing->quantity }}</td>
                            <td>{{ $ing->unit?->code ?? $ing->product?->unit?->code ?? '—' }}</td>
                            <td>{{ $ing->cost_override !== null ? number_format($ing->cost_override, 4) : '—' }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted py-3">No ingredients.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
