@extends('layouts.app')

@section('title', 'Receipt / KOT Layouts')

@section('content')
<style>
    .preview-pane {
        background: #f0f0f0;
        border-left: 1px solid #dee2e6;
        border-radius: 0 0.375rem 0.375rem 0;
        display: flex;
        flex-direction: column;
        min-height: 500px;
    }
    .preview-pane iframe {
        flex: 1;
        border: none;
        background: #fff;
        border-radius: 4px;
        box-shadow: 0 2px 12px rgba(0,0,0,.12);
        width: 100%;
        min-height: 460px;
    }
    .form-pane {
        max-height: 72vh;
        overflow-y: auto;
        padding-right: 4px;
    }
</style>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <h1 class="mb-0">Receipt &amp; KOT Layouts</h1>
    @can('tenant.printing.layouts.store')
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLayoutModal">Add Layout</button>
    @endcan
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body pb-0">
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-4">
                <select name="branch_id" class="form-select">
                    <option value="">All Branches</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($selectedBranchId == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-light">Filter</button>
                <a href="{{ url('/printing/layouts') }}" class="btn btn-light">Clear</a>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Branch</th>
                    <th>Type</th>
                    <th>Paper</th>
                    <th>Font</th>
                    <th>Header</th>
                    <th>Footer</th>
                    <th>Active</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($layouts as $l)
                <tr>
                    <td>{{ $l->branch?->name }}</td>
                    <td>{{ ucfirst($l->document_type) }}</td>
                    <td>{{ $l->paper_size }}</td>
                    <td>{{ $l->font_size }}px</td>
                    <td>{{ $l->header_text ? Str::limit($l->header_text, 30) : '—' }}</td>
                    <td>{{ $l->footer_text ? Str::limit($l->footer_text, 30) : '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $l->is_active ? 'success' : 'secondary' }}">
                            {{ $l->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="text-end">
                        @can('tenant.printing.layouts.store')
                            <button class="btn btn-sm btn-primary"
                                    onclick="openEditLayout({{ $l->id }}, '{{ addslashes($l->branch?->name) }}', '{{ $l->document_type }}')">
                                Edit / Preview
                            </button>
                        @else
                            <button class="btn btn-sm btn-outline-secondary"
                                    onclick="openEditLayout({{ $l->id }}, '{{ addslashes($l->branch?->name) }}', '{{ $l->document_type }}')">
                                Preview
                            </button>
                        @endcan
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No layouts configured.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ── Side-by-side Edit + Live Preview Modal ── --}}
@can('tenant.printing.layouts.store')
<div class="modal fade" id="editLayoutModal" tabindex="-1" aria-labelledby="editLayoutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" action="{{ url('/printing/layouts') }}" class="modal-content" enctype="multipart/form-data" id="layout-edit-form">
            @csrf
            <input type="hidden" name="branch_id" id="edit-branch-id">
            <input type="hidden" name="document_type" id="edit-document-type">

            <div class="modal-header py-2">
                <h5 class="modal-title" id="editLayoutModalLabel">Edit Layout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-0">
                <div class="row g-0">
                    {{-- Left: form --}}
                    <div class="col-lg-6 p-3 form-pane">
                        @include('tenant.printing.layouts._form', ['layout' => null])
                    </div>

                    {{-- Right: live preview --}}
                    <div class="col-lg-6 preview-pane p-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="small fw-semibold text-muted">Live Preview</span>
                            <a id="preview-open-tab" href="#" target="_blank" class="btn btn-xs btn-outline-secondary btn-sm small">
                                Open full page ↗
                            </a>
                        </div>
                        <iframe id="live-preview-frame" src="" title="Live receipt preview"></iframe>
                    </div>
                </div>
            </div>

            <div class="modal-footer py-2">
                <button class="btn btn-primary" type="submit">Save Layout</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endcan

{{-- ── Add Layout modal ── --}}
@can('tenant.printing.layouts.store')
<div class="modal fade" id="addLayoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="{{ url('/printing/layouts') }}" class="modal-content" enctype="multipart/form-data">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Add Layout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body row g-3">
                <div class="col-md-6">
                    <label class="form-label required">Branch</label>
                    <select name="branch_id" class="form-select" required>
                        <option value="">— Select —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label required">Document Type</label>
                    <select name="document_type" class="form-select" required>
                        <option value="receipt" @selected(old('document_type') === 'receipt')>Receipt</option>
                        <option value="kot" @selected(old('document_type') === 'kot')>KOT</option>
                    </select>
                </div>
                @include('tenant.printing.layouts._form')
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary">Add Layout</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endcan

{{-- Layout data for JS --}}
@php
$layoutsForJs = [];
foreach ($layouts as $l) {
    $layoutsForJs[$l->id] = [
        'id'                     => $l->id,
        'branch_id'              => $l->branch_id,
        'branch_name'            => $l->branch?->name,
        'document_type'          => $l->document_type,
        'paper_size'             => $l->paper_size,
        'font_size'              => $l->font_size,
        'kot_font_size'          => $l->kot_font_size,
        'header_text'            => $l->header_text,
        'footer_text'            => $l->footer_text,
        'show_logo'              => (bool)$l->show_logo,
        'show_branch_name'       => (bool)$l->show_branch_name,
        'show_branch_address'    => (bool)$l->show_branch_address,
        'show_branch_phone'      => (bool)$l->show_branch_phone,
        'show_tax_number'        => (bool)$l->show_tax_number,
        'show_cashier_name'      => (bool)$l->show_cashier_name,
        'show_customer_name'     => (bool)$l->show_customer_name,
        'show_table_info'        => (bool)$l->show_table_info,
        'show_order_no'          => (bool)$l->show_order_no,
        'show_item_codes'        => (bool)$l->show_item_codes,
        'show_payment_breakdown' => (bool)$l->show_payment_breakdown,
        'is_active'              => (bool)$l->is_active,
        'preview_url'            => url('/printing/layouts/' . $l->id . '/preview'),
    ];
}
@endphp
<script>
var _layoutData = @json($layoutsForJs);

var _activeLayoutId = null;
var _previewDebounce = null;

function openEditLayout(layoutId, branchName, docType) {
    var data = _layoutData[layoutId];
    if (!data) return;
    _activeLayoutId = layoutId;

    // Set modal title
    document.getElementById('editLayoutModalLabel').textContent =
        'Edit Layout — ' + branchName + ' (' + (docType === 'kot' ? 'KOT' : 'Receipt') + ')';

    // Set hidden fields
    document.getElementById('edit-branch-id').value = data.branch_id;
    document.getElementById('edit-document-type').value = data.document_type;

    // Fill form fields
    var form = document.getElementById('layout-edit-form');
    setFormValue(form, 'paper_size',   data.paper_size);
    setFormValue(form, 'font_size',    data.font_size);
    setFormValue(form, 'kot_font_size', data.kot_font_size);
    setFormValue(form, 'header_text',  data.header_text || '');
    setFormValue(form, 'footer_text',  data.footer_text || '');

    var boolFields = ['show_logo','show_branch_name','show_branch_address','show_branch_phone',
        'show_tax_number','show_cashier_name','show_customer_name','show_table_info',
        'show_order_no','show_item_codes','show_payment_breakdown','is_active'];

    boolFields.forEach(function(f) {
        var el = form.querySelector('[name="' + f + '"]');
        if (el) el.checked = !!data[f];
    });

    // Load preview
    refreshPreview();

    // Open modal
    var modal = new bootstrap.Modal(document.getElementById('editLayoutModal'));
    modal.show();
}

function setFormValue(form, name, value) {
    var el = form.querySelector('[name="' + name + '"]');
    if (el) el.value = value !== null && value !== undefined ? value : '';
}

function buildPreviewUrl() {
    if (!_activeLayoutId) return '';
    var data = _layoutData[_activeLayoutId];
    if (!data) return '';

    var form = document.getElementById('layout-edit-form');
    var params = new URLSearchParams();

    params.set('paper_size',   form.querySelector('[name="paper_size"]')?.value   || data.paper_size);
    params.set('font_size',    form.querySelector('[name="font_size"]')?.value    || data.font_size);
    params.set('kot_font_size',form.querySelector('[name="kot_font_size"]')?.value || data.kot_font_size);
    params.set('header_text',  form.querySelector('[name="header_text"]')?.value  || '');
    params.set('footer_text',  form.querySelector('[name="footer_text"]')?.value  || '');

    var boolFields = ['show_logo','show_branch_name','show_branch_address','show_branch_phone',
        'show_tax_number','show_cashier_name','show_customer_name','show_table_info',
        'show_order_no','show_item_codes','show_payment_breakdown'];

    boolFields.forEach(function(f) {
        var el = form.querySelector('[name="' + f + '"]');
        params.set(f, el && el.checked ? '1' : '0');
    });

    return data.preview_url + '?' + params.toString();
}

function refreshPreview() {
    var url = buildPreviewUrl();
    if (!url) return;
    document.getElementById('live-preview-frame').src = url;
    document.getElementById('preview-open-tab').href = url;
}

function schedulePreviewRefresh() {
    clearTimeout(_previewDebounce);
    _previewDebounce = setTimeout(refreshPreview, 400);
}

// Attach change listeners once DOM ready
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('layout-edit-form');
    if (!form) return;

    // Listen to all inputs/selects/textareas/checkboxes
    ['input', 'change'].forEach(function(evt) {
        form.addEventListener(evt, function(e) {
            if (e.target.name === 'logo') return; // skip file input
            schedulePreviewRefresh();
        });
    });
});
</script>
@endsection
