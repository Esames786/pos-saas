{{-- MASTER-HISAB-1 — owners (nafe mein hissa) aur khate (paisa kahan hai). Hisse mil kar 100%. --}}
@php
    $setupPartners = $partners->map(fn ($p) => [
        'key' => 'p'.$p->id, 'id' => $p->id, 'name' => $p->name,
        'share_percent' => (float) $p->share_percent, 'is_active' => (bool) $p->is_active,
    ])->values();
    $setupAccounts = $accounts->map(fn ($a) => [
        'id' => $a->id, 'name' => $a->name, 'partner_key' => $a->partner_id ? 'p'.$a->partner_id : '',
        'is_active' => (bool) $a->is_active, 'balance' => (float) ($balances[$a->id] ?? 0),
    ])->values();
@endphp
<div class="modal fade" id="hsSetupModal" tabindex="-1" aria-labelledby="hsSetupTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" id="hsSetupForm" novalidate
              data-partners='@json($setupPartners)' data-accounts='@json($setupAccounts)'>
            <div class="modal-header">
                <h5 class="modal-title" id="hsSetupTitle">Owners aur khate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Band karein"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none" role="alert" data-hs-setup-errors></div>

                <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="fw-semibold"><span aria-hidden="true">👥</span> Owners — har mahine ke nafe mein hissa</div>
                    <button type="button" class="btn btn-sm btn-light" data-hs-add-partner><i class="ti ti-plus"></i> Owner</button>
                </div>
                <div class="text-muted small mb-2">Sab owners ke hisse mil kar 100% hone chahiye. <span data-hs-share-total></span></div>
                <div data-hs-partners class="mb-4"></div>

                <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="fw-semibold"><span aria-hidden="true">🏦</span> Khate — paisa kahan aata / jata hai</div>
                    <button type="button" class="btn btn-sm btn-light" data-hs-add-account><i class="ti ti-plus"></i> Khata</button>
                </div>
                <div class="text-muted small mb-2">Owner ka apna account chunein, ya "Company ka apna khata" (us ka paisa abhi kisi owner ka nahi).
                    Jis khate mein paisa para ho wo band nahi hota.</div>
                <div data-hs-accounts></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Wapas</button>
                <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy"></i> Save karein</button>
            </div>
        </form>
    </div>
</div>
