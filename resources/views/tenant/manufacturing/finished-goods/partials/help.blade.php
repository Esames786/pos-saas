{{-- Side help/summary card for the Finished Goods form --}}
<div class="card">
    <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-info-circle me-1"></i>About this record</h6>
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 mb-3">
            <strong>Tracking only.</strong>
            A Finished Goods record captures production output (received / accepted /
            rejected / scrap) from a WIP job. It does <strong>not</strong> increase
            inventory, write a stock ledger entry, post WIP→FG accounting, create
            COGS or GL entries in this phase.
        </div>

        <ul class="list-unstyled mb-0 small text-muted">
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">FG No</strong> auto-generates as
                <code>FG-000001</code> when left blank.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Use <strong class="text-dark">Record Finished Goods</strong> on a WIP
                job to prefill the header and a default output line.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Belongs to a <strong class="text-dark">single branch</strong>.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">Accepted + Rejected + Scrap</strong> cannot
                exceed Received; Received cannot exceed the WIP planned quantity.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Recording finished goods does <strong class="text-dark">not</strong>
                change the WIP or production order status.</li>
            <li><i class="ti ti-point-filled text-primary me-1"></i>
                Fields marked <span class="text-danger">*</span> are required.</li>
        </ul>
    </div>
</div>
