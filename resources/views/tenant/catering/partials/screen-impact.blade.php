{{--
    KASHIF-CATERING-PRODUCT-UX-1 (item 6) — the impact header.

    Catering actions are not uniform: some are free to repeat, some post to the
    general ledger, one moves real stock, and a few cannot be undone. An
    operator cannot tell which is which from a button label, and the cost of
    guessing wrong is a wrong ledger or missing inventory.

    So every catering screen states, before anything is clicked: what it
    manages, whether it touches finance, whether it touches stock, whether it
    prints or emails, and whether the action can be taken back.

    Reversibility is deliberately three-valued. A binary yes/no would have to
    lie about the case that matters most — cancelling an event after an advance
    has been received is reversible as an operation and NOT reversible as a
    financial fact.

    Parameters (all optional except $manages):
      manages     string  what this screen is for
      managesUr   string  the same in Urdu
      finance     false | string   false, or what it posts
      stock       false | string   false, or what it moves
      prints      false | string   false, or what it prints
      emails      false | string   false, or who it emails
      reversible  'safe' | 'partly' | 'irreversible'
      note        string  the honest caveat, when there is one
      noteUr      string  the same in Urdu
--}}
@php
    $isUr = app()->getLocale() === 'ur';

    $finance    = $finance    ?? false;
    $stock      = $stock      ?? false;
    $prints     = $prints     ?? false;
    $emails     = $emails     ?? false;
    $reversible = $reversible ?? 'safe';
    $note       = $note       ?? null;
    $noteUr     = $noteUr     ?? null;
    $managesUr  = $managesUr  ?? null;

    $chips = [];

    if ($finance) {
        $chips[] = ['bg-primary', 'ti-cash', $isUr ? 'کھاتے پر اثر' : 'Affects finance', $finance];
    } else {
        $chips[] = ['bg-light text-dark border', 'ti-cash-off', $isUr ? 'کھاتے پر کوئی اثر نہیں' : 'No finance effect', null];
    }

    if ($stock) {
        $chips[] = ['bg-warning text-dark', 'ti-package', $isUr ? 'اسٹاک پر اثر' : 'Moves stock', $stock];
    } else {
        $chips[] = ['bg-light text-dark border', 'ti-package-off', $isUr ? 'اسٹاک نہیں ہلتا' : 'No stock movement', null];
    }

    if ($prints) {
        $chips[] = ['bg-info text-dark', 'ti-printer', $isUr ? 'پرنٹ' : 'Prints', $prints];
    }

    if ($emails) {
        $chips[] = ['bg-info text-dark', 'ti-mail', $isUr ? 'ای میل' : 'Emails', $emails];
    }

    $chips[] = match ($reversible) {
        'irreversible' => ['bg-danger', 'ti-lock', $isUr ? 'واپس نہیں ہو سکتا' : 'Cannot be undone', null],
        'partly'       => ['bg-warning text-dark', 'ti-alert-triangle', $isUr ? 'جزوی طور پر واپس' : 'Only partly reversible', null],
        default        => ['bg-success', 'ti-check', $isUr ? 'محفوظ' : 'Safe to repeat', null],
    };
@endphp

<div class="alert alert-light border d-flex align-items-start gap-2 mb-3" role="note">
    <i class="ti ti-info-circle fs-18 mt-1 text-primary"></i>
    <div class="flex-grow-1">
        <div>{{ $isUr && $managesUr ? $managesUr : $manages }}</div>

        <div class="d-flex flex-wrap gap-1 mt-2">
            @foreach($chips as [$cls, $icon, $label, $detail])
                <span class="badge {{ $cls }} fw-normal fs-12"
                      @if($detail) data-bs-toggle="tooltip" title="{{ $detail }}" @endif>
                    <i class="ti {{ $icon }} me-1"></i>{{ $label }}
                </span>
            @endforeach
        </div>

        @if($note)
            <div class="mt-2 fs-12 text-muted">
                <i class="ti ti-alert-circle me-1"></i>{{ $isUr && $noteUr ? $noteUr : $note }}
            </div>
        @endif
    </div>
</div>
