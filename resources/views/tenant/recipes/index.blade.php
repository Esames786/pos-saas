@extends('layouts.app')

@section('title', 'Recipes / BOM')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Recipes / BOM</h1>
    @can('tenant.recipes.create')
        <a href="{{ url('/recipes/create') }}" class="btn btn-primary">New Recipe</a>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<div class="card">
    <div class="card-body pb-0">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4">
                <select name="product_id" class="form-select">
                    <option value="">All Products</option>
                    @foreach($products as $p)
                        <option value="{{ $p->id }}" @selected(request('product_id') == $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select name="is_active" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="1" @selected(request('is_active') === '1')>Active</option>
                    <option value="0" @selected(request('is_active') === '0')>Inactive</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-light">Filter</button>
                <a href="{{ url('/recipes') }}" class="btn btn-light">Clear</a>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Recipe Name</th>
                    <th>Product</th>
                    <th>Yield</th>
                    <th>Ingredients</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($recipes as $recipe)
                <tr>
                    <td><a href="{{ url('/recipes/' . $recipe->id) }}">{{ $recipe->name }}</a></td>
                    <td>{{ $recipe->product?->name }}</td>
                    <td>{{ $recipe->yield_quantity }} {{ $recipe->yieldUnit?->code }}</td>
                    <td>{{ $recipe->ingredients_count ?? '—' }}</td>
                    <td>
                        @if($recipe->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @can('tenant.recipes.edit')
                            <a href="{{ url('/recipes/' . $recipe->id . '/edit') }}" class="btn btn-sm btn-light">Edit</a>
                        @endcan
                        @can('tenant.recipes.destroy')
                            <form method="POST" action="{{ url('/recipes/' . $recipe->id) }}"
                                  class="d-inline" onsubmit="return confirm('Delete recipe?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        @endcan
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No recipes found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $recipes->links() }}
@endsection
