@extends('layouts.app')

@section('title', 'Role Permissions')

@section('content')
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3">
    <div>
        <h1 class="mb-1">Role Permissions</h1>
        <p class="fw-medium mb-0">Role: <span class="fw-bold">{{ $role->name }}</span>
            <span id="pc-unsaved" class="badge bg-warning text-dark ms-2 d-none">Unsaved changes</span>
        </p>
    </div>
    <a href="{{ url('/roles') }}" class="btn btn-light">Back</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
@endif

<div class="card mb-3">
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
        <input type="search" id="pc-search" class="form-control form-control-sm" style="max-width: 320px"
               placeholder="Search features or permissions…">
        <span class="text-muted small ms-auto">Checked = full access · dash = partial · click a group name to see what's included</span>
    </div>
</div>

@if(!empty($unavailableModules))
    <div class="alert alert-light border small py-2">
        Not available on the current plan (hidden, existing grants preserved):
        <strong>{{ implode(', ', array_map(fn ($m) => \Illuminate\Support\Str::title(str_replace('_', ' ', $m)), $unavailableModules)) }}</strong>
    </div>
@endif

<form method="POST" action="{{ url('/roles/' . $role->id . '/permissions') }}" id="pc-form">
    @csrf
    @method('PUT')

    <div class="accordion" id="pc-modules">
        @foreach($catalog as $module)
            <div class="accordion-item pc-module" data-module="{{ $module['key'] }}">
                <h2 class="accordion-header">
                    <button class="accordion-button {{ $loop->first ? '' : 'collapsed' }}" type="button"
                            data-bs-toggle="collapse" data-bs-target="#pc-mod-{{ $module['key'] }}">
                        <span class="fw-semibold">{{ $module['name'] }}</span>
                        <span class="ms-3 small text-muted d-none d-md-inline">
                            <a href="#" class="pc-select-module link-primary me-2" data-module="{{ $module['key'] }}">Select all</a>
                            <a href="#" class="pc-clear-module link-secondary" data-module="{{ $module['key'] }}">Clear</a>
                        </span>
                    </button>
                </h2>
                <div id="pc-mod-{{ $module['key'] }}" class="accordion-collapse collapse {{ $loop->first ? 'show' : '' }}"
                     data-bs-parent="#pc-modules">
                    <div class="accordion-body">
                        <div class="row g-3">
                            @foreach($module['features'] as $feature)
                                <div class="col-12 col-lg-6 col-xxl-4 pc-feature" data-text="{{ strtolower($feature['name']) }}">
                                    <div class="border rounded p-2 h-100">
                                        <div class="fw-semibold small mb-2">{{ $feature['name'] }}</div>

                                        @foreach(['view' => 'View', 'add' => 'Add', 'edit' => 'Edit', 'delete' => 'Delete'] as $bucket => $bucketLabel)
                                            @php $children = $feature['groups'][$bucket] ?? []; @endphp
                                            @if($children)
                                                <div class="mb-1">
                                                    <div class="form-check form-check-inline mb-0">
                                                        <input class="form-check-input pc-parent" type="checkbox"
                                                               id="pcp-{{ md5($module['key'] . $feature['name'] . $bucket) }}">
                                                        <label class="form-check-label small fw-medium"
                                                               for="pcp-{{ md5($module['key'] . $feature['name'] . $bucket) }}">{{ $bucketLabel }}</label>
                                                    </div>
                                                    <a class="small link-secondary pc-expand" data-bs-toggle="collapse"
                                                       href="#pcg-{{ md5($module['key'] . $feature['name'] . $bucket) }}">▸ details</a>
                                                    <div class="collapse ms-4" id="pcg-{{ md5($module['key'] . $feature['name'] . $bucket) }}">
                                                        @foreach($children as $child)
                                                            <div class="form-check mb-0" data-text="{{ strtolower($child['label'] . ' ' . $child['name']) }}">
                                                                <input class="form-check-input pc-child" type="checkbox"
                                                                       name="permissions[]" value="{{ $child['name'] }}"
                                                                       @checked(in_array($child['name'], $assignedPermissions))
                                                                       @if($child['shared']) data-shared="1" @endif
                                                                       id="pcc-{{ md5($child['name']) }}">
                                                                <label class="form-check-label small" for="pcc-{{ md5($child['name']) }}">
                                                                    {{ $child['label'] }}
                                                                    <span class="text-muted" style="font-size: .72em">{{ $child['name'] }}</span>
                                                                    @if($child['shared'])<span class="badge bg-info-subtle text-info-emphasis ms-1">shared</span>@endif
                                                                </label>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                        @endforeach

                                        @if(!empty($feature['actions']))
                                            <div class="mt-2 pt-1 border-top">
                                                <div class="text-muted mb-1" style="font-size: .72rem">Special actions</div>
                                                @foreach($feature['actions'] as $action)
                                                    <div class="form-check mb-0" data-text="{{ strtolower($action['label'] . ' ' . $action['name']) }}">
                                                        <input class="form-check-input pc-child pc-action" type="checkbox"
                                                               name="permissions[]" value="{{ $action['name'] }}"
                                                               @checked(in_array($action['name'], $assignedPermissions))
                                                               @if($action['shared']) data-shared="1" @endif
                                                               id="pca-{{ md5($action['name']) }}">
                                                        <label class="form-check-label small" for="pca-{{ md5($action['name']) }}">
                                                            {{ $action['label'] }}
                                                            <span class="text-muted" style="font-size: .72em">{{ $action['name'] }}</span>
                                                            @if($action['shared'])<span class="badge bg-info-subtle text-info-emphasis ms-1">shared</span>@endif
                                                        </label>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="position-sticky bottom-0 bg-body border-top py-2 mt-3 d-flex gap-2" style="z-index: 5">
        <button type="submit" class="btn btn-primary">Save Permissions</button>
        <a href="{{ url('/roles') }}" class="btn btn-light">Cancel</a>
    </div>
</form>

<script>
(function () {
    const form = document.getElementById('pc-form');
    const unsaved = document.getElementById('pc-unsaved');

    function groupChildren(parent) {
        const wrap = parent.closest('.mb-1');
        return wrap ? Array.from(wrap.querySelectorAll('.pc-child')) : [];
    }
    function refreshParent(parent) {
        const kids = groupChildren(parent);
        const checked = kids.filter(function (k) { return k.checked; }).length;
        parent.checked = checked === kids.length && kids.length > 0;
        parent.indeterminate = checked > 0 && checked < kids.length;
    }
    document.querySelectorAll('.pc-parent').forEach(function (parent) {
        refreshParent(parent);
        parent.addEventListener('change', function () {
            const kids = groupChildren(parent);
            const sharedBlocked = !parent.checked && kids.some(function (k) { return k.checked && k.dataset.shared; });
            if (sharedBlocked && !confirm('This group includes a SHARED lookup used by multiple screens. Removing it can break other pages for this role. Continue?')) {
                parent.checked = true;
                refreshParent(parent);
                return;
            }
            kids.forEach(function (k) { k.checked = parent.checked; });
            markDirty();
        });
    });
    document.querySelectorAll('.pc-child').forEach(function (child) {
        child.addEventListener('change', function () {
            if (!child.checked && child.dataset.shared
                && !confirm('This is a SHARED lookup permission used by multiple screens. Removing it can break other pages for this role. Continue?')) {
                child.checked = true;
                return;
            }
            const wrap = child.closest('.mb-1');
            const parent = wrap ? wrap.querySelector('.pc-parent') : null;
            if (parent) refreshParent(parent);
            markDirty();
        });
    });

    document.querySelectorAll('.pc-select-module, .pc-clear-module').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const on = link.classList.contains('pc-select-module');
            const mod = document.querySelector('.pc-module[data-module="' + link.dataset.module + '"]');
            if (!mod) return;
            mod.querySelectorAll('.pc-child').forEach(function (k) { k.checked = on; });
            mod.querySelectorAll('.pc-parent').forEach(refreshParent);
            markDirty();
        });
    });

    const search = document.getElementById('pc-search');
    if (search) {
        search.addEventListener('input', function () {
            const q = search.value.trim().toLowerCase();
            document.querySelectorAll('.pc-feature').forEach(function (card) {
                if (!q) { card.classList.remove('d-none'); return; }
                const hit = (card.dataset.text || '').includes(q)
                    || Array.from(card.querySelectorAll('[data-text]')).some(function (el) { return el.dataset.text.includes(q); });
                card.classList.toggle('d-none', !hit);
            });
        });
    }

    function markDirty() { unsaved.classList.remove('d-none'); }
    form.addEventListener('submit', function () { unsaved.classList.add('d-none'); });
})();
</script>
@endsection
