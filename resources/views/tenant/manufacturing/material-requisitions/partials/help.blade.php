{{-- Side help/summary card for the Material Requisition form --}}
<div class="card">
    <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-info-circle me-1"></i>About this requisition</h6>
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 mb-3">
            <strong>Request / planning only.</strong>
            A Material Requisition lists the components needed for a production run.
            It does <strong>not</strong> deduct stock, reserve inventory, post WIP,
            create finished goods, COGS or GL entries in this phase.
        </div>

        <ul class="list-unstyled mb-0 small text-muted">
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">MRC No</strong> auto-generates as
                <code>MRC-000001</code> when left blank.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Pick a <strong class="text-dark">Production Order</strong> to prefill
                components from its active BOM (planned qty × BOM ratio + wastage).</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Belongs to a <strong class="text-dark">single branch</strong> /
                production unit.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">Issued</strong> cannot exceed
                <strong class="text-dark">Required</strong>; duplicate components are
                not allowed.</li>
            <li><i class="ti ti-point-filled text-primary me-1"></i>
                Fields marked <span class="text-danger">*</span> are required.</li>
        </ul>
    </div>
</div>
