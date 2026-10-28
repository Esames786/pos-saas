{{--
    "How to use this screen" — one collapsible panel, the same on every purchasing task screen (closed by default, so the
    data-entry area stays in front). Pass $guide = [
        'what'    => 'What this screen does (one or two sentences)',
        'steps'   => ['Step one', ...],
        'effects' => ['Stock' => '...', 'Supplier balance' => '...', 'Payments' => '...'],
        'example' => 'A simple worked example',
        'blocked' => ['Why an action may be blocked', ...],
    ]
--}}
@once
@push('styles')
<style>
    .htu { border:1px solid #E3E8EE; border-radius:12px; background:#FBFCFD; margin:0 0 12px; font-size:.9rem; color:#2A3A4E; }
    .htu > summary { cursor:pointer; list-style:none; padding:8px 14px; font-weight:800; color:#092C4C; display:flex; align-items:center; gap:8px; }
    .htu > summary::-webkit-details-marker { display:none; }
    .htu > summary::before { content:'?'; display:inline-grid; place-items:center; width:20px; height:20px; border-radius:50%; background:#092C4C; color:#fff; font-size:.75rem; }
    .htu > summary::after { content:'Show'; margin-left:auto; font-weight:700; font-size:.8rem; color:#4E5B6B; }
    .htu[open] > summary::after { content:'Hide'; }
    .htu > summary:focus-visible { outline:3px solid #B8902E; outline-offset:2px; border-radius:12px; }
    .htu .htu-body { padding:2px 16px 12px; display:grid; gap:10px; grid-template-columns:repeat(auto-fit, minmax(min(100%, 260px), 1fr)); }
    .htu h3 { font-size:.72rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#4E5B6B; margin:0 0 4px; }
    .htu ol, .htu ul { margin:0; padding-left:1.25rem; display:grid; gap:2px; }
    .htu ol { list-style:decimal; } .htu ul { list-style:disc; }   /* the theme takes list markers off */
    .htu li { display:list-item; }
    .htu dl { margin:0; display:grid; grid-template-columns:auto minmax(0, 1fr); gap:2px 10px; }
    .htu dt { font-weight:700; }
    .htu dd { margin:0; }
    .htu .htu-terms { grid-column:1 / -1; color:#4E5B6B; font-size:.84rem; border-top:1px solid #E3E8EE; padding-top:8px; }
</style>
@endpush
@endonce
<details class="htu" id="how-to-use">
    <summary>How to use this screen</summary>
    <div class="htu-body">
        <section>
            <h3>What this screen does</h3>
            <p class="mb-0">{{ $guide['what'] }}</p>
        </section>
        <section>
            <h3>Steps</h3>
            <ol>
                @foreach($guide['steps'] as $step)<li>{{ $step }}</li>@endforeach
            </ol>
        </section>
        <section>
            <h3>What happens</h3>
            <dl>
                @foreach($guide['effects'] as $what => $effect)<dt>{{ $what }}</dt><dd>{{ $effect }}</dd>@endforeach
            </dl>
        </section>
        <section>
            <h3>Example</h3>
            <p class="mb-0">{{ $guide['example'] }}</p>
        </section>
        @if(! empty($guide['blocked']))
        <section>
            <h3>When it is blocked</h3>
            <ul>
                @foreach($guide['blocked'] as $why)<li>{{ $why }}</li>@endforeach
            </ul>
        </section>
        @endif
        <p class="htu-terms mb-0">Buying has separate steps: a <b>Purchase Order</b> (optional) says what you plan to buy;
            a <b>Goods Receipt</b> records what arrived and puts it into stock; the <b>Supplier Invoice</b> records what
            you owe; a <b>Payment</b> pays it; a <b>Supplier Return</b> sends goods back and gives you <b>Supplier Credit</b>.
            One screen may do more than one step, but each is recorded on its own.</p>
    </div>
</details>
