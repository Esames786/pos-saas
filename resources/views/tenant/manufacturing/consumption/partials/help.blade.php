{{-- Side help/summary card for the Consumption form --}}
<div class="card">
    <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-info-circle me-1"></i>About this record</h6>
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 mb-3">
            <strong>Tracking only.</strong>
            A Consumption record captures planned vs consumed material (with wastage and
            variance). It does <strong>not</strong> deduct inventory, post raw-material
            issue, update WIP/MRC issued quantities, post WIP/material variance, create
            COGS or GL entries in this phase.
        </div>

        <ul class="list-unstyled mb-0 small text-muted">
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">Consumption No</strong> auto-generates as
                <code>CONS-000001</code> when left blank.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Use <strong class="text-dark">Record Consumption</strong> on a WIP job
                or a material requisition to prefill lines (planned from the source).</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">Variance</strong> = Consumed − Planned
                (auto-calculated per line and as a total).</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Wastage cannot exceed consumed per line. Header totals auto-calc from
                lines.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Belongs to a <strong class="text-dark">single branch</strong>; this is a
                separate tracking record and does <strong class="text-dark">not</strong>
                change WIP line consumed qty or MRC issued qty.</li>
            <li><i class="ti ti-point-filled text-primary me-1"></i>
                Fields marked <span class="text-danger">*</span> are required.</li>
        </ul>
    </div>
</div>
