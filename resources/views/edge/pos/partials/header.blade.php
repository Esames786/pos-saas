{{-- W0 (Team 1 owns from W1): page header — POS title, View Tables, who/where, order type, terminal, entry points, sync chip, logout. --}}
    <header>
        <h1>POS</h1>
        <button type="button" class="ghost" id="view-tables-btn">View Tables</button>
        <span class="who"><span id="ctx-branch-name">{{ $branchName }}</span> · <span id="ctx-user-name">{{ $userName }}</span></span>
        <span class="spacer"></span>
        <label class="who" for="order-type">Order</label>
        <select id="order-type"></select>
        <label class="who" for="terminal">Terminal</label>
        <select id="terminal" @unless($canChangeTerminal) title="Selling terminal is fixed for your account" @endunless></select>
        <button type="button" class="ghost" id="shift-btn">Shift</button>
        <button type="button" class="ghost" id="returns-btn">Returns</button>
        @if($canSupplierFinance ?? false)
            <a class="navbtn" id="suppliers-link" href="{{ url('/edge/local/pos/suppliers') }}">Suppliers</a>
        @endif
        @if($canManualJournal ?? false)
            <a class="navbtn" id="journal-link" href="{{ url('/edge/local/pos/finance/journal') }}">Journal</a>
        @endif
        @if($canPurchaseReturn ?? false)
            <a class="navbtn" id="purchase-returns-link" href="{{ url('/edge/local/pos/purchase-returns') }}">Purchase Returns</a>
        @endif
        <a class="navbtn" id="health-link" href="{{ url('/edge/local/pos/health') }}">Status</a>
        <span class="chip" id="sync-chip" hidden></span>
        <form method="POST" action="{{ url('/edge/local/logout') }}" style="margin:0">@csrf<button class="ghost" id="logout-btn">Logout</button></form>
    </header>
