{{-- Side help/summary card for the Scrap / Hard Waste form --}}
<div class="card">
    <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-info-circle me-1"></i>About this record</h6>
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 mb-3">
            <strong>Tracking only.</strong>
            A Scrap / Hard Waste record captures wasted / damaged / lost quantity. It
            does <strong>not</strong> deduct or adjust inventory, post scrap expense,
            post WIP variance, create COGS or GL entries in this phase.
        </div>

        <ul class="list-unstyled mb-0 small text-muted">
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">Scrap No</strong> auto-generates as
                <code>SCRAP-000001</code> when left blank.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Use <strong class="text-dark">Record Scrap</strong> on a WIP job or a
                finished goods receipt to prefill the source.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                When you add component lines, the header
                <strong class="text-dark">Total / Recoverable / Disposed</strong> are
                auto-calculated from the lines.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Recoverable + Disposed cannot exceed the quantity (per line and total).</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Belongs to a <strong class="text-dark">single branch</strong>; does not
                change WIP / Finished Goods / Production Order status.</li>
            <li><i class="ti ti-point-filled text-primary me-1"></i>
                Fields marked <span class="text-danger">*</span> are required.</li>
        </ul>
    </div>
</div>
