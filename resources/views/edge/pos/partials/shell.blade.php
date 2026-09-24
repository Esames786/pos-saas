{{-- W1 (Team 1): the page shell — the ONE generic dialog every feature renders into (#modal, js/core openModal/closeModal),
     the severity toast (#toast), the loading bar, the Branch & Terminal dialog (Online #posContextModal, O:625-659), the
     confirm fallback (used only when SweetAlert2 is not loaded) and the calculator panel (Online #calculator-panel, O:354-363;
     js/boot docks it in the cart column). SweetAlert2 + Bootstrap's bundle load from the local asset route when it is
     enabled on this appliance — exactly the files the Online layout loads (layouts/app.blade.php:104-111). --}}
    <div class="modal" id="modal" role="dialog" aria-modal="true" aria-label="Dialog"><div class="box"><div id="modal-body"></div></div></div>
    <div class="edge-toast" id="toast" role="status" aria-live="polite"></div>
    <div id="edge-loading" hidden aria-hidden="true"></div>

    <div class="edge-dialog" id="posContextModal" role="dialog" aria-modal="true" aria-labelledby="posContextModalLabel">
        <div class="edge-dialog-box">
            <div class="edge-dialog-head">
                <h2 id="posContextModalLabel">Branch &amp; Terminal</h2>
                <button type="button" class="edge-dialog-close" data-close-dialog="posContextModal" aria-label="Close">&times;</button>
            </div>
            <div class="edge-dialog-body">
                <label>Branch</label>
                <div class="bound-branch" id="ctx-bound-branch"><strong>{{ $branchName }}</strong><br><span class="text-muted">This Branch Server is bound to one branch — the branch cannot be changed here.</span></div>
                <label for="terminal">Terminal</label>
                <select id="terminal" @unless($canChangeTerminal) title="Selling terminal is fixed for your account" @endunless></select>
            </div>
            <div class="edge-dialog-foot">
                <button type="button" class="primary" data-close-dialog="posContextModal" id="pos-context-done">Done</button>
            </div>
        </div>
    </div>

    <div class="edge-dialog" id="edge-confirm" role="alertdialog" aria-modal="true" aria-labelledby="edge-confirm-title">
        <div class="edge-dialog-box">
            <div class="edge-dialog-body">
                <div class="edge-confirm-icon" id="edge-confirm-icon" aria-hidden="true">!</div>
                <h2 class="edge-confirm-title" id="edge-confirm-title"></h2>
                <p class="edge-confirm-text" id="edge-confirm-text"></p>
            </div>
            <div class="edge-dialog-foot">
                <button type="button" class="ghost" id="edge-confirm-cancel">Cancel</button>
                <button type="button" class="primary" id="edge-confirm-ok">OK</button>
            </div>
        </div>
    </div>

    <section class="calculator-panel" id="calculator-panel" hidden aria-labelledby="calculator_heading">
        <h2 id="calculator_heading">Touch Keypad / Calculator</h2>
        <input id="calc-display" readonly aria-label="Calculator display">
        <div class="keypad" id="calc-keypad">
            <button type="button" data-key="7">7</button><button type="button" data-key="8">8</button><button type="button" data-key="9">9</button><button type="button" data-key="/">/</button>
            <button type="button" data-key="4">4</button><button type="button" data-key="5">5</button><button type="button" data-key="6">6</button><button type="button" data-key="*">*</button>
            <button type="button" data-key="1">1</button><button type="button" data-key="2">2</button><button type="button" data-key="3">3</button><button type="button" data-key="-">-</button>
            <button type="button" data-key="0">0</button><button type="button" data-key=".">.</button><button type="button" data-key="C">C</button><button type="button" data-key="+">+</button>
            <button type="button" data-key="=" class="eq">=</button>
        </div>
    </section>
@if(\App\Http\Controllers\Edge\EdgeLocalAssetController::available())
    <script src="{{ \App\Http\Controllers\Edge\EdgeLocalAssetController::url('js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ \App\Http\Controllers\Edge\EdgeLocalAssetController::url('plugins/sweetalert/sweetalert2.all.min.js') }}"></script>
@endif
