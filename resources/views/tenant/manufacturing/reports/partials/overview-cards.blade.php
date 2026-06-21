{{-- Production overview: count + quantity cards --}}
@php
    $c = $overview['counts'];
    $q = $overview['quantities'];
    $countCards = [
        ['Production Orders', $c['production_orders'], 'ti-clipboard-check', 'primary'],
        ['Open Orders', $c['open_orders'], 'ti-progress', 'warning'],
        ['Completed / Closed', $c['closed_orders'], 'ti-circle-check', 'success'],
        ['Material Requisitions', $c['mrcs'], 'ti-clipboard-list', 'info'],
        ['WIP Jobs', $c['wip_jobs'], 'ti-settings-cog', 'primary'],
        ['Finished Goods', $c['finished_goods'], 'ti-package', 'success'],
        ['Scrap Records', $c['scrap_records'], 'ti-trash', 'danger'],
        ['Rejection Records', $c['rejection_records'], 'ti-ban', 'danger'],
        ['Consumption Records', $c['consumption_records'], 'ti-flask', 'info'],
    ];
    $qtyCards = [
        ['Planned Production', $q['planned_production']],
        ['WIP Planned', $q['wip_planned']],
        ['WIP Completed', $q['wip_completed']],
        ['FG Received', $q['fg_received']],
        ['FG Accepted', $q['fg_accepted']],
        ['FG Rejected', $q['fg_rejected']],
        ['FG Scrap', $q['fg_scrap']],
        ['Consumption Planned', $q['cons_planned']],
        ['Consumption Consumed', $q['cons_consumed']],
        ['Scrap Total', $q['scrap_total']],
        ['Rejection Total', $q['rejection_total']],
    ];
@endphp

<div class="row g-2 mb-3">
    @foreach($countCards as [$label, $val, $icon, $color])
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 d-flex align-items-center gap-2">
                    <span class="bg-{{ $color }} bg-opacity-10 text-{{ $color }} rounded p-2"><i class="ti {{ $icon }} fs-18"></i></span>
                    <div>
                        <div class="fw-bold fs-5">{{ number_format($val) }}</div>
                        <div class="text-muted small">{{ $label }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-2 mb-3">
    @foreach($qtyCards as [$label, $val])
        <div class="col-6 col-md-3 col-xl-2">
            <div class="card border-0 shadow-sm text-center h-100"><div class="card-body py-2">
                <div class="text-muted small">{{ $label }}</div>
                <div class="fw-bold">{{ number_format((float) $val, 2) }}</div>
            </div></div>
        </div>
    @endforeach
</div>
