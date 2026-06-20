{{-- Side help/summary card for the BOM form --}}
<div class="card">
    <div class="card-header">
        <h6 class="mb-0"><i class="ti ti-info-circle me-1"></i>About this BOM</h6>
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 mb-3">
            <strong>Configuration only.</strong>
            A BOM defines the recipe of components for a finished product. It does
            <strong>not</strong> consume inventory, post WIP, create finished goods or
            create GL entries.
        </div>

        <ul class="list-unstyled mb-0 small text-muted">
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">BOM No</strong> auto-generates as
                <code>BOM-000001</code> when left blank.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                Setting status to <strong class="text-dark">Active</strong> deactivates
                any other active BOM for the same finished product.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                <strong class="text-dark">Output Qty</strong> is the batch size the
                component quantities are measured against.</li>
            <li class="mb-2"><i class="ti ti-point-filled text-primary me-1"></i>
                At least one component is required; a component cannot be the finished
                product itself, and duplicates are not allowed.</li>
            <li><i class="ti ti-point-filled text-primary me-1"></i>
                Fields marked <span class="text-danger">*</span> are required.</li>
        </ul>
    </div>
</div>
