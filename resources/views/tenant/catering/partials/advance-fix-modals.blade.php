{{--
    CATERING-ADVANCE-VOID-1 — darj shuda receipt ki ghalti theek karne ke do
    darwaze, EK jagah likhe hue.

    Malik ne ye kaam booking ki screen par maanga tha aur phir Customer
    Catering Balances ki screen ka screenshot bhej kar kaha: "is screen pe
    bhi". Dono jagah alag alag markup likhna aasan tha aur ghalat hota —
    ek jagah ki tabdeeli doosri jagah na pahunchti, aur ek hi kaam do qaidon
    par chalne lagta. Peechhe service bhi ek hi hai, is liye saamne bhi ek hi
    parcha.

    $returnCustomer — Customer Balances se aane par us customer ka id, taake
    kaam ke baad wapas usi screen par pahunche. Booking ki screen se null.

    Hudood do hain aur dono jaan-boojh kar:
      • AMOUNT yahan se nahi badalti. Receipt darj hote hi paisa hil chuka
        hota hai; adad chup chaap badal dena kitabon aur screen ko alag kar
        deta. Is liye ulta karo, sahi nayi darj karo.
      • WAJAH lazmi hai. Chhe mahine baad "ye 40,000 kahan gaye" ka jawab
        sirf wahin milega.
--}}
@php $__returnCustomer = $returnCustomer ?? null; @endphp

<div class="modal fade" id="advVoidModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" id="adv-void-form">
            @csrf
            @if($__returnCustomer)
                <input type="hidden" name="return_customer" value="{{ $__returnCustomer }}">
            @endif
            <div class="modal-header">
                <h5 class="modal-title">Receipt ulta karein</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning fs-13 mb-3">
                    <strong><span id="adv-void-amount"></span></strong>
                    <span id="adv-void-date" class="text-muted"></span><br>
                    Is rakam ki journal entry ulti ho jayegi aur cash/bank balance
                    utna hi kam ho jayega. Receipt mitti nahi — wo record par
                    <strong>VOIDED</strong> nishan ke saath rehti hai.
                    Sahi rakam is ke baad nayi receipt se darj karein.
                </div>
                <label class="form-label">Wajah <span class="text-danger">*</span></label>
                <input type="text" name="reason" class="form-control" maxlength="255" required
                       placeholder="jaise: amount ghalat daal diya tha / duplicate entry">
                <div class="form-text">Ye wajah hamesha ke liye receipt aur ledger par likhi rehti hai.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Rehne dein</button>
                <button type="submit" class="btn btn-danger">Ulta karein</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="advRefModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" id="adv-ref-form">
            @csrf
            @if($__returnCustomer)
                <input type="hidden" name="return_customer" value="{{ $__returnCustomer }}">
            @endif
            <div class="modal-header">
                <h5 class="modal-title">Reference theek karein</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Reference</label>
                    <input type="text" name="reference" class="form-control" maxlength="255"
                           placeholder="slip ya cheque number">
                </div>
                <div>
                    <label class="form-label">Note</label>
                    <textarea name="notes" class="form-control" rows="2" maxlength="255"></textarea>
                </div>
                <div class="form-text mt-2">
                    Rakam yahan se nahi badalti — us ke saath paisa hilta hai.
                    Rakam ghalat ho to receipt ulta kar ke sahi nayi darj karein.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Rehne dein</button>
                <button type="submit" class="btn btn-primary">Mehfooz karein</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // Raasta yahan BANAYA jata hai, kisi button ke data se nahi liya jata.
    // Form ka action bahar se aana ek aisa khula darwaza hota jis ki yahan
    // koi zarurat nahi.
    const voidForm = document.getElementById('adv-void-form');
    const refForm = document.getElementById('adv-ref-form');
    const base = @json(url('/catering/advances'));

    document.addEventListener('click', function (e) {
        const v = e.target.closest('.adv-void-btn');
        if (v) {
            voidForm.action = base + '/' + encodeURIComponent(v.dataset.id) + '/void';
            voidForm.querySelector('[name=reason]').value = '';
            document.getElementById('adv-void-amount').textContent = v.dataset.amount || '';
            document.getElementById('adv-void-date').textContent = v.dataset.date ? ' · ' + v.dataset.date : '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('advVoidModal')).show();
            return;
        }
        const r = e.target.closest('.adv-ref-btn');
        if (r) {
            refForm.action = base + '/' + encodeURIComponent(r.dataset.id) + '/reference';
            refForm.querySelector('[name=reference]').value = r.dataset.reference || '';
            refForm.querySelector('[name=notes]').value = r.dataset.notes || '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('advRefModal')).show();
        }
    });

    // Ek dabao, ek karwai. Do baar dabane par service dobara paisa wapas nahi
    // karti — wo pehra wahan hai — magar user ko do baar error dikhane ka bhi
    // koi faida nahi.
    [voidForm, refForm].forEach(function (f) {
        f.addEventListener('submit', function () {
            const btn = f.querySelector('[type=submit]');
            if (btn) { btn.disabled = true; btn.textContent = '…'; }
        });
    });
})();
</script>
