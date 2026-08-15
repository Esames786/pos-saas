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
                        <th>Pricing</th>
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
                            'default_quote_unit_id' => $profile->default_quote_unit_id,
                            'pricing_mode' => $profile->pricing_mode,
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
                        <td>{{ $profile->pricing_mode === 'per_pax' ? 'Per PAX' : 'Fixed' }}</td>
                        <td class="text-end">{{ $profile->default_catering_rate !== null ? number_format($profile->default_catering_rate, 2) : '—' }}</td>
                        <td>{{ $profile->defaultQuoteUnit?->code ?? $profile->product->unit?->code ?? '—' }}</td>
                        <td>{{ $profile->production_station ?? '—' }}</td>
                        <td><span class="badge bg-{{ $profile->catering_enabled ? 'success' : 'secondary' }}">{{ $profile->catering_enabled ? 'Yes' : 'No' }}</span></td>
                        <td class="text-end">
                            @can('tenant.catering.profiles.update')
                                <button class="btn btn-sm btn-light edit-profile"
                                        data-profile='@json($profilePayload)'>Edit</button>
                            @endcan
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No catering products configured yet.</td></tr>
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
                    <div class="col-md-4">
                        <label class="form-label">Pricing Mode</label>
                        <select name="pricing_mode" class="form-select">
                            <option value="per_pax">Per PAX</option>
                            <option value="fixed">Fixed</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Default Catering Rate</label>
                        <input type="number" step="0.01" min="0" name="default_catering_rate" class="form-control">
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
    });

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
        $('[name=pricing_mode]').val(p.pricing_mode);
        $('[name=default_catering_rate]').val(p.default_catering_rate ?? '');
        $('[name=default_quote_unit_id]').val(p.default_quote_unit_id ?? '');
        $('[name=production_station]').val(p.production_station || '');
        $('[name=minimum_qty]').val(p.minimum_qty ?? '');
        $('[name=catering_enabled]').val(p.catering_enabled ? '1' : '0');
        $('[name=production_label]').val(p.production_label || '');
        $('[name=production_label_ur]').val(p.production_label_ur || '');
        $('[name=instructions]').val(p.instructions || '');
        new bootstrap.Modal(document.getElementById('profileModal')).show();
    });
});
</script>
@endpush
