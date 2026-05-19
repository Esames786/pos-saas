@extends('layouts.app')

@section('title', $kitchenProduction->production_no)

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h1 class="mb-0">{{ $kitchenProduction->production_no }}</h1>
        <div class="text-muted">{{ $kitchenProduction->recipe?->name }} — {{ $kitchenProduction->recipe?->product?->name }}</div>
    </div>
    <a href="{{ url('/kitchen/productions') }}" class="btn btn-light">Back</a>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="row g-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                @php $colors = ['planned'=>'secondary','in_progress'=>'warning','completed'=>'success','cancelled'=>'danger']; @endphp
                <dl class="mb-0">
                    <dt>Status</dt>
                    <dd><span class="badge bg-{{ $colors[$kitchenProduction->status] ?? 'secondary' }} fs-6">
                        {{ ucwords(str_replace('_',' ',$kitchenProduction->status)) }}
                    </span></dd>
                    <dt>Branch</dt>
                    <dd>{{ $kitchenProduction->branch?->name }}</dd>
                    <dt>Production Date</dt>
                    <dd>{{ $kitchenProduction->production_date?->format('d M Y') }}</dd>
                    <dt>Qty Produced</dt>
                    <dd>{{ $kitchenProduction->quantity_produced }} {{ $kitchenProduction->yieldUnit?->code }}</dd>
                    <dt>Produced By</dt>
                    <dd>{{ $kitchenProduction->producedBy?->name ?? '—' }}</dd>
                    @if($kitchenProduction->notes)
                    <dt>Notes</dt>
                    <dd>{{ $kitchenProduction->notes }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><strong>Ingredients</strong></div>

            @if(in_array($kitchenProduction->status, ['planned', 'in_progress']))
            <div class="card-body border-bottom">
                <form method="POST" action="{{ url('/kitchen/productions/' . $kitchenProduction->id . '/complete') }}">
                    @csrf
                    <p class="mb-2 text-muted">Enter actual quantities used to complete this production:</p>
                    @foreach($kitchenProduction->ingredients as $ing)
                    <div class="row g-2 mb-2 align-items-center">
                        <div class="col-md-5">
                            <span>{{ $ing->product?->name }}</span>
                            @if($ing->variant) <small class="text-muted">({{ $ing->variant->name }})</small> @endif
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted">Required: {{ $ing->quantity_required }} {{ $ing->unit?->code }}</span>
                        </div>
                        <div class="col-md-4">
                            <div class="input-group input-group-sm">
                                <input type="number" name="usages[{{ $ing->id }}]"
                                       value="{{ $ing->quantity_required }}"
                                       class="form-control" step="0.0001" min="0">
                                <span class="input-group-text">{{ $ing->unit?->code }}</span>
                            </div>
                        </div>
                    </div>
                    @endforeach
                    @can('tenant.kitchen.productions.complete')
                    <button type="submit" class="btn btn-success mt-2"
                            onclick="return confirm('Complete this production? Stock will be adjusted.')">
                        Complete Production
                    </button>
                    @endcan
                </form>
            </div>
            @endif

            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Ingredient</th>
                            <th>Qty Required</th>
                            <th>Qty Used</th>
                            <th>Unit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($kitchenProduction->ingredients as $ing)
                        <tr>
                            <td>
                                {{ $ing->product?->name }}
                                @if($ing->variant) <small>({{ $ing->variant->name }})</small> @endif
                            </td>
                            <td>{{ $ing->quantity_required }}</td>
                            <td>{{ $ing->quantity_used > 0 ? $ing->quantity_used : '—' }}</td>
                            <td>{{ $ing->unit?->code }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No ingredients.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
