{{--
    Read-only posting status (MFG-FIN-B) — infrastructure only.
    Prop: $document (a model using HasManufacturingPostingStatus).
    Shows the posting state. There is intentionally NO Post / Reverse button and
    NO posting action — event posting is added in a later phase.
--}}
@if(method_exists($document, 'postingStatusLabel'))
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small">Posting status</span>
                <span class="badge bg-{{ $document->postingStatusBadgeClass() }}">{{ $document->postingStatusLabel() }}</span>
            </div>
            <small class="text-muted">
                <i class="ti ti-info-circle me-1"></i>Posting infrastructure is prepared, but event posting (journal &amp; stock) will be added in later phases.
            </small>
        </div>
    </div>
@endif
