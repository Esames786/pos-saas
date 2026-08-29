@extends('layouts.app')

@section('title', 'Catering Products')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Catering Products</h1>
    @can('tenant.catering.profiles.store')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#profileModal" id="new-profile-btn">
            <i class="ti ti-plus me-1"></i>Add Catering Product
        </button>
    @endcan
</div>

@include('tenant.catering.partials.tooltips')
@include('tenant.catering.partials.screen-impact', ['manages' => 'Which dishes can be quoted, and their serving size, kitchen station and instructions.', 'managesUr' => 'کون سی ڈشیں تخمینے میں آ سکتی ہیں، اور ان کی تفصیل۔', 'reversible' => 'safe', 'note' => 'Editing a profile does not reprice an estimate that is already sent.', 'noteUr' => 'یہاں تبدیلی سے بھیجا ہوا تخمینہ دوبارہ قیمت نہیں لگاتا۔'])
@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card">
    <div class="card-body pb-0">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control" placeholder="Search product / SKU…" value="{{ $search }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-light">Search</button>
                <a href="{{ url('/catering/profiles') }}" class="btn btn-light">Clear</a>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Urdu Name</th>
                        <th>How it is priced</th>
                        <th>Costing Source</th>
                        <th class="text-end">Default Rate</th>
                        <th>Quote Unit</th>
                        <th>Station</th>
                        <th>Enabled</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($profiles as $profile)
                    @php
                        $urName = optional($profile->product->translations->firstWhere('language_code', 'ur'))->name;
                        // Build the payload in PHP, NOT inline inside @json(...):
                        // Blade matches a directive argument with a RECURSIVE paren regex.
                        // On a long multi-line payload that match hits PCRE limits and
                        // SILENTLY TRUNCATES — it emitted only 3 of 12 array items and
                        // an unbalanced bracket, i.e. invalid PHP, while `view:cache`
                        // still reported success.
                        $profilePayload = [
                            'id' => $profile->id,
                            'product_name' => $profile->product->name,
                            'catering_enabled' => (bool) $profile->catering_enabled,
                            'allow_party_supply' => (bool) ($profile->allow_party_supply ?? true),
                            'is_complimentary' => (bool) ($profile->is_complimentary ?? false),
                            'default_quote_unit_id' => $profile->default_quote_unit_id,
                            'pricing_mode' => $profile->pricing_mode,
                            'costing_mode' => $profile->costingMode(),
                            // What the OTHER source still holds. Switching never
                            // deletes it, and the operator is told so rather than
                            // left to wonder.
                            'recipe_ingredients' => $profile->product->activeRecipe?->ingredients->count() ?? 0,
                            'block_count' => (int) ($profile->active_block_count ?? 0),
                            'default_catering_rate' => $profile->default_catering_rate,
                            'production_station' => $profile->production_station,
                            'minimum_qty' => $profile->minimum_qty,
                            'production_label' => $profile->production_label,
                            'production_label_ur' => $profile->production_label_ur,
                            'instructions' => $profile->instructions,
                            'name_ur' => $urName,
                        ];
                    @endphp
                    <tr>
                        <td>
                            {{ $profile->product->name }}
                            <div class="text-muted fs-12">{{ $profile->product->sku }}</div>
                        </td>
                        <td dir="rtl" lang="ur">{{ $urName }}</td>
                        <td><span class="text-muted fs-13">Quantity &times; rate</span></td>
                        <td>
                            @if($profile->usesBlocks())
                                <span class="badge bg-info-subtle text-info-emphasis">Cost Blocks</span>
                                <div class="fs-12 text-muted">
                                    {{ $profilePayload['block_count'] }} block{{ $profilePayload['block_count'] === 1 ? '' : 's' }}
                                    @if($profilePayload['recipe_ingredients'] > 0)
                                        · recipe kept
                                    @endif
                                </div>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Recipe</span>
                                <div class="fs-12 text-muted">
                                    {{ $profilePayload['recipe_ingredients'] }} ingredient{{ $profilePayload['recipe_ingredients'] === 1 ? '' : 's' }}
                                    @if($profilePayload['block_count'] > 0)
                                        · blocks kept
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="text-end">{{ $profile->default_catering_rate !== null ? number_format($profile->default_catering_rate, 2) : '—' }}</td>
                        <td>{{ $profile->defaultQuoteUnit?->code ?? $profile->product->unit?->code ?? '—' }}</td>
                        <td>{{ $profile->production_station ?? '—' }}</td>
                        <td><span class="badge bg-{{ $profile->catering_enabled ? 'success' : 'secondary' }}">{{ $profile->catering_enabled ? 'Yes' : 'No' }}</span></td>
                        <td class="text-end">
                            @can('tenant.catering.cost-blocks.edit')
                                <a href="{{ url('/catering/profiles/' . $profile->id . '/blocks') }}"
                                   class="btn btn-sm btn-light" data-bs-toggle="tooltip"
                                   title="What this dish is made of, and what each part adds to its price.">
                                    Cost Blocks
                                </a>
                            @endcan
                            @can('tenant.catering.profiles.update')
                                <button class="btn btn-sm btn-light edit-profile"
                                        data-profile='@json($profilePayload)'>Edit</button>
                            @endcan
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No catering products configured yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($profiles->hasPages())
        <div class="card-footer">{{ $profiles->links() }}</div>
    @endif
</div>

{{-- Add / edit modal --}}
<div class="modal fade" id="profileModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" id="profile-form" action="{{ url('/catering/profiles') }}" class="modal-content">
            @csrf
            <input type="hidden" name="_method" id="profile-method" value="POST">
            <div class="modal-header">
                <h5 class="modal-title" id="profile-modal-title">Add Catering Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6" id="product-select-wrap">
                        <label class="form-label">Product <span class="text-danger">*</span></label>
                        <select name="product_id" id="profile-product" class="form-select"></select>
                    </div>
                    <div class="col-md-6 d-none" id="product-name-wrap">
                        <label class="form-label">Product</label>
                        <input type="text" class="form-control" id="profile-product-name" disabled>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Urdu Display Name</label>
                        <input type="text" name="name_ur" dir="rtl" lang="ur" class="form-control">
                        <div class="form-text">Optional — stored as a product translation; English name stays the fallback.</div>
                    </div>
                    {{-- Two different questions, deliberately labelled so. One is
                         how the dish is quoted; the other is what decides its
                         cost. Calling either of them "Mode" is how they get
                         confused for each other. --}}
                    {{-- CAT-PRICE-001 — REMOVED FOR V1, not hidden and not faked.
                         "Per PAX" and "Fixed" were offered, stored and displayed,
                         and no calculation anywhere ever read them. An operator
                         choosing Per PAX got exactly the same quotation as one
                         choosing Fixed, which is worse than having no setting:
                         they believed a decision had been recorded.
                         What actually decides the price is the quantity typed on
                         the line and the Cost Blocks behind it, so that is what
                         the screen now says. The column stays, defaulted, so no
                         existing profile changes meaning; making the mode real is
                         a pricing tranche of its own, not a dropdown. --}}
                    <input type="hidden" name="pricing_mode" value="fixed">
                    <div class="col-md-4">
                        <label class="form-label">How it is priced</label>
                        <div class="form-control-plaintext">
                            <span class="badge bg-light text-dark border">Quantity &times; rate</span>
                        </div>
                        <div class="form-text">
                            The quantity on the quotation line decides the amount — kilos, plates,
                            waiters, boxes. For a charge that does not scale, use a
                            <strong>lump sum</strong> cost block.
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Costing Source</label>
                        <select name="costing_mode" id="profile-costing-mode" class="form-select">
                            <option value="recipe">Recipe</option>
                            <option value="blocks">Cost Blocks</option>
                        </select>
                        <div class="form-text">What decides this dish's cost. Changing it leaves the other one stored.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Default Catering Rate</label>
                        <input type="number" step="0.01" min="0" name="default_catering_rate" class="form-control">
                    </div>
                    <div class="col-12 d-none" id="costing-switch-warning">
                        <div class="alert alert-warning mb-0 py-2">
                            <i class="ti ti-alert-triangle me-1"></i>
                            <span id="costing-switch-text"></span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Default Quote Unit</label>
                        <select name="default_quote_unit_id" class="form-select">
                            <option value="">Product unit</option>
                            @foreach($units as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->code }} — {{ $unit->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Production Station</label>
                        <input type="text" name="production_station" class="form-control" placeholder="e.g. Rice, BBQ, Curry">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Minimum Qty</label>
                        <input type="number" step="0.001" min="0" name="minimum_qty" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Enabled</label>
                        <select name="catering_enabled" class="form-select">
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    {{-- KASHIF-ORDER-PUNCH §A — the legacy per-item switches,
                         set HERE with the item, never at order punching. --}}
                    <div class="col-md-4">
                        <label class="form-label">Allow Party Meat <span dir="rtl" class="text-muted">گاہک کا سامان</span></label>
                        <select name="allow_party_supply" class="form-select">
                            <option value="1">Yes — customer apna saman de sakta hai</option>
                            <option value="0">No — sirf hum</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Complimentry Item <span dir="rtl" class="text-muted">اعزازی</span></label>
                        <select name="is_complimentary" class="form-select">
                            <option value="0">No</option>
                            <option value="1">Yes — naya line khud 0 par</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Production Label (English)</label>
                        <input type="text" name="production_label" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Production Label (Urdu)</label>
                        <input type="text" name="production_label_ur" dir="rtl" lang="ur" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Default Instructions</label>
                        <textarea name="instructions" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    $('#profile-product').select2({
        width: '100%',
        dropdownParent: $('#profileModal'),
        placeholder: 'Search products…',
        ajax: {
            url: '{{ url('/ajax/products') }}',
            dataType: 'json',
            delay: 200,
            data: params => ({ q: params.term, page: params.page || 1 }),
            processResults: data => ({ results: data.results || [], pagination: data.pagination || {} }),
        },
    });

    $('#new-profile-btn').on('click', function () {
        const form = $('#profile-form')[0];
        form.reset();
        $('#profile-method').val('POST');
        $('#profile-form').attr('action', '{{ url('/catering/profiles') }}');
        $('#profile-modal-title').text('Add Catering Product');
        $('#product-select-wrap').removeClass('d-none');
        $('#product-name-wrap').addClass('d-none');
        $('#profile-product').val(null).trigger('change');
        current = { costing_mode: 'recipe', recipe_ingredients: 0, block_count: 0 };
        renderCostingWarning();
    });

    // What the operator is about to move away from, and what stays behind.
    // Switching never deletes either side; saying so is the difference between
    // a considered change and one someone is afraid to make.
    let current = { costing_mode: 'recipe', recipe_ingredients: 0, block_count: 0 };

    function renderCostingWarning() {
        const chosen = $('#profile-costing-mode').val();
        const box = $('#costing-switch-warning');

        if (chosen === current.costing_mode) {
            box.addClass('d-none');
            return;
        }

        let text;
        if (chosen === 'blocks') {
            text = 'This dish will be costed from its cost blocks instead of its recipe.';
            if (current.recipe_ingredients > 0) {
                text += ' Its recipe (' + current.recipe_ingredients + ' ingredient'
                     + (current.recipe_ingredients === 1 ? '' : 's') + ') stays stored and is not deleted — '
                     + 'it simply stops deciding the cost. You can switch back at any time.';
            }
            if (current.block_count === 0) {
                text += ' No cost blocks are configured yet, so this dish cannot be quoted until you add them.';
            }
        } else {
            text = 'This dish will be costed from its recipe instead of its cost blocks.';
            if (current.block_count > 0) {
                text += ' Its ' + current.block_count + ' cost block'
                     + (current.block_count === 1 ? '' : 's') + ' stay stored and are not deleted.';
            }
            if (current.recipe_ingredients === 0) {
                text += ' No recipe is configured yet, so this dish cannot be quoted until one exists.';
            }
        }

        $('#costing-switch-text').text(text);
        box.removeClass('d-none');
    }

    $('#profile-costing-mode').on('change', renderCostingWarning);

    $(document).on('click', '.edit-profile', function () {
        const p = $(this).data('profile');
        const form = $('#profile-form')[0];
        form.reset();
        $('#profile-method').val('PUT');
        $('#profile-form').attr('action', '{{ url('/catering/profiles') }}/' + p.id);
        $('#profile-modal-title').text('Edit — ' + p.product_name);
        $('#product-select-wrap').addClass('d-none');
        $('#product-name-wrap').removeClass('d-none');
        $('#profile-product-name').val(p.product_name);
        $('[name=name_ur]').val(p.name_ur || '');

        $('[name=costing_mode]').val(p.costing_mode || 'recipe');
        current = {
            costing_mode: p.costing_mode || 'recipe',
            recipe_ingredients: p.recipe_ingredients || 0,
            block_count: p.block_count || 0,
        };
        renderCostingWarning();
        $('[name=default_catering_rate]').val(p.default_catering_rate ?? '');
        $('[name=default_quote_unit_id]').val(p.default_quote_unit_id ?? '');
        $('[name=production_station]').val(p.production_station || '');
        $('[name=minimum_qty]').val(p.minimum_qty ?? '');
        $('[name=catering_enabled]').val(p.catering_enabled ? '1' : '0');
        $('[name=allow_party_supply]').val(p.allow_party_supply ? '1' : '0');
        $('[name=is_complimentary]').val(p.is_complimentary ? '1' : '0');
        $('[name=production_label]').val(p.production_label || '');
        $('[name=production_label_ur]').val(p.production_label_ur || '');
        $('[name=instructions]').val(p.instructions || '');
        new bootstrap.Modal(document.getElementById('profileModal')).show();
    });
});
</script>
@endpush
