{{-- props: $setting, $fields --}}
@php
    $yes = '<span class="badge bg-success">Yes</span>';
    $no  = '<span class="badge bg-secondary">No</span>';
    $missing = $setting->missingRequired();
@endphp
<div class="card">
    <div class="card-header"><h6 class="mb-0">Posting readiness checklist</h6></div>
    <ul class="list-group list-group-flush">
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>Posting enabled</span>{!! $setting->is_enabled ? $yes : $no !!}
        </li>
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>All required accounts mapped</span>
            @if($setting->isComplete())
                <span class="badge bg-success">Yes</span>
            @else
                <span class="badge bg-danger">{{ count($missing) }} missing</span>
            @endif
        </li>
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>Negative stock policy</span><span class="badge bg-info text-dark">{{ str_replace('_', ' ', $setting->negative_stock_policy) }}</span>
        </li>
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>Costing method</span><span class="badge bg-info text-dark">{{ str_replace('_', ' ', $setting->costing_method) }}</span>
        </li>
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>FG cost source</span><span class="badge bg-info text-dark">{{ str_replace('_', ' ', $setting->fg_cost_source) }}</span>
        </li>
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <span><strong>Ready for manufacturing posting</strong></span>
            {!! $setting->canPost() ? '<span class="badge bg-success">Ready</span>' : '<span class="badge bg-secondary">Not ready</span>' !!}
        </li>
    </ul>
    <div class="card-footer text-muted small">
        "Ready" means manufacturing posting can use this configuration. Map every required
        account before posting consumption, finished goods, or WIP closing documents.
    </div>
</div>
