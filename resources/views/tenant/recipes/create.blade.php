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

                {{-- KITCHEN-RECIPE-COST-1 report header --}}
                <div class="col-md-2">
                    <label class="form-label">Doc #</label>
                    <input type="text" name="doc_no" value="{{ old('doc_no') }}" class="form-control" maxlength="100">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Recipe #</label>
                    <input type="text" name="recipe_no" value="{{ old('recipe_no') }}" class="form-control" maxlength="100">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Revision #</label>
                    <input type="number" name="revision_no" value="{{ old('revision_no', 1) }}" class="form-control" min="1">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Review Date</label>
                    <input type="date" name="review_date" value="{{ old('review_date') }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Overhead %</label>
                    <input type="number" name="overhead_percent" value="{{ old('overhead_percent', 0) }}" class="form-control" step="0.0001" min="0" max="1000">
                    <div class="form-help">Added on top of recipe cost (gas/labour). 0 = none.</div>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" value="1" id="is_active"
                               class="form-check-input" @checked(old('is_active', true))>
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
                @if(old('ingredients'))
                    @foreach(old('ingredients') as $i => $ing)
                    <div class="ingredient-row row g-2 mb-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label @if($i===0) required @endif">Ingredient</label>
                            <select name="ingredients[{{ $i }}][product_id]" class="form-select" required>
                                <option value="">— Select —</option>
                                @foreach($products as $p)
                                    <option value="{{ $p->id }}" @selected($ing['product_id'] == $p->id)>{{ $p->name }}</option>
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
                @else
                    <div class="ingredient-row row g-2 mb-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label required">Ingredient</label>
                            <select name="ingredients[0][product_id]" class="form-select" required>
                                <option value="">— Select —</option>
                                @foreach($products as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Section</label>
                            <select name="ingredients[0][line_section]" class="form-select">
                                @foreach(\App\Models\Tenant\RecipeIngredient::SECTIONS as $sv => $sl)
                                    <option value="{{ $sv }}">{{ $sl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="ingredients[0][quantity]"
                                   class="form-control" step="0.0001" min="0.0001" required>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">Unit</label>
                            <select name="ingredients[0][unit_id]" class="form-select">
                                <option value="">— Same —</option>
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
                        @include('tenant.recipes._order_scope', ['i' => 0, 'otSel' => []])
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

    const sectionsJson    = @json(\App\Models\Tenant\RecipeIngredient::SECTIONS);
    const orderTypesJson  = @json(\App\Models\Tenant\RecipeIngredient::ORDER_TYPES);

    const presetsJson = { all:'All Orders', dine_in:'Dine In only', takeaway_delivery:'Takeaway + Delivery', takeaway:'Takeaway only', delivery:'Delivery only', quick_sale:'Quick Sale only', custom:'Custom…' };
    const PRESET_MAP = { all:[], dine_in:['dine_in'], takeaway_delivery:['takeaway','delivery'], takeaway:['takeaway'], delivery:['delivery'], quick_sale:['quick_sale'] };

    function orderScopeHtml(idx) {
        const presetOptions = Object.entries(presetsJson).map(([v, l]) => `<option value="${v}">${l}</option>`).join('');
        const checks = Object.entries(orderTypesJson).map(([v, l]) =>
            `<label class="me-2 small mb-0"><input type="checkbox" class="form-check-input ot-check me-1" name="ingredients[${idx}][applicable_order_types][]" value="${v}" ${v === 'all' ? 'checked' : ''}>${l}</label>`
        ).join('');
        return `<div class="col-12 ot-wrap d-flex align-items-center flex-wrap gap-2">
            <small class="text-muted"><i class="ti ti-receipt me-1"></i>Order Scope:</small>
            <select class="form-select form-select-sm os-preset" style="width:auto">${presetOptions}</select>
            <span class="os-detail d-flex flex-wrap gap-2" style="display:none">${checks}</span>
        </div>`;
    }

    function applyPreset(row) {
        const sel = row.querySelector('.os-preset'); if (!sel) return;
        const preset = sel.value;
        const detail = row.querySelector('.os-detail');
        const boxes = row.querySelectorAll('.ot-check');
        if (preset === 'custom') { if (detail) detail.style.display = ''; return; }
        if (detail) detail.style.display = 'none';
        const want = PRESET_MAP[preset] || [];
        boxes.forEach(b => { b.checked = (b.value === 'all') ? (preset === 'all') : (want.indexOf(b.value) !== -1); });
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

    // UX-POLISH-1: Order Scope preset drives the underlying checkboxes; "All" stays
    // exclusive vs specific types when editing the Custom pills directly.
    document.getElementById('ingredientRows').addEventListener('change', function (e) {
        if (e.target.classList.contains('os-preset')) {
            applyPreset(e.target.closest('.ingredient-row'));
            return;
        }
        if (!e.target.classList.contains('ot-check')) return;
        const row = e.target.closest('.ingredient-row');
        const checks = row.querySelectorAll('.ot-check');
        const allBox = row.querySelector('.ot-check[value="all"]');
        if (e.target.value === 'all') {
            if (e.target.checked) checks.forEach(c => { if (c !== allBox) c.checked = false; });
        } else {
            if (e.target.checked && allBox) allBox.checked = false;
            const anySpecific = Array.prototype.some.call(checks, c => c.value !== 'all' && c.checked);
            if (!anySpecific && allBox) allBox.checked = true;
        }
    });
})();
</script>
@endpush
@endsection
