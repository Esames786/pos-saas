{{-- Side help/summary card for the WIP Job form --}}
<div class="card">
    <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-info-circle me-1"></i>About this WIP job</h6>
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 mb-3">
            <strong>Tracking / planning only.</strong>
            A WIP job tracks an in-process production run (planned vs started vs
            completed). It does <strong>not</strong> deduct stock, reserve inventory,
            post WIP accounting, create finished goods, COGS or GL entries in this phase.
        </div>

        <ul class="list-unstyled mb-0 small text-muted">
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">WIP No</strong> auto-generates as
                <code>WIP-000001</code> when left blank.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Use <strong class="text-dark">Create WIP Job</strong> on a production
                order or MRC to prefill the header and material lines.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Belongs to a <strong class="text-dark">single branch</strong>.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">Started/Completed</strong> cannot exceed
                planned; <strong class="text-dark">consumed</strong> cannot exceed
                issued. Progress % is recalculated from completed ÷ planned.</li>
            <li><i class="ti ti-point-filled text-primary me-1"></i>
                Fields marked <span class="text-danger">*</span> are required.</li>
        </ul>
    </div>
</div>
