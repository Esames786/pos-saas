@extends('layouts.app')

@section('title', 'New Recipe')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">New Recipe</h1>
    <a href="{{ url('/recipes') }}" class="btn btn-light">Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ url('/recipes') }}" id="recipeForm">
            @csrf

            @if($errors->any())
                <div class="alert alert-danger">{{ $errors->first() }}</div>
            @endif

            <h5 class="mb-3">Recipe Header</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label required">Finished Product</label>
                    <select name="product_id" class="form-select @error('product_id') is-invalid @enderror" required>
                        <option value="">— Select Product —</option>
                        @foreach($products as $p)
                            <option value="{{ $p->id }}" @selected(old('product_id') == $p->id)>{{ $p->name }} ({{ $p->sku }})</option>
                        @endforeach
                    </select>
                    @error('product_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label required">Recipe Name</label>
                    <input type="text" name="name" value="{{ old('name') }}"
                           class="form-control @error('name') is-invalid @enderror"
                           maxlength="190" required>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label required">Yield Quantity</label>
                    <input type="number" name="yield_quantity" value="{{ old('yield_quantity', 1) }}"
                           class="form-control @error('yield_quantity') is-invalid @enderror"
                           step="0.0001" min="0.0001" required>
                    @error('yield_quantity') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label">Yield Unit</label>
                    <select name="yield_unit_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($units as $u)
                            <option value="{{ $u->id }}" @selected(old('yield_unit_id') == $u->id)>{{ $u->name }} ({{ $u->code }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" value="{{ old('notes') }}"
                           class="form-control" maxlength="255">
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" value="1" id="is_active"
                               class="form-check-input" @checked(old('is_active', true))>
                        <label for="is_active" class="form-check-label">Active</label>
                    </div>
                </div>
            </div>

            <h5 class="mb-3">Ingredients</h5>
            <div id="ingredientRows">
                @if(old('ingredients'))
                    @foreach(old('ingredients') as $i => $ing)
                    <div class="ingredient-row row g-2 mb-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label @if($i===0) required @endif">Ingredient</label>
                            <select name="ingredients[{{ $i }}][product_id]" class="form-select" required>
                                <option value="">— Select —</option>
                                @foreach($products as $p)
                                    <option value="{{ $p->id }}" @selected($ing['product_id'] == $p->id)>{{ $p->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="ingredients[{{ $i }}][quantity]"
                                   value="{{ $ing['quantity'] ?? '' }}"
                                   class="form-control" step="0.0001" min="0.0001" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Unit</label>
                            <select name="ingredients[{{ $i }}][unit_id]" class="form-select">
                                <option value="">— Same as product —</option>
                                @foreach($units as $u)
                                    <option value="{{ $u->id }}" @selected(($ing['unit_id'] ?? '') == $u->id)>{{ $u->code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Cost Override</label>
                            <input type="number" name="ingredients[{{ $i }}][cost_override]"
                                   value="{{ $ing['cost_override'] ?? '' }}"
                                   class="form-control" step="0.0001" min="0" placeholder="Auto">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-outline-danger remove-row w-100">Remove</button>
                        </div>
                    </div>
                    @endforeach
                @else
                    <div class="ingredient-row row g-2 mb-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label required">Ingredient</label>
                            <select name="ingredients[0][product_id]" class="form-select" required>
                                <option value="">— Select —</option>
                                @foreach($products as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="ingredients[0][quantity]"
                                   class="form-control" step="0.0001" min="0.0001" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Unit</label>
                            <select name="ingredients[0][unit_id]" class="form-select">
                                <option value="">— Same as product —</option>
                                @foreach($units as $u)
                                    <option value="{{ $u->id }}">{{ $u->code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Cost Override</label>
                            <input type="number" name="ingredients[0][cost_override]"
                                   class="form-control" step="0.0001" min="0" placeholder="Auto">
                        </div>
                        <div class="col-md-2">
                            <button type="button" class="btn btn-outline-danger remove-row w-100">Remove</button>
                        </div>
                    </div>
                @endif
            </div>

            <button type="button" id="addIngredient" class="btn btn-outline-secondary btn-sm mt-1">+ Add Ingredient</button>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Recipe</button>
                <a href="{{ url('/recipes') }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const productsJson = @json($products->map(fn($p) => ['id' => $p->id, 'name' => $p->name]));
    const unitsJson    = @json($units->map(fn($u) => ['id' => $u->id, 'code' => $u->code]));

    function buildIngredientRow(idx) {
        const productOptions = productsJson.map(p =>
            `<option value="${p.id}">${p.name}</option>`
        ).join('');
        const unitOptions = '<option value="">— Same as product —</option>' +
            unitsJson.map(u => `<option value="${u.id}">${u.code}</option>`).join('');

        return `<div class="ingredient-row row g-2 mb-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Ingredient</label>
                <select name="ingredients[${idx}][product_id]" class="form-select" required>
                    <option value="">— Select —</option>${productOptions}
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Quantity</label>
                <input type="number" name="ingredients[${idx}][quantity]" class="form-control" step="0.0001" min="0.0001" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Unit</label>
                <select name="ingredients[${idx}][unit_id]" class="form-select">${unitOptions}</select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Cost Override</label>
                <input type="number" name="ingredients[${idx}][cost_override]" class="form-control" step="0.0001" min="0" placeholder="Auto">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-danger remove-row w-100">Remove</button>
            </div>
        </div>`;
    }

    let rowCount = document.querySelectorAll('.ingredient-row').length;

    document.getElementById('addIngredient').addEventListener('click', function () {
        const container = document.getElementById('ingredientRows');
        container.insertAdjacentHTML('beforeend', buildIngredientRow(rowCount++));
    });

    document.getElementById('ingredientRows').addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-row')) {
            const rows = document.querySelectorAll('.ingredient-row');
            if (rows.length > 1) {
                e.target.closest('.ingredient-row').remove();
            }
        }
    });
})();
</script>
@endpush
@endsection
