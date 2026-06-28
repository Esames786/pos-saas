@extends('layouts.app')

@section('title', 'Edit Recipe — ' . $recipe->name)

@section('content')
<style>
    .os-scope-menu .os-item { cursor:pointer; }
    .os-scope-menu .os-item:hover { background:#f1f3f5; }
    .os-scope-menu .os-check { visibility:hidden; color:#0d6efd; }
    .os-scope-menu .ot-check:checked ~ .os-check { visibility:visible; }
    .os-scope-menu .ot-check:checked ~ span { font-weight:600; }
</style>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Edit Recipe</h1>
    <div class="d-flex gap-2">
        <a href="{{ url('/recipes/' . $recipe->id) }}" class="btn btn-light">View</a>
        <a href="{{ url('/recipes') }}" class="btn btn-light">Back</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ url('/recipes/' . $recipe->id) }}" id="recipeForm">
            @csrf @method('PUT')

            @if($errors->any())
                <div class="alert alert-danger">{{ $errors->first() }}</div>
            @endif

            <div class="alert alert-light border small mb-4">
                <i class="ti ti-info-circle me-1"></i>
                Use this screen to define the ingredients and packaging needed to prepare/sell this item.
                <strong>Food Cost</strong> lines are used in every order by default; <strong>Packing Material</strong>
                can be limited to Takeaway/Delivery. This screen defines the recipe and costing — actual stock is
                consumed when a sale/production is completed.
            </div>

            <h5 class="mb-3">Recipe Header</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label required">Finished Product</label>
                    <select name="product_id" class="form-select @error('product_id') is-invalid @enderror" required>
                        <option value="">— Select Product —</option>
                        @foreach($products as $p)
                            <option value="{{ $p->id }}" @selected(old('product_id', $recipe->product_id) == $p->id)>{{ $p->name }} ({{ $p->sku }})</option>
                        @endforeach
                    </select>
                    @error('product_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label required">Recipe Name</label>
                    <input type="text" name="name" value="{{ old('name', $recipe->name) }}"
                           class="form-control @error('name') is-invalid @enderror"
                           maxlength="190" required>
                    @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label required">Yield Quantity</label>
                    <input type="number" name="yield_quantity" value="{{ old('yield_quantity', $recipe->yield_quantity) }}"
                           class="form-control" step="0.0001" min="0.0001" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Yield Unit</label>
                    <select name="yield_unit_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($units as $u)
                            <option value="{{ $u->id }}" @selected(old('yield_unit_id', $recipe->yield_unit_id) == $u->id)>{{ $u->name }} ({{ $u->code }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" value="{{ old('notes', $recipe->notes) }}"
                           class="form-control" maxlength="255">
                </div>

                {{-- KITCHEN-RECIPE-COST-1 report header --}}
                <div class="col-md-2">
                    <label class="form-label">Doc #</label>
                    <input type="text" name="doc_no" value="{{ old('doc_no', $recipe->doc_no) }}" class="form-control" maxlength="100">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Recipe #</label>
                    <input type="text" name="recipe_no" value="{{ old('recipe_no', $recipe->recipe_no) }}" class="form-control" maxlength="100">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Revision #</label>
                    <input type="number" name="revision_no" value="{{ old('revision_no', $recipe->revision_no ?? 1) }}" class="form-control" min="1">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Review Date</label>
                    <input type="date" name="review_date" value="{{ old('review_date', optional($recipe->review_date)->format('Y-m-d')) }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Overhead %</label>
                    <input type="number" name="overhead_percent" value="{{ old('overhead_percent', $recipe->overhead_percent ?? 0) }}" class="form-control" step="0.0001" min="0" max="1000">
                    <div class="form-help">Added on top of recipe cost (gas/labour). 0 = none.</div>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" value="1" id="is_active"
                               class="form-check-input" @checked(old('is_active', $recipe->is_active))>
                        <label for="is_active" class="form-check-label">Active</label>
                    </div>
                </div>
            </div>

            <h5 class="mb-3 mt-4">Ingredients</h5>
            <div class="form-text mb-2">
                <strong>Section:</strong> Food Cost = edible ingredients · Packing Material = containers, foil, bags · Other = optional costing items.
                <strong>Order Scope</strong> decides when a line is used — e.g. packing usually applies to Takeaway + Delivery.
            </div>
            <div id="ingredientRows">
                @php $ingList = old('ingredients', $recipe->ingredients->toArray()); @endphp
                @foreach($ingList as $i => $ing)
                <div class="ingredient-row row g-2 mb-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Ingredient</label>
                        <select name="ingredients[{{ $i }}][product_id]" class="form-select" required>
                            <option value="">— Select —</option>
                            @foreach($products as $p)
                                <option value="{{ $p->id }}" @selected(($ing['product_id'] ?? '') == $p->id)>{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Section</label>
                        <select name="ingredients[{{ $i }}][line_section]" class="form-select">
                            @foreach(\App\Models\Tenant\RecipeIngredient::SECTIONS as $sv => $sl)
                                <option value="{{ $sv }}" @selected(($ing['line_section'] ?? 'food_cost') === $sv)>{{ $sl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Quantity</label>
                        <input type="number" name="ingredients[{{ $i }}][quantity]"
                               value="{{ $ing['quantity'] ?? '' }}"
                               class="form-control" step="0.0001" min="0.0001" required>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Unit</label>
                        <select name="ingredients[{{ $i }}][unit_id]" class="form-select">
                            <option value="">— Same —</option>
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
                    @include('tenant.recipes._order_scope', ['i' => $i, 'otSel' => $ing['applicable_order_types'] ?? []])
                </div>
                @endforeach
            </div>

            <button type="button" id="addIngredient" class="btn btn-outline-secondary btn-sm mt-1">+ Add Ingredient</button>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Update Recipe</button>
                <a href="{{ url('/recipes/' . $recipe->id) }}" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const productsJson = @json($products->map(fn($p) => ['id' => $p->id, 'name' => $p->name]));
    const unitsJson    = @json($units->map(fn($u) => ['id' => $u->id, 'code' => $u->code]));

    const sectionsJson   = @json(\App\Models\Tenant\RecipeIngredient::SECTIONS);
    const orderTypesJson = @json(\App\Models\Tenant\RecipeIngredient::ORDER_TYPES);

    function orderScopeHtml(idx) {
        const items = Object.entries(orderTypesJson).map(([v, l]) =>
            `<label class="os-item d-flex justify-content-between align-items-center px-2 py-1 rounded mb-0">
                <input type="checkbox" class="ot-check d-none" name="ingredients[${idx}][applicable_order_types][]" value="${v}" ${v === 'all' ? 'checked' : ''}>
                <span class="small">${l}</span>
                <i class="ti ti-check os-check ms-3"></i>
            </label>`
        ).join('');
        return `<div class="col-12 ot-wrap d-flex align-items-center flex-wrap gap-2">
            <small class="text-muted"><i class="ti ti-receipt me-1"></i>Order Scope:</small>
            <div class="os-scope position-relative">
                <button type="button" class="btn btn-sm btn-outline-secondary os-scope-btn d-inline-flex align-items-center">
                    <span class="os-scope-label">All Orders</span><i class="ti ti-chevron-down ms-2"></i>
                </button>
                <div class="os-scope-menu border rounded bg-white shadow-sm p-1" style="display:none; position:absolute; z-index:1050; min-width:190px;">${items}</div>
            </div>
        </div>`;
    }

    function updateScopeLabel(scope) {
        const label = scope.querySelector('.os-scope-label');
        if (!label) return;
        const specifics = Array.prototype.filter.call(scope.querySelectorAll('.ot-check'), c => c.value !== 'all' && c.checked);
        label.textContent = specifics.length ? specifics.map(c => orderTypesJson[c.value] || c.value).join(', ') : 'All Orders';
    }

    function buildIngredientRow(idx) {
        const productOptions = productsJson.map(p =>
            `<option value="${p.id}">${p.name}</option>`
        ).join('');
        const unitOptions = '<option value="">— Same —</option>' +
            unitsJson.map(u => `<option value="${u.id}">${u.code}</option>`).join('');
        const sectionOptions = Object.entries(sectionsJson).map(([v, l]) =>
            `<option value="${v}">${l}</option>`
        ).join('');

        return `<div class="ingredient-row row g-2 mb-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Ingredient</label>
                <select name="ingredients[${idx}][product_id]" class="form-select" required>
                    <option value="">— Select —</option>${productOptions}
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Section</label>
                <select name="ingredients[${idx}][line_section]" class="form-select">${sectionOptions}</select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Quantity</label>
                <input type="number" name="ingredients[${idx}][quantity]" class="form-control" step="0.0001" min="0.0001" required>
            </div>
            <div class="col-md-1">
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
            ${orderScopeHtml(idx)}
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

    // UX-POLISH-1: multi-select Order Scope. "All" stays exclusive vs specific types.
    document.getElementById('ingredientRows').addEventListener('change', function (e) {
        if (!e.target.classList.contains('ot-check')) return;
        const scope = e.target.closest('.os-scope');
        const checks = scope.querySelectorAll('.ot-check');
        const allBox = scope.querySelector('.ot-check[value="all"]');
        if (e.target.value === 'all') {
            if (e.target.checked) checks.forEach(c => { if (c !== allBox) c.checked = false; });
        } else {
            if (e.target.checked && allBox) allBox.checked = false;
            const anySpecific = Array.prototype.some.call(checks, c => c.value !== 'all' && c.checked);
            if (!anySpecific && allBox) allBox.checked = true;
        }
        updateScopeLabel(scope);
    });

    // Toggle a row's Order Scope dropdown; close any others / on outside click.
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.os-scope-btn');
        const inMenu = e.target.closest('.os-scope-menu');
        document.querySelectorAll('.os-scope').forEach(function (scope) {
            const menu = scope.querySelector('.os-scope-menu');
            if (!menu) return;
            if (btn && scope.contains(btn)) {
                menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
            } else if (!(inMenu && scope.contains(e.target))) {
                menu.style.display = 'none';
            }
        });
    });

    // Initialise existing rows' summary labels.
    document.querySelectorAll('.os-scope').forEach(updateScopeLabel);
})();
</script>
@endpush
@endsection
