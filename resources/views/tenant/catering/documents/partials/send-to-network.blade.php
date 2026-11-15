{{--
    CATERING-SEND-TO-PRINTER-1 — "Send to network", THEEK WAHIN jahan Print hai.

    Malik: "jaise abhi preview HTML ka khulta hai, wahan send to network ka
    option de dena — jaise manual option diya hua hai." Aur saath: "old manual
    print aur send to network dono work karain."

    Pehli koshish me maine ye control booking ki screen par rakha tha. Wo ghalat
    jagah thi: operator kaam YAHIN karta hai — preview kholta hai aur Print
    dabata hai. Jo control us ke haath ke paas na ho, wo mojood hone ke baraabar
    nahi.

    ── CHAAR BAATEIN JO IS PARCHE KI SHAKAL BANATI HAIN ───────────────────────

    1. YAHAN KAGAZ KA KHAANA HAI HI NAHI, aur yehi poore kaam ka maqsad hai.
       Malik ki shikayat thi: "bar bar A4 / A5 select karna parta hai." Size
       document khud jaanta hai aur job ke saath jata hai.

    2. KOI BOOTSTRAP NAHI. Ye safhe app ke layout se BAHAR hain — apna CSS, apni
       koi JS nahi. Yahan `class="btn btn-primary"` likhna ek neela text bana
       deta aur `dropdown` likhne par kuch khulta hi nahi. Is liye poora CSS
       neeche khud likha hua hai.

    3. CSS `@can` SE BAHAR HAI, aur ye farq ahem hai. Agar style bhi shart ke
       andar hota to jis tenant ke paas printer ya ijazat na ho, us ka PURANA
       Print button bhi be-shakal ho jata — ek nayi cheez purani ko tor deti.

    4. PRINTER NA HO TO BUTTON HI NAHI — us ki jagah ek satar jo batati hai ke
       karna kya hai. Ek control jo dabane par hamesha error de, screen ka jhoot
       hai.

    Parameters: kind (quotation|kitchen_sheet|address_sheet), ids, printers
--}}
<style>
    /* Teenon preview safhon ka toolbar. `doc-btn` purane Print button par bhi
       lagta hai, is liye ye CSS har haal me nikalti hai — dekho upar (3). */
    .doc-toolbar-row { display: inline-flex; align-items: stretch; gap: 8px; vertical-align: middle; }
    .doc-btn, .doc-select {
        font-family: Arial, Helvetica, sans-serif; font-size: 13px; line-height: 1.2;
        padding: 8px 16px; border: 1px solid #d1d5db; border-radius: 5px;
        background: #fff; color: #111827; cursor: pointer;
    }
    .doc-select { padding: 8px 10px; max-width: 220px; }
    .doc-btn:hover, .doc-select:hover { border-color: #9ca3af; }
    .doc-btn-primary { background: #111827; border-color: #111827; color: #fff; }
    .doc-btn-primary:hover { background: #374151; border-color: #374151; }
    .doc-paper {
        display: inline-flex; align-items: center; font-family: Arial, Helvetica, sans-serif;
        font-size: 12px; font-weight: 700; letter-spacing: .04em; padding: 0 12px;
        border: 1px solid #d1d5db; border-radius: 5px; background: #f3f4f6; color: #374151;
    }
    .doc-note { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #6b7280; }
    .doc-flash {
        font-family: Arial, Helvetica, sans-serif; font-size: 13px; border-radius: 5px;
        padding: 8px 12px; margin: 10px auto 0; max-width: 210mm;
    }
    .doc-flash-ok  { color: #065f46; background: #d1fae5; border: 1px solid #6ee7b7; }
    .doc-flash-bad { color: #991b1b; background: #fee2e2; border: 1px solid #fca5a5; }
    /* PRINT-PREPARE-1 — "⏳ tayyar ho raha hai / ✓ Tayyar · waqt ↻". Sirf khabar: Send kabhi is par nahi rukta. */
    .doc-prep {
        display: inline-flex; align-items: center; gap: 6px; font-family: Arial, Helvetica, sans-serif;
        font-size: 12px; padding: 0 10px; border-radius: 5px; border: 1px solid #d1d5db; color: #374151; background: #f9fafb;
    }
    .doc-prep[data-state="ready"] { color: #065f46; background: #ecfdf5; border-color: #6ee7b7; }
    .doc-prep[data-state="offline"], .doc-prep[data-state="failed"] { color: #92400e; background: #fffbeb; border-color: #fcd34d; }
    .doc-prep-refresh { border: 0; background: transparent; cursor: pointer; font-size: 14px; padding: 0 2px; color: inherit; }
    /* `hidden` ko class ka `display` na hara de. */
    .doc-prep[hidden], .doc-prep-refresh[hidden] { display: none; }
    @media print { .doc-toolbar-row, .doc-flash, .doc-note, .doc-prep { display: none !important; } }
</style>

{{-- IDS NA HON TO YAHAN SE AAGE KUCH NAHI.

     Ye sirf ehtiyat nahi, ek asli kharabi ka ilaj hai. Yehi document
     `CateringDocumentQueueService` bhi render karta hai — agent ko bhejne ke
     liye HTML jama karte waqt — aur wahan `ids` hoti hi nahi. Us surat me
     neeche ka hissa chalta to chhapne wale kaghaz par "No A4/A5 printer yet"
     likha nikal aata, aur `@error` ko `$errors` chahiye jo request ke bahar
     mojood hi nahi hota (yehi 500 test ne pakra).

     CSS upar hai, is shart se BAHAR: wo purane Print button par bhi lagti hai. --}}
@if(isset($ids) || isset($sendAction))
@php
    $stnPrinters = collect($printers ?? []);
    // `?? []` zaroori: invoice wale raaste par `ids` hoti hi nahi (wahan apna
    // `sendAction` hota hai), aur us surat me ye satar warna girti thi.
    $stnIds = collect($ids ?? [])->filter()->values();
    $stnPaper = \App\Services\Catering\CateringDocumentQueueService::paperLabel($kind);

    // CATERING-SEND-INVOICE-DOC-1 — ek alag raasta bhi.
    //
    // Quotation / kitchen sheet / address sheet bulk endpoint par jaate hain
    // (`kind` + `ids[]`). Final invoice ka apna raasta hai, kyunke us ka
    // apna permission hai aur wo `reprint` ki ginti bhi rakhta hai. Do alag
    // control banane se ye saada toolbar do jagah likha jata — aur ek din
    // dono alag ho jate.
    $stnAction = $sendAction ?? url('/catering/documents/bulk/print');
    $stnExtra = $sendExtra ?? [];
    $stnPermission = $sendPermission ?? 'tenant.catering.documents.bulk-print';
    // PRINT-PREPARE-1 — Master switch band ho to chip / script bilkul nahi; safha bilkul pehle jaisa.
    $stnPrepare = \App\Support\TenantFeatures::on(\App\Support\TenantFeatures::PRINT_PREPARE);
@endphp

@can($stnPermission)
    @if($stnPrinters->isNotEmpty() && (isset($sendAction) || $stnIds->isNotEmpty()))
        <form method="POST" action="{{ $stnAction }}" class="doc-toolbar-row" @if($stnPrepare) data-stn-prepare @endif>
            @csrf
            @if(isset($sendAction))
                @foreach($stnExtra as $k => $v)
                    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                @endforeach
            @else
                <input type="hidden" name="kind" value="{{ $kind }}">
                @foreach($stnIds as $id)
                    <input type="hidden" name="ids[]" value="{{ $id }}">
                @endforeach
                {{-- CATERING-SEND-LANGUAGE-1 (11 Nov) — JO ZUBAAN SCREEN PAR, WOHI KAGHAZ PAR.

                     Client: "Urdu ya sirf English select karo, send to network hamesha
                     English + Urdu bhejta hai." Form zubaan bhejta hi nahi tha, aur
                     server har baar settings ki default chhapta tha. Ye safha jis zubaan
                     me khula hai (`$lang`, controller se) wohi ab saath jata hai.
                     Invoice wala raasta upar hai — us ke `sendExtra` me `lang` pehle se
                     tha, isi liye sirf invoice theek chhapti thi. Address sheet ki koi
                     zubaan nahi, is liye `isset`. --}}
                @if(isset($lang))
                    <input type="hidden" name="lang" value="{{ $lang }}">
                @endif
            @endif

            {{-- KAGHAZ KA SIZE YAHAN LIKHA HAI, aur ye jaan bujh kar hai.

                 Malik (10 Oct): "dono printer A4 bhi chhap sakte hain aur A5
                 bhi. Kitchen sheet hamesha A5, baqi sab A4." Qaida pehle se
                 THEEK chal raha tha — size document se aata hai, printer se
                 nahi — magar screen par kahin likha nahi tha. Operator ko
                 printer ke naam par jana parta tha ("… (A4)"), aur wo naam
                 jhoot bolta hai: usi printer par kitchen sheet bhejo to A5 hi
                 nikalti hai.

                 Ye label SERVICE se aata hai, yahan dobara nahi likha ja raha.
                 Do jagah likhne par ek din dono alag ho jate aur screen jhoot
                 bolne lagti. --}}
            <span class="doc-paper" title="Kaghaz ka size document se tay hota hai, printer se nahi">
                {{ $stnPaper }}
            </span>
            <select name="printer_id" class="doc-select" required>
                {{-- JIS KAGAZ KA DOCUMENT HAI, USI NAAP KA PRINTER PEHLE SE CHUNA HO.

                     Malik (10 Oct): "kitchen sheet par A4 likha hai magar printer A5
                     aa raha hai — ye confusing lag raha hai." Theek shikayat thi, aur
                     screen WAQAI apne aap ko jhutla rahi thi: chip "A4" kehti aur us
                     ke saath "Office - HP M127fn (A5)" chuna hua nazar aata.

                     Wajah mamooli thi: fehrist sirf NAAM ke hisaab se tarteeb me hai,
                     is liye "M127fn" hamesha "P2055dn" se pehle aa jata aur browser
                     pehla option khud chun leta — document se us ka koi taaluq hi nahi
                     tha.

                     `paper_size` yahan sirf PEHLA CHUNAO tay karta hai, rokta kisi ko
                     nahi: dono printer fehrist me rehte hain aur dono dono naap chhap
                     sakte hain. Isi liye duplicate rows banane ki zaroorat nahi pari —
                     wo har cheez do guni kar deti (do health check, do jagah naam
                     theek karna) aur masla phir bhi screen ka hi rehta.

                     Kisi printer ka naap na mile to koi `selected` nahi lagta aur
                     browser pehla chun leta hai — yani purana rawaiyya, jo kabhi
                     galat jawab nahi deta kyunke kagaz document se jata hai. --}}
                @foreach($stnPrinters as $p)
                    <option value="{{ $p->id }}"
                        @selected(strtoupper((string) ($p->paper_size ?? '')) === $stnPaper)>{{ $p->name }}</option>
                @endforeach
            </select>
            {{-- HAR BAAR NAYA KAGHAZ — ye malik ka faisla hai, 11 October.

                 Pehle ek document do baar queue nahi hota tha: wohi purani job
                 lautti thi, taake ghalti se do kaghaz na nikal jayein. Us ki
                 qeemat ye nikli ke malik kitchen sheet dobara bhejte rahe aur
                 kuch hota hi nahi tha.

                 Maine pehle yahan ek "Dobara chhapein" ka khaana lagaya tha.
                 Malik ne saaf kaha: "jitni baar bhi bhejoon, POS thori hai."
                 Baat durust hai — catering ka parcha POS ki parchi nahi: bawarchi
                 khane ko teen copy chahiye to teen baar dabana hi us ka tareeqa
                 hai, aur har baar ek khaane par nishan lagwana rozmarra ke kaam
                 me rukawat hai.

                 Is liye ab koi khaana nahi — ye hamesha ON hai, aur har dabana ek
                 naya kaghaz deta hai. (Duplicate ki rok KHATM nahi hui; wo POS ke
                 receipt/KOT par jaise thi waisi hai. Yahan sirf band hai.)

                 Qeemat ye hai ke do baar dabane se do kaghaz nikalenge. Malik ko
                 ye maloom hai aur yehi un ki farmaish thi. --}}
            <input type="hidden" name="reprint" value="1">
            <button type="submit" class="doc-btn doc-btn-primary">Send to network</button>
            @if($stnPrepare)
                {{-- PRINT-PREPARE-1 — sirf khabar. Send ka button upar hai aur hamesha chalta hai, tayyar ho ya na ho. --}}
                <span class="doc-prep" data-prep data-state="preparing" hidden>
                    <span data-prep-text>⏳ Print tayyar ho raha hai…</span>
                    <button type="button" class="doc-prep-refresh" data-prep-refresh hidden
                            title="Dobara tayyar karo — naya waqt, aaj ka order">↻</button>
                </span>
            @endif
        </form>
        @if($stnPrepare)
            {{-- PRINT-PREPARE-1 — safha khulte hi agent ko "PDF bana kar rakho"; phir "tayyar hai? order wohi hai?".

                 Wohi form, wohi raasta, wohi ijazat — bas `mode`. Kuch bhi ghalat ho (network, server, switch band) to
                 chip chup-chaap chhup jati hai: ye sirf raftar ka intezam hai, Send kabhi is par munhasir nahi.
                 Server ko har poll par document dobara banana parta hai (order badla ya nahi), is liye poll dheema:
                 tayyari ke pehle minute 2 s, phir 10 s; tayyar ho to 15 s; tab chhupi ho to ruk jata hai.
                 ↻ = nayi tayyari (naya waqt) + safha naya. Order badla = safha khud naya (jo screen par, wohi kaghaz par). --}}
            <script>
            (function () {
                var form = document.querySelector('form[data-stn-prepare]');
                if (!form || !window.fetch || !window.FormData) { return; }
                var chip = form.querySelector('[data-prep]');
                var text = form.querySelector('[data-prep-text]');
                var refresh = form.querySelector('[data-prep-refresh]');
                var known = {};
                var timer = null;
                var opened = Date.now();

                function show(state, label) {
                    chip.hidden = false;
                    chip.setAttribute('data-state', state);
                    text.textContent = label;
                    refresh.hidden = (state === 'preparing');
                }
                function clock(iso) {
                    try { return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; }
                }
                function call(mode, extra) {
                    var body = new FormData(form);
                    body.set('mode', mode);
                    Object.keys(known).forEach(function (id) { body.set('known[' + id + ']', known[id]); });
                    Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
                    return fetch(form.action, {
                        method: 'POST', body: body, credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    }).then(function (r) { if (!r.ok) { throw new Error('http ' + r.status); } return r.json(); });
                }
                function render(res) {
                    if (!res || res.state === 'off') { chip.hidden = true; return null; }
                    (res.items || []).forEach(function (i) { if (i.fingerprint) { known[i.id] = i.fingerprint; } });
                    if (res.changed) {
                        show('preparing', 'Order badal gaya — dobara tayyar ho raha hai…');
                        setTimeout(function () { location.reload(); }, 1500);
                        return 'changed';
                    }
                    if (!res.agent_online) { show('offline', '⚠ Agent offline — Send phir bhi kaam karega'); return 'offline'; }
                    if (res.state === 'ready') {
                        var at = (res.total === 1 && res.items[0] && res.items[0].ready_at) ? clock(res.items[0].ready_at) : '';
                        show('ready', '✓ Tayyar' + (res.total > 1 ? ' (' + res.total + ')' : '') + (at ? ' · ' + at : ''));
                        return 'ready';
                    }
                    if (res.state === 'failed') { show('failed', 'Tayyari nahi hui — Send phir bhi kaam karega'); return 'failed'; }
                    show('preparing', '⏳ Print tayyar ho raha hai…' + (res.total > 1 ? ' (' + res.ready + ' / ' + res.total + ')' : ''));
                    return 'preparing';
                }
                function next(state) {
                    clearTimeout(timer);
                    if (state === null || state === 'changed') { return; }
                    var wait = state === 'preparing' ? (Date.now() - opened < 60000 ? 2000 : 10000) : 15000;
                    timer = setTimeout(poll, wait);
                }
                function poll() {
                    if (document.hidden) { timer = setTimeout(poll, 5000); return; }
                    call('status').then(function (res) { next(render(res)); })
                        .catch(function () { chip.hidden = true; timer = setTimeout(poll, 30000); });
                }
                refresh.addEventListener('click', function () {
                    show('preparing', '⏳ Print tayyar ho raha hai…');
                    call('prepare', { force: '1' }).then(function () { location.reload(); }, function () { location.reload(); });
                });
                call('prepare').then(function (res) { next(render(res)); }, function () { chip.hidden = true; });
            })();
            </script>
        @endif
    @elseif($stnPrinters->isEmpty())
        <span class="doc-note">
            No A4/A5 printer yet — add one under Printing › Printers (Type = Windows).
        </span>
    @endif
@endcan

{{-- Natija usi safhe par, kyunke ye safhe naye tab me khulte hain aur un ka
     koi "wapas" nahi hota. --}}
@if(session('status'))
    <div class="doc-flash doc-flash-ok">{{ session('status') }}</div>
@endif

{{-- `$errors` web middleware deta hai; is safhe ko kahin aur se render karne par
     wo mojood nahi hota. `@error` seedha likhne se wahan 500 aata tha. --}}
@if(isset($errors) && $errors->has('print'))
    <div class="doc-flash doc-flash-bad">{{ $errors->first('print') }}</div>
@endif
@endif
