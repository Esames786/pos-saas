{{-- Side help/summary card for the Production Order form --}}
<div class="card">
    <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-info-circle me-1"></i>About this order</h6>
    </div>
    <div class="card-body">
        <div class="alert alert-warning py-2 mb-3">
            <strong>Planning only.</strong>
            A production order does <strong>not</strong> post inventory, WIP, finished
            goods, COGS or GL entries yet. Material consumption and production posting
            are planned for upcoming modules.
        </div>

        <ul class="list-unstyled mb-0 small text-muted">
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">Order No</strong> auto-generates as
                <code>PROD-000001</code> when left blank.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Belongs to a <strong class="text-dark">single branch</strong> — one
                production run, one location.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">Produced</strong> quantity cannot exceed
                <strong class="text-dark">Planned</strong>.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Manufacturing Customer is optional and is
                <strong class="text-dark">not</strong> a POS/Sales customer.</li>
            <li><i class="ti ti-point-filled text-primary me-1"></i>
                Fields marked <span class="text-danger">*</span> are required.</li>
        </ul>
    </div>
</div>
