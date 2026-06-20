{{-- Side help/summary card for the Manufacturing Customer form --}}
<div class="card">
    <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-info-circle me-1"></i>About this record</h6>
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 mb-3">
            <strong>Separate from POS/Sales customers.</strong>
            Manufacturing Customers are used only for production orders, job-work and
            manufacturing reports. They have no AR ledger and do not affect POS sales
            or customer payments.
        </div>

        <ul class="list-unstyled mb-0 small text-muted">
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">Code</strong> auto-generates as
                <code>MFG-CUST-0001</code> when left blank.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Fields marked <span class="text-danger">*</span> are required.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Set status to <strong class="text-dark">Inactive</strong> to retire a
                customer without deleting history.</li>
            <li><i class="ti ti-point-filled text-primary me-1"></i>
                Tax number / NTN is optional but recommended for invoicing.</li>
        </ul>
    </div>
</div>
