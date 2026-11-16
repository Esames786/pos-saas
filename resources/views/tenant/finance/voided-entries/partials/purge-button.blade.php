{{-- VOID-PURGE-1: "Delete permanently" on a voided entry. Shown only with the Master switch on and the Owner's
     permission; the server decides again whether this entry may go (voided on its own date, nothing else pointing at
     it). Needs the purge-modal partial once on the page.
     $purgeType (manual_journal|expense|supplier_payment|catering_receipt), $purgeId, $purgeLabel, $purgeDate, $purgeAmount --}}
@if(\App\Support\TenantFeatures::on(\App\Support\TenantFeatures::VOID_PURGE) && auth()->user()?->can('tenant.finance.voided-entries.purge'))
    <button type="button" class="btn btn-sm btn-outline-danger void-purge-open"
            data-action="{{ url('/finance/voided-entries/' . $purgeType . '/' . $purgeId . '/purge') }}"
            data-label="{{ $purgeLabel }}" data-date="{{ $purgeDate }}" data-amount="{{ $purgeAmount }}"
            title="Delete this voided entry and its reversal permanently">Delete permanently</button>
@endif
